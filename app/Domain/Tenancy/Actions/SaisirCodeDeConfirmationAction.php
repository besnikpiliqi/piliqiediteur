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
 * EnvoyerCodeDeConfirmationAction) : le code actif de sa société, comparé
 * à son empreinte sous le verrou de sa ligne. Juste, il sert, une seule
 * fois, et le contrôle de l'adresse s'inscrit au registre des
 * vérifications (C) ; la société est validée si elle peut l'être (voir
 * ValiderLaSocieteAction). Faux, il compte un essai : au cinquième, le
 * code s'annule et le signal essais_repetes bloque les voies
 * automatiques jusqu'à la décision de l'éditeur. Expiré, annulé ou déjà
 * utilisé, il ne sert plus. Cinq saisies par heure au plus, en plus du
 * compteur.
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
     * @return array{0: bool, 1: bool} le code est-il juste, et vient-il d'être annulé ?
     *
     * @throws VerificationRefuseeException
     */
    private function comparer(User $proprietaire, Societe $societe, string $saisie): array
    {
        $code = CodeConfirmation::query()
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where('expire_le', '>', now())
            ->lockForUpdate()
            ->first() ?? throw new VerificationRefuseeException(self::MESSAGE_AUCUN_CODE);

        if ($code->canal === CanalEnvoi::LreQualifiee && $code->remis_le === null) {
            throw new VerificationRefuseeException(self::MESSAGE_LRE_NON_ACCEPTEE);
        }

        if (! $this->codes->correspond($saisie, $code->code_hache)) {
            $tentatives = $code->tentatives + 1;
            $annule = $tentatives >= CodeConfirmation::ESSAIS_PERMIS;
            $code->forceFill(['tentatives' => $tentatives, ...($annule ? ['annule_le' => now()] : [])])->save();
            $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $proprietaire, null, null, ['motif' => $code->motif->value, 'resultat' => 'faux', 'tentatives' => $tentatives], $code);

            if ($annule) {
                $this->registre->noter($societe, TypeVerification::Signal, ResultatVerification::AExaminer, ['constat' => CodeConfirmation::ESSAIS_PERMIS.' codes faux'], signal: SignalDeRisque::EssaisRepetes);
            }

            return [false, $annule];
        }

        $code->forceFill(['utilise_le' => now()])->save();
        $this->journalAudit->inscrire(ActionAuditee::CodeSaisi, $proprietaire, null, null, ['motif' => $code->motif->value, 'resultat' => 'juste', 'tentatives' => $code->tentatives + 1], $code);
        $this->registre->noter($societe, $code->canal->typeDeVerification(), ResultatVerification::Concluante, [
            'envoi_id' => $code->getKey(),
            'motif' => $code->motif->value,
            ...($code->canal === CanalEnvoi::RecommandeAr
                ? ['source_adresse' => $code->destinataire['source'] ?? null]
                : ['dirigeant' => $code->destinataire['dirigeant'] ?? null]),
            'reference_envoi' => $code->reference_envoi,
        ], $proprietaire, fournisseur: 'recommande', reference: $code->reference_envoi);

        return [true, false];
    }
}
