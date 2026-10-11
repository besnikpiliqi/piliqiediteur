<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * L'éditeur refuse une société en cours de vérification (voir
 * editeur:societe:refuser) : refus définitif (refusee_le), qui met tous ses
 * magasins en lecture seule pour toujours ; ses pièces s'effacent, son
 * magasin de démonstration se ferme, et son propriétaire est prévenu. Le
 * refus s'inscrit au journal d'audit avec la justification de l'éditeur.
 */
class RefuserLaSocieteAction
{
    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly PiecesDeVerification $pieces,
        private readonly FermerLaDemonstrationAction $fermerLaDemonstration,
    ) {}

    /**
     * @throws VerificationRefuseeException
     */
    public function handle(Societe $societe, InterventionDeLEditeur $editeur): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $editeur): void {
            DB::transaction(function () use ($societe, $editeur): void {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

                if ($verrouillee->estValidee() || $verrouillee->estRefusee()) {
                    throw new VerificationRefuseeException('Cette société est déjà validée ou refusée.');
                }

                $verrouillee->forceFill(['refusee_le' => now()])->save();
                $this->journalAudit->inscrire(ActionAuditee::SocieteRefusee, null, null, null, ['motif' => $editeur->justification], $verrouillee, $editeur);
                $this->pieces->effacerToutes($verrouillee, PiecesDeVerification::MOTIF_DECISION, $editeur);
            });

            $demonstration = $societe->demonstration()->where('statut', StatutMagasin::Actif->value)->first();

            if ($demonstration !== null) {
                try {
                    $this->fermerLaDemonstration->handle($demonstration);
                } catch (Throwable $erreur) {
                    report($erreur);
                }
            }

            $societe->proprietaire?->notify(new AvisDeVerification($societe, 'L\'inscription de votre société est refusée', 'Vos magasins restent consultables, en lecture seule. Pour toute question, contactez l\'éditeur.', AvisDeVerification::REFUS));
        });
    }
}
