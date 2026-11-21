<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;
use Illuminate\Support\Facades\DB;

/**
 * Le suivi des courriers recommandés à code (voir
 * EnvoyerCodeDeConfirmationAction), par le rappel signé du prestataire
 * (voir RappelsDesPrestatairesController) ou en l'interrogeant chaque
 * heure (voir app:suivre-envois-recommandes) :
 * - un courrier remis contre signature note sa remise ;
 * - un courrier revenu à l'expéditeur, faute d'avoir été retiré, annule
 *   son code, et le propriétaire d'une inscription qui l'attendait est
 *   prévenu de ce qu'il peut faire alors (voir CourriersDeVerification) :
 *   demander le nouveau courrier, ou à partir de quel jour il le pourra ;
 *   une fois ce nouveau courrier parti, déposer des pièces pour l'éditeur
 *   ou le contacter.
 * Idempotent : un courrier déjà remis, revenu, utilisé ou annulé ne change
 * plus.
 */
class SuivreLesEnvoisAction
{
    public function __construct(
        private readonly ServiceEnvoiRecommande $envoi,
        private readonly CourriersDeVerification $courriers,
    ) {}

    /**
     * Interroge le prestataire sur chaque courrier encore en route.
     *
     * @return int le nombre de courriers dont le suivi a changé
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
     * Note le suivi du courrier de cette référence, retrouvé toutes
     * sociétés confondues, puis dans sa société.
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

        $change = app(TenantContext::class)->runAsSociete($societe, fn (): bool => DB::transaction(function () use ($code, $suivi): bool {
            $verrouille = CodeConfirmation::query()->whereKey($code->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->remis_le !== null || $verrouille->retourne_le !== null || $verrouille->utilise_le !== null || $verrouille->annule_le !== null) {
                return false;
            }

            $verrouille->forceFill($suivi->statut === SuiviDEnvoi::RETOURNE ? ['retourne_le' => now(), 'annule_le' => now()] : ['remis_le' => now()])->save();

            return true;
        }));

        if ($change && $suivi->statut === SuiviDEnvoi::RETOURNE && $code->motif === MotifCode::Verification && $societe->attendSonCode()) {
            app(TenantContext::class)->runAsSociete($societe, fn () => $societe->proprietaire?->notify(new AvisDeVerification(
                $societe,
                'Votre courrier recommandé est revenu',
                'Le courrier recommandé n\'a pas été retiré au bureau de poste et son code ne vaut plus. '.$this->suiteApresUnRetour($societe),
            )));
        }

        return $change;
    }

    /**
     * Ce que le propriétaire peut faire quand le courrier de sa
     * vérification revient.
     */
    private function suiteApresUnRetour(Societe $societe): string
    {
        $possibleLe = $this->courriers->nouvelEnvoiPossibleLe($societe);

        return match (true) {
            $possibleLe === null => 'Déposez des pièces depuis la page de la vérification pour que l\'éditeur traite votre demande, ou contactez-le.',
            $possibleLe->isFuture() => 'Un nouveau courrier pourra être demandé depuis la page de la vérification à partir du '.Calendrier::deParis()->afficher($possibleLe, 'd/m/Y à H:i').'.',
            default => 'Demandez un nouveau courrier depuis la page de la vérification.',
        };
    }
}
