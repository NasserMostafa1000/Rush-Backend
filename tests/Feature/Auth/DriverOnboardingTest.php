<?php

namespace Tests\Feature\Auth;

use App\Data\Models\DriverProfile;
use App\Data\Models\ServiceTransportation;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_login_work_for_personal_store_and_driver(): void
    {
        $personal = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971501111111',
            'user_type' => 'personal',
            'name' => 'Customer One',
        ])->assertCreated()
            ->assertJsonPath('user.user_type', 'personal');

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971501111111',
        ])->assertOk()
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.id', $personal->json('user.id'));

        $storeFile = UploadedFile::fake()->create('store-license.jpg', 100, 'image/jpeg');
        $store = $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971502222222',
            'user_type' => 'store',
            'name' => 'Store Co',
            'license_image' => $storeFile,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.user_type', 'store');

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971502222222',
        ])->assertOk()
            ->assertJsonPath('user.user_type', 'store')
            ->assertJsonPath('user.id', $store->json('user.id'));

        $driver = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971503333333',
            'user_type' => 'driver',
            'name' => 'Ahmed Khaled',
        ])->assertCreated()
            ->assertJsonPath('user.user_type', 'driver')
            ->assertJsonPath('user.driver.verification_status', 'incomplete')
            ->assertJsonPath('user.driver.can_go_online', false);

        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => User::query()->where('phone', '971503333333')->value('id'),
            'verification_status' => DriverProfile::STATUS_INCOMPLETE,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971503333333',
        ])->assertOk()
            ->assertJsonPath('user.user_type', 'driver')
            ->assertJsonPath('user.id', $driver->json('user.id'))
            ->assertJsonStructure(['user' => ['driver' => ['missing_requirements', 'vehicle']]]);
    }

    public function test_driver_completes_profile_with_transportation_name_and_gets_approved(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971504444444',
            'user_type' => 'driver',
            'name' => 'Captain',
        ])->assertCreated();

        $driver = User::query()->where('phone', '971504444444')->firstOrFail();
        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/transportations')
            ->assertOk()
            ->assertJsonFragment(['id' => ServiceTransportation::MOTORCYCLE, 'code' => 'motorcycle']);

        $this->patchJson('/api/v1/driver/profile', [
            'national_id_number' => '784-1990-1234567-1',
            'emergency_contact_name' => 'Sara',
            'emergency_contact_phone' => '+971505555555',
            'bank_name' => 'FAB',
            'iban' => 'AE070331234567890123456',
            'ziina_payment_link' => 'https://ziina.com/u/captain',
            'service_area' => 'Dubai',
        ])->assertOk()
            ->assertJsonPath('driver.national_id_number', '784-1990-1234567-1')
            ->assertJsonPath('driver.emergency_contact_phone', '971505555555');

        $this->post('/api/v1/driver/documents', [
            'national_id_front' => UploadedFile::fake()->create('id-front.jpg', 80, 'image/jpeg'),
            'national_id_back' => UploadedFile::fake()->create('id-back.jpg', 80, 'image/jpeg'),
            'personal_photo' => UploadedFile::fake()->create('photo.jpg', 80, 'image/jpeg'),
            'driving_license_front' => UploadedFile::fake()->create('license.jpg', 80, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['driver' => ['national_id_front_path', 'driving_license_front_path', 'personal_photo_url']]);

        $this->post('/api/v1/driver/vehicle', [
            'transportation' => 'motorcycle',
            'model' => 'Bajaj Boxer 150',
            'color' => 'Black',
            'plate_number' => 'D-48242',
            'vehicle_image' => UploadedFile::fake()->create('bike.jpg', 90, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.vehicle.transportation_id', ServiceTransportation::MOTORCYCLE)
            ->assertJsonPath('driver.vehicle.transportation', 'motorcycle')
            ->assertJsonPath('driver.vehicle.model', 'Bajaj Boxer 150')
            ->assertJsonPath('driver.vehicle.plate_number', 'D-48242');

        $this->postJson('/api/v1/driver/submit')
            ->assertOk()
            ->assertJsonPath('driver.verification_status', 'under_review')
            ->assertJsonPath('driver.missing_requirements', []);

        $admin = User::factory()->create(['user_role_id' => UserRole::ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/drivers/'.$driver->public_id.'/verify', [
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('driver.verification_status', 'approved')
            ->assertJsonPath('driver.can_go_online', true);

        Sanctum::actingAs($driver);
        $this->post('/api/v1/driver/vehicle', [
            'transportation' => 'Car',
            'model' => 'Toyota Yaris',
            'color' => 'White',
            'plate_number' => 'D-99999',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('driver.verification_status', 'under_review')
            ->assertJsonPath('driver.can_go_online', false)
            ->assertJsonPath('driver.vehicle.transportation_id', ServiceTransportation::CAR)
            ->assertJsonPath('driver.vehicle.transportation', 'car');
    }

    public function test_non_driver_cannot_access_driver_endpoints(): void
    {
        $user = User::factory()->create(['user_role_id' => UserRole::PERSONAL]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/driver/profile')
            ->assertStatus(403)
            ->assertJsonPath('code', 'driver_only');
    }

    public function test_google_register_driver_and_login(): void
    {
        $reg = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'google',
            'token' => 'test:driver-google-1:driver@example.com:Captain G',
            'user_type' => 'driver',
        ])->assertCreated()
            ->assertJsonPath('user.user_type', 'driver')
            ->assertJsonPath('user.auth_provider', 'google');

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'google',
            'token' => 'test:driver-google-1:driver@example.com:Captain G',
        ])->assertOk()
            ->assertJsonPath('user.user_type', 'driver')
            ->assertJsonPath('user.id', $reg->json('user.id'));
    }
}
