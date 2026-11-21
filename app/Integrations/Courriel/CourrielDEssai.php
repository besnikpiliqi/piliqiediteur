<?php

namespace App\Integrations\Courriel;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'e-mail d'essai de la commande courriel:essayer : il dit par quel
 * transport et à quelle heure (de Paris) il est parti, sans aucun secret.
 * Jamais mis en file : il part pendant la commande, qui en constate le
 * résultat.
 */
class CourrielDEssai extends Mailable
{
    public function __construct(public readonly string $transport) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Essai d\'envoi '.Reglage::texte('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.essai', with: [
            'transport' => $this->transport,
            'envoyeLe' => Calendrier::deParis()->afficher(now(), 'd/m/Y à H:i'),
        ]);
    }
}
