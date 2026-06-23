<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\PlannedExam;
use App\Models\TestCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlannedExamFactory extends Factory
{
    protected $model = PlannedExam::class;

    public function definition(): array
    {
        return [
            'id_exam' => Exam::factory(),
            'id_test_center' => TestCenter::factory(),
            // NOTA: id_examiner e id_decision_maker non hanno FK reale nel DB
            // (vedi migration planned_exams), quindi un intero placeholder e' sufficiente.
            'id_examiner' => fake()->numberBetween(1, 1000),
            'id_decision_maker' => fake()->numberBetween(1, 1000),
            'date' => now()->format('Y-m-d'),
            'time' => '10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
            'location' => 'Piattaforma Gcerti',
            'end_time' => '11:00:00',
            'public_id' => fake()->uuid(),
        ];
    }
}
