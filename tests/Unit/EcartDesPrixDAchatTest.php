<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * Le calcul de la carte « Écart des prix d'achat » (A2 à A4, A8 et A13 de la
 * maquette) : le « premier achat » est le premier de la liste donnée, « achats
 * comparés » compte aussi les achats au même prix, les deux pourcentages n'ont
 * pas la même base, « Prix inchangés » quand l'écart net est nul, les produits
 * masqués, achetés une seule fois ou sans achat, la somme des douze mois, le
 * singulier « ne est pas compté » de la maquette, chacun des cas que le jeu de
 * la maquette ne montre pas (A8) avec le texte exact que produit la référence
 * de calcul, l'entrée refusée et les identités du calcul.
 */
class EcartDesPrixDAchatTest extends TestCase
{
    /**
     * Un calcul à partir de produits écrits (nom, [[mois, jour, quantité, prix en centimes]…]).
     *
     * @param  list<array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>  $produits
     * @return array<string, mixed>
     */
    private static function calculDe(array $produits): array
    {
        return EcartDesPrixDAchat::calculer(
            array_map(fn (array $produit): array => ['nom' => $produit[0], 'achats' => array_map(fn (array $achat): array => ['k' => $achat[0], 'j' => $achat[1], 'q' => $achat[2], 'c' => $achat[3]], $produit[1])], $produits),
            LectureDesFixtures::moisDeLaMaquette(),
        );
    }

    /**
     * Ce que la carte écrit, en gros : les textes, les classes et les lignes du calcul achat par achat.
     *
     * @param  array<string, mixed>  $lecture
     * @return array<string, mixed>
     */
    private static function resume(array $lecture): array
    {
        return [
            'moisChoisi' => $lecture['moisChoisi'],
            'grand' => [$lecture['grand']['texte'], $lecture['grand']['classe']],
            'pastille' => [$lecture['pastille']['texte'], $lecture['pastille']['classe']],
            'detail' => $lecture['detail'],
            'stats' => $lecture['stats'] === null ? null : [$lecture['stats']['nomBase'], $lecture['stats']['base'], $lecture['stats']['nomCout'], $lecture['stats']['cout']],
            'calcul' => array_map(
                fn (array $ligne): array => [$ligne['classe'], array_map(
                    fn (array $cellule): string => $cellule['t'].($cellule['c'] !== '' ? ' {'.$cellule['c'].'}' : '').($cellule['colspan'] > 1 ? ' [colspan '.$cellule['colspan'].']' : ''),
                    $ligne['cellules'],
                )],
                $lecture['calcul']['lignes'],
            ),
            'notes' => $lecture['calcul']['notes'],
        ];
    }

