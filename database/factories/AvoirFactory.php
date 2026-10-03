<?php

namespace Database\Factories;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Avoir>
 */
class AvoirFactory extends Factory
{
    protected $model = Avoir::class;

    public function definition(): array
    {
        return [
            'facture_id' => Facture::factory(),
        ];
    }
}
