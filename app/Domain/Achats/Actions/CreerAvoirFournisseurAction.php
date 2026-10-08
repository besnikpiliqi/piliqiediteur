<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Un avoir fournisseur (retour de marchandise) référence une facture
 * d'achat pour la traçabilité (quel produit, quelle facture d'achat
 * d'origine) SANS jamais la modifier : elle reste un document figé, au
 * même titre qu'une facture de vente émise.
 *
 * Il peut être partiel : porter sur une sélection de lignes, et sur une
 * quantité partielle de chacune (même logique que CreerAvoirAction côté
 * vente). Un produit déjà vendu à un client ne peut jamais être inclus :
 * voir LigneAchat::quantiteRetournableRestante(), qui plafonne la
 * quantité retournable au stock actuellement disponible du produit.
 */
class CreerAvoirFournisseurAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{ligne_achat_id: int|string, quantite: int|string}>  $lignesSelectionnees
     */
    public function handle(FactureAchat $factureAchat, array $lignesSelectionnees, ?string $motif = null): AvoirFournisseur
    {
        return DB::transaction(function () use ($factureAchat, $lignesSelectionnees, $motif) {
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();
            $factureAchat->load('lignes');

            if ($factureAchat->statut !== StatutFactureAchat::Validee) {
                throw new LogicException("Impossible de créer un avoir fournisseur sur une facture d'achat au statut [{$factureAchat->statut->value}].");
            }

            if ($lignesSelectionnees === []) {
                throw new InvalidArgumentException('Un avoir fournisseur doit porter sur au moins une ligne.');
            }

            // Même règle que CreerAvoirAction : une ligne une seule fois.
            $identifiantsLignes = array_map(fn (array $selection): int => (int) $selection['ligne_achat_id'], $lignesSelectionnees);

            if (count($identifiantsLignes) !== count(array_unique($identifiantsLignes))) {
                throw new InvalidArgumentException('Une même ligne de facture d\'achat ne peut apparaître qu\'une fois dans un avoir fournisseur.');
            }

            $avoir = AvoirFournisseur::create([
                'facture_achat_id' => $factureAchat->id,
                'motif' => $motif,
            ]);

            $lignesConstruites = [];

            foreach (array_values($lignesSelectionnees) as $index => $selection) {
                $ligneAchat = $factureAchat->lignes->firstWhere('id', (int) $selection['ligne_achat_id']);

                if ($ligneAchat === null) {
                    throw new InvalidArgumentException("Cette ligne n'appartient pas à la facture d'achat créditée.");
                }

                $restante = $ligneAchat->quantiteRetournableRestante();
                $quantite = QuantiteEntiere::depuisSaisie($selection['quantite'] ?? null, 'La quantité à retourner');

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneAchat->description}\" ne peut pas être retournée au-delà de la quantité disponible ({$restante}) : elle a déjà été vendue ou déjà retournée.");
                }

                // Un retour partiel se calcule comme la ligne achetée (voir
                // CalculateurLigne) ; le dernier retour en prend le solde
                // exact.
                $resteSurLaLigne = $ligneAchat->quantite - $ligneAchat->quantiteDejaRetournee();

                $montants = $quantite === $resteSurLaLigne
                    ? $this->calculateurLigne->solder(
                        $ligneAchat->only(['montant_ht', 'montant_tva', 'montant_ttc']),
                        $ligneAchat->lignesAvoirFournisseur()
                            ->whereHas('avoirFournisseur', fn ($query) => $query->where('statut', StatutAvoirFournisseur::Emise))
                            ->get(['montant_ht', 'montant_tva', 'montant_ttc'])
                            ->toArray(),
                    )
                    : $this->calculateurLigne->calculer(
                        $ligneAchat->prix_reference,
                        (float) $ligneAchat->prix_unitaire_ht,
                        $ligneAchat->prix_unitaire_ttc === null ? null : (float) $ligneAchat->prix_unitaire_ttc,
                        (float) $ligneAchat->taux_tva,
                        $quantite,
                        (float) $ligneAchat->remise_pourcentage,
                    );

                $avoir->lignes()->create([
                    'ligne_achat_id' => $ligneAchat->id,
                    'produit_id' => $ligneAchat->produit_id,
                    'description' => $ligneAchat->description,
                    'reference' => $ligneAchat->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $ligneAchat->prix_unitaire_ht,
                    'prix_unitaire_ttc' => $ligneAchat->prix_unitaire_ttc,
                    'prix_reference' => $ligneAchat->prix_reference,
                    'taux_tva' => $ligneAchat->taux_tva,
                    'remise_pourcentage' => $ligneAchat->remise_pourcentage,
                    'ordre' => $index,
                    ...$montants,
                ]);

                $lignesConstruites[] = $montants;
            }

            $avoir->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $avoir->fresh('lignes');
        });
    }
}
