<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Explicit matrix the product owner asked for:
 * - phone  → token may be null; phone required
 * - google → token required; phone may be null
 * - apple  → token (provider id) required; phone may be null
 * Same rules for register and login.
 */
class AuthNullFieldsMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_login_allows_null_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971501111111',
            'user_type' => 'personal',
            'token' => null,
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => '971501111111',
            'token' => null,
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.phone', '971501111111');
    }

    public function test_phone_register_allows_null_token(): void
    {
        $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'phone',
            'phone' => '971502222222',
            'user_type' => 'store',
            'token' => null,
            'license_image' => UploadedFile::fake()->create('license.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.user_type', 'store')
            ->assertJsonPath('user.auth_provider', 'phone');
    }

    public function test_google_login_with_token_and_null_phone(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'google',
            'token' => 'test:g-matrix-1:g@rush.test:Google',
            'phone' => null,
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.phone', null)
            ->assertJsonPath('user.google_linked', true);
    }

    public function test_google_register_with_token_and_null_phone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'google',
            'token' => 'test:g-matrix-2:g2@rush.test:Google2',
            'phone' => null,
            'user_type' => 'personal',
        ])->assertCreated()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.phone', null)
            ->assertJsonPath('user.google_linked', true);
    }

    public function test_apple_login_with_token_and_null_phone(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'apple',
            'token' => 'test:a-matrix-1:a@rush.test:Apple',
            'phone' => null,
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.user_type', 'personal')
            ->assertJsonPath('user.phone', null)
            ->assertJsonPath('user.apple_linked', true);
    }

    public function test_apple_register_with_token_and_null_phone(): void
    {
        $this->post('/api/v1/auth/register', [
            'login_provider_name' => 'apple',
            'token' => 'test:a-matrix-2:a2@rush.test:Apple2',
            'phone' => null,
            'user_type' => 'store',
            'license_image' => UploadedFile::fake()->create('license.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.phone', null)
            ->assertJsonPath('user.user_type', 'store')
            ->assertJsonPath('user.apple_linked', true);
    }

    public function test_google_login_without_token_fails(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'google',
            'token' => null,
            'phone' => null,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'token_required');
    }

    public function test_apple_register_without_token_fails(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'login_provider_name' => 'apple',
            'token' => null,
            'phone' => null,
            'user_type' => 'personal',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'token_required');
    }

    public function test_phone_login_without_phone_fails_even_with_token_null(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login_provider_name' => 'phone',
            'phone' => null,
            'token' => null,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'phone_required');
    }
}
