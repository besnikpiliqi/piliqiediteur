<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Enums\ResultatDInvitation;
use App\Domain\Cabinets\Exceptions\CabinetNonVerifieException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesCabinets;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire d'un cabinet d'expertise comptable vérifié invite un
 * client, uniquement par son SIREN et l'adresse e-mail à laquelle part le
 * lien, en redonnant son mot de passe (voir InvitationCabinetPolicy::create()) :
 * - un SIREN libre (aucune société ne le tient) reçoit une invitation,
 *   valable 7 jours, dont l'acceptation crée la société, validée sans
 *   courrier (voir AccepterLInvitationAction) ; une invitation ouverte à la
 *   même adresse n'est pas renvoyée, à une autre adresse elle est refusée ;
 *   après un refus, pas de nouvelle invitation avant 7 jours ;
 * - un SIREN déjà pris reçoit une demande de rattachement, ligne du lien
 *   dans la société qui le tient, dont l'e-mail part à l'adresse saisie,
 *   que PILIQI ne garde pas, et que son propriétaire voit aussi dans son
 *   espace ; le cabinet apprend seulement que le SIREN est inscrit, rien
 *   d'autre de la société tant que le lien n'est pas actif ; un SIREN tenu
 *   par un cabinet est refusé par un message neutre.
 * L'entreprise doit exister et être active au répertoire Sirene, lu hors
 * de toute transaction. Au plus 50 invitations et demandes par jour et par
 * cabinet, comptées sous le verrou de la ligne du cabinet, où se suivent
 * les invitations et les demandes d'un cabinet, et chacune dans la limite
 * d'e-mails de son auteur. L'e-mail part après la transaction, relu au
 * moment de l'envoi (voir EnvoiDesLiensDesCabinets).
 */
class InviterUneEntrepriseAction
{
    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire du cabinet invite ses clients et répond à leurs demandes.';

    public const MESSAGE_SOI_MEME = 'Votre cabinet ne peut pas être son propre client.';

    public const MESSAGE_SIREN_NEUTRE = 'Ce SIREN ne peut pas être invité par votre cabinet.';

    public const MESSAGE_DEMANDE_DU_CLIENT = 'Cette entreprise a déjà demandé à lier votre cabinet : répondez à sa demande dans « Invitations et demandes ».';

    public const MESSAGE_DEMANDE_RECENTE = 'Votre cabinet a déjà demandé ce lien il y a moins de 7 jours.';

    public const MESSAGE_INVITATION_OUVERTE = 'Une invitation est déjà ouverte pour ce SIREN : retirez-la pour en envoyer une autre.';

    public const MESSAGE_INVITATION_REFUSEE = 'Cette entreprise a refusé votre invitation il y a moins de 7 jours.';

    public const MESSAGE_REPERTOIRE_INDISPONIBLE = 'Le répertoire Sirene ne répond pas : réessayez dans quelques minutes.';

    public const DELAI_AVANT_UNE_NOUVELLE_INVITATION_EN_JOURS = 7;

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
        private readonly EnvoiDesLiensDesCabinets $envoi,
        private readonly TraceDuVisiteur $traces,
        private readonly RetirerLaDemandeDeLienAction $demandes,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $cabinet, string $siren, string $email, #[SensitiveParameter] string $motDePasse): ResultatDInvitation
    {
        $valides = Validator::make(['siren' => $siren, 'email' => $email], [
            'siren' => ['required', 'string', new SirenValide],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validate();
        $siren = ValeursLues::texteDe($valides, 'siren');
        $email = User::normaliserEmail(ValeursLues::texteDe($valides, 'email'));

        if ($siren === (string) $cabinet->siren) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_SOI_MEME]);
        }

        MotDePasseRedemande::exiger($auteur, $motDePasse);
        app(TenantContext::class)->runAsSociete($cabinet, fn (): User => $this->exigerLeProprietaire($auteur, $cabinet));
        $this->debit->verifier($auteur);

        $consultation = $this->controleSirene->unite($siren);

        if ($consultation['resultat'] !== ResultatVerification::Concluante) {
            throw ValidationException::withMessages(['siren' => $consultation['motif'] ?? self::MESSAGE_REPERTOIRE_INDISPONIBLE]);
        }

        $jeton = Str::random(64);

