<?php

namespace App\Domain\Cabinets\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'invitation qu'une société envoie à son expert-comptable, dont le
 * cabinet n'a pas encore d'espace (voir
 * InviterSonCabinetACreerSonEspaceAction) : le lien mène à l'inscription,
 * « Cabinet d'expertise comptable » présélectionné ; ce type est
 * définitif, et le cabinet est vérifié (69.20Z, Ordre) avant tout lien.
 */
class InvitationACreerUnEspaceMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly string $societe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->societe} vous invite à créer l'espace de votre cabinet sur ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation-a-creer-un-espace', with: [
            'lien' => $this->lien,
            'societe' => $this->societe,
        ]);
    }
}
