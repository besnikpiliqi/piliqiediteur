<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\AdresseOfficielle;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\EnvoiRecommandeIndisponibleException;
use App\Integrations\Rne\RneIndisponibleException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Envoie un code de confirmation à l'entreprise d'une société (C) : par
 * courrier recommandé avec accusé de réception et remise contre signature,
 * à l'adresse officielle de son siège lue le jour même (voir
 * AdresseOfficielle), jamais à une adresse saisie ou tirée d'une pièce ;
 * ou par lettre recommandée électronique qualifiée à l'un de ses
 * dirigeants inscrits au registre national des entreprises, à l'adresse
 * électronique que donne le propriétaire, le code ne se saisissant
 * qu'une fois la lettre acceptée par la personne que le prestataire a
 * identifiée comme ce dirigeant (voir SuivreLesEnvoisAction).
 *
 * Le code prouve qu'on reçoit le courrier à cette adresse, jamais à lui
 * seul la qualité de représentant. Il ne part qu'à la demande d'un
 * propriétaire dont l'identité est vérifiée (une inscription factice ne
 * fait partir aucun pli chez le vrai titulaire), et au plus deux fois par
 * SIREN et par trente jours, ou à la demande de l'éditeur. Un seul code
 * actif par SIREN et par motif dans tout le logiciel : un code expiré
 * s'annule d'abord. Quand le pli en route ou les plis récents viennent
 * d'une autre inscription du même SIREN, le propriétaire lit un refus qui
 * n'en révèle rien (MESSAGE_EXAMEN), et l'éditeur est alerté.
 *
 * Seule l'empreinte du code se garde (voir CodesDeConfirmation) ; le code
 * en clair ne va qu'au pli. Le pli dit la dénomination, le SIREN, l'objet,
 * le code, son expiration, où le saisir, et à qui signaler une demande
 * qu'on n'a pas faite. Un prestataire qui ne répond pas n'envoie rien et
 * n'enregistre rien.
 */
class EnvoyerCodeDeConfirmationAction
{
    public const PLIS_PAR_SIREN_ET_PAR_PERIODE = 2;

    public const JOURS_DE_LA_PERIODE = 30;

    public const MESSAGE_IDENTITE_D_ABORD = 'Vérifiez d\'abord votre identité : un courrier ne part qu\'à la demande d\'un propriétaire dont l\'identité est vérifiée.';

    public const MESSAGE_LIMITE = 'Deux courriers sont déjà partis vers cette entreprise ces trente derniers jours : l\'éditeur doit examiner votre demande.';

    public const MESSAGE_CODE_EN_COURS = 'Un courrier est déjà en route vers l\'adresse officielle de cette entreprise : saisissez le code qu\'il contient, ou attendez son expiration.';

    public const MESSAGE_DIRIGEANT_INCONNU = 'Choisissez l\'un des dirigeants que le registre national des entreprises donne de votre entreprise.';

