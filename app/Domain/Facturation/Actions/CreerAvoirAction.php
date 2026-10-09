<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
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
 *
 * Jamais de tickets de caisse et de factures dans un même avoir : leurs
 * circuits fiscaux diffèrent (clôture de caisse et e-reporting, ou
 * facture électronique), et un avoir n'en suit qu'un (voir
 * Facture::serie()).
 *
 * L'avoir créé ici reste un brouillon jusqu'à son émission : l'interface
 * passe toujours par EnregistrerAvoirAction, qui fait les deux d'un seul
 * geste.
 */
class CreerAvoirAction
{
    public function __construct(private readonly CalculateurLigne $calculateurLigne) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: int|string}>  $lignesSelectionnees
     */
    public function handle(Client $client, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        if ($lignesSelectionnees === []) {
            throw new InvalidArgumentException('Un avoir doit porter sur au moins une ligne.');
        }

        // Une même ligne deux fois dans un avoir échapperait au plafond de
        // la quantité restante (calculé sur les avoirs déjà émis) et au
        // solde exact du dernier retour.
        $identifiantsLignes = array_map(fn (array $selection): int => (int) $selection['ligne_facture_id'], $lignesSelectionnees);

        if (count($identifiantsLignes) !== count(array_unique($identifiantsLignes))) {
            throw new InvalidArgumentException('Une même ligne de facture ne peut apparaître qu\'une fois dans un avoir.');
        }

        return DB::transaction(function () use ($client, $lignesSelectionnees, $motif) {
            $avoir = Avoir::create([
                'client_id' => $client->getKey(),
                'motif' => $motif,
            ]);

            $lignesConstruites = [];
            $serie = null;

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

                if ($facture->statut === StatutFacture::Brouillon) {
                    throw new LogicException("Impossible de créditer une ligne d'une facture au statut [{$facture->statut->value}].");
                }

                $serie ??= $facture->serie();

                if ($facture->serie() !== $serie) {
                    throw new InvalidArgumentException('Un avoir ne mélange jamais des tickets de caisse et des factures : faites un avoir pour les tickets, et un autre pour les factures.');
                }

                $restante = $ligneFacture->quantiteCreditableRestante();
                $quantite = QuantiteEntiere::depuisSaisie($selection['quantite'] ?? null, 'La quantité à créditer');

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneFacture->description}\" ne peut pas être créditée au-delà de la quantité restante ({$restante}).");
                }

                $montants = $ligneFacture->montantsDuRetour($quantite);

                $avoir->lignes()->create([
                    'ligne_facture_id' => $ligneFacture->id,
                    'produit_id' => $ligneFacture->produit_id,
                    'nature' => $ligneFacture->nature,
                    'description' => $ligneFacture->description,
                    'reference' => $ligneFacture->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $ligneFacture->prix_unitaire_ht,
                    'prix_unitaire_ttc' => $ligneFacture->prix_unitaire_ttc,
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
