<?php

namespace Tests\Feature\Driver;

use App\Data\Models\DriverProfile;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\ServiceTransportation;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use App\Data\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function completeApprovedOnlineDriver(
        string $phone = '971508888001',
        string $transportation = 'motorcycle',
    ): User {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => $phone,
            'user_type' => 'driver',
            'name' => 'Captain',
        ])->assertCreated();

        $driver = User::query()->where('phone', $phone)->firstOrFail();
        Sanctum::actingAs($driver);

        $this->patchJson('/api/v1/driver/profile', [
            'national_id_number' => '784-1990-'.substr($phone, -7).'-1',
            'emergency_contact_name' => 'Sara',
            'emergency_contact_phone' => '971509999001',
        ])->assertOk();

        $this->post('/api/v1/driver/documents', [
            'national_id_front' => UploadedFile::fake()->create('a.jpg', 40, 'image/jpeg'),
            'national_id_back' => UploadedFile::fake()->create('b.jpg', 40, 'image/jpeg'),
            'personal_photo' => UploadedFile::fake()->create('c.jpg', 40, 'image/jpeg'),
            'driving_license_front' => UploadedFile::fake()->create('d.jpg', 40, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/v1/driver/vehicle', [
            'transportation' => $transportation,
            'model' => $transportation === 'car' ? 'Corolla' : 'Boxer 150',
            'color' => 'Black',
            'plate_number' => 'D-'.substr($phone, -5),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson('/api/v1/driver/submit')->assertOk();

        $admin = User::factory()->create(['user_role_id' => UserRole::ADMIN]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/drivers/'.$driver->public_id.'/verify', [
            'status' => 'approved',
        ])->assertOk();

        Sanctum::actingAs($driver);
        // Place driver near typical test pickup (25.1, 55.2) so H3 disk includes the order.
        $this->patchJson('/api/v1/driver/online', [
            'is_online' => true,
            'latitude' => 25.105,
            'longitude' => 55.205,
        ])->assertOk()
            ->assertJsonPath('driver.is_online', true);

        return $driver->fresh();
    }

    private function createCashOrder(User $customer, string $transportation = 'motorcycle', ?string $service = null, array $extraDetails = []): string
    {
        Sanctum::actingAs($customer);
        $details = [[
            'transportation' => $transportation,
            'delivery_speed' => 'standard',
            'pickup_latitude' => 25.1,
            'pickup_longitude' => 55.2,
            'dropoff_latitude' => 25.3,
            'dropoff_longitude' => 55.4,
            'sender_name' => 'A',
            'sender_phone' => '971501111111',
            'receiver_name' => 'B',
            'receiver_phone' => '971502222222',
            'inside_the_package' => 'docs',
            'store_name' => 'Store A',
        ]];
        foreach ($extraDetails as $row) {
            $details[] = array_merge([
                'transportation' => $transportation,
                'delivery_speed' => 'standard',
                'pickup_latitude' => 25.102,
                'pickup_longitude' => 55.203,
                'dropoff_latitude' => 25.31,
                'dropoff_longitude' => 55.41,
                'sender_name' => 'A',
                'sender_phone' => '971501111111',
                'receiver_name' => 'B',
                'receiver_phone' => '971502222222',
                'inside_the_package' => 'box',
                'store_name' => 'Store B',
            ], $row);
        }

        $res = $this->postJson('/api/v1/orders', [
            'service' => $service ?? 'send a package',
            'payment_method' => 'cash',
            'payer' => 'sender',
            'pickup_timing' => 'asap',
            'subtotal' => 25,
            'tip_amount' => 5,
            'details' => $details,
        ])->assertCreated()
            ->assertJsonPath('order.status', 'awaiting_driver');

        return $res->json('order.id');
    }

    public function test_new_awaiting_driver_order_notifies_nearby_drivers_only_via_h3(): void
    {
        // Nearby (~same Dubai area as order pickup 25.1/55.2)
        $nearby = $this->completeApprovedOnlineDriver('971508888010');
        $this->assertNotNull($nearby->h3_index);

        // Far away (Cairo) — must not be notified
        $far = $this->completeApprovedOnlineDriver('971508888011');
        Sanctum::actingAs($far);
        $this->patchJson('/api/v1/driver/online', [
            'is_online' => true,
            'latitude' => 30.0444,
            'longitude' => 31.2357,
        ])->assertOk();
        $far = $far->fresh();
        $this->assertNotNull($far->h3_index);
        $this->assertNotSame($nearby->h3_index, $far->h3_index);

        $customer = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        $orderId = $this->createCashOrder($customer);

        $this->assertTrue(
            AppNotification::query()
                ->where('user_id', $nearby->id)
                ->where('data->type', 'new_order_offer')
                ->where('data->order_id', $orderId)
                ->exists()
        );
        $this->assertFalse(
            AppNotification::query()
                ->where('user_id', $far->id)
                ->where('data->type', 'new_order_offer')
                ->where('data->order_id', $orderId)
                ->exists()
        );
    }

    public function test_login_persists_driver_location_and_h3_index(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971508888040',
            'user_type' => 'driver',
            'name' => 'LocDriver',
            'latitude' => 25.2048,
            'longitude' => 55.2708,
        ])->assertCreated()
            ->assertJsonPath('user.location.latitude', '25.2048000');

        $driver = User::query()->where('phone', '971508888040')->firstOrFail();
        $this->assertNotNull($driver->h3_index);

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971508888040',
            'latitude' => 25.21,
            'longitude' => 55.28,
        ])->assertOk()
            ->assertJsonPath('user.location.latitude', '25.2100000');

        $driver = $driver->fresh();
        $this->assertSame('25.2100000', (string) $driver->latitude);
        $this->assertNotNull($driver->h3_index);

        Sanctum::actingAs($driver);
        $this->patchJson('/api/v1/auth/location', [
            'latitude' => 25.22,
            'longitude' => 55.29,
        ])->assertOk()
            ->assertJsonPath('location.latitude', '25.2200000')
            ->assertJsonStructure(['location' => ['latitude', 'longitude', 'h3_index', 'location_updated_at']]);
    }

    public function test_driver_can_accept_pickup_and_deliver_order(): void
    {
        $driver = $this->completeApprovedOnlineDriver('971508888020');
        $customer = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        $orderId = $this->createCashOrder($customer);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/orders/available')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId]);

        $this->postJson('/api/v1/driver/orders/'.$orderId.'/accept')
            ->assertOk()
            ->assertJsonPath('order.status', 'driver_assigned')
            ->assertJsonPath('order.driver_id', $driver->public_id);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'driver_id' => $driver->id,
            'status_id' => OrderStatus::DRIVER_ASSIGNED,
        ]);

        // Second driver cannot accept same order.
        $driver2 = $this->completeApprovedOnlineDriver('971508888021');
        Sanctum::actingAs($driver2);
        $this->postJson('/api/v1/driver/orders/'.$orderId.'/accept')
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_unavailable');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver/orders/'.$orderId.'/picked-up')
            ->assertOk()
            ->assertJsonPath('order.status', 'picked_up');

        $this->post('/api/v1/driver/orders/'.$orderId.'/delivered', [
            'proof_image' => UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('order.status', 'delivered');

        $this->getJson('/api/v1/driver/earnings')
            ->assertOk()
            ->assertJsonPath('earnings.delivered_orders', 1)
            ->assertJsonPath('earnings.total_tips', '5.00');

        $this->assertNotNull(Order::query()->where('id', $orderId)->value('delivery_proof_path'));
    }

    public function test_cannot_go_online_unless_approved(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971508888030',
            'user_type' => 'driver',
            'name' => 'Newbie',
        ])->assertCreated();

        $driver = User::query()->where('phone', '971508888030')->firstOrFail();
        Sanctum::actingAs($driver);

        $this->patchJson('/api/v1/driver/online', ['is_online' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'driver_not_verified');

        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'verification_status' => DriverProfile::STATUS_INCOMPLETE,
            'is_online' => false,
        ]);
    }

    public function test_notifies_only_matching_vehicle_type_not_all_nearby_drivers(): void
    {
        $moto = $this->completeApprovedOnlineDriver('971508888050', 'motorcycle');
        $car = $this->completeApprovedOnlineDriver('971508888051', 'car');

        $customer = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        $orderId = $this->createCashOrder($customer, 'motorcycle');

        $this->assertTrue(
            AppNotification::query()
                ->where('user_id', $moto->id)
                ->where('data->type', 'new_order_offer')
                ->where('data->order_id', $orderId)
                ->exists()
        );
        $this->assertFalse(
            AppNotification::query()
                ->where('user_id', $car->id)
                ->where('data->type', 'new_order_offer')
                ->where('data->order_id', $orderId)
                ->exists()
        );
    }

    public function test_driver_can_reject_offer_and_it_stays_available_for_others(): void
    {
        $driver = $this->completeApprovedOnlineDriver('971508888060');
        $other = $this->completeApprovedOnlineDriver('971508888061');
        $customer = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        $orderId = $this->createCashOrder($customer);

        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/driver/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('order.status', 'awaiting_driver')
            ->assertJsonStructure(['order' => ['details']]);

        $this->postJson('/api/v1/driver/orders/'.$orderId.'/reject')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/v1/driver/orders/available')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/driver/orders/available')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId]);

        $this->postJson('/api/v1/driver/orders/'.$orderId.'/accept')
            ->assertOk()
            ->assertJsonPath('order.status', 'driver_assigned');
    }

    public function test_multi_store_request_delivery_notifies_and_accepts_as_one_order(): void
    {
        $driver = $this->completeApprovedOnlineDriver('971508888070');
        $customer = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        $orderId = $this->createCashOrder($customer, 'motorcycle', 'request delivery', [
            ['store_name' => 'Store B'],
        ]);

        $this->assertTrue(
            AppNotification::query()
                ->where('user_id', $driver->id)
                ->where('data->type', 'new_order_offer')
                ->where('data->order_id', $orderId)
                ->where('data->details_count', 2)
                ->exists()
        );

        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/driver/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('order.status', 'awaiting_driver')
            ->assertJsonCount(2, 'order.details');

        $this->postJson('/api/v1/driver/orders/'.$orderId.'/accept')
            ->assertOk()
            ->assertJsonPath('order.status', 'driver_assigned')
            ->assertJsonCount(2, 'order.details');
    }
}
