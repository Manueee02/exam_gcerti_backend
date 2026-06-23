<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\PlannedExam;
use App\Models\PlannedExamCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlannedExamCandidateFactory extends Factory
{
    protected $model = PlannedExamCandidate::class;

    public function definition(): array
    {
        return [
            'id_candidate' => Candidate::factory(),
            'id_planned_exam' => PlannedExam::factory(),
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => fake()->uuid(),
        ];
    }
}
