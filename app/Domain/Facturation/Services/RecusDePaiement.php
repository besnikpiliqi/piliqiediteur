<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureRecuPaiement;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Les reçus de paiement (R2, décision de l'éditeur du 02/11/2026) : chaque
 * encaissement fait après l'émission d'une facture ou d'un ticket (un geste
 * « Encaisser », toutes ses parts comprises, voir
 * EncaisserEnPlusieursModesAction ; un paiement saisi dans « Paiements »,
 * voir EnregistrerPaiementAction), et chaque contre-passation (voir
 * ContrePasserPaiementAction), donne un reçu. Un paiement reçu à
 * l'émission n'en a pas : le document l'imprime lui-même.
 *
 * Le reçu naît dans la transaction de son encaissement, sous le verrou du
 * registre fiscal que l'encaissement a pris, en deux temps, la table étant
 * en ajout seul :
 *  1. ouvrir(), avant le premier paiement, qui le désigne : sa référence
 *     stable (RP-LYON1-2026-000001, la série RP du magasin, de l'année de
 *     son enregistrement), le jour de l'encaissement, son client, son
 *     auteur et le magasin et l'identité de la société tels qu'ils sont ce
 *     jour-là (voir Emetteur::duMagasin()) ;
 *  2. clore(), une fois tout imputé : chaque document réglé, dans l'ordre
 *     où ses paiements l'ont réglé, avec ce qu'ils lui imputent, le crédit
 *     d'avoir qu'ils y imputent ou y rendent (bons d'avoir présentés,
 *     crédit réimputé après une contre-passation) et ce qui en restait dû
 *     ensuite. Ce qui s'imprime ne se recalcule jamais.
 */
class RecusDePaiement
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Le reçu de l'encaissement qui commence, avant son premier paiement.
     *
     * @param  string  $recuLe  AAAA-MM-JJ, le jour de l'encaissement dans le calendrier du magasin
     *
     * @throws LogicException quand le magasin n'a pas encore de territoire
     */
    public function ouvrir(User $compte, Magasin $magasin, NatureRecuPaiement $nature, Client $client, string $recuLe): RecuPaiement
    {
        $recu = (new RecuPaiement)->forceFill([
            'client_id' => $client->getKey(),
            'numero' => $this->numerotation->suivant($magasin, TypeDocument::RecuPaiement, $magasin->calendrier()->anneeDe(CarbonImmutable::now())),
            'nature' => $nature,
            'recu_le' => $recuLe,
            'emetteur' => Emetteur::duMagasin($magasin)->enTableau(),
            'cree_par' => $compte->getKey(),
        ]);
        $recu->save();

        return $recu;
    }

    /**
     * Les documents que le reçu règle, une fois tout imputé : ce que ses
     * paiements imputent sur chacun, le crédit d'avoir qu'ils y imputent ou
     * y rendent, et ce qui en reste dû à cet instant, dans l'ordre où ils
     * ont été réglés (les imputations des paiements, puis celles du
     * crédit).
     *
     * @throws LogicException quand ses paiements ne règlent aucun document
     */
    public function clore(RecuPaiement $recu): void
    {
        $paiements = $recu->paiements()->pluck('id')->all();
        $imputations = PaiementAllocation::query()->whereIn('paiement_id', $paiements)->orderBy('id')->get(['facture_id', 'montant_alloue']);
        $credits = AvoirAllocation::query()->whereIn('paiement_id', $paiements)->orderBy('id')->get(['facture_id', 'montant_alloue']);
        $documents = [];

        foreach ([['montant', $imputations], ['credit', $credits]] as [$colonne, $allocations]) {
            foreach ($allocations as $allocation) {
                $documents[$allocation->facture_id] ??= ['montant' => 0, 'credit' => 0];
                $documents[$allocation->facture_id][$colonne] += $this->calculateur->enCentiemes($allocation->montant_alloue);
            }
        }

        if ($documents === []) {
            throw new LogicException("Le reçu de paiement {$recu->numero} ne règle aucun document.");
        }

        $factures = Facture::query()->with(['allocationsPaiement', 'allocationsAvoir'])->whereKey(array_keys($documents))->get()->keyBy('id');
        $ordre = 0;

        foreach ($documents as $factureId => $montants) {
            LigneRecuPaiement::query()->create([
                'recu_paiement_id' => $recu->getKey(),
                'facture_id' => $factureId,
                'montant' => $this->calculateur->enDecimal($montants['montant']),
                'credit' => $this->calculateur->enDecimal($montants['credit']),
                'reste_du' => $this->calculateur->enDecimal(ValeurRequise::de($factures->get($factureId), 'la facture réglée par le reçu')->resteDuEnCentimes()),
                'ordre' => $ordre++,
            ]);
        }
    }
}
