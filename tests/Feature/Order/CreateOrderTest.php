<?php

namespace Tests\Feature\Order;

use App\Data\Models\Payment;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'user_role_id' => UserRole::PERSONAL,
        ], $attrs));
        Sanctum::actingAs($user);

        return $user;
    }

    private function detail(array $overrides = []): array
    {
        return array_merge([
            'transportation' => 'motorcycle',
            'delivery_preference' => 'call_on_arrival',
            'delivery_speed' => 'standard',
            'minutes' => 45,
            'pickup_latitude' => 25.2048,
            'pickup_longitude' => 55.2708,
            'dropoff_latitude' => 25.1972,
            'dropoff_longitude' => 55.2744,
            'pickup_address' => 'Marina Walk, Dubai',
            'dropoff_address' => 'Downtown Dubai',
            'package_size' => 'Small box',
            'pickup_notes' => 'Call downstairs',
            'dropoff_notes' => 'Leave with guard',
            'sender_name' => 'Ali',
            'sender_phone' => '971501111111',
            'receiver_name' => 'Sara',
            'receiver_phone' => '971502222222',
            'inside_the_package' => 'Documents',
        ], $overrides);
    }

    public function test_create_send_package_order_with_stripe_pending(): void
    {
        $this->actingUser();

        $res = $this->postJson('/api/v1/orders', [
            'service' => 'send a package',
            'subtotal' => 25.5,
            'tip_amount' => 5,
            'payment_method' => 'card',
            'payer' => 'sender',
            'distance_km' => 4.2,
            'details' => [$this->detail()],
        ]);

        $res->assertCreated()
            ->assertJsonPath('order.service_id', 1)
            ->assertJsonPath('order.status', 'pending_payment')
            ->assertJsonPath('order.total', '30.50')
            ->assertJsonPath('payment.method', 'card')
            ->assertJsonPath('payment.status', 'requires_action')
            ->assertJsonStructure(['payment' => ['client_secret', 'id']]);

        $this->assertMatchesRegularExpression('/^RSH-[A-Z0-9]{6}$/', $res->json('order.id'));
    }

    public function test_payment_status_becomes_success_only_after_stripe_webhook(): void
    {
        $user = $this->actingUser();

        $created = $this->postJson('/api/v1/orders', [
            'service' => 1,
            'subtotal' => 20,
            'payment_method' => 'card',
            'details' => [$this->detail()],
        ])->assertCreated();

        $orderId = $created->json('order.id');
        $intentId = Payment::query()->where('order_id', $orderId)->value('stripe_payment_intent_id');

        $this->getJson('/api/v1/orders/'.$orderId.'/payment-status')
            ->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('success', false);

        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $intentId]],
        ]);

        $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 't=1,v1=fake'],
            $payload
        )->assertOk();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/orders/'.$orderId.'/payment-status')
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_status', 'awaiting_driver');
    }

    public function test_cash_order_awaits_driver(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/orders', [
            'service' => 'receive a package',
            'subtotal' => 15,
            'payment_method' => 'cash',
            'payer' => 'receiver',
            'details' => [$this->detail()],
        ])->assertCreated()
            ->assertJsonPath('order.status', 'awaiting_driver')
            ->assertJsonPath('order.can_cancel', true)
            ->assertJsonPath('payment.method', 'cash');
    }

    public function test_request_delivery_allows_multiple_details(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/orders', [
            'service' => 'request delivery',
            'subtotal' => 80,
            'details' => [
                $this->detail(['inside_the_package' => 'Box 1']),
                $this->detail([
                    'transportation' => 'car',
                    'delivery_speed' => 'rush',
                    'minutes' => 20,
                    'inside_the_package' => 'Box 2',
                ]),
            ],
        ])->assertCreated()
            ->assertJsonPath('order.service_id', 4)
            ->assertJsonCount(2, 'order.details');
    }

    public function test_personal_shopping_requires_extra_fields_and_accepts_invoice(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/orders', [
            'service' => 'personal shopping',
            'subtotal' => 50,
            'details' => [$this->detail(['store_name' => 'Carrefour', 'inside_the_package' => null])],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'shopping_request_required');

        $file = UploadedFile::fake()->create('invoice.jpg', 200, 'image/jpeg');

        $this->post('/api/v1/orders', [
            'service' => 'personal shopping',
            'subtotal' => 50,
            'payment_method' => 'card',
            'details' => json_encode([$this->detail([
                'store_name' => 'Carrefour',
                'shopping_request' => '2x milk, 1x bread',
                'is_already_paid' => false,
                'expected_order_value' => 30,
                'inside_the_package' => null,
            ])]),
            'invoice_image' => $file,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('order.details.0.store_name', 'Carrefour')
            ->assertJsonPath('order.details.0.shopping_request', '2x milk, 1x bread')
            ->assertJsonPath('order.details.0.expected_order_value', '30.00');
    }

    public function test_promo_and_loyalty_reduce_total(): void
    {
        $this->actingUser(['user_role_id' => UserRole::ADMIN]);

        $this->postJson('/api/v1/admin/promo-codes', [
            'code' => 'SAVE10',
            'type' => 'fixed',
            'value' => 10,
            'max_redemptions' => 5,
        ])->assertCreated();

        Sanctum::actingAs(User::factory()->create([
            'user_role_id' => UserRole::PERSONAL,
            'loyalty_points' => 100,
        ]));

        $this->postJson('/api/v1/orders', [
            'service' => 1,
            'subtotal' => 50,
            'promo_code' => 'SAVE10',
            'loyalty_points_to_redeem' => 50,
            'tip_amount' => 0,
            'details' => [$this->detail()],
        ])->assertCreated()
            ->assertJsonPath('order.discount_amount', '10.00')
            ->assertJsonPath('order.loyalty_points_redeemed', 50)
            ->assertJsonPath('order.total', '35.00');
    }

    public function test_show_and_history(): void
    {
        $this->actingUser();

        $created = $this->postJson('/api/v1/orders', [
            'service' => 2,
            'subtotal' => 10,
            'payment_method' => 'cash',
            'details' => [$this->detail(['delivery_preference' => null])],
        ])->assertCreated();

        $id = $created->json('order.id');

        $this->getJson('/api/v1/orders/'.$id)
            ->assertOk()
            ->assertJsonPath('order.id', $id);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders');
    }
}
