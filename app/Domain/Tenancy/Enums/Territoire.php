<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Facturation\Enums\TerritoireTva;
use App\Domain\Facturation\Services\TerritoireFiscal;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/**
 * Le territoire d'un magasin : la métropole, la Corse ou un département
 * d'outre-mer. Il donne à la fois son territoire de TVA (son taux par
 * défaut, voir tva()), son département d'outre-mer (voir departement()) et
 * son calendrier (journée de caisse, date et année de ses documents,
 * échéances, voir Calendrier::duTerritoire()).
 *
 * Il se lit au répertoire Sirene, à la commune de l'établissement (voir
 * depuisLeRepertoire()), se fixe au premier contrôle concluant et ne
 * change plus (voir Magasin et ControleSirene::territoireDe()). Les
 * collectivités d'outre-mer (Saint-Barthélemy, Saint-Martin,
 * Saint-Pierre-et-Miquelon, Pacifique), hors du territoire de TVA
 * français, n'en sont pas ; la Guyane et Mayotte en sont, mais le
 * logiciel ne les prend pas encore en charge (voir estPrisEnCharge()).
 */
enum Territoire: string implements HasLabel
{
    case Metropole = 'metropole';
    case Corse = 'corse';
    case Guadeloupe = 'guadeloupe';
    case Martinique = 'martinique';
    case Guyane = 'guyane';
    case Reunion = 'reunion';
    case Mayotte = 'mayotte';

    /**
     * Le territoire d'un établissement d'après ce que le répertoire Sirene
     * en dit : sa commune (code officiel géographique), qui dit le
     * département sans ambiguïté (Saint-Barthélemy, 977, et Saint-Martin,
     * 978, ont un code postal de la Guadeloupe) et prime sur le code
     * postal quand ils se contredisent ; une collectivité d'outre-mer (975,
     * 977, 978, 98) ou un pays étranger (99) n'en donne aucun ; sans
     * commune, son code postal (voir depuisCodePostal()) ; sans l'un ni
     * l'autre, aucun.
     */
    public static function depuisLeRepertoire(?string $codeCommune, ?string $codePostal): ?self
    {
        $commune = Str::upper(trim((string) $codeCommune));

        if ($commune === '') {
            return trim((string) $codePostal) === '' ? null : self::depuisCodePostal('FR', $codePostal);
        }

        return match (true) {
            str_starts_with($commune, '2A'), str_starts_with($commune, '2B') => self::Corse,
            str_starts_with($commune, '971') => self::Guadeloupe,
            str_starts_with($commune, '972') => self::Martinique,
            str_starts_with($commune, '973') => self::Guyane,
            str_starts_with($commune, '974') => self::Reunion,
            str_starts_with($commune, '976') => self::Mayotte,
            str_starts_with($commune, '97'), str_starts_with($commune, '98'), str_starts_with($commune, '99') => null,
            default => self::Metropole,
        };
    }

    /**
     * Le territoire d'une adresse en France, d'après son code postal : le
     * département d'outre-mer, 971 (Guadeloupe), 972 (Martinique), 973
     * (Guyane), 974 (La Réunion) et 976 (Mayotte), comme pour une
     * livraison (voir TerritoireFiscal::departementDom()) ; 20 pour la
     * Corse ; aucun pour une collectivité d'outre-mer (voir
     * TerritoireFiscal::estUneCollectiviteDOutreMer()) ni pour une adresse
     * hors de France. Tout autre code postal, ou aucun, est en métropole.
     */
    public static function depuisCodePostal(?string $pays, ?string $codePostal): ?self
    {
        if (Str::upper(trim((string) $pays)) !== 'FR' || TerritoireFiscal::estUneCollectiviteDOutreMer('FR', $codePostal)) {
            return null;
        }

        return match (TerritoireFiscal::departementDom('FR', $codePostal)) {
            '971' => self::Guadeloupe,
            '972' => self::Martinique,
            '973' => self::Guyane,
            '974' => self::Reunion,
            '976' => self::Mayotte,
            default => str_starts_with((string) preg_replace('/\s+/', '', (string) $codePostal), '20') ? self::Corse : self::Metropole,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Metropole => 'Métropole',
            self::Corse => 'Corse',
            self::Guadeloupe => 'Guadeloupe',
            self::Martinique => 'Martinique',
            self::Guyane => 'Guyane',
            self::Reunion => 'La Réunion',
            self::Mayotte => 'Mayotte',
        };
    }

    /**
     * Son territoire de TVA, qui fixe son taux normal et ses taux en
     * vigueur : la Guadeloupe, la Martinique et La Réunion ont les mêmes.
     */
    public function tva(): TerritoireTva
    {
        return match ($this) {
            self::Metropole => TerritoireTva::Metropole,
            self::Corse => TerritoireTva::Corse,
            self::Guadeloupe, self::Martinique, self::Reunion => TerritoireTva::AntillesReunion,
            self::Guyane, self::Mayotte => TerritoireTva::GuyaneMayotte,
        };
    }

    /**
     * Le logiciel gère-t-il un magasin de ce territoire ? Pas encore en
     * Guyane ni à Mayotte, où la TVA n'est pas applicable et où les
     * documents suivent d'autres règles (voir CreerEtablissementAction).
     */
    public function estPrisEnCharge(): bool
    {
        return $this !== self::Guyane && $this !== self::Mayotte;
    }

    /**
     * « en Martinique », pour dire où est le magasin.
     */
    public function lieu(): string
    {
        return match ($this) {
            self::Metropole => 'en métropole',
            self::Corse => 'en Corse',
            self::Guadeloupe => 'en Guadeloupe',
            self::Martinique => 'en Martinique',
            self::Guyane => 'en Guyane',
            self::Reunion => 'à La Réunion',
            self::Mayotte => 'à Mayotte',
        };
    }

    /**
     * Son département d'outre-mer (« 971 » à « 976 »), celui d'où partent
     * ses livraisons (voir ControleNatureOperation) ; aucun en métropole ni
     * en Corse.
     */
    public function departement(): ?string
    {
        return match ($this) {
            self::Guadeloupe => '971',
            self::Martinique => '972',
            self::Guyane => '973',
            self::Reunion => '974',
            self::Mayotte => '976',
            self::Metropole, self::Corse => null,
        };
    }
}
