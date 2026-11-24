<?php

namespace Tests\Feature\Auth;

use App\Data\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_profile_updates_contact_fields_and_photo(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'phone' => '971500000001',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'name' => 'Nethmi Perera',
            'email' => 'nethmi.perera@gmail.com',
            'phone' => '+971 50 999 8877',
            'preferred_language' => 'ar',
            'push_notifications_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('user.name', 'Nethmi Perera')
            ->assertJsonPath('user.email', 'nethmi.perera@gmail.com')
            ->assertJsonPath('user.phone', '971509998877')
            ->assertJsonPath('user.preferred_language', 'ar')
            ->assertJsonPath('user.push_notifications_enabled', false);

        // Change photo via multipart POST (mobile-friendly)
        $this->post('/api/v1/auth/profile', [
            'profile_image' => UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['user' => ['profile_image_path', 'profile_image_url']]);

        $this->assertStringContainsString('profiles/', $user->fresh()->profile_image_path);
    }

    public function test_edit_profile_rejects_duplicate_email_and_phone(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'phone' => '971501111222']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['email' => 'taken@example.com'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_taken');

        $this->patchJson('/api/v1/auth/profile', ['phone' => '971501111222'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'phone_taken');
    }

    public function test_saved_addresses_crud(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'address' => '24 Flower Road, Colombo 07',
            'latitude' => 6.9147,
            'longitude' => 79.8560,
        ])->assertCreated();

        $id = $created->json('address.id');

        $this->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'addresses')
            ->assertJsonPath('addresses.0.label', 'home');

        $this->patchJson('/api/v1/addresses/'.$id, ['label' => 'work'])
            ->assertOk()
            ->assertJsonPath('address.label', 'work');

        $this->deleteJson('/api/v1/addresses/'.$id)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
