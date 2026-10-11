<?php

namespace App\Providers;

use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Le câblage des fournisseurs externes de la vérification des sociétés,
 * chacun selon son pilote (config/services.php) : le répertoire Sirene de
 * l'INSEE (SIRENE_PILOTE, « insee » ou « faux »). Le pilote « faux » sert
 * au développement et aux tests, sans aucune requête ; l'application
 * refuse de démarrer en production avec lui.
 */
class VerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RepertoireSirene::class, fn (): RepertoireSirene => match ($this->pilote('sirene')) {
            'insee' => new InseeSirene,
            'faux' => new FauxRepertoireSirene,
        });
    }

    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        if ($this->app->isProduction() && $this->pilote('sirene') === 'faux') {
            throw new RuntimeException('Le répertoire Sirene factice (SIRENE_PILOTE=faux) ne sert jamais en production.');
        }
    }

    /**
     * @throws RuntimeException quand le pilote configuré n'existe pas
     */
    private function pilote(string $service): string
    {
        $pilote = config("services.{$service}.pilote");

        if (! in_array($pilote, ['insee', 'faux'], true)) {
            throw new RuntimeException("Pilote inconnu pour {$service} : « {$pilote} » (insee ou faux).");
        }

        return $pilote;
    }
}
