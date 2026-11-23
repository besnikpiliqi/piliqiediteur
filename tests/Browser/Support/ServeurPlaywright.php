<?php

namespace Tests\Browser\Support;

use Pest\Browser\Playwright\Servers\PlaywrightNpmServer;
use Pest\Browser\ServerManager;
use Pest\Plugins\Parallel;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/**
 * Le serveur Playwright que le module browser de Pest lance pour la suite
 * (node_modules/.bin/playwright run-server), par un shell : sous Linux et
 * macOS, le module arrête ce shell à la fin de la suite, mais le serveur,
 * son enfant, lui survit, garde le navigateur ouvert et la sortie de la
 * suite (une commande « php artisan test | tail » ne finit jamais). À la
 * fin du processus principal de la suite, ce serveur s'arrête donc aussi,
 * reconnu à son port. Sous Windows, le module arrête déjà tout l'arbre des
 * processus. Le serveur reçoit SIGKILL : arrêté par SIGTERM, il attend la
 * fermeture du navigateur que le module lui a laissé ouvert ; le navigateur,
 * qui le suit par son tube, se ferme avec lui.
 */
final class ServeurPlaywright
{
    /**
     * Arrête le serveur de cette suite à la fin du processus principal.
     */
    public static function arreterALaFin(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || Parallel::isWorker()) {
            return;
        }

        register_shutdown_function(static function (): void {
            $serveur = (new ReflectionProperty(ServerManager::class, 'playwright'))->getValue(ServerManager::instance());

            if (! $serveur instanceof PlaywrightNpmServer) {
                return;
            }

            $recherche = new Process(['pgrep', '-f', sprintf('^node .*playwright run-server --host %s --port %d ', preg_quote($serveur->host), $serveur->port)]);
            $recherche->run();

            foreach (preg_split('/\s+/', trim($recherche->getOutput()), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pid) {
                (new Process(['kill', '-KILL', $pid]))->run();
            }
        });
    }
}
