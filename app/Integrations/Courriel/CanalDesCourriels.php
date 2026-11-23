<?php

namespace App\Integrations\Courriel;

use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Le canal « mail » des notifications (voir CourrielsServiceProvider) : un
 * avis par e-mail part aussitôt, pendant le geste qui le fait naître ; un
 * serveur d'envoi qui le refuse ou se tait ne casse jamais ce geste, déjà
 * enregistré, ni ne donne une erreur 500 : l'e-mail ne part pas, le
 * journal de l'application le dit au niveau critique (voir EnvoiImmediat),
 * et l'avis reste dans la cloche quand il y est aussi.
 */
class CanalDesCourriels extends MailChannel
{
    public function send($notifiable, Notification $notification): ?SentMessage
    {
        $envoye = null;

        EnvoiImmediat::essayer('L\'e-mail de la notification '.class_basename($notification), function () use ($notifiable, $notification, &$envoye): void {
            $envoye = parent::send($notifiable, $notification);
        });

        return $envoye;
    }
}
