<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'id_question' => Question::factory(),
            'text' => fake()->sentence(),
            'is_correct' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
