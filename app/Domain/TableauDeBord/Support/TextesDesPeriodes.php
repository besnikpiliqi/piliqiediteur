<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;

/**
 * Les phrases grises du tableau de bord sur le découpage par période
 * (choix de l'éditeur du 16/11/2026, lot GRAPH-AN ; décisions de Claude du
 * 22/11/2026, D76) : celles d'une carte, que la série porte en note (voir
 * ConstructeurDeSeriesParPeriode), et celles des filtres de l'écran, qui
 * disent pourquoi une case est grisée.
 */
final class TextesDesPeriodes
{
    /** La case « Comparer avec l'année précédente », grisée par année. */
    public const COMPARAISON_PAR_ANNEE = 'La comparaison se fait mois par mois ou trimestre par trimestre ; par année, chaque barre est déjà une année.';

    /** La case du cumul, grisée par année. */
    public const CUMUL_PAR_ANNEE = 'Par année, il n\'y a rien à cumuler : chaque barre est déjà une année entière.';

    /** Le menu « Période », grisé par année. */
    public const PERIODE_PAR_ANNEE = '10 dernières années';

    /** La carte « Écart des prix d'achat », qui compare les achats mois par mois, par année. */
    public const ECART_DES_PRIX_D_ACHAT_PAR_ANNEE = 'Cette carte compare les achats mois par mois : par année, elle montre les 12 derniers mois.';

    /** Le cumul réglé sur la carte d'un stock. */
    public const CUMUL_D_UN_STOCK = 'Le cumul ne s\'applique pas à un stock : la carte garde ses valeurs de fin de période.';

    /** La tendance demandée avec le cumul. */
    public const TENDANCE_ET_CUMUL = 'Pas de tendance sur un cumul.';

    /** L'écart en % sur un an, avec ce qui se met de côté. */
    public const ECART_SEUL = 'L\'écart en % se lit sans cumul, sans comparaison et sans tendance.';

    /** Une barre en cours se compare aux mêmes jours de l'an dernier. */
    public const MEMES_JOURS = 'La période en cours se compare aux mêmes jours de l\'an dernier.';

    /** La TVA d'un jour ne compte pas les charges, que leur mois de TVA rattache à un mois. */
    public const TVA_DES_CHARGES_PAR_JOUR = 'La TVA des charges se rattache à son mois de TVA : le détail par jour ne la compte pas.';

    /**
     * Ce que dit l'écran, sous la barre des filtres, de ce que ses choix
     * font (maquette du 22/11/2026) : par année, la période des dix
     * dernières années et ce qui est grisé ; par trimestre sur « 12 derniers
     * mois », les quatre blocs de trois mois ; avec le cumul, d'où il part et
     * ce que devient la comparaison.
     *
     * @return list<string>
     */
    public static function dEcran(DecoupageDuTableauDeBord $decoupage, PeriodeDuTableauDeBord $periode, bool $cumul, bool $comparer, bool $exercices): array
    {
        $notes = [];
        $terme = $exercices ? 'exercice' : 'année';

        if ($decoupage === DecoupageDuTableauDeBord::Annee) {
            $notes[] = "Par {$terme} : la période est les 10 dernières années pour tout l'écran ; les années vides du début ne s'affichent pas, une {$terme} vide au milieu reste à 0".($exercices ? ' ; chaque barre est un exercice.' : '.');
            $notes[] = self::COMPARAISON_PAR_ANNEE.' '.self::CUMUL_PAR_ANNEE;

            return $notes;
        }

        if ($decoupage === DecoupageDuTableauDeBord::Trimestre && $periode === PeriodeDuTableauDeBord::DouzeDerniersMois && ! $cumul) {
            $notes[] = 'Par trimestre, sur les 12 derniers mois : quatre blocs de trois mois, le dernier finissant ce mois-ci.';
        }

        if ($cumul) {
            $debut = $exercices ? "de l'exercice" : "de l'année";
            $notes[] = "Le cumul se compte depuis le début {$debut}".($comparer ? ($exercices ? " ; l'exercice précédent est cumulé en pointillés." : " ; l'année précédente est cumulée en pointillés.") : '.');
        }

        return $notes;
    }

    /**
     * Les années du début sans donnée, que la carte ne montre pas : « Une
     * année plus ancienne sans donnée n'est pas affichée. », « 2 exercices
     * plus anciens sans donnée ne sont pas affichés. ».
     */
    public static function anneesCachees(int $nombre, bool $exercices): string
    {
        if ($exercices) {
            return $nombre === 1 ? 'Un exercice plus ancien sans donnée n\'est pas affiché.' : "{$nombre} exercices plus anciens sans donnée ne sont pas affichés.";
        }

        return $nombre === 1 ? 'Une année plus ancienne sans donnée n\'est pas affichée.' : "{$nombre} années plus anciennes sans donnée ne sont pas affichées.";
    }

    /**
     * Ce que dit la carte de l'écart en % sur un an, avec, quand une barre
     * est en cours, comment elle se compare.
     */
    public static function ecart(bool $barreEnCours): string
    {
        return 'Écart en % sur un an : chaque barre compare la période au même moment un an plus tôt ; « – » quand cette année-là vaut 0.'.($barreEnCours ? ' '.self::MEMES_JOURS : '');
    }

    /**
     * Ce que dit la carte du cumul : « Cumul depuis le début de l'année :
     * chaque point ajoute les précédents. », avec, sur « 12 derniers mois »,
     * de quand part la carte.
     */
    public static function cumul(bool $exercices, bool $surLesDouzeDerniersMois): string
    {
        $debut = $exercices ? 'de l\'exercice' : 'de l\'année';

        return "Cumul depuis le début {$debut} : chaque point ajoute les précédents.".($surLesDouzeDerniersMois ? " La carte part du début {$debut} en cours." : '');
    }

    /**
     * Ce que dit une carte ouverte en détail : les montants seulement.
     */
    public static function detail(string $intitule): string
    {
        return "Détail de {$intitule} : les montants seulement, sans cumul, comparaison, écart ni tendance.";
    }
}
