<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaiementAllocation>
 */
class PaiementAllocationFactory extends Factory
{
    protected $model = PaiementAllocation::class;

    public function definition(): array
    {
        return [
            'paiement_id' => Paiement::factory(),
            'facture_id' => Facture::factory(),
            'montant_alloue' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
