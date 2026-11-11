<?php

namespace App\Domain\Tenancy\Mail;

use App\Domain\Tenancy\Support\Reglage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avis envoyé, à la clôture d'une inscription (voir
 * CloreLInscriptionAction), à l'adresse de connexion que son propriétaire
 * vient de perdre : l'inscription est close, avec son motif ; ce qui
 * reste consultable (ses magasins, ou l'espace d'un cabinet d'expertise
 * comptable, qui n'a aucun magasin) ; cette adresse peut servir à une
 * nouvelle inscription, avec le bon type d'activité quand l'inscription
 * close l'avait mal choisi ; le compte de
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
        public readonly string $consultable,
        public readonly ?string $reinscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'L\'inscription de votre société sur '.Reglage::texte('app.name').' est close');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.inscription-close', with: [
            'societe' => $this->societe,
            'motif' => $this->motif,
            'urlInscription' => $this->urlInscription,
            'consultable' => $this->consultable,
            'reinscription' => $this->reinscription,
        ]);
    }
}
