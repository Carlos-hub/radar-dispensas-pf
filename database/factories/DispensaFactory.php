<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Dispensa>
 */
class DispensaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pncp_id' => fake()->unique()->numerify('##############-1-######/2026'),
            'objeto' => 'Serviço de ' . fake()->unique()->words(3, true),
            'complemento' => '',
            'orgao' => 'Prefeitura De Teste',
            'unidade' => 'Secretaria de Administração',
            'municipio' => 'Chapecó',
            'uf' => 'SC',
            'esfera' => 'Municipal',
            'valor' => 10000,
            'abre_em' => now()->subDay(),
            'encerra_em' => now()->addDays(5),
            'amparo' => 'Lei 14.133/2021, Art. 75, II',
            'disputa' => 'Dispensa Com Disputa',
            'link_pncp' => 'https://pncp.gov.br/app/editais/12345678000199/2026/1',
            'link_origem' => 'https://compras.example.gov.br/1',
            'categorias' => ['ti'],
            'fit' => 'alta',
            'motivo' => 'Serviço intelectual sem exigência de empresa no objeto.',
            'sincronizado_em' => now(),
        ];
    }

    public function encerrada(): static
    {
        return $this->state(['encerra_em' => now()->subDay()]);
    }
}
