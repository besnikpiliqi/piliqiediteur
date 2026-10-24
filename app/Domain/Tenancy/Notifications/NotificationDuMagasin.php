<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Magasin;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Notification;

/**
 * Notification en base, affichée dans la cloche du panneau (voir
 * AppPanelProvider::databaseNotifications()), à propos d'un seul magasin :
 * elle nomme le magasin en tête de son titre, ne parle que de lui, et
 * retient sa société (`societe_id`) et son identifiant (`magasin_id`), qui
 * servent à effacer les notifications d'un compte qui quitte sa société
 * ou n'est plus affecté au magasin (voir User::effacerToutesSesNotifications()
 * et User::effacerLesNotificationsDesMagasins()).
 */
abstract class NotificationDuMagasin extends Notification
{
    public function __construct(public readonly Magasin $magasin) {}

    /**
     * Le contenu de la notification, sans le nom du magasin, ajouté au
     * titre par toDatabase().
     */
    abstract protected function contenu(): NotificationFilament;

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
        $contenu = $this->contenu();

        return [
            ...$contenu->title("{$this->magasin->nom} : {$contenu->getTitle()}")->getDatabaseMessage(),
            'societe_id' => (int) $this->magasin->societe_id,
            'magasin_id' => $this->magasin->getKey(),
        ];
    }
}
