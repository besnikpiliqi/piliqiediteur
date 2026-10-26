<?php

namespace Database\Factories;

use App\Domain\Catalogue\Enums\OrigineProduit;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\OrigineDesProduits;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * Un produit ne naît que de la validation d'une facture d'achat ou d'un
 * inventaire d'ouverture (voir OrigineDesProduits) : la fabrique enregistre
 * les siens dans le contexte d'origine d'un achat, comme des produits déjà
 * reçus, que la fabrique soit appelée seule ou depuis celle d'un autre
 * modèle.
 *
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
            'cmup_ht' => fake()->randomFloat(2, 1, 100),
            'prix_vente_ttc' => fake()->randomFloat(2, 1, 240),
            'taux_tva' => 20.00,
            'suivi_stock' => true,
            'seuil_alerte' => 5,
        ];
    }

    /**
     * @param  Collection<int, Produit>  $results
     */
    protected function store(Collection $results): void
    {
        app(OrigineDesProduits::class)->pendant(OrigineProduit::Achat, fn () => parent::store($results));
    }
}
