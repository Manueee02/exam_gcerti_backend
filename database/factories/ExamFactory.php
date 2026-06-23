<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    protected $model = Exam::class;

    public function definition(): array
    {
        return [
            'type' => 'digcomp',
            'name' => fake()->jobTitle(),
            'description' => fake()->sentence(),
            'cost' => '100',
            'created_at' => now(),
            'updated_at' => now(),
            'active' => 'true',
            'color' => fake()->safeHexColor(),
            'public_id' => fake()->uuid(),
            'duration_minutes' => 60,
        ];
    }
}
