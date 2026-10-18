<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ImputationDuCredit;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un paiement saisi par erreur ne se modifie ni ne se supprime (voir
 * ProtegeLesDonneesValidees) : il se contre-passe (B4). La contre-passation
 * est un paiement opposé, l'inverse exact de l'original (K9) : même client,
 * même mode, même référence, montant et imputations de signe contraire,
 * le crédit d'avoir que l'original avait imputé compris, le motif pour
 * notes. Elle désigne l'original, qui reste tel quel, et les factures
 * qu'il réglait redeviennent dues de tout ce qu'il y avait réglé (voir
 * Facture::constaterUnReglement()). Seul le paiement de la première part
 * d'un encaissement impute des bons d'avoir, ceux présentés pour un
 * ticket du client comptoir resté dû (voir EncaisserEnPlusieursModesAction) :
 * le contre-passer leur rend ce crédit, que le ticket doit de nouveau et
 * que leur numéro peut resservir ; contre-passer une autre part de cet
 * encaissement les laisse imputés. La fenêtre de la contre-passation le
 * dit (voir PaiementResource).
 *
 * Aussitôt, dans la même transaction, le crédit d'avoir disponible du
 * client s'impute sur ce qui reste dû, dans le même ordre qu'à l'émission
 * d'un avoir (complément 1, voir ImputationDuCredit) : d'abord sur les
 * factures que chaque avoir corrige, puis sur les autres factures dues du
 * client, de la plus ancienne à la plus récente, jamais sur une autre
 * vente du client comptoir. Ces imputations désignent la contre-passation,
 * qui les signe avec les siennes. On saisit ensuite le bon paiement, qui
 * ne règle que ce qui reste dû.
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
 * ContenuFiscal), imputations et crédits inversés et crédit réimputé
 * compris, dans la même transaction.
 *
 * Son auteur est relu sous le verrou de sa ligne, après celui du registre
 * (voir LecteurDesDroits) : il doit pouvoir contre-passer ce paiement (voir
 * PaiementPolicy::contrePasser()), et n'encaisse que dans un magasin à la
 * fois (voir VerificateurCaissesPersonnelles). C'est lui que la
 * contre-passation enregistre et que le registre signe.
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous le
 * verrou du registre : répétée après une réponse perdue, elle rend la
 * contre-passation déjà enregistrée.
 */
class ContrePasserPaiementAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly VerificateurCaissesPersonnelles $caissesPersonnelles,
        private readonly ActionsUniques $actionsUniques,
        private readonly ImputationDuCredit $imputation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Paiement $paiementErrone, string $motif, ?string $jeton = null): Paiement
    {
        $motif = trim($motif);

        if ($motif === '') {
            throw new LogicException('Indiquez le motif de la contre-passation.');
        }

        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::CONTRE_PASSER_PAIEMENT, $auteur, ['paiement_id' => $paiementErrone->getKey(), 'motif' => $motif], fn () => $this->registre->verrouiller($magasin), fn (): Paiement => DB::transaction(function () use ($auteur, $paiementErrone, $motif, $magasin) {
            $journee = $this->registre->verrouiller($magasin)->enHeureLocale();
            $original = Paiement::query()->whereKey($paiementErrone->getKey())->firstOrFail();

            if ($original->estUneContrePassation()) {
                throw new LogicException('Une contre-passation ne se contre-passe pas.');
            }

            if ($original->contrePassation()->exists()) {
                throw new LogicException('Ce paiement a déjà été contre-passé.');
            }

            $this->refuserSiLaJourneeEstCloturee($journee, $this->registre->derniereJourneeCloturee($magasin));
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'contrePasser', $magasin, $original);
            $this->caissesPersonnelles->refuserSiSaCaisseEstOuverteAilleurs($compte, $magasin);

            return $this->operateur->pendant($compte, fn (): Paiement => $this->contrePasser($compte, $original, $motif, $journee));
        }));
    }

    /**
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function contrePasser(User $compte, Paiement $original, string $motif, CarbonImmutable $journee): Paiement
    {
        $contrePassation = new Paiement([
            'client_id' => $original->client_id,
            'mode_paiement' => $original->mode_paiement,
            'montant' => '-'.$original->montant,
            'reference' => $original->reference,
            'notes' => $motif,
            'recu_le' => $journee->toDateString(),
        ]);
        $contrePassation->forceFill(['contre_passe_paiement_id' => $original->getKey(), 'cree_par' => $compte->getKey()])->save();
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

        $this->reimputerLeCreditDisponible($contrePassation);

        $contrePassation = $contrePassation->fresh();
        $this->registre->enregistrer(ChaineFiscale::Encaissement, 'contre_passation', $this->contenuFiscal->encaissement($contrePassation), $contrePassation);

        return $contrePassation;
    }

    /**
     * Le crédit d'avoir disponible du client s'impute sur ce qui reste dû
     * (complément 1) : chaque imputation désigne la contre-passation, qui
     * la signe.
     */
    private function reimputerLeCreditDisponible(Paiement $contrePassation): void
    {
        /** @var Client $client */
        $client = $contrePassation->client;

        foreach ($this->imputation->apresUneContrePassation($client) as ['avoir' => $avoir, 'facture' => $facture, 'montant' => $montant]) {
            AvoirAllocation::create([
                'avoir_id' => $avoir->getKey(),
                'facture_id' => $facture->getKey(),
                'paiement_id' => $contrePassation->getKey(),
                'montant_alloue' => $this->calculateur->enDecimal($montant),
            ]);

            $facture->constaterUnReglement();
        }
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
