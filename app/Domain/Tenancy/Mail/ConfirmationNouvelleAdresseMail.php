<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien envoyé à la nouvelle adresse de connexion demandée dans le
 * profil (voir DemanderUneNouvelleAdresseAction) : la nouvelle adresse ne
 * remplace l'ancienne qu'une fois ce lien suivi, dans les 24 heures (voir
 * ConfirmerNouvelleAdresseAction).
 */
class ConfirmationNouvelleAdresseMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly string $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez votre nouvelle adresse de connexion à '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.confirmation-nouvelle-adresse', with: [
            'lien' => $this->lien,
            'expireLe' => $this->expireLe,
        ]);
    }
}
