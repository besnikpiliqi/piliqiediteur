<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Un avoir est rattaché à un CLIENT, pas à une seule facture : il peut
 * regrouper des lignes provenant de plusieurs factures différentes de ce
 * même client en une seule fois (ex : un client retourne des articles
 * achetés lors de visites différentes). La loi n'impose pas qu'une facture
 * rectificative porte sur une seule facture d'origine, seulement qu'elle
 * référence précisément ce qu'elle corrige -- chaque ligne garde sa trace
 * vers sa ligne de facture d'origine (voir LigneAvoir::ligne_facture_id).
 *
 * Il peut aussi être partiel : porter sur une sélection de lignes, et sur
 * une quantité partielle de chacune. Plusieurs avoirs successifs restent
 * possibles sur une même ligne de facture (ex : un second défaut découvert
 * après un premier avoir), tant que leur somme ne dépasse jamais la
 * quantité facturée (voir LigneFacture::quantiteCreditableRestante()).
 */
class CreerAvoirAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: float|string}>  $lignesSelectionnees
     */
    public function handle(Client $client, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        if ($lignesSelectionnees === []) {
            throw new InvalidArgumentException('Un avoir doit porter sur au moins une ligne.');
        }

        return DB::transaction(function () use ($client, $lignesSelectionnees, $motif) {
            $avoir = Avoir::create([
                'client_id' => $client->getKey(),
                'motif' => $motif,
            ]);

            $lignesConstruites = [];

            foreach (array_values($lignesSelectionnees) as $index => $selection) {
                $ligneFacture = LigneFacture::query()
                    ->with('facture')
                    ->whereKey((int) $selection['ligne_facture_id'])
                    ->lockForUpdate()
                    ->first();

                if ($ligneFacture === null || $ligneFacture->facture === null) {
                    throw new InvalidArgumentException('Cette ligne de facture est introuvable.');
                }

                $facture = $ligneFacture->facture;

                if ((int) $facture->client_id !== $client->getKey()) {
                    throw new InvalidArgumentException("Cette ligne appartient à une facture d'un autre client.");
                }

                if (in_array($facture->statut, [StatutFacture::Brouillon, StatutFacture::Annulee], true)) {
                    throw new LogicException("Impossible de créditer une ligne d'une facture au statut [{$facture->statut->value}].");
                }

                $quantite = (float) $selection['quantite'];

                if ($quantite <= 0) {
                    throw new InvalidArgumentException('La quantité à créditer doit être supérieure à zéro.');
                }

                $restante = $ligneFacture->quantiteCreditableRestante();

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneFacture->description}\" ne peut pas être créditée au-delà de la quantité restante ({$restante}).");
                }

                // Le dernier retour d'une ligne en prend le solde exact ; un
                // retour partiel est calculé depuis le même prix de
                // référence que la ligne facturée (voir PrixReference).
                $montants = abs($quantite - $restante) < 0.0005
                    ? $this->calculateurLigne->solder(
                        $ligneFacture->only(['montant_ht', 'montant_tva', 'montant_ttc']),
                        $ligneFacture->lignesAvoir()
                            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
                            ->get(['montant_ht', 'montant_tva', 'montant_ttc'])
                            ->toArray(),
                    )
                    : $this->calculateurLigne->calculer(
                        $ligneFacture->prix_reference,
                        (float) $ligneFacture->prix_unitaire_ht,
                        (float) $ligneFacture->prix_unitaire_ttc,
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
                    'prix_unitaire_ttc' => $ligneFacture->prix_unitaire_ttc,
                    'prix_reference' => $ligneFacture->prix_reference,
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
