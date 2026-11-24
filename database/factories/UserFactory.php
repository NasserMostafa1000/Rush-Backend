<?php

namespace Database\Factories;

use App\Data\Models\OsProvider;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'invite_code' => strtoupper(Str::random(8)),
            'loyalty_points' => 0,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => null,
            'user_role_id' => UserRole::PERSONAL,
            'preferred_language' => 'en',
            'os_provider_id' => OsProvider::PHONE,
            'remember_token' => Str::random(10),
        ];
    }
}
