<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avis envoyé, à la clôture d'une inscription (voir
 * CloreLInscriptionAction), à l'adresse de connexion que son propriétaire
 * vient de perdre : l'inscription est close, avec son motif ; cette
 * adresse peut servir à une nouvelle inscription ; le compte de
 * l'inscription close ne s'ouvre plus qu'avec son identifiant de
 * connexion, que montre son profil, et l'éditeur aide qui ne le connaît
 * pas. Il ne dit jamais l'identifiant, qui ne s'envoie pas par e-mail.
 */
class InscriptionCloseMail extends Mailable
{
    public function __construct(
        public readonly string $societe,
        public readonly string $motif,
        public readonly string $urlInscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'L\'inscription de votre société sur '.config('app.name').' est close');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.inscription-close', with: [
            'societe' => $this->societe,
            'motif' => $this->motif,
            'urlInscription' => $this->urlInscription,
        ]);
    }
}
