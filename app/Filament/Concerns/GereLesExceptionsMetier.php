<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use LogicException;

/**
 * Une Action du domaine (app/Domain/**\/Actions) qui refuse une opération
 * invalide lève une LogicException (InvalidArgumentException y compris,
 * puisqu'elle en hérite en PHP) avec un message déjà rédigé pour un
 * utilisateur final -- jamais pour un développeur. Sans ce trait, cette
 * exception remonterait telle quelle jusqu'à la page d'erreur Laravel par
 * défaut : inacceptable pour un magasin en production, qui ne doit jamais
 * voir une trace de pile ou une requête SQL, juste pourquoi son geste n'a
 * pas été accepté.
 *
 * À utiliser dans toute Resource/Page Filament qui appelle directement une
 * Action du domaine depuis une Action Filament ou un cycle de vie de page.
 */
trait GereLesExceptionsMetier
{
    /**
     * Pour une Action de table/page/formulaire dont Filament n'utilise pas
     * la valeur de retour (Tables\Actions\Action::make(...)->action(...),
     * Actions\Action::make(...)->action(...)) : on notifie et on
     * s'arrête là, sans rien renvoyer.
     */
    protected static function executerSansCasser(Closure $callback): void
    {
        try {
            $callback();
        } catch (LogicException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Pour handleRecordCreation()/handleRecordUpdate(), qui doivent
     * impérativement renvoyer un Model : on notifie puis on interrompt
     * proprement le cycle de vie Filament via Halt plutôt que de laisser
     * l'exception se propager.
     */
    protected static function executerOuInterrompre(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (LogicException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
