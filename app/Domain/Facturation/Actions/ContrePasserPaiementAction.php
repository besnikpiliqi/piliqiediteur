<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un paiement saisi par erreur ne se modifie ni ne se supprime (voir
 * ProtegeLesDonneesValidees) : il se contre-passe (B4). La contre-passation
 * est un paiement opposé, l'inverse exact de l'original (K9) : même client,
 * même mode, même référence, montant et imputations de signe contraire,
 * le crédit d'avoir que l'original avait imputé compris, le motif pour
 * notes. Ce crédit revient donc à ses avoirs, disponible de nouveau. Elle
 * désigne l'original, qui reste tel quel, et les factures qu'il réglait
 * redeviennent dues de tout ce qu'il y avait réglé (voir
 * Facture::constaterUnReglement()). On saisit ensuite le bon paiement, avec
 * lequel le crédit disponible du client solde le reste de ses factures
 * (voir EnregistrerPaiementAction).
 *
 * Elle est datée du jour où elle est faite, à l'heure de Paris, jamais
 * d'une journée déjà clôturée : une fois la journée clôturée, elle attend
 * le lendemain. Un paiement ne se contre-passe qu'une fois, et une
 * contre-passation ne se contre-passe pas.
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture de
 * la transaction ; l'original, relu sous ce verrou sans verrou de ligne (la
 * table des paiements est en ajout seul : le compte de l'application n'y a
 * pas le droit de modifier, qu'exige un SELECT ... FOR UPDATE sous
 * PostgreSQL), et chaque facture qu'il réglait, relue verrouillée, voient
 * ce que les transactions précédentes ont validé. La contre-passation est
 * signée dans la chaîne des encaissements (type « contre_passation », voir
 * ContenuFiscal), imputations et crédits inversés compris, dans la même
 * transaction.
 */
class ContrePasserPaiementAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
    ) {}

    /**
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Paiement $paiementErrone, string $motif): Paiement
    {
        $motif = trim($motif);

        if ($motif === '') {
            throw new LogicException('Indiquez le motif de la contre-passation.');
        }

        return DB::transaction(function () use ($paiementErrone, $motif) {
            $magasin = app(TenantContext::class)->magasin();
            $journee = $this->registre->verrouiller($magasin)->enHeureLocale();
            $original = Paiement::query()->whereKey($paiementErrone->getKey())->firstOrFail();

            if ($original->estUneContrePassation()) {
                throw new LogicException('Une contre-passation ne se contre-passe pas.');
            }

            if ($original->contrePassation()->exists()) {
                throw new LogicException('Ce paiement a déjà été contre-passé.');
            }

            $this->refuserSiLaJourneeEstCloturee($journee, $this->registre->derniereJourneeCloturee($magasin));

            $contrePassation = new Paiement([
                'client_id' => $original->client_id,
                'mode_paiement' => $original->mode_paiement,
                'montant' => '-'.$original->montant,
                'reference' => $original->reference,
                'notes' => $motif,
                'recu_le' => $journee->toDateString(),
            ]);
            $contrePassation->forceFill(['contre_passe_paiement_id' => $original->getKey()])->save();
            $factures = [];

            foreach ($original->allocations()->orderBy('id')->get() as $allocation) {
                $factures[$allocation->facture_id] ??= Facture::query()->whereKey($allocation->facture_id)->lockForUpdate()->firstOrFail();

                PaiementAllocation::create([
                    'paiement_id' => $contrePassation->id,
                    'facture_id' => $allocation->facture_id,
                    'montant_alloue' => '-'.$allocation->montant_alloue,
                ]);
            }

            foreach ($original->creditsImputes()->orderBy('id')->get() as $credit) {
                $factures[$credit->facture_id] ??= Facture::query()->whereKey($credit->facture_id)->lockForUpdate()->firstOrFail();

                AvoirAllocation::create([
                    'avoir_id' => $credit->avoir_id,
                    'facture_id' => $credit->facture_id,
                    'paiement_id' => $contrePassation->id,
                    'montant_alloue' => '-'.$credit->montant_alloue,
                ]);
            }

            foreach ($factures as $facture) {
                $facture->constaterUnReglement();
            }

            $contrePassation = $contrePassation->fresh();
            $this->registre->enregistrer(ChaineFiscale::Encaissement, 'contre_passation', $this->contenuFiscal->encaissement($contrePassation), $contrePassation);

            return $contrePassation;
        });
    }

    /**
     * @param  string|null  $derniereJourneeCloturee  AAAA-MM-JJ, lue sous le verrou du registre
     *
     * @throws LogicException
     */
    private function refuserSiLaJourneeEstCloturee(CarbonImmutable $journee, ?string $derniereJourneeCloturee): void
    {
        if ($derniereJourneeCloturee !== null && $journee->toDateString() <= $derniereJourneeCloturee) {
            throw new LogicException("La journée du {$journee->format('d/m/Y')} est clôturée : la contre-passation pourra être faite demain.");
        }
    }
}
