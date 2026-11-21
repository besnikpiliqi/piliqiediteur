<?php

namespace App\Domain\Tenancy\Mail;

use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avis envoyé à l'ancienne adresse de connexion d'un compte quand une
 * nouvelle adresse, confirmée par le lien qui lui a été envoyé, la
 * remplace (voir ConfirmerNouvelleAdresseAction), ou à l'ancienne adresse
 * d'un expert-comptable de la société, qui n'est pas une adresse de
 * connexion ($adresseDeConnexion faux). Il ne dit pas la nouvelle adresse.
 */
class AdresseChangeeMail extends Mailable
{
    public function __construct(
        public readonly string $nom,
        public readonly string $changeeLe,
        public readonly bool $adresseDeConnexion = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->adresseDeConnexion
            ? 'Votre adresse de connexion à '.Reglage::texte('app.name').' a changé'
            : 'Votre adresse sur '.Reglage::texte('app.name').' a changé');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.adresse-changee', with: [
            'nom' => $this->nom,
            'changeeLe' => $this->changeeLe,
            'adresseDeConnexion' => $this->adresseDeConnexion,
        ]);
    }
}
