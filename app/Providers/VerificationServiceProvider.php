<?php

namespace App\Providers;

use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\FauxServiceEnvoiRecommande;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Le câblage des fournisseurs externes de la vérification des sociétés,
 * chacun selon son pilote (config/services.php) : le répertoire Sirene de
 * l'INSEE (SIRENE_PILOTE, « insee » ou « faux »), le registre national
 * des entreprises de l'INPI (RNE_PILOTE, « inpi » ou « faux ») et l'envoi
 * recommandé par l'API de La Poste (RECOMMANDE_PILOTE), seulement
 * « faux » tant que le contrat de l'éditeur avec La Poste n'est pas
 * branché. Le pilote « faux » sert au développement et aux tests, sans
 * aucune requête ; l'application refuse de démarrer en production avec
 * l'un d'eux.
 */
class VerificationServiceProvider extends ServiceProvider
{
    /**
     * Les pilotes de chaque fournisseur, et la classe de chacun.
     *
     * @var array<string, array<string, class-string>>
     */
    private const PILOTES = [
        'sirene' => ['insee' => InseeSirene::class, 'faux' => FauxRepertoireSirene::class],
        'rne' => ['inpi' => InpiRne::class, 'faux' => FauxRne::class],
        'recommande' => ['faux' => FauxServiceEnvoiRecommande::class],
    ];

    /**
     * Le contrat de chaque fournisseur.
     *
     * @var array<string, class-string>
     */
    private const CONTRATS = [
        'sirene' => RepertoireSirene::class,
        'rne' => RegistreNationalDesEntreprises::class,
        'recommande' => ServiceEnvoiRecommande::class,
    ];

    public function register(): void
    {
        foreach (self::CONTRATS as $service => $contrat) {
            $this->app->singleton($contrat, fn (): object => $this->app->make(self::PILOTES[$service][$this->pilote($service)]));
        }
    }

    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        foreach (array_keys(self::CONTRATS) as $service) {
            if (config("services.{$service}.pilote") === 'faux') {
                throw new RuntimeException("Le fournisseur factice de « {$service} » (pilote faux) ne sert jamais en production.");
            }
        }
    }

    /**
     * @throws RuntimeException quand le pilote configuré n'existe pas
     */
    private function pilote(string $service): string
    {
        $pilote = config("services.{$service}.pilote");

        if (! is_string($pilote) || ! isset(self::PILOTES[$service][$pilote])) {
            $connus = implode(' ou ', array_keys(self::PILOTES[$service]));

            throw new RuntimeException("Pilote inconnu pour {$service} : « {$pilote} » ({$connus}).");
        }

        return $pilote;
    }
}
