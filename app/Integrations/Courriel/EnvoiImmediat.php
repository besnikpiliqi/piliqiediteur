<?php

namespace App\Integrations\Courriel;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;

/**
 * Un e-mail qui part aussitôt, sans file d'attente, pendant le geste de
 * la personne (diagnostic vérifié des e-mails du 02/11/2026, E8) : un
 * serveur d'envoi qui refuse ou se tait ne donne jamais une erreur 500 au
 * bout du délai, mais une cause en français (voir CauseDEchecDEnvoi),
 * écrite au journal de l'application au niveau critique, sans le texte du
 * serveur. Le délai d'attente est celui du transport, MAIL_TIMEOUT.
 */
final class EnvoiImmediat
{
    /**
     * L'e-mail part, ou CourrielNonParti dit pourquoi : « {$quoi} n'est
     * pas parti : le serveur d'envoi ne répond pas. » suivi de $suite.
     *
     * @param  string  $quoi  ce qui part, au début d'une phrase (« L'e-mail de confirmation »)
     * @param  Closure(): mixed  $envoi
     * @param  string  $suite  ce que la personne peut faire
     *
     * @throws CourrielNonParti
     */
    public static function exiger(string $quoi, Closure $envoi, string $suite = 'Réessayez dans quelques minutes.'): void
    {
        $cause = self::cause($quoi, $envoi);

        if ($cause !== null) {
            throw new CourrielNonParti("{$quoi} n'est pas parti : {$cause->libelle()}. {$suite}", $cause);
        }
    }

    /**
     * L'e-mail part si le serveur d'envoi le prend ; sinon, seul le journal
     * le dit, et le geste continue : pour un avis dont rien ne dépend.
     *
     * @param  Closure(): mixed  $envoi
     */
    public static function essayer(string $quoi, Closure $envoi): bool
    {
        return self::cause($quoi, $envoi) === null;
    }

    /**
     * @param  Closure(): mixed  $envoi
     */
    private static function cause(string $quoi, Closure $envoi): ?CauseDEchecDEnvoi
    {
        try {
            $envoi();
        } catch (TransportExceptionInterface|UnsupportedSchemeException $erreur) {
            $cause = CauseDEchecDEnvoi::de($erreur);
            Log::critical("{$quoi} n'est pas parti : {$cause->libelle()}.", ['cause' => $cause->value]);

            return $cause;
        }

        return null;
    }
}
