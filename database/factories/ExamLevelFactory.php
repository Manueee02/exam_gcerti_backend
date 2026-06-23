<?php

namespace Database\Factories;

use App\Models\ExamArea;
use App\Models\ExamLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamLevelFactory extends Factory
{
    protected $model = ExamLevel::class;

    public function definition(): array
    {
        return [
            'exam_area_id' => ExamArea::factory(),
            'name' => fake()->randomElement(['base', 'intermedio', 'avanzato']),
            'created_at' => now(),
            'updated_at' => now(),
            'label' => fake()->word(),
            'public_id' => fake()->uuid(),
            'order' => fake()->numberBetween(1, 5),
        ];
    }
}
