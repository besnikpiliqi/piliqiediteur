<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Un panier d'une ligne : un produit du magasin actif, à son prix de
 * vente TTC.
 *
 * @extends Factory<PanierCaisse>
 */
class PanierCaisseFactory extends Factory
{
    protected $model = PanierCaisse::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'jeton' => fn (): string => (string) Str::uuid(),
            'lignes' => fn (): array => self::lignesDu(Produit::factory()->create(), 1),
        ];
    }

    public function avecLeProduit(Produit $produit, int $quantite): static
    {
        return $this->state(['lignes' => self::lignesDu($produit, $quantite)]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function lignesDu(Produit $produit, int $quantite): array
    {
        return [(string) Str::uuid() => [
            'produit_id' => $produit->id,
            'service_id' => null,
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => (string) $produit->prix_vente_ht,
            'prix_unitaire_ttc' => (string) $produit->prix_vente_ttc,
            'taux_tva' => (string) (float) $produit->taux_tva,
            'remise_pourcentage' => '0',
        ]];
    }
}