    /**
     * Le refus que lit le propriétaire quand le pli en route, ou les plis
     * des trente derniers jours, viennent d'une autre inscription du même
     * SIREN : il n'en apprend rien.
     */
    public const MESSAGE_EXAMEN = 'Un courrier ne peut pas partir vers votre entreprise pour le moment : l\'éditeur examinera votre demande et vous contactera.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly AdresseOfficielle $adresseOfficielle,
        private readonly RapprochementDesDirigeants $rapprochement,
        private readonly CodesDeConfirmation $codes,
        private readonly ServiceEnvoiRecommande $envoi,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
    ) {}

    /**
     * @param  User|null  $proprietaire  le propriétaire qui le demande, ou null pour l'éditeur
     * @param  array{rang: int, email: string}|null  $lre  le dirigeant destinataire d'une lettre électronique, par son rang parmi les candidats du registre, et son adresse
     *
     * @throws VerificationRefuseeException
     * @throws InvalidArgumentException quand ni le propriétaire ni l'éditeur ne le demande, ou les deux
     */
    public function handle(Societe $societe, MotifCode $motif, CanalEnvoi $canal = CanalEnvoi::RecommandeAr, ?User $proprietaire = null, ?InterventionDeLEditeur $editeur = null, ?array $lre = null): CodeConfirmation
    {
        if (($proprietaire === null) === ($editeur === null)) {
            throw new InvalidArgumentException('Un code part à la demande du propriétaire, ou de l\'éditeur.');
        }

        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $motif, $canal, $proprietaire, $editeur, $lre): CodeConfirmation {
            if ($proprietaire !== null) {
                $this->verifierLaDemandeDuProprietaire($proprietaire, $societe, $motif);
            }

            $destinataire = $canal === CanalEnvoi::LreQualifiee ? $this->dirigeantDestinataire($societe, $lre) : $this->adresseOfficielle->du($societe);
            $code = $this->codes->generer();
            $expireLe = now()->addDays(CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS);

            try {
                return DB::transaction(function () use ($societe, $motif, $canal, $proprietaire, $editeur, $destinataire, $code, $expireLe): CodeConfirmation {
                    $this->annulerLesCodesExpires($societe, $motif);

                    $confirmation = tap((new CodeConfirmation)->forceFill([
                        'siren' => $societe->siren,
                        'motif' => $motif,
                        'canal' => $canal,
                        'destinataire' => $destinataire instanceof AdressePostale ? $destinataire->enTableau() : $destinataire,
                        'code_hache' => $this->codes->hacher($code),
                        'expire_le' => $expireLe,
                        'envoye_le' => now(),
                        'demande_par' => $proprietaire?->getKey(),
                    ]))->save();

                    $objet = config('app.name').' — '.$motif->getLabel();
                    $corps = self::corps($societe, $motif, $code, $expireLe->format('d/m/Y'));
                    $reference = $destinataire instanceof AdressePostale
                        ? $this->envoi->envoyerCourrier($destinataire, $objet, $corps)
                        : $this->envoi->envoyerLre($destinataire['dirigeant'], $destinataire['email'], $objet, $corps);
                    $confirmation->forceFill(['reference_envoi' => $reference])->save();

                    $this->journalAudit->inscrire(ActionAuditee::CodeEnvoye, $proprietaire, null, null, [
                        'motif' => $motif->value,
                        'canal' => $canal->value,
                        'source_adresse' => $destinataire instanceof AdressePostale ? $destinataire->source : null,
                        'reference_envoi' => $reference,
                    ], $confirmation, $editeur);

                    return $confirmation;
                });
            } catch (UniqueConstraintViolationException) {
                $leSien = CodeConfirmation::query()->where('motif', $motif->value)->whereNull('utilise_le')->whereNull('annule_le')->where('expire_le', '>', now())->exists();

                throw new VerificationRefuseeException($proprietaire === null || $leSien ? self::MESSAGE_CODE_EN_COURS : $this->refusSansRienReveler($societe));
            } catch (EnvoiRecommandeIndisponibleException $indisponible) {
                throw new VerificationRefuseeException($indisponible->getMessage());
            }
        });
    }

    /**
     * Le corps du pli : la dénomination et le SIREN, l'objet, le code, son
     * expiration, où le saisir, et à qui signaler une demande qu'on n'a pas
     * faite.
     *
     * @return list<string>
     */
    public static function corps(Societe $societe, MotifCode $motif, string $code, string $expireLe): array
    {
        $application = config('app.name');
        $editeur = config('editeur.email') ?: 'l\'éditeur du logiciel';

        return [
            "{$societe->getFilamentName()} — SIREN {$societe->siren}",
            "Objet : {$motif->getLabel()} sur {$application}.",
            "Votre code de confirmation : {$code}",
            "Ce code est valable jusqu'au {$expireLe} et ne sert qu'une fois : saisissez-le dans l'espace d'administration de votre société sur ".config('app.url').', page « Vérification ».',
            "Si vous n'êtes pas à l'origine de cette demande, ne saisissez pas ce code et signalez-le à l'éditeur : {$editeur}.",
        ];
    }

    /**
     * @throws VerificationRefuseeException
     */
    private function verifierLaDemandeDuProprietaire(User $proprietaire, Societe $societe, MotifCode $motif): void
    {
        DemarrerVerificationIdentiteAction::verifierLaVoie($proprietaire, $societe, $this->evaluateur);

        if ($motif !== MotifCode::Verification) {
            throw new VerificationRefuseeException('Ce courrier ne part qu\'à la demande de l\'éditeur.');
        }

        if (! $this->evaluateur->evaluer($societe)->identiteVerifiee) {
            throw new VerificationRefuseeException(self::MESSAGE_IDENTITE_D_ABORD);
        }

        $plisRecents = CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->where('siren', $societe->siren)
            ->where('envoye_le', '>=', now()->subDays(self::JOURS_DE_LA_PERIODE))
            ->pluck('societe_id');

        if ($plisRecents->count() >= self::PLIS_PAR_SIREN_ET_PAR_PERIODE) {
            throw new VerificationRefuseeException($plisRecents->every(fn (mixed $societeId): bool => (int) $societeId === $societe->getKey()) ? self::MESSAGE_LIMITE : $this->refusSansRienReveler($societe));
        }
    }

    /**
     * Le pli en route, ou les plis récents, viennent d'une autre inscription
     * du même SIREN : le propriétaire lit un refus qui n'en révèle rien, et
     * l'éditeur est alerté.
     */
    private function refusSansRienReveler(Societe $societe): string
    {
        $this->alerteEditeur->alerter($societe, 'Courrier demandé alors qu\'une autre inscription du même SIREN en a déjà reçu', [
            'Le propriétaire de cette société a demandé un courrier recommandé ; le code en route, ou les plis des trente derniers jours, viennent d\'une autre société du même SIREN.',
            'Examinez les deux inscriptions, et envoyez au besoin le courrier : php artisan editeur:courrier:envoyer '.$societe->getKey().' --motif=verification',
        ]);

        return self::MESSAGE_EXAMEN;
    }

    /**
     * Le dirigeant destinataire d'une lettre recommandée électronique, tel
     * que le registre le donne, et l'adresse électronique que le
     * propriétaire donne pour lui.
     *
     * @param  array{rang: int, email: string}|null  $lre
     * @return array{dirigeant: string, email: string, representant: array<string, mixed>}
     *
     * @throws VerificationRefuseeException
     */
    private function dirigeantDestinataire(Societe $societe, ?array $lre): array
    {
        $email = Validator::make(['email' => $lre['email'] ?? null], ['email' => ['required', 'string', 'email', 'max:255']])->passes() ? $lre['email'] : null;

        try {
            $candidat = $email === null ? null : ($this->rapprochement->candidats((string) $societe->siren)[$lre['rang']] ?? null);
        } catch (RneIndisponibleException) {
            throw new VerificationRefuseeException(DemanderAccordDUnDirigeantAction::MESSAGE_RNE_INDISPONIBLE);
        }

        if ($candidat === null) {
            throw new VerificationRefuseeException(self::MESSAGE_DIRIGEANT_INCONNU);
        }

        return [
            'dirigeant' => trim(($candidat['representant']->prenoms[0] ?? '').' '.$candidat['representant']->nom),
            'email' => $email,
            'representant' => $candidat['representant']->enTableau(),
        ];
    }

    /**
     * Annule les codes expirés de ce SIREN et de ce motif, sans les avoir
     * servis : seul un code encore valable reste actif. Écriture limitée à
     * ce SIREN, qui ne lit rien des autres sociétés.
     */
    private function annulerLesCodesExpires(Societe $societe, MotifCode $motif): void
    {
        CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->where('siren', $societe->siren)
            ->where('motif', $motif->value)
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where('expire_le', '<=', now())
            ->get()
            ->each(fn (CodeConfirmation $expire) => $expire->forceFill(['annule_le' => now()])->save());
    }
}
