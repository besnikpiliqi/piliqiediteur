<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avis envoyé à l'ancienne adresse de connexion d'un compte quand une
 * nouvelle adresse, confirmée par le lien qui lui a été envoyé, la
 * remplace (voir ConfirmerNouvelleAdresseAction). Il ne dit pas la
 * nouvelle adresse.
 */
class AdresseChangeeMail extends Mailable
{
    public function __construct(
        public readonly string $nom,
        public readonly string $changeeLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre adresse de connexion à '.config('app.name').' a changé');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.adresse-changee', with: [
            'nom' => $this->nom,
            'changeeLe' => $this->changeeLe,
        ]);
    }
}
