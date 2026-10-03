<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Concerns\WithTenantContext;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Symétrique de SubmitFactureToPdpJob, pour l'e-reporting agrégé B2C
 * plutôt que l'e-invoicing individuel : voir ce job pour le détail du
 * raisonnement (appel réseau externe jamais bloquant, idempotence).
 */
class SubmitClotureCaisseToPdpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithTenantContext;

    public int $tries = 4;

    public array $backoff = [10, 30, 120];

    public function __construct(
        public readonly int $magasinId,
        public readonly int $clotureCaisseId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->clotureCaisseId;
    }

    public function handle(PdpGatewayInterface $gateway): void
    {
        $this->demarrerContexteTenant();

        $cloture = ClotureCaisse::query()->findOrFail($this->clotureCaisseId);

        if ($cloture->transmissionsPdp()->where('direction', 'outbound')->exists()) {
            return;
        }

        $transmission = $cloture->transmissionsPdp()->create([
            'direction' => 'outbound',
            'prestataire_pdp' => config('services.pdp.nom', 'fake'),
            'statut' => StatutTransmissionPdp::Soumise,
            'soumise_le' => now(),
        ]);

        try {
            $resultat = $gateway->soumettreClotureCaisse($cloture);
        } catch (Throwable $exception) {
            $transmission->enregistrerChangementStatut(StatutTransmissionPdp::Rejetee, [
                'erreur' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $transmission->forceFill([
            'transmission_externe_id' => $resultat->transmissionExterneId,
            'format' => $resultat->format,
            'chemin_fichier_archive' => $resultat->cheminFichierArchive,
            'code_erreur' => $resultat->codeErreur,
            'message_erreur' => $resultat->messageErreur,
        ])->save();

        $transmission->enregistrerChangementStatut($resultat->statut);
    }
}
