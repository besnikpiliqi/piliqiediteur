<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\Facturation\Models\Facture;
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
 * L'appel au PDP est un appel réseau externe : il ne doit jamais bloquer
 * ni faire échouer la transaction d'émission de la facture (voir
 * EmettreFactureAction). Dispatché avec ->afterCommit() par le listener
 * qui le déclenche, pour ne démarrer qu'une fois la facture réellement
 * visible en base.
 *
 * ShouldBeUnique : une facture ne doit jamais être soumise deux fois en
 * parallèle si l'event se déclenchait plusieurs fois par erreur.
 */
class SubmitFactureToPdpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithTenantContext;

    public int $tries = 4;

    public array $backoff = [10, 30, 120];

    public function __construct(
        public readonly int $magasinId,
        public readonly int $factureId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->factureId;
    }

    public function handle(PdpGatewayInterface $gateway): void
    {
        $this->demarrerContexteTenant();

        $facture = Facture::query()->findOrFail($this->factureId);

        // Idempotence : si une transmission sortante existe déjà (retry
        // après un crash du worker post-traitement, ou double dispatch),
        // on ne resoumet pas.
        if ($facture->transmissionsPdp()->where('direction', 'outbound')->exists()) {
            return;
        }

        $transmission = $facture->transmissionsPdp()->create([
            'direction' => 'outbound',
            'prestataire_pdp' => config('services.pdp.nom', 'fake'),
            'statut' => StatutTransmissionPdp::Soumise,
            'soumise_le' => now(),
        ]);

        try {
            $resultat = $gateway->soumettreFacture($facture);
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
