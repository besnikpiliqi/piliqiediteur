<?php

namespace App\Domain\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La réponse à une demande d'inscription faite avec l'adresse de connexion
 * du propriétaire d'une autre société (voir InscriptionSociete) : aucune
 * demande n'est créée, et la personne qui détient cette adresse apprend
 * qu'elle a déjà un compte de propriétaire, avec le lien de connexion et
 * celui du mot de passe oublié, et qu'une nouvelle inscription se demande
 * avec une autre adresse, même quand l'inscription de sa société est
 * close (une adresse n'est l'adresse de connexion que d'un seul
 * propriétaire). L'écran de l'inscription, lui, répond comme pour une
 * adresse libre. L'adresse de contact d'un autre compte n'empêche aucune
 * inscription.
 */
class CompteExistantMail extends Mailable
{
    public function __construct(
        public readonly string $urlDeConnexion,
        public readonly string $urlMotDePasseOublie,
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
            ],
        );
    }
}
