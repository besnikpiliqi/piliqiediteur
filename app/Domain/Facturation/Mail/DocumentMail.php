<?php

namespace App\Domain\Facturation\Mail;

use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'e-mail qui porte un devis, une facture ou un avoir à son client, PDF
 * en pièce jointe (voir EnvoyerDocumentJob). Il part de l'adresse du
 * logiciel, au nom du magasin, et les réponses vont au magasin.
 *
 * Jamais mis en file lui-même : c'est EnvoyerDocumentJob qui l'est, et qui
 * constate son envoi.
 */
class DocumentMail extends Mailable
{
    public function __construct(
        public readonly EnvoiDocument $envoi,
        private readonly string $pdf,
        private readonly string $nomFichier,
    ) {}

    public function envelope(): Envelope
    {
        $magasin = $this->magasin();

        return new Envelope(
            from: new Address(config('mail.from.address'), $magasin->nom),
            replyTo: $magasin->email ? [new Address($magasin->email, $magasin->nom)] : [],
            subject: $this->envoi->objet,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.document',
            with: [
                'magasin' => $this->magasin(),
                'introduction' => $this->introduction(),
                'paragraphes' => $this->paragraphesDuMessage(),
                'coordonnees' => $this->coordonneesDuMagasin(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->nomFichier)->withMime('application/pdf'),
        ];
    }

    private function magasin(): Magasin
    {
        return $this->envoi->magasin;
    }

    /**
     * Ce que contient la pièce jointe. Une relance n'en a pas : son message
     * dit déjà tout de la facture (voir EnvoyerDocumentAction::relancer()).
     * Une facture ne demande d'être réglée avant son échéance que s'il en
     * reste quelque chose à payer quand l'e-mail part : une vente au
     * comptoir, soldée dès son émission, ou une facture réglée depuis, se
     * dit déjà réglée. Seul un document numéroté directement en base, que
     * signale la vérification de l'intégrité, peut n'avoir aucune échéance
     * (voir Facture::estEchueEtImpayee()).
     */
    private function introduction(): ?string
    {
        if ($this->envoi->nature === NatureEnvoi::Relance) {
            return null;
        }

        /** @var DocumentEnvoyable $document */
        $document = $this->envoi->document;
        $montant = number_format((float) $document->total_ttc, 2, ',', ' ').' € TTC';

        return match (true) {
            $document instanceof Facture && ! $document->estPourUnProfessionnel() => "Veuillez trouver ci-joint votre ticket de caisse {$document->numero} d'un montant de {$montant}.",
            $document instanceof Facture && $document->montantRestant() <= 0 => "Veuillez trouver ci-joint notre facture {$document->numero} d'un montant de {$montant}, déjà réglée.",
            $document instanceof Facture => "Veuillez trouver ci-joint notre facture {$document->numero} d'un montant de {$montant}"
                .($document->date_echeance ? ", à régler au plus tard le {$document->date_echeance->format('d/m/Y')}." : '.'),
            $document instanceof Devis => "Veuillez trouver ci-joint notre devis {$document->numero} d'un montant de {$montant}"
                .($document->date_validite ? ", valable jusqu'au {$document->date_validite->format('d/m/Y')}." : '.'),
            $document instanceof Avoir => "Veuillez trouver ci-joint notre avoir {$document->numero} d'un montant de {$montant}.",
        };
    }

    /**
     * Les coordonnées du magasin en pied d'e-mail, une ligne par sujet, sans
     * celles qu'il n'a pas renseignées.
     *
     * @return list<string>
     */
    private function coordonneesDuMagasin(): array
    {
        $magasin = $this->magasin();
        $joindre = fn (array $morceaux): string => implode(' · ', array_filter($morceaux, fn (?string $morceau): bool => filled($morceau)));

        return array_values(array_filter([
            $joindre([$magasin->raison_sociale ?: $magasin->nom, $magasin->adresse, trim("{$magasin->code_postal} {$magasin->ville}")]),
            $joindre([$magasin->telephone ? "Tél. {$magasin->telephone}" : null, $magasin->email]),
            $magasin->siret ? "SIRET {$magasin->siret}" : '',
        ], fn (string $ligne): bool => $ligne !== ''));
    }

    /**
     * Le message de l'envoi, un paragraphe par ligne, chacun imprimé hors
     * Markdown (voir la vue mail.document) : il arrive tel que l'expéditeur
     * l'a écrit, sans lien, titre ni mise en forme qu'il n'aurait pas
     * voulus.
     *
     * @return list<string>
     */
    private function paragraphesDuMessage(): array
    {
        return collect(preg_split('/\R/', (string) $this->envoi->message))
            ->map(fn (string $ligne): string => trim($ligne))
            ->filter(fn (string $ligne): bool => $ligne !== '')
            ->values()
            ->all();
    }
}
