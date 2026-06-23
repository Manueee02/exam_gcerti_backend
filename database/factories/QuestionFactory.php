<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamArea;
use App\Models\ExamLevel;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'exam_id' => Exam::factory(),
            'text' => fake()->sentence() . '?',
            'type' => 'multiple_choice',
            'points' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'exam_area_id' => ExamArea::factory(),
            'exam_level_id' => ExamLevel::factory(),
        ];
    }
}