    /**
     * Les cas de A8 : le jeu, l'affichage, le mois choisi et ce que la carte écrit.
     *
     * @return array<string, array{0: list<array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>, 1: string, 2: int, 3: array<string, mixed>}>
     */
    public static function casLimites(): array
    {
        return [
            'mois où l’on a acheté mais aucun produit deux fois' => [
                [['A', [[2, 5, 4, 100]]], ['B', [[2, 9, 2, 250]]]],
                'avant', 2,
                [
                    'moisChoisi' => 'Écart total du mois : févr. 2026',
                    'grand' => ['—', 'grand'],
                    'pastille' => ['Aucun achat comparable ce mois-ci', 'pastille neutre'],
                    'detail' => 'Aucun produit acheté deux fois dans le mois : pas d’écart à calculer.',
                    'stats' => null,
                    'calcul' => [
                        ['', ['Aucun produit avec un écart ce mois-ci. {vide} [colspan 6]']],
                        ['total', ['Total du mois, févr. 2026', '', '', '', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent (2 produits sans écart sont masqués).',
                    ],
                ],
            ],
            'mois où un produit est acheté deux fois au même prix' => [
                [['A', [[0, 5, 4, 100], [0, 20, 4, 100]]]],
                'avant', 0,
                [
                    'moisChoisi' => 'Écart total du mois : déc. 2025',
                    'grand' => ['0,00 €', 'grand'],
                    'pastille' => ['Prix inchangés', 'pastille neutre'],
                    'detail' => 'Hausses 0,00 €, baisses 0,00 €. 0,0 % sur l’achat comparé, qui vaut 4,00 € au prix du premier achat du mois.',
                    'stats' => null,
                    'calcul' => [
                        ['', ['Aucun produit avec un écart ce mois-ci. {vide} [colspan 6]']],
                        ['total', ['Total du mois, déc. 2025', '', '', '', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent (1 produit sans écart est masqué).',
                    ],
                ],
            ],
            'mois avec hausses et baisses de même montant' => [
                [['A', [[0, 3, 1, 100], [0, 10, 1, 110]]], ['B', [[0, 4, 1, 200], [0, 11, 1, 190]]]],
                'avant', 0,
                [
                    'moisChoisi' => 'Écart total du mois : déc. 2025',
                    'grand' => ['0,00 €', 'grand'],
                    'pastille' => ['Prix inchangés', 'pastille neutre'],
                    'detail' => 'Hausses +0,10 €, baisses −0,10 €. 0,0 % sur les 2 achats comparés, qui valent 3,00 € au prix du premier achat du mois.',
                    'stats' => null,
                    'calcul' => [
                        ['', ['A', '3 déc. : 1 × 1,00 €', '— {tiret}', '1', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '10 déc. : 1 × 1,10 €', '1', '+0,10 € {plus}', '+0,10 € {plus}']],
                        ['', ['B', '4 déc. : 1 × 2,00 €', '— {tiret}', '1', '— {tiret}', '— {tiret}']],
                        ['', ['B', ' {tiret}', '11 déc. : 1 × 1,90 €', '1', '−0,10 € {moins}', '−0,10 € {moins}']],
                        ['total', ['Total du mois, déc. 2025', '', '', '', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent.',
                    ],
                ],
            ],
            'mois dont la base est nulle' => [
                [['A', [[1, 3, 5, 0], [1, 10, 5, 100]]]],
                'avant', 1,
                [
                    'moisChoisi' => 'Écart total du mois : janv. 2026',
                    'grand' => ['+5,00 €', 'grand plus'],
                    'pastille' => ['Payé plus cher qu’au premier achat du mois', 'pastille plus'],
                    'detail' => 'Hausses +5,00 €, baisses 0,00 €. — sur l’achat comparé, qui vaut 0,00 € au prix du premier achat du mois.',
                    'stats' => null,
                    'calcul' => [
                        ['', ['A', '3 janv. : 5 × 0,00 €', '— {tiret}', '5', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '10 janv. : 5 × 1,00 €', '5', '+1,00 € {plus}', '+5,00 € {plus}']],
                        ['total', ['Total du mois, janv. 2026', '', '', '', '', '+5,00 € {plus}']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent.',
                    ],
                ],
            ],
            'mois où un seul achat est comparé, avec hausse' => [
                [['A', [[4, 3, 2, 100], [4, 20, 2, 130]]]],
                'avant', 4,
                [
                    'moisChoisi' => 'Écart total du mois : avr. 2026',
                    'grand' => ['+0,60 €', 'grand plus'],
                    'pastille' => ['Payé plus cher qu’au premier achat du mois', 'pastille plus'],
                    'detail' => 'Hausses +0,60 €, baisses 0,00 €. +30,0 % sur l’achat comparé, qui vaut 2,00 € au prix du premier achat du mois.',
                    'stats' => null,
                    'calcul' => [
                        ['', ['A', '3 avr. : 2 × 1,00 €', '— {tiret}', '2', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '20 avr. : 2 × 1,30 €', '2', '+0,30 € {plus}', '+0,60 € {plus}']],
                        ['total', ['Total du mois, avr. 2026', '', '', '', '', '+0,60 € {plus}']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent.',
                    ],
                ],
            ],
            '12 mois : un seul produit acheté une seule fois' => [
                [['A', [[3, 3, 2, 100]]]],
                'debut', 11,
                [
                    'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : déc. 2025 à nov. 2026',
                    'grand' => ['0,00 €', 'grand'],
                    'pastille' => ['Prix inchangés', 'pastille neutre'],
                    'detail' => '1 produit n’a pas d’écart et ne est pas compté (1 acheté une seule fois).',
                    'stats' => ['Payé en plus (hausses)', '0,00 €', 'Payé en moins (baisses)', '0,00 €'],
                    'calcul' => [
                        ['total', ['Total des 12 mois, produits avec écart', 'départ compris {mention}', '0,00 €', '0', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                        'Hausses 0,00 €, baisses 0,00 €, total 0,00 €, soit — : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : 0,00 €). Ce total se calcule à part : ce n’est pas la somme des mois.',
                        'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois. 1 produit n’a pas d’écart et ne est pas compté (1 acheté une seule fois).',
                        'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                        'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total.',
                    ],
                ],
            ],
            '12 mois : aucun achat' => [
                [],
                'debut', 11,
                [
                    'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : déc. 2025 à nov. 2026',
                    'grand' => ['0,00 €', 'grand'],
                    'pastille' => ['Prix inchangés', 'pastille neutre'],
                    'detail' => 'Aucun achat à comparer sur les 12 mois.',
                    'stats' => ['Payé en plus (hausses)', '0,00 €', 'Payé en moins (baisses)', '0,00 €'],
                    'calcul' => [
                        ['total', ['Total des 12 mois, produits avec écart', 'départ compris {mention}', '0,00 €', '0', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                        'Hausses 0,00 €, baisses 0,00 €, total 0,00 €, soit — : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : 0,00 €). Ce total se calcule à part : ce n’est pas la somme des mois.',
                        'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois.',
                        'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                        'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total.',
                    ],
                ],
            ],
            '12 mois : un seul produit avec écart et un achat au même prix que son départ' => [
                [['A', [[0, 3, 10, 100], [2, 4, 10, 100], [5, 9, 10, 120]]]],
                'debut', 11,
                [
                    'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : déc. 2025 à nov. 2026',
                    'grand' => ['+2,00 €', 'grand plus'],
                    'pastille' => ['Payé plus cher qu’au premier achat de l’année', 'pastille plus'],
                    'detail' => '+10,0 % par rapport au prix du premier achat de l’année. Total du produit avec écart : 22,00 € HT.',
                    'stats' => ['Payé en plus (hausses)', '+2,00 €', 'Payé en moins (baisses)', '0,00 €'],
                    'calcul' => [
                        ['', ['A', '3 déc. : 10 × 1,00 €', '— {tiret}', '10', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '9 mai : 10 × 1,20 €', '10', '+0,20 € {plus}', '+2,00 € {plus}']],
                        ['soustotal', ['Total A', 'départ compris {mention}', '22,00 €', '20', '', '+2,00 € {plus}']],
                        ['total', ['Total des 12 mois, produits avec écart', 'départ compris {mention}', '22,00 €', '20', '', '+2,00 € {plus}']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                        'Hausses +2,00 €, baisses 0,00 €, total +2,00 €, soit +10,0 % : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : 20,00 €). Ce total se calcule à part : ce n’est pas la somme des mois.',
                        'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois.',
                        'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                        'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total (ici : 1 achat, 10 pièces, 10,00 €).',
                    ],
                ],
            ],
            '12 mois : quantités à quatre chiffres, deux produits sans écart' => [
                [['A', [[0, 3, 1500, 100], [1, 4, 1200, 110], [2, 4, 1200, 100], [3, 4, 1000, 100]]], ['B', [[1, 8, 3, 500]]], ['C', [[1, 8, 3, 500], [6, 8, 3, 500]]]],
                'debut', 11,
                [
                    'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : déc. 2025 à nov. 2026',
                    'grand' => ['+120,00 €', 'grand plus'],
                    'pastille' => ['Payé plus cher qu’au premier achat de l’année', 'pastille plus'],
                    'detail' => '+4,4 % par rapport au prix du premier achat de l’année. Total du produit avec écart : 2 820,00 € HT.',
                    'stats' => ['Payé en plus (hausses)', '+120,00 €', 'Payé en moins (baisses)', '0,00 €'],
                    'calcul' => [
                        ['', ['A', '3 déc. : 1500 × 1,00 €', '— {tiret}', '1 500', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '4 janv. : 1200 × 1,10 €', '1 200', '+0,10 € {plus}', '+120,00 € {plus}']],
                        ['soustotal', ['Total A', 'départ compris {mention}', '2 820,00 €', '2 700', '', '+120,00 € {plus}']],
                        ['total', ['Total des 12 mois, produits avec écart', 'départ compris {mention}', '2 820,00 €', '2 700', '', '+120,00 € {plus}']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                        'Hausses +120,00 €, baisses 0,00 €, total +120,00 €, soit +4,4 % : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : 2 700,00 €). Ce total se calcule à part : ce n’est pas la somme des mois.',
                        'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois. 2 produits n’ont pas d’écart et ne sont pas comptés (1 acheté une seule fois, 1 toujours au même prix).',
                        'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                        'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total (ici : 2 achats, 2 200 pièces, 2 200,00 €).',
                    ],
                ],
            ],
            '12 mois : écart nul avec hausses et baisses de même montant' => [
                [['A', [[0, 3, 1, 100], [1, 10, 1, 110]]], ['B', [[0, 4, 1, 200], [1, 11, 1, 190]]]],
                'debut', 11,
                [
                    'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : déc. 2025 à nov. 2026',
                    'grand' => ['0,00 €', 'grand'],
                    'pastille' => ['Prix inchangés', 'pastille neutre'],
                    'detail' => '0,0 % par rapport au prix du premier achat de l’année. Total des 2 produits avec écart : 6,00 € HT.',
                    'stats' => ['Payé en plus (hausses)', '+0,10 €', 'Payé en moins (baisses)', '−0,10 €'],
                    'calcul' => [
                        ['', ['A', '3 déc. : 1 × 1,00 €', '— {tiret}', '1', '— {tiret}', '— {tiret}']],
                        ['', ['A', ' {tiret}', '10 janv. : 1 × 1,10 €', '1', '+0,10 € {plus}', '+0,10 € {plus}']],
                        ['soustotal', ['Total A', 'départ compris {mention}', '2,10 €', '2', '', '+0,10 € {plus}']],
                        ['', ['B', '4 déc. : 1 × 2,00 €', '— {tiret}', '1', '— {tiret}', '— {tiret}']],
                        ['', ['B', ' {tiret}', '11 janv. : 1 × 1,90 €', '1', '−0,10 € {moins}', '−0,10 € {moins}']],
                        ['soustotal', ['Total B', 'départ compris {mention}', '3,90 €', '2', '', '−0,10 € {moins}']],
                        ['total', ['Total des 12 mois, produits avec écart', 'départ compris {mention}', '6,00 €', '4', '', '0,00 €']],
                    ],
                    'notes' => [
                        'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                        'Hausses +0,10 €, baisses −0,10 €, total 0,00 €, soit 0,0 % : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : 6,00 €). Ce total se calcule à part : ce n’est pas la somme des mois.',
                        'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois.',
                        'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                        'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total.',
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('casLimites')]
    public function test_chaque_cas_limite_donne_le_texte_de_la_reference(array $produits, string $affichage, int $mois, array $attendu): void
    {
        $lecture = TextesDeLEcartDesPrixDAchat::lecture(self::calculDe($produits), $affichage, $mois);

        $this->assertSame($attendu, self::resume($lecture));
    }

    public function test_le_premier_achat_est_le_premier_de_la_liste_meme_le_meme_jour(): void
    {
        $dansLOrdre = self::calculDe([['A', [[0, 5, 1, 1000], [0, 5, 2, 1200]]]]);
        $alEnvers = self::calculDe([['A', [[0, 5, 2, 1200], [0, 5, 1, 1000]]]]);

        $this->assertSame(1000, $dansLOrdre['calculs'][0]['premiers'][0]['e']['c']);
        $this->assertSame(400, $dansLOrdre['calculs'][0]['ecart']);
        $this->assertSame(400, $dansLOrdre['calculDeLaPeriode']['ecart']);
        $this->assertSame(1200, $alEnvers['calculs'][0]['premiers'][0]['e']['c']);
        $this->assertSame(-200, $alEnvers['calculs'][0]['ecart']);
        $this->assertSame(-200, $alEnvers['calculDeLaPeriode']['ecart']);
        $this->assertSame(200, $alEnvers['calculs'][0]['baisses']);
        $this->assertSame(0, $alEnvers['calculs'][0]['hausses']);
    }

    public function test_l_ecart_se_calcule_avec_la_quantite_de_l_achat_compare_jamais_celle_du_depart(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 100, 1000], [0, 2, 3, 1100]]]]);

        $this->assertSame(300, $calcul['calculs'][0]['ecart']);
        $this->assertSame(3000, $calcul['calculs'][0]['base']);
        $this->assertSame(3300, $calcul['calculs'][0]['cout']);
    }

    public function test_les_achats_compares_comptent_ceux_au_meme_prix_et_la_base_n_a_jamais_le_depart(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 1, 100], [0, 2, 1, 100], [0, 3, 1, 110]]]]);

        $mois = $calcul['calculs'][0];

        $this->assertSame(2, $mois['n']);
        $this->assertSame(1, $mois['sans']);
        $this->assertSame(10, $mois['hausses']);
        $this->assertSame(200, $mois['base']);
        $this->assertSame(210, $mois['cout']);
        $this->assertSame(10, $mois['ecart']);
        $this->assertCount(2, $mois['pas']);
    }

    public function test_les_deux_pourcentages_n_ont_pas_la_meme_base(): void
    {
        $calcul = self::calculDe([
            ['A', [[0, 1, 5, 100], [1, 1, 5, 110], [2, 1, 5, 100]]],
            ['B', [[3, 1, 2, 100], [3, 2, 2, 100], [3, 3, 2, 150]]],
        ]);

        // Par mois : la base du mois 3 est celle des deux achats comparés, au prix du départ (jamais le départ).
        $this->assertSame(400, $calcul['calculs'][3]['base']);
        $this->assertSame(100, $calcul['calculs'][3]['ecart']);
        $this->assertStringContainsString('+25,0 % sur les 2 achats comparés, qui valent 4,00'."\u{00A0}".'€ au prix', TextesDeLEcartDesPrixDAchat::lecture($calcul, 'avant', 3)['detail']);

        // Sur les 12 mois : A vaut +50 (départ 5 × 1,00 €, puis 5 × 1,10 € ; le troisième achat est au même prix, jamais compté),
        // B vaut +100 (départ 2 × 1,00 €, puis 2 × 1,50 €), et la base est le total des prix moins l'écart.
        $periode = $calcul['calculDeLaPeriode'];
        $this->assertSame(150, $periode['ecart']);
        $this->assertSame(500 + 550 + 200 + 300, $periode['montant']);
        $this->assertSame(1400, $periode['base']);
        $this->assertSame(['n' => 2, 'qte' => 7, 'montant' => 700], $periode['egaux']);
        $this->assertStringStartsWith('+10,7 % par rapport au prix du premier achat de l’année.', TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 11)['detail']);
    }

    public function test_un_produit_achete_une_fois_par_mois_n_est_dans_aucun_mois_mais_dans_les_douze_mois(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 1, 100], [1, 1, 1, 110]]]]);

        $this->assertSame([0, 0], [$calcul['calculs'][0]['n'], $calcul['calculs'][1]['n']]);
        $this->assertSame([1, 1], [$calcul['calculs'][0]['sans'], $calcul['calculs'][1]['sans']]);
        $this->assertSame(10, $calcul['calculDeLaPeriode']['ecart']);
        $this->assertSame(1, $calcul['calculDeLaPeriode']['visibles']);
        $this->assertSame(0, $calcul['calculDeLaPeriode']['uneFois']);
        $this->assertSame(0, $calcul['calculDeLaPeriode']['memePrix']);
    }

    public function test_un_produit_sans_aucun_achat_n_est_compte_nulle_part(): void
    {
        $calcul = self::calculDe([['A', []], ['B', [[0, 1, 1, 100]]], ['C', [[0, 1, 1, 100], [4, 1, 1, 100]]]]);

        $periode = $calcul['calculDeLaPeriode'];

        $this->assertSame(1, $periode['uneFois']);
        $this->assertSame(1, $periode['memePrix']);
        $this->assertSame(0, $periode['visibles']);
        $this->assertSame(1, $periode['n']);
        $this->assertSame(['A', 'B', 'C'], $calcul['produits']);
        $this->assertStringContainsString('2 produits n’ont pas d’écart et ne sont pas comptés (1 acheté une seule fois, 1 toujours au même prix).', TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 11)['detail']);
    }

