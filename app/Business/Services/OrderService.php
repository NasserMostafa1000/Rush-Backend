<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Business\Support\RushId;
use App\Data\Models\DeliveryPreference;
use App\Data\Models\DeliverySpeed;
use App\Data\Models\Order;
use App\Data\Models\OrderDetail;
use App\Data\Models\OrderStatus;
use App\Data\Models\OrderStatusEvent;
use App\Data\Models\Service;
use App\Data\Models\ServiceTransportation;
use App\Data\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PromoCodeService $promos,
        private readonly LoyaltyService $loyalty,
        private readonly CloudflareR2Service $r2,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{order: array, payment: array|null}
     */
    public function create(User $user, array $payload, ?UploadedFile $invoiceImage = null): array
    {
        $serviceId = $this->resolveServiceId($payload['service'] ?? null);
        $details = $payload['details'] ?? null;

        if (! is_array($details) || $details === []) {
            throw ApiException::make('details_required', 'At least one order detail is required.', 422);
        }

        $paymentMethod = strtolower((string) ($payload['payment_method'] ?? 'card'));
        if (! in_array($paymentMethod, ['card', 'cash'], true)) {
            throw ApiException::make('payment_method_invalid', 'payment_method must be card or cash.', 422);
        }

        $payer = strtolower((string) ($payload['payer'] ?? 'sender'));
        if (! in_array($payer, ['sender', 'receiver'], true)) {
            throw ApiException::make('payer_invalid', 'payer must be sender or receiver.', 422);
        }

        $pickupTiming = strtolower((string) ($payload['pickup_timing'] ?? 'asap'));
        if (! in_array($pickupTiming, ['asap', 'scheduled'], true)) {
            throw ApiException::make('pickup_timing_invalid', 'pickup_timing must be asap or scheduled.', 422);
        }

        if ($pickupTiming === 'scheduled' && empty($payload['scheduled_pickup_at'])) {
            throw ApiException::make('scheduled_pickup_required', 'scheduled_pickup_at is required when pickup_timing=scheduled.', 422);
        }

        $subtotal = (float) ($payload['subtotal'] ?? $payload['total'] ?? 0);
        $tip = (float) ($payload['tip_amount'] ?? 0);
        $distance = isset($payload['distance_km']) ? (float) $payload['distance_km'] : null;

        $promoResult = $this->promos->resolveForUser($payload['promo_code'] ?? null, $user, $subtotal);
        $discount = (float) $promoResult['discount'];
        $afterPromo = max(0.0, $subtotal - $discount);

        $loyaltyPointsRequested = (int) ($payload['loyalty_points_to_redeem'] ?? 0);
        $loyaltyResult = $this->loyalty->redeem($user, $loyaltyPointsRequested, $afterPromo);
        $loyaltyDiscount = (float) $loyaltyResult['discount'];
        $loyaltyPoints = (int) $loyaltyResult['points'];

        $total = max(0.0, round($afterPromo - $loyaltyDiscount + $tip, 2));

        $invoicePath = null;
        if ($serviceId === Service::PERSONAL_SHOPPING && $invoiceImage) {
            $invoicePath = $this->r2->upload($invoiceImage, 'invoices');
        }

        $result = DB::transaction(function () use (
            $user, $serviceId, $payload, $details, $paymentMethod, $payer, $pickupTiming,
            $subtotal, $tip, $distance, $discount, $loyaltyDiscount, $loyaltyPoints, $total,
            $promoResult, $invoicePath
        ) {
            $orderId = $this->uniqueRushId(Order::class);

            // Card unpaid → pending_payment. Paid/free or cash → waiting for a driver to accept.
            $initialStatus = $paymentMethod === 'cash'
                ? OrderStatus::AWAITING_DRIVER
                : ($total <= 0 ? OrderStatus::AWAITING_DRIVER : OrderStatus::PENDING_PAYMENT);

            $order = Order::query()->create([
                'id' => $orderId,
                'user_id' => $user->id,
                'service_id' => $serviceId,
                'status_id' => $initialStatus,
                'total' => $total,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tip_amount' => $tip,
                'loyalty_discount_amount' => $loyaltyDiscount,
                'loyalty_points_redeemed' => $loyaltyPoints,
                'promo_code_id' => $promoResult['promo']?->id,
                'payment_method' => $paymentMethod,
                'payer' => $payer,
                'pickup_timing' => $pickupTiming,
                'scheduled_pickup_at' => $payload['scheduled_pickup_at'] ?? null,
                'distance_km' => $distance,
                'estimated_pickup_minutes' => $payload['estimated_pickup_minutes'] ?? null,
                'estimated_delivery_minutes' => $payload['estimated_delivery_minutes'] ?? null,
                'ordered_at' => now('UTC'),
            ]);

            OrderStatusEvent::query()->create([
                'order_id' => $order->id,
                'status_id' => $initialStatus,
                'note' => 'Order created',
                'occurred_at' => now('UTC'),
            ]);

            foreach ($details as $index => $row) {
                if (! is_array($row)) {
                    throw ApiException::make('details_invalid', "details[{$index}] must be an object.", 422);
                }
                $this->createDetail($order, $serviceId, $row, $index, $index === 0 ? $invoicePath : null);
            }

            if ($promoResult['promo']) {
                $this->promos->redeem($promoResult['promo'], $user, $order->id, $discount);
            }

            if ($loyaltyPoints > 0) {
                $user->forceFill([
                    'loyalty_points' => max(0, (int) $user->loyalty_points - $loyaltyPoints),
                ])->save();
            }

            $payment = $this->payments->createForOrder($order, $user, $paymentMethod);

            if ($paymentMethod === 'cash' || $payment->isPaid()) {
                // already in awaiting_cash / confirmed
            }

            $this->notifications->push($user, [
                'title_en' => 'Order created',
                'title_ar' => 'تم إنشاء الطلب',
                'body_en' => "Order {$order->id} was created.",
                'body_ar' => "تم إنشاء الطلب {$order->id}.",
                'route' => '/orders/'.$order->id,
                'data' => ['type' => 'order_created', 'order_id' => $order->id],
            ]);

            return [
                $order->load([
                    'service',
                    'status',
                    'promoCode',
                    'details.transportation',
                    'details.deliveryPreference',
                    'details.deliverySpeed',
                    'statusEvents.status',
                    'payments',
                ]),
                $payment,
            ];
        });

        [$order, $payment] = $result;

        // Cash/free orders start as awaiting_driver → nearby matching vehicle drivers (H3).
        if ((int) $order->status_id === OrderStatus::AWAITING_DRIVER) {
            $this->notifications->notifyApprovedDriversNewOrder($order);
        }

        return [
            'order' => $this->present($order),
            'payment' => $this->payments->presentPayment($payment),
        ];
    }

    public function show(User $user, string $orderId): array
    {
        $order = $this->findOwned($user, $orderId);

        return ['order' => $this->present($order)];
    }

    public function index(User $user): array
    {
        $orders = Order::query()
            ->with(['service', 'status', 'details'])
            ->where('user_id', $user->id)
            ->orderByDesc('ordered_at')
            ->limit(100)
            ->get();

        return [
            'orders' => $orders->map(fn (Order $o) => $this->present($o))->values()->all(),
        ];
    }

    public function cancel(User $user, string $orderId, string $cancellationReason): array
    {
        $reason = trim($cancellationReason);
        if ($reason === '') {
            throw ApiException::make('cancellation_reason_required', 'cancellation_reason is required.', 422);
        }

        $order = $this->findOwned($user, $orderId);
        $statusId = (int) $order->status_id;

        if (! OrderStatus::isCancellable($statusId)) {
            throw ApiException::make(
                'cannot_cancel',
                'Order cannot be cancelled in its current status. Cancellation is only allowed before a driver accepts the order.',
                422,
                ['current_status' => OrderStatus::codeFromId($statusId)],
            );
        }

        // Refund first (Stripe → original payment method). Unpaid intents are voided.
        $refund = $this->payments->refundForCancelledOrder($order, $reason);

        DB::transaction(function () use ($order, $user, $reason, $refund) {
            // Restore redeemed loyalty points.
            if ((int) $order->loyalty_points_redeemed > 0) {
                $owner = User::query()->lockForUpdate()->find($order->user_id);
                if ($owner) {
                    $owner->forceFill([
                        'loyalty_points' => (int) $owner->loyalty_points + (int) $order->loyalty_points_redeemed,
                    ])->save();
                }
            }

            // Release promo redemption so the code is not burned by a cancelled order.
            if ($order->promo_code_id) {
                $redemption = \App\Data\Models\PromoCodeRedemption::query()
                    ->where('promo_code_id', $order->promo_code_id)
                    ->where('order_id', $order->id)
                    ->first();
                if ($redemption) {
                    $redemption->delete();
                    \App\Data\Models\PromoCode::query()
                        ->where('id', $order->promo_code_id)
                        ->where('times_redeemed', '>', 0)
                        ->decrement('times_redeemed');
                }
            }

            $this->payments->transitionOrder($order, OrderStatus::CANCELLED, 'Cancelled by customer: '.$reason);
            $order->forceFill([
                'cancelled_at' => now('UTC'),
                'cancellation_reason' => $reason,
                'refund_status' => $refund['refund_status'],
            ])->save();
        });

        $this->notifications->push($user, [
            'title_en' => 'Order cancelled',
            'title_ar' => 'تم إلغاء الطلب',
            'body_en' => $refund['refund_status'] === 'refunded'
                ? "Order {$order->id} was cancelled. Refund sent to your original payment method."
                : "Order {$order->id} was cancelled.",
            'body_ar' => $refund['refund_status'] === 'refunded'
                ? "تم إلغاء الطلب {$order->id}. تم رد المبلغ إلى وسيلة الدفع الأصلية."
                : "تم إلغاء الطلب {$order->id}.",
            'route' => '/orders/'.$order->id,
            'data' => [
                'type' => 'order_cancelled',
                'order_id' => $order->id,
                'cancellation_reason' => $reason,
                'refund_status' => $refund['refund_status'],
            ],
        ]);

        $fresh = $order->fresh([
            'service', 'status', 'promoCode', 'details.transportation',
            'details.deliveryPreference', 'details.deliverySpeed', 'statusEvents.status', 'payments',
        ]);

        return [
            'order' => $this->present($fresh),
            'refund_status' => $refund['refund_status'],
            'payment' => $refund['payment'],
        ];
    }

    public function rate(User $user, string $orderId, int $rating, ?string $comment = null): array
    {
        $order = $this->findOwned($user, $orderId);
        if ((int) $order->status_id !== OrderStatus::DELIVERED) {
            throw ApiException::make('not_delivered', 'Only delivered orders can be rated.', 422);
        }

        $order->forceFill([
            'rating' => max(1, min(5, $rating)),
            'rating_comment' => $comment,
        ])->save();

        return ['order' => $this->present($order->fresh([
            'service', 'status', 'promoCode', 'details.transportation',
            'details.deliveryPreference', 'details.deliverySpeed', 'statusEvents.status', 'payments',
        ]))];
    }

    private function findOwned(User $user, string $orderId): Order
    {
        $order = Order::query()
            ->with([
                'service', 'status', 'promoCode', 'driver',
                'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
                'statusEvents.status', 'payments',
            ])
            ->where('id', strtoupper($orderId))
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            throw ApiException::make('not_found', 'Order not found.', 404);
        }

        return $order;
    }

    private function createDetail(Order $order, int $serviceId, array $row, int $index, ?string $invoicePath): OrderDetail
    {
        $transportationId = $this->resolveTransportationId($row['transportation'] ?? $row['transportation_id'] ?? null, $index);
        $preferenceId = $this->resolvePreferenceId($row['delivery_preference'] ?? $row['delivery_preference_id'] ?? null, $index);
        $speedId = $this->resolveSpeedId($row['delivery_speed'] ?? $row['delivery_speed_id'] ?? null, $index);

        $required = [
            'pickup_latitude', 'pickup_longitude', 'dropoff_latitude', 'dropoff_longitude',
            'sender_name', 'sender_phone', 'receiver_name', 'receiver_phone',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                throw ApiException::make('validation_failed', "details[{$index}].{$field} is required.", 422);
            }
        }

        $storeName = $row['store_name'] ?? null;
        $shoppingRequest = $row['shopping_request'] ?? null;
        if ($serviceId === Service::PERSONAL_SHOPPING) {
            if ($storeName === null || $storeName === '') {
                throw ApiException::make('store_name_required', "details[{$index}].store_name is required for personal shopping.", 422);
            }
            if ($shoppingRequest === null || $shoppingRequest === '') {
                throw ApiException::make('shopping_request_required', "details[{$index}].shopping_request is required for personal shopping.", 422);
            }
            if (! array_key_exists('expected_order_value', $row) || $row['expected_order_value'] === null || $row['expected_order_value'] === '') {
                throw ApiException::make('expected_order_value_required', "details[{$index}].expected_order_value is required for personal shopping.", 422);
            }
        }

        $inside = $row['inside_the_package'] ?? null;
        if ($serviceId !== Service::PERSONAL_SHOPPING && ($inside === null || $inside === '')) {
            throw ApiException::make('inside_required', "details[{$index}].inside_the_package is required.", 422);
        }

        return OrderDetail::query()->create([
            'id' => $this->uniqueRushId(OrderDetail::class),
            'order_id' => $order->id,
            'transportation_id' => $transportationId,
            'delivery_preference_id' => $preferenceId,
            'delivery_speed_id' => $speedId,
            'minutes' => isset($row['minutes']) ? (int) $row['minutes'] : null,
            'pickup_latitude' => $row['pickup_latitude'],
            'pickup_longitude' => $row['pickup_longitude'],
            'dropoff_latitude' => $row['dropoff_latitude'],
            'dropoff_longitude' => $row['dropoff_longitude'],
            'pickup_address' => $row['pickup_address'] ?? null,
            'dropoff_address' => $row['dropoff_address'] ?? null,
            'package_size' => $row['package_size'] ?? null,
            'pickup_notes' => $this->clip($row['pickup_notes'] ?? null, 200),
            'dropoff_notes' => $this->clip($row['dropoff_notes'] ?? null, 200),
            'store_name' => $storeName,
            'shopping_request' => $shoppingRequest,
            'is_already_paid' => array_key_exists('is_already_paid', $row) ? (bool) $row['is_already_paid'] : null,
            'expected_order_value' => $row['expected_order_value'] ?? null,
            'invoice_image_path' => $invoicePath,
            'sender_name' => (string) $row['sender_name'],
            'sender_phone' => (string) $row['sender_phone'],
            'receiver_name' => (string) $row['receiver_name'],
            'receiver_phone' => (string) $row['receiver_phone'],
            'inside_the_package' => $inside,
        ]);
    }

    private function resolveServiceId(mixed $value): int
    {
        if ($value === null || $value === '') {
            throw ApiException::make('service_required', 'service is required (id or name/code).', 422);
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            if (! Service::query()->where('id', $id)->exists()) {
                throw ApiException::make('service_invalid', 'Unknown service id.', 422);
            }

            return $id;
        }

        $name = strtolower(trim((string) $value));
        $map = [
            'send a package' => Service::SEND_A_PACKAGE,
            'send_a_package' => Service::SEND_A_PACKAGE,
            'receive a package' => Service::RECEIVE_A_PACKAGE,
            'receive_a_package' => Service::RECEIVE_A_PACKAGE,
            'personal shopping' => Service::PERSONAL_SHOPPING,
            'personal_shopping' => Service::PERSONAL_SHOPPING,
            'package from market' => Service::PERSONAL_SHOPPING,
            'request delivery' => Service::REQUEST_DELIVERY,
            'request_delivery' => Service::REQUEST_DELIVERY,
        ];

        if (! isset($map[$name])) {
            throw ApiException::make('service_invalid', 'Unknown service. Use id or name.', 422);
        }

        return $map[$name];
    }

    private function resolveTransportationId(mixed $value, int $index): int
    {
        if ($value === null || $value === '') {
            throw ApiException::make('transportation_required', "details[{$index}].transportation is required.", 422);
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            if (! ServiceTransportation::query()->where('id', $id)->exists()) {
                throw ApiException::make('transportation_invalid', "details[{$index}].transportation is invalid.", 422);
            }

            return $id;
        }

        $code = strtolower(trim((string) $value));
        $row = ServiceTransportation::query()->where('code', $code)->first();
        if (! $row) {
            throw ApiException::make('transportation_invalid', "details[{$index}].transportation must be car or motorcycle.", 422);
        }

        return (int) $row->id;
    }

    private function resolvePreferenceId(mixed $value, int $index): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            if (! DeliveryPreference::query()->where('id', $id)->exists()) {
                throw ApiException::make('delivery_preference_invalid', "details[{$index}].delivery_preference is invalid.", 422);
            }

            return $id;
        }

        $code = strtolower(trim((string) $value));
        $row = DeliveryPreference::query()->where('code', $code)->first();
        if (! $row) {
            throw ApiException::make('delivery_preference_invalid', "details[{$index}].delivery_preference is invalid.", 422);
        }

        return (int) $row->id;
    }

    private function resolveSpeedId(mixed $value, int $index): int
    {
        if ($value === null || $value === '') {
            throw ApiException::make('delivery_speed_required', "details[{$index}].delivery_speed is required (standard|rush).", 422);
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            if (! in_array($id, [DeliverySpeed::STANDARD, DeliverySpeed::RUSH], true)) {
                throw ApiException::make('delivery_speed_invalid', "details[{$index}].delivery_speed is invalid.", 422);
            }

            return $id;
        }

        try {
            return DeliverySpeed::idFromCode((string) $value);
        } catch (\InvalidArgumentException) {
            throw ApiException::make('delivery_speed_invalid', "details[{$index}].delivery_speed must be standard or rush.", 422);
        }
    }

    /** @param  class-string<Order>|class-string<OrderDetail>  $model */
    private function uniqueRushId(string $model): string
    {
        for ($i = 0; $i < 20; $i++) {
            $id = RushId::generate();
            if (! $model::query()->where('id', $id)->exists()) {
                return $id;
            }
        }

        throw ApiException::make('server_error', 'Could not allocate order id.', 500);
    }

    private function clip(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }

    public function present(Order $order): array
    {
        if (! $order->relationLoaded('driver')) {
            $order->loadMissing('driver');
        }

        return [
            'id' => $order->id,
            'service_id' => (int) $order->service_id,
            'service_name' => $order->service?->service_name,
            'status_id' => (int) $order->status_id,
            'status' => OrderStatus::codeFromId((int) $order->status_id),
            'payment_method' => $order->payment_method,
            'payer' => $order->payer,
            'pickup_timing' => $order->pickup_timing,
            'scheduled_pickup_at' => $order->scheduled_pickup_at?->utc()->toIso8601String(),
            'distance_km' => $order->distance_km !== null ? (string) $order->distance_km : null,
            'subtotal' => (string) $order->subtotal,
            'discount_amount' => (string) $order->discount_amount,
            'tip_amount' => (string) $order->tip_amount,
            'loyalty_discount_amount' => (string) $order->loyalty_discount_amount,
            'loyalty_points_redeemed' => (int) $order->loyalty_points_redeemed,
            'promo_code' => $order->promoCode?->code,
            'total' => (string) $order->total,
            'estimated_pickup_minutes' => $order->estimated_pickup_minutes,
            'estimated_delivery_minutes' => $order->estimated_delivery_minutes,
            'rating' => $order->rating,
            'rating_comment' => $order->rating_comment,
            'delivery_proof_path' => $order->delivery_proof_path,
            'delivery_proof_url' => $this->r2->url($order->delivery_proof_path),
            'picked_up_at' => $order->picked_up_at?->utc()->toIso8601String(),
            'delivered_at' => $order->delivered_at?->utc()->toIso8601String(),
            'can_cancel' => OrderStatus::isCancellable((int) $order->status_id),
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at?->utc()->toIso8601String(),
            'refund_status' => $order->refund_status,
            'driver_id' => $order->driver?->public_id,
            'ordered_at' => $order->ordered_at?->utc()->toIso8601String(),
            'created_at' => $order->created_at?->utc()->toIso8601String(),
            'timeline' => $order->relationLoaded('statusEvents')
                ? $order->statusEvents->map(fn (OrderStatusEvent $e) => [
                    'status' => OrderStatus::codeFromId((int) $e->status_id),
                    'note' => $e->note,
                    'occurred_at' => $e->occurred_at?->utc()->toIso8601String(),
                ])->values()->all()
                : [],
            'payment' => $order->relationLoaded('payments')
                ? optional($order->payments->sortByDesc('id')->first(), fn ($p) => $this->payments->presentPayment($p))
                : null,
            'details' => $order->details->map(function (OrderDetail $d) {
                return [
                    'id' => $d->id,
                    'transportation_id' => (int) $d->transportation_id,
                    'transportation' => $d->transportation?->code,
                    'delivery_preference_id' => $d->delivery_preference_id !== null ? (int) $d->delivery_preference_id : null,
                    'delivery_preference' => $d->deliveryPreference?->code,
                    'delivery_speed_id' => (int) $d->delivery_speed_id,
                    'delivery_speed' => $d->deliverySpeed?->code,
                    'minutes' => $d->minutes,
                    'pickup_latitude' => (string) $d->pickup_latitude,
                    'pickup_longitude' => (string) $d->pickup_longitude,
                    'dropoff_latitude' => (string) $d->dropoff_latitude,
                    'dropoff_longitude' => (string) $d->dropoff_longitude,
                    'pickup_address' => $d->pickup_address,
                    'dropoff_address' => $d->dropoff_address,
                    'package_size' => $d->package_size,
                    'pickup_notes' => $d->pickup_notes,
                    'dropoff_notes' => $d->dropoff_notes,
                    'store_name' => $d->store_name,
                    'shopping_request' => $d->shopping_request,
                    'is_already_paid' => $d->is_already_paid,
                    'expected_order_value' => $d->expected_order_value !== null ? (string) $d->expected_order_value : null,
                    'invoice_image_path' => $d->invoice_image_path,
                    'invoice_image_url' => $this->r2->url($d->invoice_image_path),
                    'sender_name' => $d->sender_name,
                    'sender_phone' => $d->sender_phone,
                    'receiver_name' => $d->receiver_name,
                    'receiver_phone' => $d->receiver_phone,
                    'inside_the_package' => $d->inside_the_package,
                ];
            })->values()->all(),
        ];
    }
}
