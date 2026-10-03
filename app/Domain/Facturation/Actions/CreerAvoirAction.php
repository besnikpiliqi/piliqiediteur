<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Un avoir peut être partiel : porter sur une sélection de lignes de la
 * facture d'origine, et sur une quantité partielle de chacune -- la loi
 * n'impose pas qu'un avoir soit un crédit total (voir BOI-TVA-DECLA-30-20-20
 * sur la facture rectificative), seulement qu'il référence la facture
 * créditée. Plusieurs avoirs successifs sur une même facture sont donc
 * possibles (ex : un client retourne un article aujourd'hui, un autre plus
 * tard), tant que la quantité cumulée créditée par ligne ne dépasse jamais
 * la quantité facturée (voir LigneFacture::quantiteCreditableRestante()).
 *
 * Chaque ligne d'avoir référence sa ligne de facture d'origine
 * (ligne_facture_id) sans jamais modifier cette dernière : la facture
 * d'origine reste un document figé, l'avoir ne fait qu'y référencer un
 * retour/une correction.
 */
class CreerAvoirAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: float|string}>  $lignesSelectionnees
     */
    public function handle(Facture $facture, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        return DB::transaction(function () use ($facture, $lignesSelectionnees, $motif) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            $facture->load('lignes');

            if (in_array($facture->statut, [StatutFacture::Brouillon, StatutFacture::Annulee], true)) {
                throw new LogicException("Impossible de créer un avoir sur une facture au statut [{$facture->statut->value}].");
            }

            if ($lignesSelectionnees === []) {
                throw new InvalidArgumentException('Un avoir doit porter sur au moins une ligne.');
            }

            $avoir = Avoir::create([
                'facture_id' => $facture->id,
                'motif' => $motif,
            ]);

            $lignesConstruites = [];

            foreach (array_values($lignesSelectionnees) as $index => $selection) {
                $ligneFacture = $facture->lignes->firstWhere('id', (int) $selection['ligne_facture_id']);

                if ($ligneFacture === null) {
                    throw new InvalidArgumentException("Cette ligne n'appartient pas à la facture créditée.");
                }

                $quantite = (float) $selection['quantite'];

                if ($quantite <= 0) {
                    throw new InvalidArgumentException('La quantité à créditer doit être supérieure à zéro.');
                }

                $restante = $ligneFacture->quantiteCreditableRestante();

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneFacture->description}\" ne peut pas être créditée au-delà de la quantité restante ({$restante}).");
                }

                $montants = $this->calculateurLigne->calculer(
                    (float) $ligneFacture->prix_unitaire_ht,
                    $quantite,
                    (float) $ligneFacture->taux_tva,
                    (float) $ligneFacture->remise_pourcentage,
                );

                $avoir->lignes()->create([
                    'ligne_facture_id' => $ligneFacture->id,
                    'produit_id' => $ligneFacture->produit_id,
                    'description' => $ligneFacture->description,
                    'reference' => $ligneFacture->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $ligneFacture->prix_unitaire_ht,
                    'taux_tva' => $ligneFacture->taux_tva,
                    'remise_pourcentage' => $ligneFacture->remise_pourcentage,
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
