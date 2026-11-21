<?php

namespace Database\Factories;

use App\Domain\Stock\Models\InventaireOuverture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un inventaire d'ouverture brouillon, sans ligne : ses lignes, ses
 * produits et sa validation passent par ses actions (voir
 * CreerInventaireOuvertureAction et ValiderInventaireOuvertureAction).
 *
 * @extends Factory<InventaireOuverture>
 */
class InventaireOuvertureFactory extends Factory
{
    protected $model = InventaireOuverture::class;

    public function definition(): array
    {
        return [
            'notes' => null,
        ];
    }
}
