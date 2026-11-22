<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Casts\DonneesDUneNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Une notification de la cloche d'un compte (voir User::notifications()) :
 * celle de Laravel, dont le contenu ne se range qu'avec des liens relatifs
 * (voir DonneesDUneNotification). Elle n'est pas rattachée à un magasin par
 * une colonne : sa société ou son magasin, quand elle en a, se lisent dans
 * son contenu (`societe_id`, `magasin_id`, `client_id`), et c'est à son
 * seul compte, qui la lit, qu'elle appartient.
 */
class NotificationDePiliqi extends DatabaseNotification
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => DonneesDUneNotification::class,
            'read_at' => Instant::class,
        ];
    }
}
