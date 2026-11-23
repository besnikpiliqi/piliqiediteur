<?php

namespace App\Domain\Cabinets\Mail;

use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'e-mail envoyé, au rattachement de la déclaration d'un expert-comptable
 * de la société au cabinet vérifié de son SIREN (voir
 * RattacherUneDeclarationAction), à chaque personne dont le compte que la
 * société lui avait créé se ferme : elle accédera désormais à la société
 * par l'espace de son cabinet, dès que son propriétaire lui confiera ce
 * client ; si ce cabinet n'est pas le sien, elle prévient la société et
 * l'éditeur. En file d'attente, après le commit, chacun rejoué seul. Jamais
 * d'identifiant ni de mot de passe.
 */
class CompteDExpertComptableFermeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $nom,
        public readonly string $societe,
        public readonly string $cabinet,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Votre accès à {$this->societe} passe par l'espace de votre cabinet sur ".Reglage::texte('app.name'));
    }

    public function content(): Content
    {
        $editeur = config('editeur.email');

        return new Content(markdown: 'mail.compte-d-expert-comptable-ferme', with: [
            'nom' => $this->nom,
            'societe' => $this->societe,
            'cabinet' => $this->cabinet,
            'editeur' => is_string($editeur) && $editeur !== '' ? $editeur : null,
        ]);
    }
}
