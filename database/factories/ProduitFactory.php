<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produit>
 */
class ProduitFactory extends Factory
{
    protected $model = Produit::class;

    public function definition(): array
    {
        return [
            'reference' => strtoupper(fake()->unique()->bothify('REF-####??')),
            'nom' => fake()->words(3, true),
            'prix_achat_ht' => fake()->randomFloat(2, 1, 100),
            'prix_vente_ht' => fake()->randomFloat(2, 1, 200),
            'taux_tva' => 20.00,
            'suivi_stock' => true,
            'seuil_alerte' => 5,
            'actif' => true,
        ];
    }
}
