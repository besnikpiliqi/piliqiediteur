<?php

namespace App\Domain\Tenancy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le lien « Mot de passe oublié » envoyé à l'adresse de connexion du
 * propriétaire, à sa demande (voir DemandeDeLien) : il mène à la page du
 * lien (voir PageDuLien), où il choisit lui-même son mot de passe, que
 * personne d'autre ne connaît. Il ne contient ni mot de passe ni
 * identifiant.
 */
class LienPourChoisirSonMotDePasse extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly int $minutesDeValidite,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Choisissez votre mot de passe '.config('app.name'))
            ->line('Un lien pour choisir votre mot de passe '.config('app.name').' a été demandé avec votre adresse.')
            ->action('Choisir mon mot de passe', $this->url)
            ->line("Ce lien est valable {$this->minutesDeValidite} minutes. Personne d'autre que vous ne connaîtra votre mot de passe.")
            ->line('Si vous n\'attendiez pas ce message, ignorez-le : votre mot de passe actuel reste inchangé.');
    }
}
