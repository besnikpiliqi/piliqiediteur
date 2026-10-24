<?php

namespace Database\Factories;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Contacts\Models\Fournisseur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FactureAchat>
 */
class FactureAchatFactory extends Factory
{
    protected $model = FactureAchat::class;

    public function definition(): array
    {
        return [
            'fournisseur_id' => Fournisseur::factory(),
        ];
    }
}
