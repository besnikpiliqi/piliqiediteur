<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\PrixReference;
use InvalidArgumentException;

/**
 * Détermine, pour une ligne à enregistrer (vente comme achat), quel prix
 * unitaire fait foi : le prix saisi s'il y en a un, sinon le prix
 * catalogue du produit -- et, pour un prix TTC, son PU HT au centime (voir
 * CalculateurLigne::prixUnitaires()).
 */
class ResolveurPrixLigne
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{prix_unitaire_ht: float, prix_unitaire_ttc: float|null, prix_reference: PrixReference}
     */
    public function resoudre(
        array $donnees,
        PrixReference $referenceParDefaut,
        float $tauxTva,
        ?float $prixHtCatalogue = null,
        ?float $prixTtcCatalogue = null,
    ): array {
        $reference = PrixReference::depuisSaisie($donnees, $referenceParDefaut);

        $htSaisi = filled($donnees['prix_unitaire_ht'] ?? null) ? (float) $donnees['prix_unitaire_ht'] : null;
        $ttcSaisi = filled($donnees['prix_unitaire_ttc'] ?? null) ? (float) $donnees['prix_unitaire_ttc'] : null;

        $prixDeReference = match ($reference) {
            PrixReference::Ttc => $ttcSaisi
                ?? $this->ttc($htSaisi, $tauxTva)
                ?? $prixTtcCatalogue
                ?? $this->ttc($prixHtCatalogue, $tauxTva),
            PrixReference::Ht => $htSaisi
                ?? $this->ht($ttcSaisi, $tauxTva)
                ?? $prixHtCatalogue
                ?? $this->ht($prixTtcCatalogue, $tauxTva),
        };

        if ($prixDeReference === null) {
            throw new InvalidArgumentException('Une ligne sans produit_id doit fournir prix_unitaire_ht ou prix_unitaire_ttc.');
        }

        return [
            ...$this->calculateur->prixUnitaires($reference, $prixDeReference, $tauxTva),
            'prix_reference' => $reference,
        ];
    }

    private function ttc(?float $prixHt, float $tauxTva): ?float
    {
        return $prixHt === null ? null : $this->calculateur->prixUnitaireTtcDepuisHt($prixHt, $tauxTva);
    }

    private function ht(?float $prixTtc, float $tauxTva): ?float
    {
        return $prixTtc === null ? null : $this->calculateur->prixUnitaireHtDepuisTtc($prixTtc, $tauxTva);
    }
}
