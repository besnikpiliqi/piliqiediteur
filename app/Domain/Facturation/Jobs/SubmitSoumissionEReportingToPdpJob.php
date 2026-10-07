<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\Facturation\Models\SoumissionEReporting;
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
 * Transmet une SOUMISSION agrégée sur toute une période d'e-reporting,
 * jamais une clôture quotidienne isolée. Voir SubmitFactureToPdpJob pour le détail du
 * raisonnement (appel réseau externe jamais bloquant, idempotence).
 */
class SubmitSoumissionEReportingToPdpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithTenantContext;

    public int $tries = 4;

    public array $backoff = [10, 30, 120];

    public function __construct(
        public readonly int $magasinId,
        public readonly int $soumissionEReportingId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->soumissionEReportingId;
    }

    public function handle(PdpGatewayInterface $gateway): void
    {
        $this->demarrerContexteTenant();

        $soumission = SoumissionEReporting::query()->findOrFail($this->soumissionEReportingId);

        if ($soumission->transmissionsPdp()->where('direction', 'outbound')->exists()) {
            return;
        }

        $transmission = $soumission->transmissionsPdp()->create([
            'direction' => 'outbound',
            'prestataire_pdp' => config('services.pdp.nom', 'fake'),
            'statut' => StatutTransmissionPdp::Soumise,
            'soumise_le' => now(),
        ]);

        try {
            $resultat = $gateway->soumettreEReporting($soumission);
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
