<?php

namespace App\Domain\Facturation\Services;

use Illuminate\Support\Str;

/**
 * Les territoires que la TVA et l'identification des entreprises
 * distinguent, désignés par leur code de pays ISO à deux lettres.
 */
class TerritoireFiscal
{
    /**
     * La France dont les entreprises sont inscrites au répertoire SIRENE,
     * donc identifiées par un SIREN : la métropole et la Corse (FR), les
     * départements d'outre-mer (Guadeloupe, Martinique, Guyane, La Réunion,
     * Mayotte), Saint-Barthélemy, Saint-Martin et Saint-Pierre-et-Miquelon.
     * Monaco n'en fait pas partie, ni la Nouvelle-Calédonie, la Polynésie
     * française ou Wallis-et-Futuna, qui ont leur propre registre.
     */
    public const PAYS_FRANCE = ['FR', 'GP', 'MQ', 'GF', 'RE', 'YT', 'BL', 'MF', 'PM'];

    /**
     * Les préfixes des n° de TVA que VIES vérifie : ceux des 27 États
     * membres de l'Union européenne, où la Grèce est EL (et non GR, son
     * code de pays), et XI, l'Irlande du Nord, qui suit les règles de
     * l'Union pour les biens.
     */
    public const PREFIXES_TVA_UE = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * Une entreprise de ce pays est-elle identifiée par un SIREN ? Le code
     * se lit en majuscules (« gp » est la Guadeloupe).
     */
    public static function estEnFrance(?string $pays): bool
    {
        return in_array(Str::upper(trim((string) $pays)), self::PAYS_FRANCE, true);
    }

    /**
     * Le préfixe de ce n° de TVA quand VIES sait le vérifier (voir
     * PREFIXES_TVA_UE), null pour un n° d'un pays hors de l'Union (CH...)
     * ou pour une fiche sans n°. Le n° se lit normalisé : « de 123 456 789 »
     * est DE123456789.
     */
    public static function prefixeTvaUe(?string $numeroTva): ?string
    {
        $prefixe = substr(NumeroTva::normaliser((string) $numeroTva), 0, 2);

        return in_array($prefixe, self::PREFIXES_TVA_UE, true) ? $prefixe : null;
    }
}
