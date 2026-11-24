<?php

namespace Tests\Feature\Order;

use App\Business\Services\PaymentService;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\Payment;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private function detail(): array
    {
        return [
            'transportation' => 'motorcycle',
            'delivery_speed' => 'standard',
            'pickup_latitude' => 25.2048,
            'pickup_longitude' => 55.2708,
            'dropoff_latitude' => 25.1972,
            'dropoff_longitude' => 55.2744,
            'sender_name' => 'Ali',
            'sender_phone' => '971501111111',
            'receiver_name' => 'Sara',
            'receiver_phone' => '971502222222',
            'inside_the_package' => 'Documents',
        ];
    }

    private function userWithOrder(int $statusId, string $paymentStatus = 'paid', string $method = 'card'): array
    {
        $user = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        Sanctum::actingAs($user);

        $order = Order::query()->create([
            'id' => 'RSH-CXL'.random_int(100, 999),
            'user_id' => $user->id,
            'service_id' => 1,
            'status_id' => $statusId,
            'total' => 30,
            'subtotal' => 30,
            'payment_method' => $method,
            'ordered_at' => now('UTC'),
        ]);

        Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'order_id' => $order->id,
            'user_id' => $user->id,
            'provider' => $method === 'card' ? 'stripe' : 'cash',
            'method' => $method,
            'status' => $paymentStatus,
            'amount' => 30,
            'currency' => 'aed',
            'stripe_payment_intent_id' => $method === 'card' ? 'pi_fake_'.uniqid() : null,
            'paid_at' => $paymentStatus === 'paid' ? now('UTC') : null,
        ]);

        return [$user, $order];
    }

    public function test_cancel_requires_reason(): void
    {
        [, $order] = $this->userWithOrder(OrderStatus::AWAITING_DRIVER);

        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [])
            ->assertStatus(422);
    }

    public function test_cancel_paid_card_order_refunds_to_original_method(): void
    {
        [, $order] = $this->userWithOrder(OrderStatus::AWAITING_DRIVER);

        $res = $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
            'cancellation_reason' => 'Changed my mind',
        ]);

        $res->assertOk()
            ->assertJsonPath('order.status', 'cancelled')
            ->assertJsonPath('order.cancellation_reason', 'Changed my mind')
            ->assertJsonPath('refund_status', 'refunded')
            ->assertJsonPath('payment.status', 'refunded');

        $this->assertNotNull($res->json('payment.refund_id'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancellation_reason' => 'Changed my mind',
            'refund_status' => 'refunded',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'refunded',
            'refunded_amount' => '30.00',
        ]);
    }

    public function test_cancel_unpaid_card_order_voids_without_refund(): void
    {
        [, $order] = $this->userWithOrder(OrderStatus::PENDING_PAYMENT, 'requires_action');

        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
            'cancellation_reason' => 'Ordered by mistake',
        ])->assertOk()
            ->assertJsonPath('refund_status', 'not_required')
            ->assertJsonPath('payment.status', 'cancelled');
    }

    public function test_cannot_cancel_after_driver_accepted_or_picked_up_or_delivered(): void
    {
        foreach ([OrderStatus::DRIVER_ASSIGNED, OrderStatus::PICKED_UP, OrderStatus::DELIVERED] as $status) {
            [, $order] = $this->userWithOrder($status);

            $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
                'cancellation_reason' => 'Too late',
            ])->assertStatus(422)
                ->assertJsonPath('code', 'cannot_cancel');
        }
    }

    public function test_cancel_cash_awaiting_driver(): void
    {
        [, $order] = $this->userWithOrder(OrderStatus::AWAITING_DRIVER, 'pending', 'cash');

        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
            'cancellation_reason' => 'No longer needed',
        ])->assertOk()
            ->assertJsonPath('order.status', 'cancelled')
            ->assertJsonPath('refund_status', 'not_required');
    }
}
