<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
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
 * est un paiement opposé, l'inverse exact de l'original : même client, même
 * mode, même référence, montant et imputations de signe contraire, le
 * motif pour notes. Elle désigne l'original, qui reste tel quel, et les
 * factures qu'il réglait redeviennent dues (voir
 * Facture::constaterUnReglement()). On saisit ensuite le bon paiement, avec
 * lequel le crédit d'avoir disponible du client s'impute (voir
 * EnregistrerPaiementAction). Le crédit qu'avait imputé le paiement erroné
 * reste imputé : un crédit n'est jamais remboursé.
 *
 * Elle est datée du jour où elle est faite, à l'heure de Paris, jamais
 * d'une journée déjà clôturée : une fois la journée clôturée, elle attend
 * le lendemain. Un paiement ne se contre-passe qu'une fois, et une
 * contre-passation ne se contre-passe pas.
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture de
 * la transaction ; l'original et chaque facture qu'il réglait sont relus
 * sous ce verrou, et la contre-passation est signée dans la chaîne des
 * encaissements (type « contre_passation », voir ContenuFiscal), dans la
 * même transaction.
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
            $original = Paiement::query()->whereKey($paiementErrone->getKey())->lockForUpdate()->firstOrFail();

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

            foreach ($original->allocations()->orderBy('id')->get() as $allocation) {
                $facture = Facture::query()->whereKey($allocation->facture_id)->lockForUpdate()->firstOrFail();

                PaiementAllocation::create([
                    'paiement_id' => $contrePassation->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => '-'.$allocation->montant_alloue,
                ]);

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
