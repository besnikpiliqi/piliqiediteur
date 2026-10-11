<?php

namespace App\Domain\Facturation\Services;

use Illuminate\Support\Str;

/**
 * Les territoires que la TVA et l'identification des entreprises
 * distinguent, désignés par leur code de pays ISO à deux lettres et, quand
 * un pays en compte plusieurs, par le début de leur code postal.
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
     * Les 27 États membres de l'Union européenne, par leur code de pays.
     */
    private const ETATS_MEMBRES_UE = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Les territoires d'États membres hors du territoire de TVA de l'Union
     * (art. 6 de la directive 2006/112/CE), par le début de leur code
     * postal : les îles Canaries (35 et 38), Ceuta (51) et Melilla (52),
     * les îles Åland (22), le mont Athos (63086), Heligoland (27498),
     * Büsingen (78266), Livigno (23041) et Campione d'Italia (22061). Les
     * départements et collectivités d'outre-mer français ont leurs propres
     * règles (voir departementDom() et estUneCollectiviteDOutreMer()).
     * Codes postaux tirés de sources secondaires : à vérifier en recette.
     */
    private const TERRITOIRES_EXCLUS = [
        'ES' => ['35', '38', '51', '52'],
        'FI' => ['22'],
        'GR' => ['63086'],
        'DE' => ['27498', '78266'],
        'IT' => ['23041', '22061'],
    ];

    /**
     * Les départements d'outre-mer, par leur code de pays, et par le début
     * de leur code postal en France (FR).
     */
    private const DEPARTEMENTS_DOM = ['GP' => '971', 'MQ' => '972', 'GF' => '973', 'RE' => '974', 'YT' => '976'];

    /**
     * Les collectivités d'outre-mer, hors du territoire de TVA français :
     * Saint-Pierre-et-Miquelon, Saint-Barthélemy, Saint-Martin,
     * Wallis-et-Futuna, la Polynésie française, la Nouvelle-Calédonie et
     * les Terres australes, par leur code de pays...
     */
    private const COLLECTIVITES_D_OUTRE_MER = ['PM', 'BL', 'MF', 'WF', 'PF', 'NC', 'TF'];

    /**
     * ... et par le début de leur code postal en France (FR) :
     * Saint-Barthélemy et Saint-Martin ont un code postal de la
     * Guadeloupe.
     */
    private const CODES_POSTAUX_DES_COLLECTIVITES = ['97133', '97150', '975', '977', '978', '984', '986', '987', '988'];

    /**
     * Une entreprise de ce pays est-elle identifiée par un SIREN ? Le code
     * se lit en majuscules (« gp » est la Guadeloupe).
     */
    public static function estEnFrance(?string $pays): bool
    {
        return in_array(self::pays($pays), self::PAYS_FRANCE, true);
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

    /**
     * Le département d'outre-mer de cette adresse (« 971 » pour la
     * Guadeloupe, « 972 » la Martinique, « 973 » la Guyane, « 974 » La
     * Réunion, « 976 » Mayotte), par son code de pays ou, en France, par
     * son code postal ; null ailleurs, et pour Saint-Barthélemy et
     * Saint-Martin, collectivités d'outre-mer au code postal de la
     * Guadeloupe.
     */
    public static function departementDom(?string $pays, ?string $codePostal): ?string
    {
        $pays = self::pays($pays);

        if (isset(self::DEPARTEMENTS_DOM[$pays])) {
            return self::DEPARTEMENTS_DOM[$pays];
        }

        $code = self::codePostal($codePostal);

        if ($pays !== 'FR' || self::estUneCollectiviteDOutreMer($pays, $code)) {
            return null;
        }

        $departement = substr($code, 0, 3);

        return in_array($departement, self::DEPARTEMENTS_DOM, true) ? $departement : null;
    }

    /**
     * Une collectivité d'outre-mer, hors du territoire de TVA français
     * comme de celui de l'Union.
     */
    public static function estUneCollectiviteDOutreMer(?string $pays, ?string $codePostal): bool
    {
        $pays = self::pays($pays);

        return in_array($pays, self::COLLECTIVITES_D_OUTRE_MER, true)
            || ($pays === 'FR' && Str::startsWith(self::codePostal($codePostal), self::CODES_POSTAUX_DES_COLLECTIVITES));
    }

    /**
     * Des biens livrés à cette adresse quittent-ils le territoire de TVA
     * de l'Union (destination d'un export, art. 262 I du CGI) ? Oui hors
     * de ses États membres, dans leurs territoires exclus (voir
     * TERRITOIRES_EXCLUS) et dans l'outre-mer français ; jamais à Monaco,
     * qui suit la TVA française, ni en Irlande du Nord (GB, code postal
     * BT), qui suit les règles de l'Union pour les biens. Une livraison
     * vers un département d'outre-mer a sa propre nature (voir
     * departementDom()).
     */
    public static function estHorsUePourDesBiens(?string $pays, ?string $codePostal): bool
    {
        return self::estHorsDuTerritoireTvaUe($pays, $codePostal) && ! self::estEnIrlandeDuNord($pays, $codePostal);
    }

    /**
     * Un client établi à cette adresse l'est-il hors de l'Union, pour un
     * service dont le lieu suit le sien (art. 259-1° du CGI) ? Comme pour
     * des biens (voir estHorsUePourDesBiens()), à ceci près que l'Irlande
     * du Nord, pour les services, est hors de l'Union.
     */
    public static function estHorsUePourDesServices(?string $pays, ?string $codePostal): bool
    {
        return self::estHorsDuTerritoireTvaUe($pays, $codePostal);
    }

    /**
     * Des biens livrés à cette adresse arrivent-ils dans un autre État
     * membre de l'Union (destination d'une livraison intracommunautaire,
     * art. 262 ter I du CGI) ? Oui dans un État membre autre que la France,
     * hors de ses territoires exclus, et en Irlande du Nord.
     */
    public static function estDestinationUePourDesBiens(?string $pays, ?string $codePostal): bool
    {
        return ! in_array(self::pays($pays), ['FR', 'MC'], true) && ! self::estHorsUePourDesBiens($pays, $codePostal);
    }

    /**
     * Un client établi à cette adresse l'est-il dans un autre État membre
     * de l'Union, pour un service dont le lieu suit le sien (art. 259-1°
     * du CGI) : un État membre autre que la France, hors de ses territoires
     * exclus, jamais l'Irlande du Nord ?
     */
    public static function estDansUnAutreEtatMembrePourDesServices(?string $pays, ?string $codePostal): bool
    {
        return ! in_array(self::pays($pays), ['FR', 'MC'], true) && ! self::estHorsUePourDesServices($pays, $codePostal);
    }

    /**
     * Hors du territoire de TVA de l'Union : un pays qui n'en est pas
     * membre, sauf Monaco, territoire de TVA français ; un territoire
     * exclu d'un État membre ; l'outre-mer français.
     */
    private static function estHorsDuTerritoireTvaUe(?string $pays, ?string $codePostal): bool
    {
        $pays = self::pays($pays);

        if ($pays === 'MC') {
            return false;
        }

        if (! in_array($pays, self::ETATS_MEMBRES_UE, true)) {
            return true;
        }

        return self::departementDom($pays, $codePostal) !== null
            || self::estUneCollectiviteDOutreMer($pays, $codePostal)
            || Str::startsWith(self::codePostal($codePostal), self::TERRITOIRES_EXCLUS[$pays] ?? []);
    }

    private static function estEnIrlandeDuNord(?string $pays, ?string $codePostal): bool
    {
        return self::pays($pays) === 'GB' && str_starts_with(self::codePostal($codePostal), 'BT');
    }

    private static function pays(?string $pays): string
    {
        return Str::upper(trim((string) $pays));
    }

    /**
     * Le code postal en majuscules, sans espace : « bt1 1aa » est BT11AA,
     * « 974 00 » est 97400.
     */
    private static function codePostal(?string $codePostal): string
    {
        return Str::upper((string) preg_replace('/\s+/', '', (string) $codePostal));
    }
}
