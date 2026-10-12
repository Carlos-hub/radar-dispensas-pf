<?php

namespace Database\Factories;

use App\Models\Dispensa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Candidatura>
 */
class CandidaturaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'dispensa_id' => Dispensa::factory(),
            'status' => 'salva',
            'notas' => '',
        ];
    }
}
