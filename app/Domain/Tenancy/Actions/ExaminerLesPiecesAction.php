<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;

/**
 * L'éditeur traite à la main le cas d'une société dont les courriers ne
 * sont pas parvenus (voir editeur:societe:examiner), après avoir lu les
 * pièces déposées (voir editeur:societe:pieces) : sa décision s'inscrit au
 * registre des vérifications, avec les seuls types des pièces examinées,
 * jamais leur contenu, sa justification et le SIREN de la société ; les
 * fichiers s'effacent aussitôt ; et un nouveau courrier recommandé part,
 * au nom du représentant légal, à l'adresse officielle du siège (voir
 * EnvoyerCodeDeConfirmationAction::parLEditeur()) : seul son code valide
 * la société, des pièces ne la valident jamais. Tout se fait en une
 * transaction : si le courrier ne peut pas partir, rien n'est inscrit et
 * les pièces restent, pour un nouvel examen. Pour refuser, l'éditeur clôt
 * l'inscription (voir editeur:societe:refuser).
 */
class ExaminerLesPiecesAction
{
    public const MESSAGE_AUCUNE_PIECE = 'Aucune pièce n\'attend d\'examen pour cette société.';

    public function __construct(
        private readonly RegistreDesVerifications $registre,
        private readonly PiecesDeVerification $pieces,
        private readonly EnvoyerCodeDeConfirmationAction $envoyerCode,
    ) {}

    /**
     * @return CodeConfirmation le courrier qui vient de partir
     *
     * @throws VerificationRefuseeException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => DB::transaction(function () use ($societe, $editeur): CodeConfirmation {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

            if (! $verrouillee->attendSonCode()) {
                throw new VerificationRefuseeException(CloreLInscriptionAction::MESSAGE_N_ATTEND_PLUS);
            }

            $examinees = PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->lockForUpdate()->get();

            if ($examinees->isEmpty()) {
                throw new VerificationRefuseeException(self::MESSAGE_AUCUNE_PIECE);
            }

            $this->registre->noter($verrouillee, TypeVerification::Manuelle, ResultatVerification::Concluante, [
                'siren' => $verrouillee->siren,
                'pieces' => $examinees->map(fn (PieceVerification $piece): string => $piece->type->value)->values()->all(),
                'decision' => 'nouveau_courrier_apres_examen_des_pieces',
                'justification' => $editeur->justification,
            ], editeur: $editeur);
            $this->pieces->effacerToutes($verrouillee, PiecesDeVerification::MOTIF_DECISION, $editeur);

            return $this->envoyerCode->parLEditeur($verrouillee, MotifCode::Verification, $editeur);
        }));
    }
}
