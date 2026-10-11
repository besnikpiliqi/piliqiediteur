<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;
use App\Integrations\Rne\RepresentantRne;
use Illuminate\Support\Facades\DB;

/**
 * Le suivi des plis à code (voir EnvoyerCodeDeConfirmationAction), par le
 * rappel signé du prestataire (voir RappelsDesPrestatairesController) ou
 * en l'interrogeant chaque heure (voir app:suivre-envois-recommandes) :
 * - un courrier remis contre signature note sa remise ;
 * - une lettre électronique acceptée ne note sa remise que si le
 *   prestataire qualifié a établi l'identité de la personne qui l'a
 *   acceptée (statut concluant), et que cette personne est le dirigeant
 *   visé (voir RapprochementDesDirigeants::correspond()) : sinon son code
 *   s'annule ;
 * - un pli revenu à l'expéditeur annule son code et constate le signal
 *   adresse_contradictoire.
 * Idempotent : un pli déjà remis, revenu, utilisé ou annulé ne change
 * plus.
 */
class SuivreLesEnvoisAction
{
    public function __construct(
        private readonly ServiceEnvoiRecommande $envoi,
        private readonly RegistreDesVerifications $registre,
    ) {}

    /**
     * Interroge le prestataire sur chaque pli encore en route.
     *
     * @return int le nombre de plis dont le suivi a changé
     */
    public function suivreTout(): int
    {
        return CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->whereNotNull('reference_envoi')
            ->whereNull('remis_le')
            ->whereNull('retourne_le')
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where('expire_le', '>', now())
            ->orderBy('id')
            ->get()
            ->filter(fn (CodeConfirmation $code): bool => $this->noter((string) $code->reference_envoi, $this->envoi->suivre((string) $code->reference_envoi)))
            ->count();
    }

    /**
     * Note le suivi du pli de cette référence, retrouvé toutes sociétés
     * confondues, puis dans sa société.
     *
     * @return bool le suivi a-t-il changé ?
     */
    public function noter(string $reference, SuiviDEnvoi $suivi): bool
    {
        $code = CodeConfirmation::withoutGlobalScope(SocieteScope::class)->where('reference_envoi', $reference)->first();

        if ($code === null || $suivi->statut === SuiviDEnvoi::DEPOSE) {
            return false;
        }

        $societe = Societe::query()->findOrFail($code->societe_id);

        return app(TenantContext::class)->runAsSociete($societe, fn (): bool => DB::transaction(function () use ($societe, $code, $suivi): bool {
            $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->remis_le !== null || $verrouille->retourne_le !== null || $verrouille->utilise_le !== null || $verrouille->annule_le !== null) {
                return false;
            }

            if ($suivi->statut === SuiviDEnvoi::RETOURNE) {
                $verrouille->forceFill(['retourne_le' => now(), 'annule_le' => now()])->save();
                $this->registre->noter($societe, TypeVerification::Signal, ResultatVerification::AExaminer, ['constat' => 'courrier revenu à l\'expéditeur'], signal: SignalDeRisque::AdresseContradictoire, fournisseur: 'recommande', reference: $verrouille->reference_envoi);

                return true;
            }

            $identite = $suivi->identiteDuDestinataire;
            $accepteParLeDirigeant = $verrouille->canal !== CanalEnvoi::LreQualifiee
                || ($identite !== null
                    && $identite->statut === ResultatVerification::Concluante
                    && RapprochementDesDirigeants::correspond($identite, RepresentantRne::depuisTableau($verrouille->destinataire['representant'])));

            $verrouille->forceFill($accepteParLeDirigeant ? ['remis_le' => now()] : ['annule_le' => now()])->save();

            return true;
        }));
    }
}
