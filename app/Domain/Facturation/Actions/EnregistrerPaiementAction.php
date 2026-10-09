<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un paiement peut régler plusieurs factures du même client en une seule
 * fois (ex : un virement qui solde trois factures d'un coup). Chaque
 * allocation est validée contre le solde réellement restant dû de la
 * facture concernée, avec verrouillage pessimiste pour empêcher qu'un
 * double encaissement concurrent ne fasse dépasser le total dû. Un
 * paiement n'a pas à être intégralement alloué dès sa création (voir
 * Paiement::montantNonAlloue) : le solde non affecté pourra être imputé
 * plus tard sur une facture future.
 *
 * Le client arrive déjà résolu par une requête limitée au magasin, jamais
 * en client_id brut : chaque facture allouée doit appartenir à CE client.
 * Un paiement s'enregistre le jour où il est reçu ou plus tard (un
 * virement vu hier sur le relevé), jamais à une date future.
 */
class EnregistrerPaiementAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * @param  array{mode_paiement: string, montant: float|string, reference?: ?string, notes?: ?string, recu_le: string}  $donneesPaiement
     * @param  array<int, array{facture_id: int|string, montant: float|string}>  $allocations
     */
    public function handle(Client $client, array $donneesPaiement, array $allocations): Paiement
    {
        if (Carbon::parse($donneesPaiement['recu_le'])->startOfDay()->gt(today())) {
            throw new LogicException('Un paiement ne peut pas être reçu à une date future.');
        }

        return DB::transaction(function () use ($client, $donneesPaiement, $allocations) {
            $paiement = Paiement::create([
                ...$donneesPaiement,
                'client_id' => $client->getKey(),
                'montant' => $this->calculateur->arrondirAuCentime((float) $donneesPaiement['montant']),
            ]);

            $totalAlloue = 0.0;

            foreach ($allocations as $allocation) {
                $montantAlloue = $this->calculateur->arrondirAuCentime((float) $allocation['montant']);

                if ($montantAlloue <= 0) {
                    throw new LogicException('Le montant alloué à une facture doit être strictement positif.');
                }

                $facture = Facture::query()->whereKey($allocation['facture_id'])->lockForUpdate()->firstOrFail();

                if ((int) $facture->client_id !== $client->getKey()) {
                    throw new LogicException("La facture {$facture->numero} n'appartient pas au client de ce paiement.");
                }

                if (! $facture->statut->attendUnReglement()) {
                    throw new LogicException("Impossible d'encaisser un paiement sur une facture au statut [{$facture->statut->value}].");
                }

                $montantRestantAvant = $facture->montantRestant();

                if ($montantAlloue > $montantRestantAvant) {
                    throw new LogicException("Le montant alloué ({$montantAlloue}) dépasse le solde restant dû ({$montantRestantAvant}) de la facture {$facture->numero}.");
                }

                $totalAlloue = $this->calculateur->sommer([$totalAlloue, $montantAlloue]);

                if ($totalAlloue > (float) $paiement->montant) {
                    throw new LogicException('La somme des allocations dépasse le montant du paiement.');
                }

                PaiementAllocation::create([
                    'paiement_id' => $paiement->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $montantAlloue,
                ]);

                $facture->constaterUnReglement();
            }

            return $paiement;
        });
    }
}
