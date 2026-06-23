<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'refresh_token' => null,
            'id_role' => UserRole::factory(),
            'first_access' => 'true',
            'password_reset_tokens' => null,
            'candidate_registration_completed' => 'false',
            'active_token' => null,
            'public_id' => fake()->uuid(),
        ];
    }
}
