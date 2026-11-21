<?php

namespace Database\Factories;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvoirFournisseur>
 */
class AvoirFournisseurFactory extends Factory
{
    protected $model = AvoirFournisseur::class;

    public function definition(): array
    {
        return [
            'facture_achat_id' => FactureAchat::factory(),
        ];
    }
}
