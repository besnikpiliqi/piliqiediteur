<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Services\ImputationDuCredit;
use App\Domain\Tva\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;

/**
 * À son émission (voir EmettreAvoirAction, sous le verrou du registre
 * fiscal et avant sa signature), un avoir impute son crédit sur ce qui
 * reste dû des factures de son client, dans l'ordre que tient aussi la
 * contre-passation d'un paiement (voir ImputationDuCredit) : d'abord sur
 * les factures qu'il corrige, puis sur les autres factures impayées du
 * client, de la plus ancienne à la plus récente, jamais sur une autre
 * vente du client comptoir. Le reliquat reste un crédit, que la prochaine
 * facture du client absorbe à son émission (voir
 * AppliquerAvoirsDisponiblesAction), ou que la contre-passation d'un
 * paiement impute sur ce qu'elle rouvre (voir ContrePasserPaiementAction) ;
 * celui d'un avoir fait au client comptoir est son bon d'avoir. Un avoir
 * n'est jamais remboursé (B5).
 *
 * Chaque facture reçoit au plus une imputation de l'avoir, puis son statut
 * suit ce qui en reste dû : une facture en retard le reste tant qu'il en
 * reste un montant dû (G1, voir Facture::constaterUnReglement()). Ces
 * imputations naissent dans la transaction de l'émission, et l'avoir les
 * signe (voir ContenuFiscal).
 */
class ImputerAvoirSurSesFacturesAction
{
    public function __construct(
        private readonly ImputationDuCredit $imputation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    public function handle(Avoir $avoir): void
    {
        DB::transaction(function () use ($avoir) {
            foreach ($this->imputation->aLEmissionDe($avoir) as ['facture' => $facture, 'montant' => $montant]) {
                AvoirAllocation::create([
                    'avoir_id' => $avoir->getKey(),
                    'facture_id' => $facture->getKey(),
                    'montant_alloue' => $this->calculateur->enDecimal($montant),
                ]);

                $facture->constaterUnReglement();
            }
        });
    }
}
