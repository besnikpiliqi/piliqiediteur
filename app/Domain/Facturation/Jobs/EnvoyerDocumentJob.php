<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Concerns\WithTenantContext;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Envoie l'e-mail d'un EnvoiDocument, son PDF en pièce jointe, puis
 * constate le résultat. Dispatché avec ->afterCommit() par
 * EnvoyerDocumentAction : il ne démarre qu'une fois l'envoi visible en
 * base, et un serveur d'envoi lent ou indisponible ne retarde jamais le
 * geste du magasin.
 *
 * Le document est relu au moment de partir : annulé entre-temps, il ne
 * part pas ; une relance ne part que si la facture est toujours échue et
 * impayée, et son rappel dit ce qu'il reste à payer à cet instant.
 *
 * Idempotent : un envoi déjà envoyé ne repart jamais, même si le job est
 * rejoué. ShouldBeUnique : jamais deux exécutions du même envoi en
 * parallèle. Le PDF est généré à chaque tentative, depuis les données
 * figées du document (voir FacturePdfGenerator).
 */
class EnvoyerDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithTenantContext;

    public int $tries = 3;

    /**
     * Un serveur d'envoi indisponible l'est souvent quelques minutes.
     *
     * @var list<int>
     */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly int $magasinId,
        public readonly int $envoiId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->envoiId;
    }

    public function handle(): void
    {
        $this->demarrerContexteTenant();

        $envoi = EnvoiDocument::query()->findOrFail($this->envoiId);

        if ($envoi->statut === StatutEnvoi::Envoye) {
            return;
        }

        $document = $envoi->document ?? throw new ModelNotFoundException("Le document de l'envoi [{$envoi->id}] n'existe plus.");

        if (($sansObjet = $this->raisonDeNePasPartir($envoi, $document)) !== null) {
            $this->echouer($envoi, $sansObjet);

            return;
        }

        if ($document instanceof Facture && $envoi->nature === NatureEnvoi::Relance) {
            $envoi->forceFill(['message' => $document->messageDeRelance()])->save();
        }

        $generateur = match (true) {
            $document instanceof Facture => app(FacturePdfGenerator::class),
            $document instanceof Devis => app(DevisPdfGenerator::class),
            $document instanceof Avoir => app(AvoirPdfGenerator::class),
        };

        try {
            Mail::to($envoi->destinataire)->send(new DocumentMail(
                $envoi,
                $generateur->generer($document)->output(),
                $generateur->nomFichier($document),
            ));
        } catch (RfcComplianceException) {
            // Une adresse que le composant d'envoi refuse : aucune nouvelle
            // tentative n'y changera rien.
            $this->echouer($envoi, "Une adresse e-mail n'est pas valide (celle du destinataire, ou celle du magasin, à laquelle le client répond) : corrigez-la, puis {$this->consigne($envoi)}.");

            return;
        }

        $envoi->forceFill([
            'statut' => StatutEnvoi::Envoye,
            'envoye_le' => now(),
            'erreur' => null,
        ])->save();
    }

    /**
     * Toutes les tentatives ont échoué : l'envoi passe en échec, avec une
     * explication pour le magasin -- jamais le message technique du
     * serveur d'envoi, qui peut contenir des identifiants ou des adresses
     * (il reste dans la table des jobs échoués) --, et celui qui l'a
     * demandé en est prévenu.
     */
    public function failed(?Throwable $exception): void
    {
        $this->demarrerContexteTenant();

        $envoi = EnvoiDocument::query()->find($this->envoiId);

        if ($envoi === null || $envoi->statut === StatutEnvoi::Envoye) {
            return;
        }

        $this->echouer($envoi, match (true) {
            $exception instanceof TransportExceptionInterface => "Le serveur d'envoi des e-mails n'a pas accepté le message : vérifiez l'adresse du destinataire, puis {$this->consigne($envoi)}.",
            $exception instanceof ModelNotFoundException => 'Le document n\'existe plus.',
            default => "Une erreur inattendue a empêché l'envoi : {$this->consigne($envoi)}, et contactez le support si elle se répète.",
        });
    }

    /**
     * Ce qui, depuis la programmation de l'envoi, l'a rendu sans objet.
     */
    private function raisonDeNePasPartir(EnvoiDocument $envoi, Model&DocumentEnvoyable $document): ?string
    {
        return match (true) {
            ! $document->peutEtreEnvoye() => "Le document est passé au statut « {$document->statut->getLabel()} » entre-temps : l'e-mail n'est pas parti.",
            $document instanceof Facture && $envoi->nature === NatureEnvoi::Relance && ! $document->estEchueEtImpayee() => 'La facture a été réglée ou soldée par un avoir entre-temps : la relance n\'est pas partie.',
            default => null,
        };
    }

    /**
     * Comment le faire repartir : une relance en échec repart à la relance
     * suivante de la facture (voir EnvoyerDocumentAction::relancer()).
     */
    private function consigne(EnvoiDocument $envoi): string
    {
        return $envoi->nature === NatureEnvoi::Relance
            ? "relancez la facture (la relance n°{$envoi->niveau_relance} repartira)"
            : 'renvoyez-le';
    }

    private function echouer(EnvoiDocument $envoi, string $erreur): void
    {
        $envoi->forceFill(['statut' => StatutEnvoi::Echec, 'erreur' => $erreur])->save();

        Notification::send($this->aPrevenir($envoi), new EnvoiDocumentEchoue($envoi));
    }

    /**
     * Celui qui a demandé l'envoi, s'il travaille toujours pour le
     * magasin ; sinon -- relance automatique, membre parti entre-temps --
     * le propriétaire du magasin.
     *
     * @return Collection<int, User>
     */
    private function aPrevenir(EnvoiDocument $envoi): Collection
    {
        $expediteur = $envoi->envoyePar;

        if ($expediteur !== null && $expediteur->appartientA($envoi->magasin)) {
            return collect([$expediteur]);
        }

        return $envoi->magasin->proprietaires()->get();
    }
}
