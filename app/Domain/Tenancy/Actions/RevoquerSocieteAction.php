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
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\FinDeLaRelation;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Domain\Tenancy\Support\ValeursLues;
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
 * depuis moins de trente jours en est la preuve, que la révocation
 * consomme (voir CodeConfirmation::preuveDeLaRemise()). Une inscription
 * qui attend encore son code ne se révoque pas : elle se clôt (voir
 * CloreLInscriptionAction::parLEditeur()).
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
 * refusée. Ensuite, ses liens actifs et ses demandes en cours, relus
 * (lecture hors de la société courante, filtrée par ce cabinet et limitée
 * aux champs nécessaires, déclarée dans CLAUDE.md, voir
 * AccesDesCabinets::liensAMettreFin()), prennent fin chacun dans sa propre
 * transaction : chaque lien par la procédure de l'éditeur qui vise le
 * cabinet, chaque client prévenu (voir MettreFinAuLienAction::parLEditeur()),
 * chaque demande, dans les deux sens, retirée (voir
 * RetirerLaDemandeDeLienAction::pourUnCabinetRevoque()) ; puis ses
 * invitations ouvertes sont retirées (voir
 * RetirerLInvitationAction::parLaRevocationDuCabinet()). Un lien, une
 * demande ou une invitation qui ne prend pas fin (un interblocage, des
 * droits qui changent au même instant) est signalé sans arrêter les
 * suivants, et la procédure le compte (en_echec) ; relancée sur ce cabinet
 * déjà révoqué, sans nouvelle preuve, elle termine ce qui reste au lieu
 * de refuser, et ne dit « déjà révoquée » qu'une fois tout terminé. Chaque
 * avis part sans interrompre la suite : une messagerie en panne n'arrête
 * ni la fin des liens ni les suites de la révocation.
 */
class RevoquerSocieteAction
{
    public const MOTIF_LIBERATION = 'Société révoquée par l\'éditeur';

    public const MESSAGE_DEUX_OPERATEURS = 'La révocation d\'une société exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_DEJA_REVOQUEE = 'Cette société est déjà révoquée.';

    public const MESSAGE_PAS_VALIDEE = 'Cette société n\'est pas validée : une inscription qui attend son code, ou close, ne se révoque pas (une inscription qui attend son code se clôt par editeur:societe:clore, ce qui libère son SIREN).';

