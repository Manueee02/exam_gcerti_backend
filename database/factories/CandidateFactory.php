<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return [
            'id_user' => User::factory(),
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'fiscal_code' => strtoupper(fake()->bothify('??????##?##?###?')),
            'sex' => fake()->randomElement(['M', 'F']),
            'birthdate' => fake()->date(),
            'birthplace' => fake()->city(),
            'birthprovince' => null,
            'birthcommun' => null,
            'is_foreign' => 'false',
            'birthcountry' => 'Italia',
            'created_at' => now(),
            'updated_at' => now(),
            'active' => 'true',
            'residence_address' => fake()->streetAddress(),
            'residence_city' => fake()->city(),
            'residence_province' => fake()->stateAbbr(),
            'residence_zip' => fake()->postcode(),
            'residence_country' => 'Italia',
            'public_id' => fake()->uuid(),
        ];
    }
}
