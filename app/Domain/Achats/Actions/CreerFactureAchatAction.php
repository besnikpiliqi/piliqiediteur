<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contrairement aux documents de vente, chaque ligne référence
 * obligatoirement un produit du catalogue (pas de ligne libre) : la
 * traçabilité pour l'avoir fournisseur (voir LigneAchat) n'a de sens que
 * pour un produit suivi.
 */
class CreerFactureAchatAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{produit_id: int|string, quantite: float|string, prix_unitaire_ht?: float|string, taux_tva?: float|string, remise_pourcentage?: float|string, description?: string, reference?: string}>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Fournisseur $fournisseur, array $lignes, array $attributs = []): FactureAchat
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        return DB::transaction(function () use ($fournisseur, $lignes, $attributs) {
            $factureAchat = FactureAchat::create([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]);

            $lignesConstruites = [];

            foreach (array_values($lignes) as $index => $donnees) {
                if (! isset($donnees['produit_id'])) {
                    throw new InvalidArgumentException('Chaque ligne d\'une facture d\'achat doit référencer un produit du catalogue.');
                }

                $produit = Produit::query()->findOrFail($donnees['produit_id']);
                $quantite = (float) ($donnees['quantite'] ?? throw new InvalidArgumentException('Chaque ligne doit fournir une quantite.'));
                $prixUnitaireHt = $this->calculateurLigne->arrondirAuCentime((float) ($donnees['prix_unitaire_ht'] ?? $produit->prix_achat_ht));
                $tauxTva = (float) ($donnees['taux_tva'] ?? $produit->taux_tva);
                $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

                $montants = $this->calculateurLigne->calculerDepuisHt($prixUnitaireHt, $quantite, $tauxTva, $remise);

                $ligne = [
                    'produit_id' => $produit->id,
                    'description' => $donnees['description'] ?? $produit->nom,
                    'reference' => $donnees['reference'] ?? $produit->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $prixUnitaireHt,
                    'taux_tva' => $tauxTva,
                    'remise_pourcentage' => $remise,
                    'ordre' => $index,
                    ...$montants,
                ];

                $factureAchat->lignes()->create($ligne);
                $lignesConstruites[] = $montants;
            }

            $factureAchat->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $factureAchat->fresh('lignes');
        });
    }
}
