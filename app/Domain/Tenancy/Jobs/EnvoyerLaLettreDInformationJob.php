<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Concerns\WithSocieteContext;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\AdresseOfficielle;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\EnvoiRecommandeIndisponibleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * La lettre simple qui informe le siège de l'entreprise de chaque
 * validation de la société (voir ValiderLaSocieteAction) : « votre
 * entreprise vient d'être validée sur PILIQI par {personne vérifiée} ; si ce
 * n'est pas vous, contactez l'éditeur ». Pour quelques centimes, le vrai
 * dirigeant apprend toute usurpation. Elle part à l'adresse officielle lue
 * le jour même (voir AdresseOfficielle), après la validation, sans jamais
 * l'annuler : un envoi impossible se reprend plus tard, et la lettre
 * partie s'inscrit au registre des vérifications. Elle ne part qu'une
 * fois par validation.
 */
class EnvoyerLaLettreDInformationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, WithSocieteContext;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    public function __construct(
        public readonly int $societeId,
        public readonly string $personneVerifiee,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->societeId;
    }

    /**
     * @throws EnvoiRecommandeIndisponibleException
     * @throws VerificationRefuseeException
     */
    public function handle(AdresseOfficielle $adresseOfficielle, ServiceEnvoiRecommande $envoi, RegistreDesVerifications $registre): void
    {
        $societe = $this->demarrerContexteSociete();

        if (VerificationSociete::query()->where('type', TypeVerification::LettreInformation->value)->where('created_at', '>=', $societe->validee_le)->exists()) {
            return;
        }

        $adresse = $adresseOfficielle->du($societe);
        $reference = $envoi->envoyerLettreSimple($adresse, 'Votre entreprise vient d\'être validée sur '.config('app.name'), self::corps($societe->siren, $this->personneVerifiee));

        $registre->noter($societe, TypeVerification::LettreInformation, ResultatVerification::Concluante, [
            'reference_envoi' => $reference,
            'personne_verifiee' => $this->personneVerifiee,
            'source_adresse' => $adresse->source,
        ], fournisseur: 'recommande', reference: $reference);
    }

    /**
     * @return list<string>
     */
    public static function corps(string $siren, string $personneVerifiee): array
    {
        $application = config('app.name');
        $editeur = config('editeur.email') ?: 'l\'éditeur du logiciel';

        return [
            'Madame, Monsieur,',
            "Votre entreprise (SIREN {$siren}) vient d'être validée sur le logiciel {$application}, à la demande de {$personneVerifiee}, dont l'identité ou la qualité de représentant a été vérifiée.",
            "Si vous n'êtes pas à l'origine de cette inscription, ou si cette personne n'a pas le droit d'agir pour votre entreprise, signalez-le sans attendre à l'éditeur : {$editeur}.",
            'Cette lettre est envoyée à chaque validation : aucune réponse n\'est attendue si vous êtes à l\'origine de la demande.',
        ];
    }
}
