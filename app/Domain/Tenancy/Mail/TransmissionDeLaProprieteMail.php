<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le courriel de la transmission de la propriété, envoyé à l'adresse que
 * l'administrateur a renseignée lui-même dans son profil (voir
 * DemanderLaTransmissionDeLaProprieteAction) : avec le lien, valable 24
 * heures, qu'il suit connecté à son compte pour accepter (voir
 * AccepterLaTransmission) ; sans lien quand cette adresse est déjà
 * l'adresse de connexion du propriétaire d'une autre société, où il dit
 * seulement qu'elle ne peut pas devenir la sienne. Il ne contient jamais
 * d'identifiant de connexion.
 */
class TransmissionDeLaProprieteMail extends Mailable
{
    public function __construct(
        public readonly ?string $lien,
        public readonly string $societe,
        public readonly string $demandeur,
        public readonly string $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Transmission de la propriété de '.$this->societe.' sur '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.transmission-de-la-propriete', with: [
            'lien' => $this->lien,
            'societe' => $this->societe,
            'demandeur' => $this->demandeur,
            'expireLe' => $this->expireLe,
        ]);
    }
}
