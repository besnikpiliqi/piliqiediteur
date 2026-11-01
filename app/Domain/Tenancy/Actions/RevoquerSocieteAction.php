<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Events\MagasinFerme;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\FinDeLaRelation;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * La procédure de l'éditeur qui révoque une société validée qui a pris le
 * SIREN d'une autre entreprise, son courrier recommandé détourné
 * (editeur:societe:revoquer) : le titulaire évincé obtient ainsi que son
 * SIREN soit libéré, puis il s'inscrit normalement, un nouveau courrier
 * partant au siège de son entreprise. Deux opérateurs distincts de
 * l'éditeur la lancent, et elle n'a lieu qu'une fois remis le courrier de
 * révocation, envoyé au nom du représentant légal à l'adresse officielle
 * du siège (editeur:courrier:envoyer --motif=revocation) : son code saisi
 * sur sa page (voir SaisirCodeDeConfirmationAction::saisirUnCodeDeProcedure())
 * en est la preuve. Une inscription qui attend encore son code ne se
 * révoque pas : elle se clôt (voir CloreLInscriptionAction::parLEditeur()).
 *
 * La société révoquée ne s'efface jamais : ses documents se conservent,
 * consultables et téléchargeables par ses comptes, jamais modifiés ; plus
 * rien ne s'y vend, ne s'y règle ni ne s'y gère (voir
 * StatutSociete::Revoquee, Magasin::estEnLectureSeule() et
 * ControleDesDroits). Son SIREN se libère (l'index unique des SIREN exclut
 * une société révoquée), comme les SIRET que ses magasins réservaient (voir
 * SiretsDesMagasins::libererCeuxDeLaSociete()) : la société du titulaire
 * les réserve ensuite pour ses propres magasins, sans jamais reprendre le
 * code d'un magasin de la société révoquée (voir CodeMagasin::estPris()).
 *
 * Une société qui n'est pas un cabinet d'expertise comptable se révoque en
 * une transaction, sous les verrous de la clôture d'une inscription (voir
 * CloreLInscriptionAction) : les registres de ses magasins réels, puis sa
 * ligne (« for no key update »), puis les lignes de ses magasins réels et
 * de ses comptes. Dans chaque magasin réel ouvert, ses journées en attente
 * se clôturent, jusqu'au jour même, au nom du système, comme à sa
 * fermeture (voir FermerMagasinAction) ; sa période d'activité se ferme,
 * motif « révocation de la société » (son abonnement s'arrête, étape 12) ;
 * puis, dans chaque magasin réel, le statut de la société s'inscrit au
 * journal des événements (JET 270 statut_societe). Son lien avec un
 * cabinet et ses demandes de lien prennent fin, cabinet prévenu (voir
 * MettreFinAuLienAction::pourUnClientRevoque()). Viennent ensuite son
 * statut, la libération de ses SIRET, l'effacement de ses pièces de
 * vérification, l'annulation de ses codes encore actifs et de ses
 * transmissions de la propriété en attente, la fin de la relation, qui
 * fixe à cinq ans la conservation de ses données de vérification (voir
 * FinDeLaRelation), et la révocation au journal d'audit, avec
 * l'intervention de l'éditeur. Après la transaction, ses caisses
 * personnelles restées ouvertes se ferment d'office, et son propriétaire
 * est prévenu.
 *
 * Un cabinet d'expertise comptable se révoque en deux temps, pour garder
 * l'ordre des verrous des liens (voir JournalDesDroits::modifierLeLien())
 * : d'abord, une transaction verrouille sa ligne (« for no key update »),
 * puis sa vérification de cabinet (FOR UPDATE), que toute acceptation de
 * lien ou d'invitation tient sous un verrou partagé, puis ses comptes
 * (« for no key update », comme la clôture d'une inscription), et
 * pose la révocation comme pour toute société, sans magasin ; le cabinet
 * n'est dès lors plus vérifié, et toute acceptation l'attend puis est
 * refusée. Ensuite, ses liens actifs, relus (lecture hors de la société
 * courante, filtrée par ce cabinet et limitée aux champs nécessaires,
 * déclarée dans CLAUDE.md), prennent fin chacun dans sa propre
 * transaction, chaque client prévenu (voir
 * MettreFinAuLienAction::parLEditeur()) ; ses demandes en cours, dans les
 * deux sens, sont retirées (voir
 * RetirerLaDemandeDeLienAction::pourUnCabinetRevoque()) et ses invitations
 * ouvertes retirées (voir RetirerLInvitationAction::parLaRevocationDuCabinet()).
 */
class RevoquerSocieteAction
{
    public const MOTIF_LIBERATION = 'Société révoquée par l\'éditeur';

    public const MESSAGE_DEUX_OPERATEURS = 'La révocation d\'une société exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_DEJA_REVOQUEE = 'Cette société est déjà révoquée.';

    public const MESSAGE_PAS_VALIDEE = 'Cette société n\'est pas validée : une inscription qui attend son code, ou close, ne se révoque pas (une inscription qui attend son code se clôt par editeur:societe:clore, ce qui libère son SIREN).';

    public const MESSAGE_SANS_CODE = 'Aucun code de révocation n\'a été saisi pour cette société : envoyez d\'abord le courrier (editeur:courrier:envoyer --motif=revocation) au représentant légal, à l\'adresse officielle du siège ; son code, saisi sur sa page, prouve la remise.';

    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

    private const MAGASINS_CHANGES = 'magasins_changes';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly CloturerLesJourneesAction $cloturerLesJournees,
        private readonly FermerLesCaissesPersonnellesAction $fermerLesCaisses,
        private readonly PeriodesDActivite $periodesDActivite,
        private readonly SiretsDesMagasins $siretsDesMagasins,
        private readonly PiecesDeVerification $pieces,
        private readonly AnnulationDesCodes $annulationDesCodes,
        private readonly FinDeLaRelation $finDeLaRelation,
        private readonly MettreFinAuLienAction $finDesLiens,
        private readonly RetirerLaDemandeDeLienAction $retraitDesDemandes,
        private readonly RetirerLInvitationAction $retraitDesInvitations,
        private readonly AccesDesCabinets $acces,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @return list<string> les SIRET libérés
     *
     * @throws VerificationRefuseeException quand la société ou la preuve ne permettent pas la révocation
     * @throws LogicException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): array
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new VerificationRefuseeException(self::MESSAGE_DEUX_OPERATEURS);
        }

        $revocation = $societe->estUnCabinet()
            ? $this->revoquerUnCabinet($societe, $editeur)
            : $this->revoquerUneSociete($societe, $editeur);

        $this->apresLaRevocation($societe->fresh(), $revocation);

        return $revocation['sirets'];
    }

    /**
     * La société, relue sous son verrou, se révoque-t-elle ? Validée, ni
     * déjà révoquée, et le code du courrier de révocation saisi.
     *
     * @throws VerificationRefuseeException
     */
    private static function verifierQuElleSeRevoque(Societe $verrouillee): void
    {
        if ($verrouillee->statut === StatutSociete::Revoquee) {
            throw new VerificationRefuseeException(self::MESSAGE_DEJA_REVOQUEE);
        }

        if (! $verrouillee->estValidee()) {
            throw new VerificationRefuseeException(self::MESSAGE_PAS_VALIDEE);
        }

        if (! CodeConfirmation::query()->where('motif', MotifCode::Revocation->value)->whereNotNull('utilise_le')->exists()) {
            throw new VerificationRefuseeException(self::MESSAGE_SANS_CODE);
        }
    }

    /**
     * Une société qui n'est pas un cabinet, en une transaction, sous les
     * verrous de ses registres, de sa ligne, de ses magasins réels et de
     * ses comptes.
     *
     * @return array{sirets: list<string>, magasins: array<int, CarbonImmutable>, transmissions: list<int>, liens: list<LienCabinet>}
     *
     * @throws VerificationRefuseeException
     * @throws LogicException
     */
    private function revoquerUneSociete(Societe $societe, InterventionDeLEditeur $editeur): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $editeur): array {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = $societe->magasinsReels()->orderBy('id')->get();

                $resultat = $this->operateur->pendantLeSysteme(fn (): array|string => $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $magasins, $editeur): array|string {
                    $verrouillee = Societe::query()->whereKey($societe->getKey())->lock('for no key update')->firstOrFail();

                    if (array_map(intval(...), $verrouillee->magasinsReels()->orderBy('id')->pluck('id')->all()) !== array_map(intval(...), $magasins->modelKeys())) {
                        return self::MAGASINS_CHANGES;
                    }

                    self::verifierQuElleSeRevoque($verrouillee);
                    $magasinsVerrouilles = $verrouillee->magasinsReels()->orderBy('id')->lock('for no key update')->get();
                    User::query()->where('societe_id', $verrouillee->getKey())->orderBy('id')->lock('for no key update')->get();
                    $fermes = $this->arreterLesMagasins($magasinsVerrouilles);
                    $this->finDesLiens->pourUnClientRevoque($verrouillee, $editeur);

                    return [...$this->revoquerSousVerrou($verrouillee, $editeur, $magasinsVerrouilles), 'magasins' => $fermes, 'liens' => []];
                }));

                if ($resultat !== self::MAGASINS_CHANGES) {
                    return $resultat;
                }
            }

            throw new LogicException('Les magasins de cette société changent en ce moment : recommencez.');
        });
    }

    /**
     * Un cabinet d'expertise comptable, en deux temps : la révocation sous
     * les verrous de sa ligne, de sa vérification et de ses comptes ; puis
     * la fin de ses liens actifs, relus, le retrait de ses demandes et de
     * ses invitations ouvertes, chacun dans sa propre transaction.
     *
     * @return array{sirets: list<string>, magasins: array<int, CarbonImmutable>, transmissions: list<int>, liens: list<LienCabinet>}
     *
     * @throws VerificationRefuseeException
     */
    private function revoquerUnCabinet(Societe $cabinet, InterventionDeLEditeur $editeur): array
    {
        $revocation = app(TenantContext::class)->runAsSociete($cabinet, fn (): array => $this->operateur->pendantLeSysteme(fn (): array => DB::transaction(function () use ($cabinet, $editeur): array {
            $verrouille = Societe::query()->whereKey($cabinet->getKey())->lock('for no key update')->firstOrFail();
            self::verifierQuElleSeRevoque($verrouille);
            $verrouille->cabinet()->lockForUpdate()->first();
            User::query()->where('societe_id', $verrouille->getKey())->orderBy('id')->lock('for no key update')->get();

            return [...$this->revoquerSousVerrou($verrouille, $editeur, $verrouille->magasinsReels()->get()), 'magasins' => []];
        })));

        $liens = $this->acces->liensActifsDuCabinet($cabinet);

        foreach ($liens as $lien) {
            $this->finDesLiens->parLEditeur($lien, MotifDeFinDuLien::SocieteRevoquee, $editeur);
        }

        LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('cabinet_id', $cabinet->getKey())
            ->whereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes()))
            ->orderBy('id')
            ->get(['id', 'societe_id', 'cabinet_id', 'etat', 'origine'])
            ->each(fn (LienCabinet $demande) => $this->retraitDesDemandes->pourUnCabinetRevoque($demande));

        app(TenantContext::class)->runAsSociete($cabinet, fn () => InvitationCabinet::query()
            ->where('etat', EtatInvitation::Ouverte->value)
            ->orderBy('id')
            ->get()
            ->each(fn (InvitationCabinet $invitation) => $this->retraitDesInvitations->parLaRevocationDuCabinet($invitation, $editeur)));

        return [...$revocation, 'liens' => $liens->all()];
    }

    /**
     * Dans chaque magasin réel ouvert, sous le verrou de son registre :
     * ses journées en attente se clôturent jusqu'au jour même, au nom du
     * système, et sa période d'activité ouverte se ferme, motif
     * « révocation de la société ». Un magasin fermé n'a plus de journée
     * en attente ni de période ouverte.
     *
     * @param  iterable<Magasin>  $magasins
     * @return array<int, CarbonImmutable> par magasin rouvert à la caisse ce jour-là, l'instant de son registre, jusqu'auquel ses caisses personnelles se ferment ensuite
     */
    private function arreterLesMagasins(iterable $magasins): array
    {
        $instants = [];

        foreach ($magasins as $magasin) {
            app(TenantContext::class)->runAs($magasin, function () use ($magasin, &$instants): void {
                $instant = $this->registre->verrouiller($magasin);

                if (! $magasin->estFerme()) {
                    $this->cloturerLesJournees->parLeSysteme($magasin, $instant->jour());
                    $instants[(int) $magasin->getKey()] = $instant->utc;
                }

                if (PeriodeActivite::query()->where('magasin_id', $magasin->getKey())->whereNull('fin')->exists()) {
                    MagasinFerme::dispatch($magasin, $this->periodesDActivite->fermer($magasin, MotifFermeture::RevocationSociete));
                }
            });
        }

        return $instants;
    }

    /**
     * La révocation elle-même, sous les verrous de l'appelant : le statut
     * au journal des événements de chaque magasin réel (JET 270), le statut
     * de la société, ses SIRET libérés, ses pièces effacées, ses codes et
     * ses transmissions en attente annulés, la fin de la relation, puis la
     * révocation au journal d'audit, la tête du journal en dernier.
     *
     * @param  iterable<Magasin>  $magasins  ses magasins réels, verrouillés
     * @return array{sirets: list<string>, transmissions: list<int>}
     */
    private function revoquerSousVerrou(Societe $verrouillee, InterventionDeLEditeur $editeur, iterable $magasins): array
    {
        foreach ($magasins as $magasin) {
            app(TenantContext::class)->runAs($magasin, fn () => $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => 'statut_societe',
                'ancienne_valeur' => $verrouillee->statut->value,
                'nouvelle_valeur' => StatutSociete::Revoquee->value,
            ]));
        }

        $ancienStatut = $verrouillee->statut;
        $verrouillee->forceFill(['statut' => StatutSociete::Revoquee])->save();
        $sirets = $this->siretsDesMagasins->libererCeuxDeLaSociete($verrouillee, self::MOTIF_LIBERATION);
        $this->pieces->effacerToutes($verrouillee, PiecesDeVerification::MOTIF_DECISION, $editeur);

        CodeConfirmation::query()->whereNull('utilise_le')->whereNull('annule_le')->orderBy('id')->lockForUpdate()->get()
            ->each(fn (CodeConfirmation $code) => $this->annulationDesCodes->annuler($code, AnnulationDesCodes::SOCIETE_REVOQUEE, editeur: $editeur));

        $transmissions = $this->annulerLesTransmissionsEnAttente($verrouillee, $editeur);
        $this->finDeLaRelation->constater($verrouillee);
        $this->journalAudit->inscrire(ActionAuditee::SocieteRevoquee, null, null, ['statut' => $ancienStatut->value], [
            'statut' => StatutSociete::Revoquee->value,
            'sirets_liberes' => $sirets,
        ], $verrouillee, $editeur);

        return ['sirets' => $sirets, 'transmissions' => $transmissions];
    }

    /**
     * Annule, sous le verrou de sa ligne, chaque transmission de la
     * propriété encore valable de la société, au nom de l'éditeur.
     *
     * @return list<int> les comptes à qui ces transmissions proposaient la propriété
     */
    private function annulerLesTransmissionsEnAttente(Societe $verrouillee, InterventionDeLEditeur $editeur): array
    {
        $destinataires = [];

        $demandes = TransmissionPropriete::query()
            ->where('societe_id', $verrouillee->getKey())
            ->whereNull('annulee_le')
            ->whereNull('acceptee_le')
            ->where('expire_le', '>', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($demandes as $demande) {
            $demande->forceFill(['annulee_le' => now()])->save();
            $destinataire = User::query()->whereKey($demande->destinataire_id)->firstOrFail();
            $this->journalAudit->inscrire(ActionAuditee::TransmissionAnnulee, null, null, null, [
                'destinataire' => ['id' => $destinataire->getKey(), 'nom' => $destinataire->name],
            ], $demande, $editeur);
            $destinataires[] = (int) $destinataire->getKey();
        }

        return $destinataires;
    }

    /**
     * Après la révocation, chaque suite à part, un échec signalé
     * n'empêchant pas les autres : les caisses personnelles des magasins
     * dont les journées viennent de se clôturer se ferment d'office ; le
     * propriétaire est prévenu dans l'espace de sa société, sans la
     * justification de l'éditeur, qui reste interne ; chaque
     * administrateur à qui une transmission annulée proposait la propriété
     * l'apprend.
     *
     * @param  array{sirets: list<string>, magasins: array<int, CarbonImmutable>, transmissions: list<int>, liens: list<LienCabinet>}  $revocation
     */
    private function apresLaRevocation(Societe $societe, array $revocation): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $revocation): void {
            foreach ($revocation['magasins'] as $magasinId => $instant) {
                $magasin = Magasin::query()->findOrFail($magasinId);
                $this->sansInterrompre(fn () => app(TenantContext::class)->runAs($magasin, fn () => $this->fermerLesCaisses->handle($magasin, $instant)));
            }

            $this->sansInterrompre(fn () => $societe->proprietaire()->first()?->notify(new AvisDeVerification(
                $societe,
                'Votre société est révoquée',
                'L\'éditeur a révoqué votre société : son SIREN appartient à une autre entreprise, dont le représentant légal a reçu le courrier recommandé envoyé au siège. '
                .($societe->estUnCabinet() ? 'L\'espace du cabinet reste consultable, en lecture seule, et vos liens avec vos clients ont pris fin.' : 'Vos magasins restent consultables, en lecture seule : plus rien ne s\'y vend ni ne s\'y modifie.')
                .' Pour toute question, contactez l\'éditeur.',
                AvisDeVerification::REFUS,
            )));

            User::query()->whereKey($revocation['transmissions'])->orderBy('id')->get()->each(fn (User $administrateur) => $this->sansInterrompre(fn () => $administrateur->notify(new AvisDeLEquipe(
                $societe,
                'Transmission de la propriété annulée',
                "L'éditeur a révoqué {$societe->getFilamentName()} : la transmission de la propriété qui vous était proposée est annulée, et le lien qui vous a été envoyé ne sert plus.",
            ))));
        });
    }

    private function sansInterrompre(Closure $suite): void
    {
        try {
            $suite();
        } catch (Throwable $erreur) {
            report($erreur);
        }
    }
}
