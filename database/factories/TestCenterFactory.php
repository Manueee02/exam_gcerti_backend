<?php

namespace Database\Factories;

use App\Models\TestCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

class TestCenterFactory extends Factory
{
    protected $model = TestCenter::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'contact_info' => fake()->email(),
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => fake()->uuid(),
        ];
    }
}
