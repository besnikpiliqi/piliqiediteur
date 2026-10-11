<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;

/**
 * L'éditeur conclut l'examen des pièces déposées par le propriétaire
 * (B4, voir editeur:societe:valider) : la décision s'inscrit au registre
 * des vérifications, avec les seuls types des pièces examinées, jamais
 * leur contenu ; les fichiers s'effacent aussitôt ; et, s'il n'est pas
 * déjà en route ou saisi, le code du courrier recommandé part à l'adresse
 * officielle (C), car des pièces, publiques ou falsifiables, ne valent
 * jamais seules. La société est validée d'elle-même à la saisie du code,
 * si son évaluation le permet. Aucun signal n'est levé par cet examen.
 * Tout se fait en une transaction : si le courrier ne peut pas partir,
 * rien n'est inscrit et les pièces restent, pour un nouvel examen.
 */
class ExaminerLesPiecesAction
{
    public const MESSAGE_AUCUNE_PIECE = 'Aucune pièce n\'attend d\'examen pour cette société.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly RegistreDesVerifications $registre,
        private readonly PiecesDeVerification $pieces,
        private readonly EnvoyerCodeDeConfirmationAction $envoyerCode,
        private readonly ValiderLaSocieteAction $validerLaSociete,
    ) {}

    /**
     * @return CodeConfirmation|null le code qui vient de partir, null s'il était déjà en route ou saisi
     *
     * @throws VerificationRefuseeException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): ?CodeConfirmation
    {
        $code = app(TenantContext::class)->runAsSociete($societe, fn (): ?CodeConfirmation => DB::transaction(function () use ($societe, $editeur): ?CodeConfirmation {
            if ($societe->estValidee() || $societe->estRefusee()) {
                throw new VerificationRefuseeException('Cette société est déjà validée ou refusée.');
            }

            $examinees = PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->lockForUpdate()->get();

            if ($examinees->isEmpty()) {
                throw new VerificationRefuseeException(self::MESSAGE_AUCUNE_PIECE);
            }

            $this->registre->noter($societe, TypeVerification::Manuelle, ResultatVerification::Concluante, [
                'pieces' => $examinees->map(fn (PieceVerification $piece): string => $piece->type->value)->values()->all(),
                'decision' => 'qualite_de_representant_examinee_sur_pieces',
            ], beneficiaire: $societe->proprietaire()->first(), editeur: $editeur);
            $this->pieces->effacerToutes($societe, PiecesDeVerification::MOTIF_DECISION, $editeur);

            $courrierEnRoute = CodeConfirmation::query()->where('motif', MotifCode::Verification->value)->whereNull('annule_le')->where(fn ($codes) => $codes->whereNotNull('utilise_le')->orWhere('expire_le', '>', now()))->exists();

            return $courrierEnRoute || $this->evaluateur->evaluer($societe)->adresseControlee
                ? null
                : $this->envoyerCode->handle($societe, MotifCode::Verification, CanalEnvoi::RecommandeAr, editeur: $editeur);
        }));

        $this->validerLaSociete->siPossible($societe->fresh(), $editeur);

        return $code;
    }
}
