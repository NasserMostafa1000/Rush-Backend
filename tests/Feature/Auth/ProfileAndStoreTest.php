<?php

namespace Tests\Feature\Auth;

use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileAndStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_store_requires_license_image(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971501234567',
            'user_type' => 'store',
            'name' => 'My Store',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'license_required');
    }

    public function test_register_store_with_license_and_invite(): void
    {
        $inviter = User::factory()->create([
            'invite_code' => 'INVITE01',
            'loyalty_points' => 0,
        ]);

        $file = UploadedFile::fake()->create('license.jpg', 150, 'image/jpeg');

        $res = $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971509998887',
            'user_type' => 'store',
            'name' => 'Company LLC',
            'invite_code' => 'INVITE01',
            'license_image' => $file,
        ], ['Accept' => 'application/json']);

        $res->assertCreated()
            ->assertJsonPath('user.user_type', 'store')
            ->assertJsonStructure(['user' => ['invite_code', 'loyalty_points', 'orders_count', 'license_image_path', 'license_image_url']]);

        $path = $res->json('user.license_image_path');
        $this->assertNotNull($path);
        $this->assertStringContainsString('licenses/', $path);
        $this->assertDatabaseHas('users', [
            'phone' => '971509998887',
            'invited_by_user_id' => $inviter->id,
            'license_image_path' => $path,
        ]);
    }

    public function test_me_returns_orders_and_loyalty(): void
    {
        $user = User::factory()->create([
            'loyalty_points' => 25,
            'user_role_id' => UserRole::PERSONAL,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.loyalty_points', 25)
            ->assertJsonPath('user.orders_count', 0)
            ->assertJsonStructure(['user' => ['loyalty' => ['aed_per_point', 'invite_reward_points']]]);
    }
}
