<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le propriétaire saisit le code reçu par courrier recommandé, ou par
 * lettre recommandée électronique une fois acceptée (voir
 * EnvoyerCodeDeConfirmationAction) : la saisie se compare à l'empreinte de
 * chacun des codes actifs de sa société (celui de la vérification, et
 * celui d'une réclamation, peuvent l'être ensemble), sous le verrou de
 * leurs lignes. Juste, elle sert le code qu'elle désigne, une seule fois,
 * et le contrôle de l'adresse s'inscrit au registre des vérifications
 * (C), avec le motif de ce code ; la société est validée si elle peut
 * l'être (voir ValiderLaSocieteAction). Fausse, elle compte un essai sur
 * chacun : au cinquième, un code s'annule et le signal essais_repetes
 * bloque les voies automatiques jusqu'à la décision de l'éditeur. Un code
 * expiré, annulé ou déjà utilisé ne sert plus ; celui d'une lettre
 * électronique pas encore acceptée ne se compare pas. Cinq saisies par
 * heure au plus, en plus du compteur.
 */
class SaisirCodeDeConfirmationAction
{
    public const SAISIES_PAR_HEURE = 5;

    public const MESSAGE_AUCUN_CODE = 'Aucun code n\'est en cours pour votre société : demandez un nouveau courrier.';

    public const MESSAGE_CODE_FAUX = 'Ce code n\'est pas le bon.';

    public const MESSAGE_CODE_ANNULE = 'Cinq codes faux ont été saisis : ce code est annulé, et l\'éditeur doit examiner votre dossier.';

    public const MESSAGE_LRE_NON_ACCEPTEE = 'Le code d\'une lettre recommandée électronique ne se saisit qu\'une fois la lettre acceptée par le dirigeant.';

    public const MESSAGE_TROP_DE_SAISIES = 'Trop de saisies en une heure : réessayez plus tard.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly CodesDeConfirmation $codes,
        private readonly RegistreDesVerifications $registre,
        private readonly JournalAudit $journalAudit,
        private readonly ValiderLaSocieteAction $validerLaSociete,
    ) {}

    /**
     * @return bool le code était-il juste ?
     *
     * @throws VerificationRefuseeException quand la saisie est refusée sans être comptée, ou que le code vient d'être annulé
     */
    public function handle(User $proprietaire, Societe $societe, string $saisie): bool
    {
        $juste = app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe, $saisie): bool {
            DemarrerVerificationIdentiteAction::verifierLaVoie($proprietaire, $societe, $this->evaluateur);

            if (! RateLimiter::attempt("saisie-code:{$societe->getKey()}:{$proprietaire->getKey()}", self::SAISIES_PAR_HEURE, static fn (): bool => true, 3600)) {
                throw new VerificationRefuseeException(self::MESSAGE_TROP_DE_SAISIES);
            }

            [$juste, $annule] = DB::transaction(fn (): array => $this->comparer($proprietaire, $societe, $saisie));

            if ($annule) {
                throw new VerificationRefuseeException(self::MESSAGE_CODE_ANNULE);
            }

            return $juste;
        });

        if ($juste) {
            $this->validerLaSociete->siPossible($societe->fresh());
        }

        return $juste;
    }

    /**
     * @return array{0: bool, 1: bool} le code est-il juste, et un code vient-il d'être annulé ?
     *
     * @throws VerificationRefuseeException
     */
    private function comparer(User $proprietaire, Societe $societe, string $saisie): array
    {
        $actifs = CodeConfirmation::query()
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where('expire_le', '>', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($actifs->isEmpty()) {
            throw new VerificationRefuseeException(self::MESSAGE_AUCUN_CODE);
        }

        $saisissables = $actifs->reject(fn (CodeConfirmation $code): bool => $code->canal === CanalEnvoi::LreQualifiee && $code->remis_le === null);

        if ($saisissables->isEmpty()) {
            throw new VerificationRefuseeException(self::MESSAGE_LRE_NON_ACCEPTEE);
        }

        $juste = $saisissables->first(fn (CodeConfirmation $code): bool => $this->codes->correspond($saisie, $code->code_hache));

        if ($juste === null) {
            $annules = $saisissables->filter(fn (CodeConfirmation $code): bool => $this->compterUnEssaiFaux($proprietaire, $code));

            if ($annules->isNotEmpty()) {
                $this->registre->noter($societe, TypeVerification::Signal, ResultatVerification::AExaminer, ['constat' => CodeConfirmation::ESSAIS_PERMIS.' codes faux'], signal: SignalDeRisque::EssaisRepetes);
            }

            return [false, $annules->isNotEmpty()];
        }

        $juste->forceFill(['utilise_le' => now()])->save();
        $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $proprietaire, null, null, ['motif' => $juste->motif->value, 'resultat' => 'juste', 'tentatives' => $juste->tentatives + 1], $juste);
        $this->registre->noter($societe, $juste->canal->typeDeVerification(), ResultatVerification::Concluante, [
            'siren' => $juste->siren,
            'envoi_id' => $juste->getKey(),
            'motif' => $juste->motif->value,
            ...($juste->canal === CanalEnvoi::RecommandeAr
                ? ['source_adresse' => $juste->destinataire['source'] ?? null]
                : ['dirigeant' => $juste->destinataire['dirigeant'] ?? null]),
            'reference_envoi' => $juste->reference_envoi,
        ], $proprietaire, fournisseur: 'recommande', reference: $juste->reference_envoi);

        return [true, false];
    }

    /**
     * Compte un essai faux sur ce code, annulé au cinquième.
     *
     * @return bool le code vient-il d'être annulé ?
     */
    private function compterUnEssaiFaux(User $proprietaire, CodeConfirmation $code): bool
    {
        $tentatives = $code->tentatives + 1;
        $annule = $tentatives >= CodeConfirmation::ESSAIS_PERMIS;
        $code->forceFill(['tentatives' => $tentatives, ...($annule ? ['annule_le' => now()] : [])])->save();
        $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $proprietaire, null, null, ['motif' => $code->motif->value, 'resultat' => 'faux', 'tentatives' => $tentatives], $code);

        return $annule;
    }
}
