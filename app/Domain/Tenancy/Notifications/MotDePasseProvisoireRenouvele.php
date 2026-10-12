<?php

namespace App\Domain\Tenancy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient une personne qu'un nouveau mot de passe provisoire vient d'être
 * fixé pour son compte, qui ne s'en est pas encore servi (voir
 * DonnerUnMotDePasseProvisoireAction) : il lui est remis de la main à la
 * main, jamais par e-mail, et ce message n'en contient rien.
 */
class MotDePasseProvisoireRenouvele extends Notification
{
    public function __construct(public readonly string $donnePar) {}

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
            ->subject('Nouveau mot de passe provisoire sur '.config('app.name'))
            ->line("{$this->donnePar} a fixé un nouveau mot de passe provisoire pour votre compte ".config('app.name').', valable 72 heures. Il vous est remis de la main à la main : vous le changerez à votre première connexion.')
            ->line('Si vous ne connaissez pas cette personne, ou si ce compte n\'est pas le vôtre, vous pouvez le refuser depuis la page de connexion ou par « Mot de passe oublié ».');
    }
}
