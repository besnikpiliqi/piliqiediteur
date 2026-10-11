<?php

namespace App\Domain\Facturation\Jobs;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutDevis;
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
 * Le document est relu au moment de partir : une relance ne part que si
 * la facture est toujours échue et impayée, et son rappel dit ce qu'il
 * reste à payer à cet instant ; l'objet de l'e-mail se relit aussi, sur
 * l'émetteur que l'e-mail présente et que son PDF imprime (voir
 * DocumentMail), quoi qu'ait changé le magasin depuis la demande. Il
 * existe toujours : une facture ou un avoir émis le reste pour toujours
 * (une correction est un avoir à part), et un devis, numéroté dès son
 * premier envoi, ne se supprime plus (voir Devis::estSupprimable()).
 *
 * Un devis, lui, se modifie après son envoi (voir ModifierDevisAction) :
 * modifié depuis la demande d'envoi, qu'il soit encore en brouillon ou
 * déjà reparti dans sa nouvelle version, il ne part pas avec cet e-mail,
 * écrit pour une version que le client ne recevrait pas. Un magasin qui
 * ne peut plus vendre au moment de l'envoi n'écrit pas à ses clients (voir
 * Magasin::peutVendre()) : suspendu ou fermé entre-temps, ou sa société
 * suspendue, résiliée ou refusée. Dans ces cas, l'envoi échoue sans
 * partir, et le magasin sait pourquoi.
 *
 * Idempotent : un envoi déjà envoyé ne repart jamais, même si le job est
 * rejoué. ShouldBeUnique : jamais deux exécutions du même envoi en
 * parallèle. Le PDF est généré à chaque tentative depuis le document tel
 * qu'il est enregistré : une facture ou un avoir figés à leur émission,
 * avec l'émetteur qu'ils ont signé, quoi que soit devenu le magasin (voir
 * FacturePdfGenerator et FigeSonEmetteur), un devis dans la version émise
 * que cet e-mail annonce. Une facture ou un avoir numéroté sans
 * enregistrement signé, déjà refusé à la demande (voir
 * EnvoyerDocumentAction), ne part pas davantage ici : l'envoi échoue
 * aussitôt, sans nouvelle tentative, et l'incident est signalé au journal
 * de l'application.
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

        if (! $envoi->magasin->peutVendre()) {
            $motif = $envoi->magasin->motifDeLectureSeule('Le magasin');
            $this->echouer($envoi, $motif === null
                ? "{$envoi->magasin->refusDeVente()} L'e-mail n'est pas parti."
                : "{$motif} : il n'écrit plus à ses clients, l'e-mail n'est pas parti.");

            return;
        }

        $document = $envoi->document;

        if ($document instanceof Devis && $this->devisModifieDepuisLaDemande($document, $envoi)) {
            $this->echouer($envoi, 'Le devis a été modifié depuis cette demande d\'envoi : l\'e-mail n\'est pas parti, envoyez sa nouvelle version à votre client si ce n\'est pas déjà fait.');

            return;
        }

        if ($document instanceof Facture && $envoi->nature === NatureEnvoi::Relance) {
            if (! $document->estEchueEtImpayee()) {
                $this->echouer($envoi, 'La facture a été réglée ou soldée par un avoir entre-temps : la relance n\'est pas partie.');

                return;
            }

            $envoi->forceFill(['message' => $document->messageDeRelance()])->save();
        }

        $generateur = match (true) {
            $document instanceof Facture => app(FacturePdfGenerator::class),
            $document instanceof Devis => app(DevisPdfGenerator::class),
            $document instanceof Avoir => app(AvoirPdfGenerator::class),
        };

        try {
            $pdf = $generateur->generer($document)->output();
        } catch (DocumentSansEnregistrementSigneException $exception) {
            // Le document ne se reproduit pas tel qu'il a été émis : aucune
            // nouvelle tentative n'y changera rien.
            report($exception);
            $this->echouer($envoi, "{$exception->getMessage()} L'e-mail n'est pas parti.");

            return;
        }

        $envoi->forceFill(['objet' => EnvoiDocument::objetPour($document, $envoi->niveau_relance)])->save();

        try {
            Mail::to($envoi->destinataire)->send(new DocumentMail($envoi, $pdf, $generateur->nomFichier($document)));
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
            default => "Une erreur inattendue a empêché l'envoi : {$this->consigne($envoi)}, et contactez le support si elle se répète.",
        });
    }

    /**
     * Repassé en brouillon par une modification, ou reparti depuis dans sa
     * nouvelle version : un devis ne repart qu'après avoir été modifié
     * (voir StatutDevis::transitionsAutorisees()), et chaque départ
     * remplace sa date d'émission (voir EmettreDevisAction). Un envoi
     * demandé pour la version en cours est noté à cette date ou après.
     */
    private function devisModifieDepuisLaDemande(Devis $devis, EnvoiDocument $envoi): bool
    {
        return $devis->statut === StatutDevis::Brouillon || $devis->date_emission->gt($envoi->created_at);
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
     * Celui qui a demandé l'envoi, s'il peut encore ouvrir le magasin ;
     * sinon -- relance automatique, membre parti entre-temps -- le
     * propriétaire de la société.
     *
     * @return Collection<int, User>
     */
    private function aPrevenir(EnvoiDocument $envoi): Collection
    {
        $expediteur = $envoi->envoyePar;

        if ($expediteur !== null && $expediteur->peutOuvrir($envoi->magasin)) {
            return collect([$expediteur]);
        }

        return collect([$envoi->magasin->societe->proprietaire]);
    }
}
