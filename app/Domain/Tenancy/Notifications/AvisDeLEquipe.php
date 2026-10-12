<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Societe;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Notification;

/**
 * Une notification en base, dans la cloche de son destinataire, sur
 * l'équipe de sa société : un responsable nommé par un administrateur (au
 * propriétaire, qui peut annuler la nomination), un compte refusé par la
 * personne (à qui l'avait créé, à défaut au propriétaire), un e-mail qui
 * n'est pas parti faute de droits (au propriétaire)... Elle porte la
 * société (`societe_id`), ne dit que ce que son destinataire savait déjà
 * ou doit savoir, et mène, s'il y a lieu, à la page où agir.
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
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
