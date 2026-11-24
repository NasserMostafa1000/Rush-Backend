<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Business\Support\RushId;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\OrderStatusEvent;
use App\Data\Models\Payment;
use App\Data\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly StripePaymentGateway $stripe,
        private readonly NotificationService $notifications,
        private readonly LoyaltyService $loyalty,
    ) {
    }

    public function createForOrder(Order $order, User $user, string $method): Payment
    {
        $method = strtolower($method);
        $amount = (float) $order->total;

        if ($method === 'cash') {
            return Payment::query()->create([
                'public_id' => (string) Str::ulid(),
                'order_id' => $order->id,
                'user_id' => $user->id,
                'provider' => 'cash',
                'method' => 'cash',
                'status' => 'pending',
                'amount' => $amount,
                'currency' => config('services.stripe.currency', 'aed'),
            ]);
        }

        $currency = config('services.stripe.currency', 'aed');
        $minor = (int) round($amount * 100);
        if ($minor < 1) {
            // Zero-total orders (full promo/points) � mark paid immediately.
            return Payment::query()->create([
                'public_id' => (string) Str::ulid(),
                'order_id' => $order->id,
                'user_id' => $user->id,
                'provider' => 'stripe',
                'method' => 'card',
                'status' => 'paid',
                'amount' => 0,
                'currency' => $currency,
                'paid_at' => now('UTC'),
                'transaction_id' => 'FREE-'.$order->id,
            ]);
        }

        $intent = $this->stripe->createPaymentIntent($minor, $currency, [
            'order_id' => $order->id,
            'user_id' => (string) $user->id,
        ]);

        return Payment::query()->create([
            'public_id' => (string) Str::ulid(),
            'order_id' => $order->id,
            'user_id' => $user->id,
            'provider' => 'stripe',
            'method' => 'card',
            'status' => 'requires_action',
            'amount' => $amount,
            'currency' => $currency,
            'stripe_payment_intent_id' => $intent['id'],
            'stripe_client_secret' => $intent['client_secret'],
            'meta' => ['stripe_status' => $intent['status']],
        ]);
    }

    public function paymentStatus(User $user, string $orderId): array
    {
        $order = Order::query()
            ->with(['payments', 'status'])
            ->where('id', strtoupper($orderId))
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            throw ApiException::make('not_found', 'Order not found.', 404);
        }

        $payment = $order->payments->sortByDesc('id')->first();
        $paid = $payment?->isPaid() === true
            || in_array((int) $order->status_id, [
                OrderStatus::CONFIRMED,
                OrderStatus::AWAITING_DRIVER,
                OrderStatus::DRIVER_ASSIGNED,
                OrderStatus::PICKED_UP,
                OrderStatus::DELIVERED,
                OrderStatus::AWAITING_CASH,
            ], true);

        return [
            'order_id' => $order->id,
            'order_status' => OrderStatus::codeFromId((int) $order->status_id),
            'paid' => $paid,
            'success' => $paid,
            'payment' => $payment ? $this->presentPayment($payment) : null,
        ];
    }

    /**
     * Refund/cancel payment for a cancelled order.
     * Paid card payments are refunded to the original Stripe payment method.
     *
     * @return array{refund_status: string, payment: array|null}
     */
    public function refundForCancelledOrder(Order $order, ?string $reason = null): array
    {
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            return ['refund_status' => 'not_required', 'payment' => null];
        }

        if ($payment->isRefunded()) {
            return ['refund_status' => 'refunded', 'payment' => $this->presentPayment($payment)];
        }

        if (! $payment->isPaid()) {
            if ($payment->method === 'card' && $payment->stripe_payment_intent_id) {
                $this->stripe->cancelPaymentIntent($payment->stripe_payment_intent_id);
            }
            $payment->forceFill(['status' => 'cancelled'])->save();

            return ['refund_status' => 'not_required', 'payment' => $this->presentPayment($payment->fresh())];
        }

        // Paid ? refund to original payment method.
        if ((float) $payment->amount <= 0) {
            $payment->forceFill([
                'status' => 'refunded',
                'refunded_amount' => 0,
                'refunded_at' => now('UTC'),
            ])->save();

            return ['refund_status' => 'refunded', 'payment' => $this->presentPayment($payment->fresh())];
        }

        if ($payment->method !== 'card' || ! $payment->stripe_payment_intent_id) {
            // Cash already collected would be handled manually; COD unpaid needs no refund.
            return ['refund_status' => 'manual', 'payment' => $this->presentPayment($payment)];
        }

        $refund = $this->stripe->createRefund(
            $payment->stripe_payment_intent_id,
            (int) round((float) $payment->amount * 100),
            $reason,
        );

        $payment->forceFill([
            'status' => 'refunded',
            'stripe_refund_id' => $refund['id'],
            'refunded_amount' => $payment->amount,
            'refunded_at' => now('UTC'),
            'meta' => array_merge($payment->meta ?? [], [
                'refund_id' => $refund['id'],
                'refund_status' => $refund['status'],
                'refund_reason' => $reason,
            ]),
        ])->save();

        return ['refund_status' => 'refunded', 'payment' => $this->presentPayment($payment->fresh())];
    }

    public function handleStripeWebhook(string $payload, ?string $signature): array
    {
        $event = $this->stripe->constructEvent($payload, $signature);
        $type = $event->type ?? null;

        if ($type === 'payment_intent.succeeded') {
            $intent = $event->data->object ?? null;
            $intentId = is_object($intent) ? ($intent->id ?? null) : null;
            if ($intentId) {
                $this->markIntentPaid((string) $intentId, is_object($intent) ? ($intent->id ?? null) : null);
            }
        }

        if ($type === 'payment_intent.payment_failed') {
            $intent = $event->data->object ?? null;
            $intentId = is_object($intent) ? ($intent->id ?? null) : null;
            if ($intentId) {
                Payment::query()
                    ->where('stripe_payment_intent_id', $intentId)
                    ->update(['status' => 'failed']);
            }
        }

        return ['received' => true];
    }

    /** Test/helper: simulate Stripe success for a payment intent. */
    public function markIntentPaid(string $intentId, ?string $transactionId = null): void
    {
        DB::transaction(function () use ($intentId, $transactionId) {
            $payment = Payment::query()
                ->where('stripe_payment_intent_id', $intentId)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->isPaid()) {
                return;
            }

            $payment->forceFill([
                'status' => 'paid',
                'paid_at' => now('UTC'),
                'transaction_id' => $transactionId ?: $intentId,
            ])->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if (! $order) {
                return;
            }

            $becameAwaitingDriver = false;
            if ((int) $order->status_id === OrderStatus::PENDING_PAYMENT) {
                $this->transitionOrder($order, OrderStatus::AWAITING_DRIVER, 'Payment confirmed by Stripe ? waiting for driver acceptance');
                $becameAwaitingDriver = true;
            }

            $user = User::query()->find($payment->user_id);
            if ($user) {
                $this->notifications->push($user, [
                    'title_en' => 'Payment successful',
                    'title_ar' => '?? ????? ?????',
                    'body_en' => "Payment for order {$order->id} was confirmed.",
                    'body_ar' => "?? ????? ??? ????? {$order->id}.",
                    'route' => '/orders/'.$order->id,
                    'data' => ['type' => 'payment_success', 'order_id' => $order->id],
                ]);
            }

            if ($becameAwaitingDriver) {
                $this->notifications->notifyApprovedDriversNewOrder($order->fresh());
            }
        });
    }

    public function transitionOrder(Order $order, int $statusId, ?string $note = null): void
    {
        $order->forceFill(['status_id' => $statusId])->save();

        OrderStatusEvent::query()->create([
            'order_id' => $order->id,
            'status_id' => $statusId,
            'note' => $note,
            'occurred_at' => now('UTC'),
        ]);

        if ($statusId === OrderStatus::DELIVERED) {
            $user = User::query()->find($order->user_id);
            if ($user) {
                $this->loyalty->maybeGrantInviteReward($user);
                $this->notifications->push($user, [
                    'title_en' => 'Order delivered',
                    'title_ar' => '?? ????? ?????',
                    'body_en' => "Order {$order->id} has been delivered.",
                    'body_ar' => "?? ????? ????? {$order->id}.",
                    'route' => '/orders/'.$order->id,
                    'data' => ['type' => 'order_delivered', 'order_id' => $order->id],
                ]);
            }
        }
    }

    public function presentPayment(Payment $p): array
    {
        return [
            'id' => $p->public_id,
            'order_id' => $p->order_id,
            'method' => $p->method,
            'status' => $p->status,
            'amount' => (string) $p->amount,
            'currency' => $p->currency,
            'client_secret' => $p->stripe_client_secret,
            'transaction_id' => $p->transaction_id,
            'refund_id' => $p->stripe_refund_id,
            'refunded_amount' => $p->refunded_amount !== null ? (string) $p->refunded_amount : null,
            'refunded_at' => $p->refunded_at?->utc()->toIso8601String(),
            'paid_at' => $p->paid_at?->utc()->toIso8601String(),
            'created_at' => $p->created_at?->utc()->toIso8601String(),
        ];
    }
}
