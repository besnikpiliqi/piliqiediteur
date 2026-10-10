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
     * Une entreprise de ce pays est-elle identifiée par un SIREN ? Le code
     * se lit en majuscules (« gp » est la Guadeloupe).
     */
    public static function estEnFrance(?string $pays): bool
    {
        return in_array(Str::upper(trim((string) $pays)), self::PAYS_FRANCE, true);
    }
}
