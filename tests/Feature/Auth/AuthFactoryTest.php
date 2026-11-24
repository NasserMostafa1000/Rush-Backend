<?php

namespace Tests\Feature\Auth;

use App\Data\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuthFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function licenseFile(): UploadedFile
    {
        return UploadedFile::fake()->create('license.jpg', 120, 'image/jpeg');
    }

    public function test_phone_register_defaults_preferred_language_to_english(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '+971 50 111 2233',
            'user_type' => 'personal',
            'name' => 'Omar',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.preferred_language', 'en')
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.auth_provider', 'phone')
            ->assertJsonStructure(['token', 'user' => ['id', 'phone']]);

        $this->assertDatabaseHas('users', [
            'phone' => '971501112233',
            'preferred_language' => 'en',
            'user_role_id' => 1,
            'os_provider_id' => 3,
        ]);
    }

    public function test_phone_login_returns_token(): void
    {
        $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971509998877',
            'user_type' => 'store',
            'license_image' => $this->licenseFile(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971509998877',
        ]);

        $login->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertNotNull($login->json('user.last_login_at'));
    }

    public function test_google_register_returns_api_token(): void
    {
        $token = 'test:g-1001:owner@rush.test:Owner';

        $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'google',
            'token' => $token,
            'user_type' => 'store',
            'license_image' => $this->licenseFile(),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'owner@rush.test')
            ->assertJsonPath('user.preferred_language', 'en')
            ->assertJsonPath('user.user_type', 'store')
            ->assertJsonPath('user.google_linked', true);
    }

    public function test_google_login_creates_personal_account_when_missing(): void
    {
        $token = 'test:g-new-user:new@rush.test:New User';

        $login = $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'google',
            'token' => $token,
        ]);

        $login->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id']])
            ->assertJsonPath('user.email', 'new@rush.test')
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.preferred_language', 'en')
            ->assertJsonPath('user.google_linked', true);

        $this->assertDatabaseHas('users', [
            'email' => 'new@rush.test',
            'user_role_id' => 1,
            'os_provider_id' => 1,
        ]);
    }

    public function test_apple_login_creates_personal_and_returns_token(): void
    {
        $token = 'test:a-9001:apple@rush.test:Apple User';

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'apple',
            'token' => $token,
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.apple_linked', true);
    }

    public function test_apple_register_requires_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'apple',
            'user_type' => 'personal',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'token_required');
    }

    public function test_social_register_when_exists_returns_token_again(): void
    {
        $token = 'test:g-dup:dup@rush.test:Dup';

        $first = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'google',
            'token' => $token,
            'user_type' => 'personal',
        ])->assertCreated();

        $second = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'google',
            'token' => $token,
            'user_type' => 'store',
        ])->assertCreated();

        $this->assertSame($first->json('user.id'), $second->json('user.id'));
        $this->assertNotEmpty($second->json('token'));
    }

    public function test_duplicate_phone_register_conflicts(): void
    {
        $payload = [
            'login_provider_name' => 'phone',
            'phone' => '971501010101',
            'user_type' => 'personal',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_exists');
    }

    public function test_me_and_language_update_with_sanctum(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971507776655',
            'user_type' => 'personal',
            'preferred_language' => 'en',
        ])->assertCreated();

        $token = $register->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.preferred_language', 'en');

        $this->withToken($token)
            ->patchJson('/api/v1/auth/language', ['preferred_language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('user.preferred_language', 'ar');

        $this->assertSame('ar', User::query()->where('phone', '971507776655')->value('preferred_language'));
    }

    public function test_invalid_provider_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'facebook',
            'user_type' => 'personal',
        ])->assertStatus(422);
    }

    public function test_register_driver_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971508887766',
            'user_type' => 'driver',
            'name' => 'Driver One',
        ])->assertCreated()
            ->assertJsonPath('user.user_type', 'driver')
            ->assertJsonPath('user.user_role_id', 3);

        $this->assertDatabaseHas('users', [
            'phone' => '971508887766',
            'user_role_id' => 3,
        ]);
    }
}
