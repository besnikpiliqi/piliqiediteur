<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Paiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paiement>
 */
class PaiementFactory extends Factory
{
    protected $model = Paiement::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'mode_paiement' => ModePaiement::Virement,
            'montant' => fake()->randomFloat(2, 50, 1000),
            'reference' => fake()->bothify('REF-####'),
            'recu_le' => now()->toDateString(),
        ];
    }
}
