<?php

namespace Tests\Fixtures\EcartDesPrixDAchat;

use App\Domain\TableauDeBord\Support\EcritureDesEcarts;

/**
 * Les textes de la page de la maquette de la carte « Écart des prix
 * d'achat » qui ne sont pas ceux de la carte (titre et introduction de la
 * page, « Votre exemple, achat par achat », « Comment la carte se
 * comporte ») : la carte n'en écrit aucun, mais les neuf règles et
 * l'exemple de l'éditeur sont à respecter et à tester mot pour mot (A14).
 * L'exemple se calcule avec les écritures de la carte (EcritureDesEcarts).
 *
 * @phpstan-type CelluleDeLExemple array{t: string, c: string}
 */
final class TextesDeLaPageDeLaMaquette
{
    /**
     * Les achats de l'exemple de l'éditeur : le jour et le prix HT en euros
     * de neuf achats d'un même produit, une pièce chacun.
     *
     * @var list<array{0: string, 1: int}>
     */
    public const ACHATS_DE_L_EXEMPLE = [['4 déc.', 12], ['15 janv.', 15], ['10 févr.', 12], ['12 mars', 17], ['8 avr.', 15], ['20 mai', 16], ['17 juin', 13], ['22 sept.', 12], ['14 oct.', 10]];

    /** Le titre de la page de la maquette (jamais affiché dans la carte). */
    public const TITRE_DE_LA_PAGE = 'Écart des prix d\'achat';

    /** L'introduction de la page de la maquette (jamais affichée dans la carte). */
    public const INTRODUCTION_DE_LA_PAGE = 'Nouvelle carte du tableau de bord. Tous les chiffres de cette page sortent d\'un même petit jeu d\'achats fictifs (12 produits, dont 4 sans aucun écart : 2 achetés une seule fois et 2 toujours au même prix ; 12 mois ; la plupart des produits achetés une à trois fois dans le mois, à des prix différents), calculés achat après achat. Choisissez l\'affichage : un total par mois (cliquez sur un mois) ou un seul total pour les 12 mois. Ce sont deux calculs différents.';

    public const TITRE_DE_L_EXEMPLE = 'Votre exemple, achat par achat';

    public const INTRODUCTION_DE_L_EXEMPLE = 'Neuf achats du même produit sur l\'année, une pièce chacun. Chaque achat est comparé au premier achat de l\'année (12 €) ; le résultat est ajouté au cumul des achats d\'avant.';

    /** @var list<string> */
    public const ENTETE_DE_L_EXEMPLE = ['Achat', 'Date', 'Prix HT', 'Écart avec l\'achat 1', 'Cumul'];

    public const TITRE_DES_REGLES = 'Comment la carte se comporte';

    /**
     * Les neuf règles « Comment la carte se comporte » de la maquette, à respecter et à tester.
     *
     * @var list<string>
     */
    public const REGLES = [
        'Le total par mois et le total des 12 mois sont deux calculs différents, faits avec la même méthode : pour chaque produit, un premier achat sert de prix de départ, et chaque achat suivant du même produit (une ligne de facture d\'achat, déjà au prix moyen de la facture quand le fournisseur a facturé deux prix sur la même facture) est comparé à lui : écart = (prix HT de l\'achat − prix HT du prix de départ) × quantité achetée.',
        '« Un total par mois » : chaque mois se calcule dans le mois, jamais avec un autre mois ; le prix de départ est le premier achat du MOIS ; le total du mois est la somme des lignes du tableau du mois, avec ses hausses (au-dessus du zéro) et ses baisses (en dessous).',
        '« Un seul total pour les 12 mois » : calculé à part, sur toute la période ; le prix de départ est le premier achat de l\'ANNÉE (le premier achat du produit dans la période) et chaque achat suivant, de n\'importe quel mois, est comparé à lui. Ce n\'est donc pas la somme des mois : un produit acheté une seule fois par mois n\'apparaît dans aucun tableau de mois, mais ses changements de prix d\'un mois à l\'autre apparaissent dans le total des 12 mois. La période suit le menu du tableau de bord (12 derniers mois, année civile ou exercice).',
        'Un achat au même prix que le prix de départ n\'a pas d\'écart et n\'est jamais affiché ; un produit sans écart n\'apparaît pas.',
        'Les totaux ne comptent que les produits qui ont un écart : un produit acheté une seule fois, ou toujours au même prix, n\'a pas d\'écart et n\'entre dans aucun total ; la carte dit combien il y en a. Le total de la carte n\'est donc pas le total de tous les achats de la période.',
        'Dans le calcul de la période (« Voir le calcul de la période, achat par achat »), chaque produit a une ligne « Total » suivie de son nom, en gras : le total des prix (quantité × prix HT de chaque achat affiché, prix de départ compris, ce que rappelle « départ compris »), le total des quantités et l\'écart total du produit ; la dernière ligne, « Total des 12 mois », les additionne pour tous les produits. Un total ne compte que les achats affichés.',
        '« Voir les chiffres » (le tableau des 12 mois) n\'existe que pour « un total par mois ».',
        'Elle n\'existe que dans une société « Commerce ou atelier » (une société de services n\'a pas d\'achats) et ne se montre qu\'aux personnes qui voient les achats du magasin. Les prix comparés sont ceux de chaque magasin, produit par produit ; « Tous mes magasins » additionne les écarts de chaque magasin, sans jamais mélanger leurs prix.',
        'Les avoirs fournisseur et les inventaires d\'ouverture ne comptent pas : seuls les achats validés. Les montants sont additionnés en centimes, chaque achat dans le mois de son magasin, comme les autres graphiques.',
    ];

    /**
     * « Votre exemple, achat par achat » : neuf achats d'un même produit, une
     * pièce chacun, comparés au premier achat de l'année ; chaque ligne est
     * une liste de cellules (texte et classe), avec la note du résultat.
     *
     * @param  list<array{0: string, 1: int}>  $achats  le jour et le prix HT en euros de chaque achat
     * @return array{lignes: list<list<CelluleDeLExemple>>, note: string}
     */
    public static function exemple(array $achats): array
    {
        $cumul = 0;
        $lignes = [];

        foreach ($achats as $rang => [$jour, $prix]) {
            $cellules = [['t' => 'Achat '.($rang + 1), 'c' => ''], ['t' => $jour, 'c' => ''], ['t' => EcritureDesEcarts::euros($prix * 100), 'c' => '']];

            if ($rang === 0) {
                $cellules[] = ['t' => 'prix de départ de l’année', 'c' => 'tiret'];
                $cellules[] = ['t' => '—', 'c' => 'tiret'];
            } else {
                $ecart = ($prix - $achats[0][1]) * 100;
                $cumul += $ecart;
                $cellules[] = ['t' => EcritureDesEcarts::signe($ecart), 'c' => self::classeDeLEcart($ecart)];
                $cellules[] = ['t' => EcritureDesEcarts::signe($cumul), 'c' => self::classeDeLEcart($cumul)];
            }

            $lignes[] = $cellules;
        }

        return [
            'lignes' => $lignes,
            'note' => 'Résultat : '.EcritureDesEcarts::signe($cumul).' payés en plus que si tout avait été acheté au prix du premier achat de l’année (une pièce par achat). Chaque « + » ou « − » est la différence entre deux achats visibles. Le même calcul, fait dans un seul mois, donne l’écart de ce mois.',
        ];
    }

    private static function classeDeLEcart(int $valeur): string
    {
        return $valeur > 0 ? 'plus' : ($valeur < 0 ? 'moins' : '');
    }
}
