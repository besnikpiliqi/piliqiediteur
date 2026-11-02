<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * L'avoir de régularisation d'une facture que désigne l'examen du SIREN de
 * la société, après sa correction par l'éditeur (voir
 * RegularisationDUneFacture), fait comme tout avoir : par le propriétaire
 * ou par un compte autorisé à faire les avoirs dans ce magasin, jamais par
 * un compte d'un cabinet lié, qui n'a jamais cette autorisation (voir
 * PermissionRegistry::duCabinet()).
 *
 * Tout se fait dans une seule transaction, comme pour un avoir enregistré
 * (voir EnregistrerAvoirAction) : le registre fiscal du magasin actif se
 * verrouille d'abord ; l'auteur est relu sous le verrou de sa ligne (voir
 * LecteurDesDroits) ; la facture, relue dans le magasin actif sous ce
 * verrou, doit encore se régulariser par un avoir (voir
 * RegularisationDUneFacture::refusDeLAvoir()) : deux demandes simultanées
 * passent l'une après l'autre, et la seconde est refusée. L'avoir crédite
 * tout ce qui reste à créditer de chaque ligne de la facture (voir
 * RegularisationDUneFacture::lignesDeLAvoir()), régularisation notée, avec
 * son motif (voir CreerAvoirAction), puis s'émet (voir EmettreAvoirAction)
 * : sans retour en stock (voir RestaurerStockApresEmissionAvoir), son
 * crédit imputé sur ce qui reste dû de la facture puis sur sa facture
 * rectificative déjà émise (voir ImputationDuCredit), signé avec
 * l'émetteur figé de la facture et la mention de la régularisation (voir
 * ContenuFiscal). La rectificative émise avant lui, qui l'attendait (voir
 * StatutFacture::EnAttenteDeRegularisation), en sort, même quand son
 * crédit n'a rien laissé pour elle (la facture n'avait rien reçu) : elle
 * reste alors due de ce que le client devait encore sur la facture, et
 * son statut suit ce qui en reste dû. L'avoir s'inscrit enfin au journal
 * d'audit, dans la chaîne du magasin. Sans clé de signature, rien de tout
 * cela n'a lieu.
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous le
 * verrou du registre : répétée après une réponse perdue, elle rend l'avoir
 * déjà émis. L'émission qu'elle compose n'en consomme aucun.
 */
class FaireLAvoirDeRegularisationAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerAvoirAction $creerAvoir,
        private readonly EmettreAvoirAction $emettreAvoir,
        private readonly LecteurDesDroits $lecteur,
        private readonly ActionsUniques $actionsUniques,
        private readonly RegularisationDUneFacture $regularisation,
        private readonly JournalAudit $journalAudit,
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
    public function handle(User $auteur, Facture $facture, ?string $jeton = null): Avoir
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::FAIRE_L_AVOIR_DE_REGULARISATION, $auteur, ['facture_id' => $facture->getKey()], fn () => $this->registre->verrouiller($magasin), fn (): Avoir => DB::transaction(function () use ($auteur, $facture, $magasin): Avoir {
            $this->registre->verrouiller($magasin);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Avoir::class);
            $origine = Facture::query()->whereKey($facture->getKey())->first() ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $refus = $this->regularisation->refusDeLAvoir($origine);

            if ($refus !== null) {
                throw new LogicException($refus);
            }

            $avoir = $this->emettreAvoir->handle($compte, $this->creerAvoir->handle(
                $compte,
                $origine->client,
                $this->regularisation->lignesDeLAvoir($origine),
                sprintf(RegularisationDUneFacture::MOTIF_DE_L_AVOIR, $origine->numero),
                regularisation: true,
            ));
            $origine->rectificative()->where('statut', StatutFacture::EnAttenteDeRegularisation)->lockForUpdate()->first()?->constaterUnReglement();

            $this->journalAudit->inscrire(ActionAuditee::AvoirDeRegularisationEmis, $compte, $magasin, null, [
                'avoir' => $avoir->numero,
                'facture' => $origine->numero,
                'siren_correct' => (string) $origine->magasin->societe->siren,
                'total_ttc' => $this->calculateur->enCentiemes($avoir->total_ttc),
            ], $avoir);

            return $avoir;
        }));
    }
}
