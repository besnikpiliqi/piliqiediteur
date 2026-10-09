<?php

namespace App\Filament\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use LogicException;

/**
 * Une Action du domaine (app/Domain/**\/Actions) qui refuse une opération
 * lève une exception dont le message est déjà rédigé pour un utilisateur
 * final -- jamais pour un développeur :
 *
 * - une LogicException (InvalidArgumentException y compris, puisqu'elle
 *   en hérite en PHP) pour un geste invalide ;
 * - une RefusFiscalException quand le module fiscal ne peut pas enregistrer
 *   l'opération (clé de signature absente, horloge du serveur qui recule) :
 *   un incident du serveur, signalé en plus au journal de l'application
 *   (report()) pour qu'il soit corrigé.
 *
 * Sans ce trait, l'exception remonterait telle quelle jusqu'à la page
 * d'erreur Laravel par défaut : inacceptable pour un magasin en
 * production, qui ne doit jamais voir une trace de pile ou une requête
 * SQL, juste pourquoi son geste n'a pas été accepté.
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
        } catch (LogicException|RefusFiscalException $exception) {
            static::notifierLeRefus($exception);
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
        } catch (LogicException|RefusFiscalException $exception) {
            static::notifierLeRefus($exception);

            throw new Halt;
        }
    }

    /**
     * Affiche pourquoi l'opération a été refusée ; un refus fiscal est en
     * plus signalé au journal de l'application.
     */
    protected static function notifierLeRefus(LogicException|RefusFiscalException $exception): void
    {
        if ($exception instanceof RefusFiscalException) {
            report($exception);
        }

        Notification::make()->title($exception->getMessage())->danger()->send();
    }
}
