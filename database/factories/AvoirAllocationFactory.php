<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvoirAllocation>
 */
class AvoirAllocationFactory extends Factory
{
    protected $model = AvoirAllocation::class;

    public function definition(): array
    {
        return [
            'avoir_id' => Avoir::factory(),
            'facture_id' => Facture::factory(),
            'montant_alloue' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
