<?php

namespace App\Domain\Tenancy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le lien envoyé à l'adresse d'un compte, à sa propre demande (« Mot de
 * passe oublié », voir DemandeDeLien) ou à celle de quelqu'un de sa société
 * (voir EnvoyerUnLienDeReinitialisationAction) : il mène à la page du lien
 * (voir PageDuLien), où la personne choisit elle-même son mot de passe, que
 * personne d'autre ne connaît, ou refuse le compte s'il peut encore l'être.
 * Il ne contient aucun mot de passe.
 */
class LienPourChoisirSonMotDePasse extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $demandePar,
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
            ->subject('Renouvelez votre accès à '.config('app.name'))
            ->line($this->demandePar === null
                ? 'Un lien pour choisir votre mot de passe '.config('app.name').' a été demandé avec votre adresse.'
                : "{$this->demandePar} a demandé à renouveler votre accès à ".config('app.name').'.')
            ->action('Choisir mon mot de passe', $this->url)
            ->line("Ce lien est valable {$this->minutesDeValidite} minutes. Personne d'autre que vous ne connaîtra votre mot de passe.")
            ->line('Si vous n\'attendiez pas ce message, ignorez-le : votre mot de passe actuel reste inchangé.');
    }
}
