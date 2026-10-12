<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La réponse à une demande d'inscription faite avec l'adresse d'un compte
 * qui existe déjà (voir InscriptionSociete) : aucune demande n'est créée,
 * et la personne qui détient cette adresse apprend qu'elle a déjà un
 * compte, avec le lien de connexion et celui du mot de passe oublié. Le
 * compte retiré de sa société, par elle ou par son propre refus, reçoit à
 * la place le lien de la page du lien (voir PageDuLien) : la personne y
 * choisit son mot de passe, ce qui confirme son adresse, puis crée sa
 * propre société avec ce compte (voir CreerSaSocieteController). L'écran
 * de l'inscription, lui, répond comme pour une adresse libre.
 */
class CompteExistantMail extends Mailable
{
    public function __construct(
        public readonly string $urlDeConnexion,
        public readonly string $urlMotDePasseOublie,
        public readonly ?string $urlPourCreerSaSociete,
        public readonly int $minutesDeValidite,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vous avez déjà un compte '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.compte-existant',
            with: [
                'urlDeConnexion' => $this->urlDeConnexion,
                'urlMotDePasseOublie' => $this->urlMotDePasseOublie,
                'urlPourCreerSaSociete' => $this->urlPourCreerSaSociete,
                'minutesDeValidite' => $this->minutesDeValidite,
            ],
        );
    }
}
