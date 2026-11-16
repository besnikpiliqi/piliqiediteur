<?php

namespace App\Domain\Demonstration;

use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;

/**
 * Le site que sert cette installation de PILIQI (décision de l'éditeur du
 * 15/11/2026 en soirée, lot DEMO-COPIE) : le site réel, celui des vraies
 * sociétés, ou le site de démonstration, qui sert à chaque société la
 * copie d'une société modèle fictive de son type. Les deux font tourner le
 * même code, au même commit ; seul le réglage PILIQI_SITE (config
 * piliqi.site), lu une fois au démarrage, les distingue, jamais APP_ENV
 * ni l'environnement de Laravel.
 *
 * Une valeur inconnue empêche de démarrer (voir
 * SiteDePiliqiServiceProvider). Chaque classe propre à l'un des deux sites
 * l'exige (exiger()) et refuse de servir sur l'autre, même appelée
 * autrement qu'à l'écran.
 */
enum SiteDePiliqi: string
{
    case Reel = 'reel';

    case Demonstration = 'demonstration';

    public const MESSAGE_VALEUR_INCONNUE = 'PILIQI_SITE vaut « %s » : écrivez PILIQI_SITE=reel pour le site réel, ou PILIQI_SITE=demonstration pour le site de démonstration, dans le fichier .env de ce serveur.';

    public const MESSAGE_AUTRE_SITE = 'Cette opération n\'existe que sur le %s ; ce serveur est le %s.';

    /**
     * Le site que sert cette installation, lu de la configuration.
     *
     * @throws SiteMalConfigureException quand PILIQI_SITE ne vaut ni reel ni demonstration
     */
    public static function courant(): self
    {
        $valeur = config('piliqi.site');

        return (is_string($valeur) ? self::tryFrom($valeur) : null)
            ?? throw new SiteMalConfigureException(sprintf(self::MESSAGE_VALEUR_INCONNUE, is_scalar($valeur) || $valeur === null ? (string) $valeur : get_debug_type($valeur)));
    }

    /**
     * Arrête une opération propre à un site quand ce serveur sert l'autre.
     *
     * @throws SiteMalConfigureException
     */
    public static function exiger(self $site): void
    {
        $courant = self::courant();

        if ($courant !== $site) {
            throw new SiteMalConfigureException(sprintf(self::MESSAGE_AUTRE_SITE, $site->libelle(), $courant->libelle()));
        }
    }

    /**
     * @throws SiteMalConfigureException quand PILIQI_SITE ne vaut ni reel ni demonstration
     */
    public static function estLaDemonstration(): bool
    {
        return self::courant() === self::Demonstration;
    }

    /**
     * Le nom du site, tel que le pied de chaque page l'écrit.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::Reel => 'site réel',
            self::Demonstration => 'site de démonstration',
        };
    }
}
