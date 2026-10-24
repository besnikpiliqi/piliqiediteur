<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Societe;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Une notification en base, dans la cloche de son destinataire, sur
 * l'équipe de sa société : la première connexion d'un compte, un accès
 * redonné, une propriété transmise, un e-mail qui n'est pas parti faute de
 * droits (au propriétaire)... Elle porte la société (`societe_id`), ne dit
 * que ce que son destinataire savait déjà ou doit savoir, jamais un
 * identifiant de connexion, et mène, s'il y a lieu, à la page où agir.
 * Avec $parCourriel, elle part aussi par e-mail à l'adresse de connexion du
 * propriétaire : un accès redonné par un autre, un identifiant affiché à un
 * administrateur, un seuil d'échecs de connexion dépassé, que la cloche
 * seule signalerait trop tard.
 */
class AvisDeLEquipe extends Notification
{
    public const INFORMATION = 'info';

    public const ATTENTION = 'warning';

    public function __construct(
        public readonly Societe $societe,
        public readonly string $titre,
        public readonly string $texte,
        public readonly ?string $url = null,
        public readonly string $libelleDuLien = 'Voir',
        public readonly string $nature = self::INFORMATION,
        public readonly bool $parCourriel = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ($this->parCourriel ?? false) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("{$this->titre} · {$this->societe->getFilamentName()}")
            ->line($this->texte);

        if ($this->url !== null) {
            $message->action($this->libelleDuLien, $this->url);
        }

        return $message->line('Ce message vous est envoyé en tant que propriétaire de la société sur '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = NotificationFilament::make()
            ->title($this->titre)
            ->body($this->texte)
            ->icon('heroicon-o-user-group')
            ->status($this->nature);

        if ($this->url !== null) {
            $notification->actions([
                Action::make('voir')
                    ->label($this->libelleDuLien)
                    ->url($this->url)
                    ->markAsRead(),
            ]);
        }

        return [
            ...$notification->getDatabaseMessage(),
            'societe_id' => $this->societe->getKey(),
        ];
    }
}
