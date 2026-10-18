<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\LignesRetirees;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Un panier d'une ligne : un produit du magasin actif, à son prix de
 * vente TTC, arrêté.
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
            'prix_arretes' => fn (array $attributs): array => self::prixArretesDes($attributs['lignes']),
        ];
    }

    public function avecLeProduit(Produit $produit, int $quantite): static
    {
        return $this->state(['lignes' => self::lignesDu($produit, $quantite)]);
    }

    /**
     * Le prix de chaque ligne, arrêté pour tous ses articles.
     *
     * @param  array<string, array<string, mixed>>  $lignes
     * @return array<string, list<array{prix_unitaire_ttc: int|null, quantite: int}>>
     */
    private static function prixArretesDes(array $lignes): array
    {
        return array_map(
            fn (array $ligne): array => [['prix_unitaire_ttc' => app(LignesRetirees::class)->prixEnCentimes($ligne['prix_unitaire_ttc']), 'quantite' => $ligne['quantite']]],
            $lignes,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function lignesDu(Produit $produit, int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);

        return [(string) Str::uuid() => [
            'produit_id' => $produit->id,
            'service_id' => null,
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $calculateur->enDecimalUtile($calculateur->prixUnitaireHtExact($calculateur->enCentiemes($produit->prix_vente_ttc), $calculateur->enCentiemes($produit->taux_tva))),
            'prix_unitaire_ttc' => (string) $produit->prix_vente_ttc,
            'taux_tva' => (string) (float) $produit->taux_tva,
            'remise_pourcentage' => '0',
        ]];
    }
}
