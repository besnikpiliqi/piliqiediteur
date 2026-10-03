<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Même raisonnement que ModifierFactureAction côté vente : un brouillon
 * n'a aucune existence légale ni impact sur le stock (voir
 * ValiderFactureAchatAction), il reste donc librement modifiable, lignes
 * comprises, pour éviter de tout ressaisir en cas d'erreur de saisie.
 */
class ModifierFactureAchatAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{produit_id: int|string, quantite: float|string, prix_unitaire_ht?: float|string, taux_tva?: float|string, remise_pourcentage?: float|string, description?: string, reference?: string}>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(FactureAchat $factureAchat, array $lignes, array $attributs = []): FactureAchat
    {
        if ($factureAchat->statut !== StatutFactureAchat::Brouillon) {
            throw new LogicException('Seule une facture d\'achat brouillon peut être modifiée.');
        }

        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        return DB::transaction(function () use ($factureAchat, $lignes, $attributs) {
            $factureAchat->update($attributs);
            $factureAchat->lignes()->delete();

            $lignesConstruites = [];

            foreach (array_values($lignes) as $index => $donnees) {
                if (! isset($donnees['produit_id'])) {
                    throw new InvalidArgumentException('Chaque ligne d\'une facture d\'achat doit référencer un produit du catalogue.');
                }

                $produit = Produit::query()->findOrFail($donnees['produit_id']);
                $quantite = (float) ($donnees['quantite'] ?? throw new InvalidArgumentException('Chaque ligne doit fournir une quantite.'));
                $prixUnitaireHt = (float) ($donnees['prix_unitaire_ht'] ?? $produit->prix_achat_ht);
                $tauxTva = (float) ($donnees['taux_tva'] ?? $produit->taux_tva);
                $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

                $montants = $this->calculateurLigne->calculer($prixUnitaireHt, $quantite, $tauxTva, $remise);

                $factureAchat->lignes()->create([
                    'produit_id' => $produit->id,
                    'description' => $donnees['description'] ?? $produit->nom,
                    'reference' => $donnees['reference'] ?? $produit->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $prixUnitaireHt,
                    'taux_tva' => $tauxTva,
                    'remise_pourcentage' => $remise,
                    'ordre' => $index,
                    ...$montants,
                ]);

                $lignesConstruites[] = $montants;
            }

            $factureAchat->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $factureAchat->fresh('lignes');
        });
    }
}
