<?php

namespace App\Providers;

use App\Domain\Tenancy\Support\ValeurSimple;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\FauxServiceEnvoiRecommande;
use App\Integrations\Ordre\AnnuaireTenuParLEditeur;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Le câblage des fournisseurs externes, chacun selon son pilote
 * (config/services.php) : le répertoire Sirene de l'INSEE (SIRENE_PILOTE,
 * « insee » ou « faux »), le registre national des entreprises de l'INPI
 * (RNE_PILOTE, « inpi » ou « faux ») et l'envoi recommandé par l'API de La
 * Poste (RECOMMANDE_PILOTE), seulement « faux » tant que le contrat de
 * l'éditeur avec La Poste n'est pas branché, qui servent à la vérification
 * des sociétés ; l'annuaire de l'Ordre des experts-comptables
 * (ORDRE_PILOTE, « editeur », le pilote de production tant qu'aucun accès
 * automatique officiel n'existe, ou « faux »), qui vérifie l'inscription
 * d'un cabinet d'expertise comptable ; et la plateforme agréée de la
 * facture électronique (PDP_PILOTE), seulement « faux » jusqu'au
 * raccordement de SUPER PDP (étape 2 de la feuille de route), dont le nom
 * du pilote s'inscrit sur chaque mandat et chaque transmission (voir
 * pdpActive()). Le pilote « faux » sert au développement et aux tests,
 * sans aucune requête ; l'application refuse de démarrer en production
 * avec l'un d'eux. L'antivirus, qui demande l'adresse de son démon, a son
 * propre câblage (voir AntivirusServiceProvider).
 */
class FournisseursExternesServiceProvider extends ServiceProvider
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
        'ordre' => ['editeur' => AnnuaireTenuParLEditeur::class, 'faux' => FauxAnnuaireDeLOrdre::class],
        'pdp' => ['faux' => FakePdpGateway::class],
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
        'ordre' => AnnuaireDeLOrdre::class,
        'pdp' => PdpGatewayInterface::class,
    ];

    public function register(): void
    {
        foreach (self::CONTRATS as $service => $contrat) {
            $this->app->singleton($contrat, fn (): object => $this->app->make(self::PILOTES[$service][self::pilote($service)]));
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
     * Le nom du pilote de la plateforme agréée, inscrit sur chaque mandat
     * et chaque transmission (colonne prestataire_pdp).
     *
     * @throws RuntimeException quand le pilote configuré n'existe pas
     */
    public static function pdpActive(): string
    {
        return self::pilote('pdp');
    }

    /**
     * @throws RuntimeException quand le pilote configuré n'existe pas
     */
    private static function pilote(string $service): string
    {
        $pilote = config("services.{$service}.pilote");

        if (! is_string($pilote) || ! isset(self::PILOTES[$service][$pilote])) {
            $connus = implode(' ou ', array_keys(self::PILOTES[$service]));

            throw new RuntimeException("Pilote inconnu pour {$service} : « ".ValeurSimple::enTexte($pilote)." » ({$connus}).");
        }

        return $pilote;
    }
}
