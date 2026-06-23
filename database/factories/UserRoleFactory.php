<?php

namespace Database\Factories;

use App\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserRoleFactory extends Factory
{
    protected $model = UserRole::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['admin', 'superAdmin', 'examiner', 'decisionMaker', 'user']),
        ];
    }
}
