<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La réponse, à la personne seule, quand une société propose de rattacher
 * un compte qui appartient encore à une société (voir
 * ProposerUnRattachementAction) : aucune proposition n'est créée, et
 * l'auteur n'en apprend rien ; la personne sait qu'elle doit d'abord être
 * retirée par sa société actuelle.
 */
class RattachementImpossibleMail extends Mailable
{
    public function __construct(public readonly string $societe) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->societe} souhaite vous rattacher à son équipe sur ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.rattachement-impossible', with: ['societe' => $this->societe]);
    }
}