        try {
            $resultat = app(TenantContext::class)->runAsSociete($cabinet, fn (): array => DB::transaction(function () use ($auteur, $cabinet, $siren, $email, $jeton): array {
                Societe::query()->whereKey($cabinet->getKey())->lockForUpdate()->firstOrFail();
                $auteurRelu = $this->exigerLeProprietaire($auteur, $cabinet);
                $this->verifierLaLimiteDuJour($cabinet);

                InvitationCabinet::query()->where('siren', $siren)->where('etat', EtatInvitation::Ouverte->value)->where('expire_le', '<=', now())->orderBy('id')->lockForUpdate()->get()
                    ->each(fn (InvitationCabinet $perimee) => $perimee->forceFill(['etat' => EtatInvitation::Expiree, 'close_le' => now()])->save());

                $tenante = Societe::societeQuiTientLeSiren($siren);

                return $tenante === null
                    ? $this->inviter($auteurRelu, $cabinet, $siren, $email, $jeton)
                    : $this->demanderLeRattachement($auteurRelu, $cabinet, $tenante, $jeton);
            }));
        } catch (UniqueConstraintViolationException $doublon) {
            if (str_contains($doublon->getMessage(), 'liens_cabinet_une_demande_par_paire')) {
                return ResultatDInvitation::DemandeDejaEnvoyee;
            }

            if (str_contains($doublon->getMessage(), 'invitations_cabinet_une_ouverte_par_siren')) {
                throw new LogicException(self::MESSAGE_INVITATION_OUVERTE);
            }

            throw $doublon;
        }

        match ($resultat['resultat']) {
            ResultatDInvitation::InvitationEnvoyee => DB::afterCommit(fn () => $this->envoi->envoyerLInvitation(ValeurRequise::de($resultat['id'], 'l\'invitation envoyée'), $jeton)),
            ResultatDInvitation::DemandeEnvoyee => DB::afterCommit(fn () => $this->envoyerLaDemande(ValeurRequise::de($resultat['id'], 'la demande de lien envoyée'), $email, $jeton)),
            default => null,
        };

        if (in_array($resultat['resultat'], [ResultatDInvitation::InvitationEnvoyee, ResultatDInvitation::DemandeEnvoyee], true)) {
            $this->debit->compter($auteur);
        }

