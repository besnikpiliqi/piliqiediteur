<?php

namespace App\Domain\Tenancy\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien qui confirme une demande d'inscription (voir InscriptionSociete),
 * envoyé à l'adresse du futur propriétaire : rien n'est créé avant qu'il
 * ne l'ouvre et ne confirme (voir ConfirmerInscriptionController). Le lien
 * se construit depuis APP_URL, jamais depuis l'adresse de la requête.
 */
class DemandeInscriptionMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly CarbonInterface $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez l\'inscription de votre société');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.inscription-demande',
            with: [
                'lien' => $this->lien,
                'expireLe' => $this->expireLe->copy()->setTimezone(config('app.timezone'))->format('d/m/Y à H:i'),
            ],
        );
    }
}