    public function test_les_produits_masques_d_un_mois_comptent_ceux_achetes_une_fois_comme_ceux_au_meme_prix(): void
    {
        $calcul = self::calculDe([
            ['A', [[0, 1, 1, 100], [0, 2, 1, 120]]],
            ['B', [[0, 3, 1, 100]]],
            ['C', [[0, 4, 1, 100], [0, 5, 1, 100]]],
            ['D', [[0, 6, 1, 100], [0, 7, 1, 90]]],
        ]);

        $notes = TextesDeLEcartDesPrixDAchat::lecture($calcul, 'avant', 0)['calcul']['notes'];

        $this->assertStringEndsWith('apparaissent (2 produits sans écart sont masqués).', $notes[0]);
        $this->assertSame(4, $calcul['calculs'][0]['sans']);
    }

    public function test_la_ligne_somme_des_douze_mois_est_coloree_meme_a_zero(): void
    {
        $lecture = TextesDeLEcartDesPrixDAchat::lecture(self::calculDe([]), 'avant', 11);
        $somme = $lecture['chiffres']['lignes'][12];

        $this->assertSame('total', $somme['classe']);
        $this->assertSame('Somme des douze mois', $somme['cellules'][0]['t']);
        $this->assertSame(['0,00'."\u{00A0}".'€', 'plus'], [$somme['cellules'][1]['t'], $somme['cellules'][1]['c']]);
        $this->assertSame(['0,00'."\u{00A0}".'€', 'moins'], [$somme['cellules'][2]['t'], $somme['cellules'][2]['c']]);
        $this->assertSame(['0,00'."\u{00A0}".'€', ''], [$somme['cellules'][3]['t'], $somme['cellules'][3]['c']]);
        $this->assertSame('0', $somme['cellules'][4]['t']);
        $this->assertSame(['—', 'tiret'], [$lecture['chiffres']['lignes'][0]['cellules'][1]['t'], $lecture['chiffres']['lignes'][0]['cellules'][1]['c']]);
    }