        return $resultat['resultat'];
    }

    /**
     * L'invitation d'un SIREN libre, sous le verrou du cabinet.
     *
     * @return array{resultat: ResultatDInvitation, id: int|null}
     */
    private function inviter(User $auteur, Societe $cabinet, string $siren, string $email, string $jeton): array
    {
        $ouverte = InvitationCabinet::query()->where('siren', $siren)->where('etat', EtatInvitation::Ouverte->value)->lockForUpdate()->first();

        if ($ouverte !== null) {
            return $ouverte->email === $email ? ['resultat' => ResultatDInvitation::InvitationDejaEnvoyee, 'id' => null] : throw new LogicException(self::MESSAGE_INVITATION_OUVERTE);
        }

        if (InvitationCabinet::query()->where('siren', $siren)->where('etat', EtatInvitation::Refusee->value)->where('close_le', '>', Calendrier::deParis()->decaler(now(), CarbonInterval::days(-self::DELAI_AVANT_UNE_NOUVELLE_INVITATION_EN_JOURS)))->exists()) {
            throw new LogicException(self::MESSAGE_INVITATION_REFUSEE);
        }

        $invitation = tap((new InvitationCabinet)->forceFill([
            'siren' => $siren,
            'email' => $email,
            'jeton_hache' => InvitationCabinet::empreinte($jeton),
            'invitee_par' => $auteur->getKey(),
            'etat' => EtatInvitation::Ouverte,
            'expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::days(InvitationCabinet::DUREE_EN_JOURS)),
        ]))->save();
        $this->journalAudit->inscrire(ActionAuditee::InvitationEnvoyee, $auteur, null, null, ['siren' => $siren], $invitation);

        return ['resultat' => ResultatDInvitation::InvitationEnvoyee, 'id' => CleDuModele::de($invitation)];
    }

    /**
     * La demande de rattachement à la société qui tient le SIREN, dans son
     * contexte, sous le verrou de sa ligne, après celui du cabinet ; une
     * demande dont l'échéance est passée expire d'abord, sans attendre la
     * tâche horaire (voir RetirerLaDemandeDeLienAction::expirerLesPerimees()).
     *
     * @param  array{id: int, type_activite: TypeDActivite}  $tenante
     * @return array{resultat: ResultatDInvitation, id: int|null}
     */
    private function demanderLeRattachement(User $auteur, Societe $cabinet, array $tenante, string $jeton): array
    {
        if ($tenante['type_activite'] === TypeDActivite::Cabinet) {
            throw new LogicException(self::MESSAGE_SIREN_NEUTRE);
        }

        $client = Societe::query()->findOrFail($tenante['id']);

        return app(TenantContext::class)->runAsSociete($client, function () use ($auteur, $cabinet, $client, $jeton): array {
            Societe::query()->whereKey($client->getKey())->lock('for no key update')->firstOrFail();
            $liens = $this->demandes->expirerLesPerimees(LienCabinet::query()->where('cabinet_id', $cabinet->getKey())->orderBy('id')->lockForUpdate()->get());

            if ($liens->contains(fn (LienCabinet $lien): bool => $lien->estActif())) {
                return ['resultat' => ResultatDInvitation::DejaLie, 'id' => null];
            }

            if ($liens->contains(fn (LienCabinet $lien): bool => $lien->etat === EtatDuLien::DemandeParLeCabinet)) {
                return ['resultat' => ResultatDInvitation::DemandeDejaEnvoyee, 'id' => null];
            }

            if ($liens->contains(fn (LienCabinet $lien): bool => $lien->etat === EtatDuLien::DemandeParLeClient)) {
                throw new LogicException(self::MESSAGE_DEMANDE_DU_CLIENT);
            }

            if ($liens->contains(fn (LienCabinet $lien): bool => $lien->origine === OrigineDuLien::DemandeDuCabinet
                && $lien->finDeLaDemande()?->greaterThan(Calendrier::deParis()->decaler(now(), CarbonInterval::days(-self::DELAI_AVANT_UNE_NOUVELLE_INVITATION_EN_JOURS))))) {
                throw new LogicException(self::MESSAGE_DEMANDE_RECENTE);
            }

            $lien = tap((new LienCabinet)->forceFill([
                'cabinet_id' => $cabinet->getKey(),
                'origine' => OrigineDuLien::DemandeDuCabinet,
                'etat' => EtatDuLien::DemandeParLeCabinet,
                'demande_par' => $auteur->getKey(),
                'demande_le' => now(),
                'expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::days(LienCabinet::DUREE_D_UNE_DEMANDE_EN_JOURS)),
                'jeton_hache' => LienCabinet::empreinte($jeton),
            ]))->save();
            $this->traces->horsDeSaSociete(fn () => $this->journalAudit->inscrireLeGesteDeLAutreSociete(ActionAuditee::LienDemande, null, ['cabinet' => IdentiteDansLeJournal::de($cabinet), 'client' => IdentiteDansLeJournal::de($client), 'origine' => OrigineDuLien::DemandeDuCabinet->value], $lien));
            app(TenantContext::class)->runAsSociete($cabinet, fn () => $this->journalAudit->inscrire(ActionAuditee::LienDemande, $auteur, null, null, ['cabinet' => IdentiteDansLeJournal::de($cabinet), 'client' => IdentiteDansLeJournal::sirenSeul($client), 'origine' => OrigineDuLien::DemandeDuCabinet->value], $lien));

            return ['resultat' => ResultatDInvitation::DemandeEnvoyee, 'id' => CleDuModele::de($lien)];
        });
    }

    /**
     * L'e-mail de la demande à l'adresse saisie, et l'avis au propriétaire
     * de la société, dans sa cloche seulement, avec le lien vers sa page de
     * rattachement.
     */
    private function envoyerLaDemande(int $lienId, string $email, #[SensitiveParameter] string $jeton): void
    {
        $this->envoi->envoyerLaDemande($lienId, $email, $jeton);
        $lien = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->findOrFail($lienId);
        $client = Societe::query()->findOrFail($lien->societe_id);
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        $client->proprietaire()->first()?->notify(new AvisDuCabinet(
            $client,
            'Demande d\'un cabinet comptable',
            "Le cabinet {$cabinet->getFilamentName()} demande à suivre votre société : répondez dans les 7 jours.",
            RattachementDUnCabinet::getUrl(['lien' => $lien->uuid], panel: 'societe', tenant: $client),
            'Répondre',
        ));
    }

    /**
     * Le propriétaire actif d'un cabinet vérifié, relu en base.
     *
     * @throws AuthorizationException
     * @throws CabinetNonVerifieException
     */
    private function exigerLeProprietaire(User $auteur, Societe $cabinet): User
    {
        $relu = User::query()->whereKey($auteur->getKey())->first();

        if ($relu === null || Gate::forUser($relu)->denies('create', [InvitationCabinet::class, $cabinet])) {
            if ($relu !== null && $relu->estProprietaire() && (int) $relu->societe_id === CleDuModele::de($cabinet) && ! ModeleRelu::de($cabinet)->estUnCabinetVerifie()) {
                throw new CabinetNonVerifieException;
            }

            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }

        return $relu;
    }

    /**
     * Au plus config('cabinets.invitations_par_jour') invitations et
     * demandes de rattachement par jour et par cabinet, comptées sous le
     * verrou du cabinet.
     *
     * @throws LogicException
     */
    private function verifierLaLimiteDuJour(Societe $cabinet): void
    {
        $limite = Reglage::entier('cabinets.invitations_par_jour');
        $depuis = Calendrier::deParis()->debutDuJour(Calendrier::deParis()->aujourdhui());
        $envoyees = InvitationCabinet::query()->where('created_at', '>', $depuis)->count()
            + LienCabinet::query()->duCabinet($cabinet)->where('origine', OrigineDuLien::DemandeDuCabinet->value)->where('demande_le', '>', $depuis)->count();

        if ($envoyees >= $limite) {
            throw new LogicException("Votre cabinet a déjà envoyé {$envoyees} invitations ou demandes aujourd'hui : réessayez demain.");
        }
    }
}
