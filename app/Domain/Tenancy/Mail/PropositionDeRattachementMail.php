<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La proposition qu'une société adresse au compte retiré d'une personne
 * (voir ProposerUnRattachementAction) : qui la fait, pour quelle société
 * et dans quel rôle, et le lien pour l'accepter ou la refuser, valable
 * sept jours. Accepter redemande l'adresse et le mot de passe du compte.
 */
class PropositionDeRattachementMail extends Mailable
{
    public function __construct(
        public readonly string $nomDeLAuteur,
        public readonly string $societe,
        public readonly string $role,
        public readonly string $lien,
        public readonly string $expireLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->societe} vous propose de rejoindre son équipe sur ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.proposition-de-rattachement', with: [
            'nomDeLAuteur' => $this->nomDeLAuteur,
            'societe' => $this->societe,
            'role' => $this->role,
            'lien' => $this->lien,
            'expireLe' => $this->expireLe,
        ]);
    }
}
