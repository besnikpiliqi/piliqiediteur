<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;

/**
 * L'éditeur ouvre la réclamation d'une société qui a prouvé sa qualité de
 * représentant alors que son SIREN est déjà validé par une autre (voir
 * editeur:reclamation:ouvrir) : la réclamation est notée
 * (reclamation_ouverte_le) et un code du motif « réclamation » part par
 * courrier recommandé à l'adresse officielle de l'entreprise, que le
 * réclamant saisit. La révocation de la société usurpatrice, qui exige ce
 * code, se fait ensuite par sa propre procédure (étape suivante de la
 * feuille de route). Tout se fait en une transaction : si le courrier ne
 * peut pas partir, rien n'est noté.
 */
class OuvrirUneReclamationAction
{
    public const MESSAGE_SANS_RECLAMATION = 'Cette société n\'a pas de réclamation possible : il lui faut une preuve de qualité de représentant, et un SIREN validé par une autre société.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly JournalAudit $journalAudit,
        private readonly EnvoyerCodeDeConfirmationAction $envoyerCode,
    ) {}

    /**
     * @throws VerificationRefuseeException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => DB::transaction(function () use ($societe, $editeur): CodeConfirmation {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->evaluateur->evaluer($verrouillee)->reclamationPossible()) {
                throw new VerificationRefuseeException(self::MESSAGE_SANS_RECLAMATION);
            }

            if ($verrouillee->reclamation_ouverte_le === null) {
                $verrouillee->forceFill(['reclamation_ouverte_le' => now()])->save();
                $this->journalAudit->inscrire(ActionAuditee::ReclamationOuverte, null, null, null, ['motif' => $editeur->justification], $verrouillee, $editeur);
            }

            return $this->envoyerCode->handle($verrouillee, MotifCode::Revocation, CanalEnvoi::RecommandeAr, editeur: $editeur);
        }));
    }
}
