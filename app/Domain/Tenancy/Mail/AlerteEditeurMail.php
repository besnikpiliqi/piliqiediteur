<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Une alerte pour l'éditeur (voir AlerteEditeur) : des pièces déposées
 * pour un courrier non reçu, une validation impossible à la saisie du
 * code, une revérification Sirene négative... Elle ne nomme la société
 * que par son identifiant et son SIREN, et dit ce que l'éditeur peut
 * faire.
 */
class AlerteEditeurMail extends Mailable
{
    /**
     * @param  list<string>  $lignes
     */
    public function __construct(
        public readonly string $objet,
        public readonly array $lignes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' — '.$this->objet);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alerte-editeur', with: ['objet' => $this->objet, 'lignes' => $this->lignes]);
    }
}
