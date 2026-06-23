<?php

namespace Database\Factories;

use App\Models\ExamArea;
use App\Models\ExamExtractionRule;
use App\Models\ExamLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamExtractionRuleFactory extends Factory
{
    protected $model = ExamExtractionRule::class;

    public function definition(): array
    {
        return [
            'exam_area_id' => ExamArea::factory(),
            'exam_level_id' => ExamLevel::factory(),
            'n_questions' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => fake()->uuid(),
            'duration_minutes' => 30,
            'passing_score' => 60,
        ];
    }
}
