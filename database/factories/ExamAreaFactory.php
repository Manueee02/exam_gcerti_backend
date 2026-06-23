<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamArea;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamAreaFactory extends Factory
{
    protected $model = ExamArea::class;

    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'name' => fake()->word(),
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => fake()->uuid(),
            'label' => fake()->word(),
            'order' => fake()->numberBetween(1, 10),
        ];
    }
}