    public function test_le_tableau_des_chiffres_n_existe_que_pour_l_affichage_par_mois(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 1, 100], [0, 2, 1, 110]]]]);

        $parMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, 'avant', 0);
        $surDouzeMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 0);

        $this->assertFalse($parMois['chiffres']['lien']['cache']);
        $this->assertCount(13, $parMois['chiffres']['lignes']);
        $this->assertNull($parMois['stats']);
        $this->assertTrue($surDouzeMois['chiffres']['lien']['cache']);
        $this->assertNull($surDouzeMois['chiffres']['lignes']);
        $this->assertSame(['nomBase' => 'Payé en plus (hausses)', 'base' => "+0,10\u{00A0}€", 'nomCout' => 'Payé en moins (baisses)', 'cout' => "0,00\u{00A0}€"], $surDouzeMois['stats']);
        $this->assertSame(['moisCachee' => true, 'periodeCachee' => false], $surDouzeMois['vues']);
        $this->assertSame(['moisCachee' => false, 'periodeCachee' => true], $parMois['vues']);
    }

    public function test_le_singulier_est_celui_de_la_maquette_ne_est_pas_compte(): void
    {
        $detail = TextesDeLEcartDesPrixDAchat::lecture(self::calculDe([['A', [[3, 3, 2, 100]]]]), 'debut', 11)['detail'];

        $this->assertSame('1 produit n’a pas d’écart et ne est pas compté (1 acheté une seule fois).', $detail);
    }

    public function test_le_mois_choisi_ne_change_ni_le_calcul_ni_les_textes_des_douze_mois(): void
    {
        $calcul = self::calculDe([['A', [[0, 1, 1, 100], [1, 1, 1, 110]]]]);

        $premier = TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 0);
        $dernier = TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 11);

        $this->assertSame(0, $premier['mois']);
        $this->assertSame(11, $dernier['mois']);
        $this->assertSame($premier['detail'], $dernier['detail']);
        $this->assertSame($premier['calcul'], $dernier['calcul']);
        $this->assertSame($premier['grand'], $dernier['grand']);
    }

    /**
     * Les entrées refusées : [produits, exception].
     *
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: class-string<\Throwable>}>
     */
    public static function entreesRefusees(): array
    {
        $achat = ['k' => 0, 'j' => 1, 'q' => 1, 'c' => 100];

        return [
            'un prix non entier' => [[['nom' => 'A', 'achats' => [['c' => 1.5] + $achat]]], InvalidArgumentException::class],
            'un prix à virgule qui vaut un entier' => [[['nom' => 'A', 'achats' => [['c' => 100.0] + $achat]]], InvalidArgumentException::class],
            'une quantité écrite en texte' => [[['nom' => 'A', 'achats' => [['q' => '2'] + $achat]]], InvalidArgumentException::class],
            'un jour absent' => [[['nom' => 'A', 'achats' => [['k' => 0, 'q' => 1, 'c' => 100]]]], InvalidArgumentException::class],
            'un mois nul' => [[['nom' => 'A', 'achats' => [['k' => null] + $achat]]], InvalidArgumentException::class],
            'un mois négatif' => [[['nom' => 'A', 'achats' => [['k' => -1] + $achat]]], InvalidArgumentException::class],
            'un mois au-delà de 11' => [[['nom' => 'A', 'achats' => [['k' => 12] + $achat]]], InvalidArgumentException::class],
            'une quantité hors des entiers sûrs' => [[['nom' => 'A', 'achats' => [['q' => 9007199254740992] + $achat]]], InvalidArgumentException::class],
            'un prix négatif hors des entiers sûrs' => [[['nom' => 'A', 'achats' => [['c' => -9007199254740992] + $achat]]], InvalidArgumentException::class],
            'un produit dont le calcul dépasse les entiers' => [[['nom' => 'A', 'achats' => [['c' => 9007199254740991, 'q' => 9007199254740991, 'k' => 0, 'j' => 1], ['c' => 1, 'q' => 9007199254740991, 'k' => 0, 'j' => 2]]]], OverflowException::class],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $produits
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('entreesRefusees')]
    public function test_une_entree_refusee_leve_une_exception_jamais_un_nombre_a_virgule(array $produits, string $exception): void
    {
        $this->expectException($exception);

        EcartDesPrixDAchat::calculer($produits, LectureDesFixtures::moisDeLaMaquette());
    }

    public function test_les_sommes_qui_depassent_les_entiers_sont_refusees(): void
    {
        $plusGrand = 9007199254740991;
        $achats = [];

        for ($i = 0; $i < 2000; $i++) {
            $achats[] = ['k' => 0, 'j' => 1, 'q' => 1, 'c' => $plusGrand];
        }

        $this->expectException(OverflowException::class);

        EcartDesPrixDAchat::calculer([['nom' => 'A', 'achats' => [['k' => 0, 'j' => 1, 'q' => 1, 'c' => 0], ...$achats]]], LectureDesFixtures::moisDeLaMaquette());
    }

    public function test_une_periode_qui_n_a_pas_douze_mois_ou_un_mois_illisible_est_refuse(): void
    {
        foreach ([array_slice(LectureDesFixtures::moisDeLaMaquette(), 0, 11), [...LectureDesFixtures::moisDeLaMaquette(), '2026-12'], [...array_slice(LectureDesFixtures::moisDeLaMaquette(), 0, 11), 'nov. 2026']] as $mois) {
            try {
                EcartDesPrixDAchat::calculer([], $mois);
                $this->fail('Une période de douze mois « AAAA-MM » est exigée.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_le_libelle_et_le_nom_court_de_chaque_mois_suivent_la_periode(): void
    {
        $calcul = EcartDesPrixDAchat::calculer([], ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12']);

        $this->assertSame('janv. 2026', $calcul['mois'][0]);
        $this->assertSame('déc. 2026', $calcul['mois'][11]);
        $this->assertSame('janv.', $calcul['courts'][0]);
        $this->assertSame('janv. 2026 à déc. 2026', $calcul['periode']);
    }

    public function test_le_total_du_mois_est_la_somme_des_lignes_affichees_et_le_total_des_prix_moins_les_quantites_au_depart_est_l_ecart(): void
    {
        $jeux = [LectureDesFixtures::verite()['jeu']['produits']];

        foreach (LectureDesFixtures::jeuxAuHasard()['jeux'] as $jeu) {
            $jeux[] = $jeu['produits'];
        }

        foreach ($jeux as $rang => $produits) {
            $calcul = EcartDesPrixDAchat::calculer($produits, LectureDesFixtures::moisDeLaMaquette());

            foreach ($calcul['calculs'] as $mois) {
                $somme = array_sum(array_map(fn (array $pas): int => $pas['ecart'], $mois['pas']));
                $this->assertSame($mois['ecart'], $somme, "Jeu {$rang}, mois {$mois['k']} : le total du mois n'est pas la somme de ses lignes.");
                $this->assertSame($mois['hausses'] - $mois['baisses'], $mois['ecart']);
                $this->assertSame($mois['cout'] - $mois['base'], $mois['ecart']);
            }

            $periode = $calcul['calculDeLaPeriode'];
            $this->assertSame($periode['hausses'] - $periode['baisses'], $periode['ecart']);

            foreach ($periode['lignes'] as $ligne) {
                $this->assertSame($ligne['net'], $ligne['montant'] - $ligne['qte'] * $ligne['ref']['c'], "Jeu {$rang}, {$ligne['nom']} : total des prix − quantités × départ ≠ écart.");
            }

            $this->assertSame($periode['ecart'], array_sum(array_column($periode['lignes'], 'net')));
            $this->assertSame($periode['montant'], array_sum(array_column($periode['lignes'], 'montant')));
            $this->assertSame($periode['qte'], array_sum(array_column($periode['lignes'], 'qte')));
        }
    }

    public function test_trois_cents_produits_et_douze_mille_achats_donnent_une_lecture_de_taille_bornee(): void
    {
        $produits = [];

        for ($produit = 0; $produit < 300; $produit++) {
            $achats = [];

            for ($rang = 0; $rang < 40; $rang++) {
                $achats[] = ['k' => intdiv($rang * 12, 40), 'j' => 1 + ($rang * 7 + $produit) % 28, 'q' => 1 + ($rang + $produit) % 9, 'c' => 1000 + (($rang * 37 + $produit * 11) % 5) * 10];
            }

            $produits[] = ['nom' => 'Produit '.($produit + 1), 'achats' => $achats];
        }

        $calcul = EcartDesPrixDAchat::calculer($produits, LectureDesFixtures::moisDeLaMaquette());

        $this->assertSame(12000, array_sum(array_map(fn (array $produit): int => count($produit['achats']), $produits)));
        $this->assertLessThan(500000, strlen(json_encode(TextesDeLEcartDesPrixDAchat::lecture($calcul, 'avant', 11), JSON_THROW_ON_ERROR)));
        $this->assertLessThan(4000000, strlen(json_encode(TextesDeLEcartDesPrixDAchat::lecture($calcul, 'debut', 11), JSON_THROW_ON_ERROR)));
    }
}