    public const MESSAGE_SANS_CODE = 'Aucun code de révocation n\'a été saisi pour cette société depuis moins de trente jours : envoyez d\'abord le courrier (editeur:courrier:envoyer --motif=revocation) au représentant légal, à l\'adresse officielle du siège ; son code, saisi sur sa page, prouve la remise.';

    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

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
     * @return array{sirets: list<string>, reprise: bool, en_echec: int} les SIRET libérés ; vrai pour la reprise d'un cabinet déjà révoqué ; le nombre de ses liens, demandes ou invitations qui n'ont pas pu prendre fin (relancer la procédure les termine)
     *
     * @throws VerificationRefuseeException quand la société ou la preuve ne permettent pas la révocation
     * @throws LogicException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): array
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new VerificationRefuseeException(self::MESSAGE_DEUX_OPERATEURS);
        }

        if ($societe->estUnCabinet()) {
            return $this->revoquerUnCabinet($societe, $editeur);
        }

        $revocation = $this->revoquerUneSociete($societe, $editeur);
        $this->apresLaRevocation(ModeleRelu::de($societe), $revocation);

        return ['sirets' => $revocation['sirets'], 'reprise' => false, 'en_echec' => 0];
    }

    /**
     * La société, relue sous son verrou et pas encore révoquée, se
     * révoque-t-elle ? Validée, et le code du courrier de révocation saisi
     * depuis moins de trente jours, qu'elle consomme alors : rend cette
     * preuve.
     *
     * @throws VerificationRefuseeException
     */
    private static function preuveQuElleSeRevoque(Societe $verrouillee): CodeConfirmation
    {
        if (! $verrouillee->estValidee()) {
            throw new VerificationRefuseeException(self::MESSAGE_PAS_VALIDEE);
        }

        return CodeConfirmation::preuveDeLaRemise(MotifCode::Revocation) ?? throw new VerificationRefuseeException(self::MESSAGE_SANS_CODE);
    }

    /**
     * Une société qui n'est pas un cabinet, en une transaction, sous les
     * verrous de ses registres, de sa ligne, de ses magasins réels et de
     * ses comptes.
     *
     * @return array{sirets: list<string>, magasins: array<int, CarbonImmutable>, transmissions: list<int>}
     *
     * @throws VerificationRefuseeException
     * @throws LogicException
     */
    private function revoquerUneSociete(Societe $societe, InterventionDeLEditeur $editeur): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $editeur) {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = $societe->magasinsReels()->orderBy('id')->get();

                // null quand les magasins réels ont changé entre leur lecture
                // et leurs verrous : tout reprend alors.
                $revocation = function () use ($societe, $magasins, $editeur) {
                    $verrouillee = Societe::query()->whereKey($societe->getKey())->lock('for no key update')->firstOrFail();

                    if (ValeursLues::entiers($verrouillee->magasinsReels()->orderBy('id')->pluck('id')) !== ValeursLues::entiers($magasins->modelKeys())) {
                        return null;
                    }

                    if ($verrouillee->statut === StatutSociete::Revoquee) {
                        throw new VerificationRefuseeException(self::MESSAGE_DEJA_REVOQUEE);
                    }

                    $preuve = self::preuveQuElleSeRevoque($verrouillee);
                    $magasinsVerrouilles = $verrouillee->magasinsReels()->orderBy('id')->lock('for no key update')->get();
                    User::query()->where('societe_id', $verrouillee->getKey())->orderBy('id')->lock('for no key update')->get();
                    $fermes = $this->arreterLesMagasins($magasinsVerrouilles);
                    $this->finDesLiens->pourUnClientRevoque($verrouillee, $editeur);

                    return [...$this->revoquerSousVerrou($verrouillee, $editeur, $magasinsVerrouilles, $preuve), 'magasins' => $fermes];
                };
                $resultat = $this->operateur->pendantLeSysteme(fn () => $this->registre->sousVerrouDesMagasins($magasins, $revocation));

                if ($resultat !== null) {
                    return $resultat;
                }
            }

            throw new LogicException('Les magasins de cette société changent en ce moment : recommencez.');
        });
    }

    /**
     * Un cabinet d'expertise comptable, en deux temps : la révocation sous
     * les verrous de sa ligne, de sa vérification et de ses comptes ; puis
     * la fin de ses liens actifs, le retrait de ses demandes en cours et de
     * ses invitations ouvertes, chacun dans sa propre transaction, un échec
     * signalé n'arrêtant pas les suivants. Un cabinet déjà révoqué ne passe
     * que par le second temps, s'il en reste quelque chose, sans nouvelle
     * preuve et sans prévenir de nouveau son propriétaire ; sinon il est
     * refusé comme déjà révoqué.
     *
     * @return array{sirets: list<string>, reprise: bool, en_echec: int}
     *
     * @throws VerificationRefuseeException
     */
    private function revoquerUnCabinet(Societe $cabinet, InterventionDeLEditeur $editeur): array
    {
        $sousLesVerrous = function () use ($cabinet, $editeur) {
            $verrouille = Societe::query()->whereKey($cabinet->getKey())->lock('for no key update')->firstOrFail();

            if ($verrouille->statut === StatutSociete::Revoquee) {
                return null;
            }

            $preuve = self::preuveQuElleSeRevoque($verrouille);
            $verrouille->cabinet()->lockForUpdate()->first();
            User::query()->where('societe_id', $verrouille->getKey())->orderBy('id')->lock('for no key update')->get();

            return [...$this->revoquerSousVerrou($verrouille, $editeur, $verrouille->magasinsReels()->get(), $preuve), 'magasins' => []];
        };
        $revocation = app(TenantContext::class)->runAsSociete($cabinet, fn () => $this->operateur->pendantLeSysteme(fn () => DB::transaction($sousLesVerrous)));

        $liens = $this->acces->liensAMettreFin($cabinet, [EtatDuLien::Actif, ...EtatDuLien::demandes()]);
        $invitations = app(TenantContext::class)->runAsSociete($cabinet, fn () => InvitationCabinet::query()->where('etat', EtatInvitation::Ouverte->value)->orderBy('id')->get());

        if ($revocation === null && $liens->isEmpty() && $invitations->isEmpty()) {
            throw new VerificationRefuseeException(self::MESSAGE_DEJA_REVOQUEE);
        }

        $enEchec = 0;

        foreach ($liens as $lien) {
            $enEchec += $this->sansInterrompre(fn () => $lien->etat === EtatDuLien::Actif
                ? $this->finDesLiens->parLEditeur($lien, MotifDeFinDuLien::SocieteRevoquee, $editeur, $cabinet)
                : $this->retraitDesDemandes->pourUnCabinetRevoque($lien)) ? 0 : 1;
        }

        foreach ($invitations as $invitation) {
            $enEchec += $this->sansInterrompre(fn () => $this->retraitDesInvitations->parLaRevocationDuCabinet($invitation, $editeur)) ? 0 : 1;
        }

        if ($revocation !== null) {
            $this->apresLaRevocation(ModeleRelu::de($cabinet), $revocation);
        }

        return ['sirets' => [], 'reprise' => $revocation === null, 'en_echec' => $enEchec];
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
                    $instants[CleDuModele::de($magasin)] = $instant->utc;
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
     * de la société, la preuve de la remise du courrier consommée, ses
     * SIRET libérés, ses pièces effacées, ses codes et ses transmissions en
     * attente annulés, la fin de la relation, puis la révocation au journal
     * d'audit, la tête du journal en dernier.
     *
     * @param  iterable<Magasin>  $magasins  ses magasins réels, verrouillés
     * @return array{sirets: list<string>, transmissions: list<int>}
     */
    private function revoquerSousVerrou(Societe $verrouillee, InterventionDeLEditeur $editeur, iterable $magasins, CodeConfirmation $preuve): array
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
        $preuve->aProuveLaProcedure();
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
            $destinataires[] = CleDuModele::de($destinataire);
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
     * @param  array{sirets: list<string>, magasins: array<int, CarbonImmutable>, transmissions: list<int>}  $revocation
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

    /**
     * Fait cette suite sans interrompre les suivantes : un échec est
     * signalé (report()), et rien de ce qui précède n'est défait. Rend
     * vrai si la suite s'est faite.
     */
    private function sansInterrompre(Closure $suite): bool
    {
        try {
            $suite();
        } catch (Throwable $erreur) {
            report($erreur);

            return false;
        }

        return true;
    }
}
