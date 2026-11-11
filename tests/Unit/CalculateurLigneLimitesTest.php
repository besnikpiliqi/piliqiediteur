<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Les cas limites du calcul des montants (voir CalculateurLigne), que les
 * exemples de CalculateurLigneTest ne touchent pas : la valeur exacte d'une
 * borne, un montant nul, une TVA écrite qu'aucune facture valable ne porte,
 * ce que la répartition de l'écart d'une TVA reporte d'une ligne à
 * l'autre, et les arrondis qui ne tombent juste qu'au bon endroit.
 */
class CalculateurLigneLimitesTest extends TestCase
{
    private const BORNE_DES_CENTIMES = 100_000_000_000_000;

    private function calculateur(): CalculateurLigne
    {
        return new CalculateurLigne;
    }

    /**
     * @return array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int}
     */
    private static function ligneDeVente(int $prixTtc, int $quantite = 1, int $taux = 2000, int $remise = 0): array
    {
        return ['prix_unitaire_ttc' => $prixTtc, 'quantite' => $quantite, 'remise_pourcentage' => $remise, 'taux_tva_du_prix' => $taux, 'taux_tva' => $taux];
    }

    /**
     * Une ligne d'achat, PU HT en millionièmes d'euro.
     *
     * @return array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int}
     */
    private static function ligneDAchat(int $prixMillioniemes, int $quantite = 1, int $taux = 2000, int $remise = 0): array
    {
        return ['prix_unitaire_ht' => $prixMillioniemes, 'quantite' => $quantite, 'remise_pourcentage' => $remise, 'taux_tva' => $taux];
    }

    /**
     * @return array{taux: int, montant_ht: int, montant_tva: int}
     */
    private static function part(int $ht, int $tva, int $taux = 2000): array
    {
        return ['taux' => $taux, 'montant_ht' => $ht, 'montant_tva' => $tva];
    }

    /**
     * Les TVA de ces lignes, dans leur ordre.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<mixed>
     */
    private static function tva(array $lignes): array
    {
        return array_column($lignes, 'montant_tva');
    }

    private function assertRefuse(Closure $calcul, string $message): void
    {
        try {
            $calcul();
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());

            return;
        }

        $this->fail("Le calcul devait être refusé : {$message}");
    }

    /**
     * Chaque borne refuse sa valeur exacte, et pas seulement au-delà : un
     * montant ou un cumul de 1e14 centimes, un prix d'achat x quantité de
     * 1e18 millionièmes, un taux lu à la borne (refusé comme montant, avant
     * d'être lu comme taux), une conversion d'euros qui atteint la borne.
     * Une remise ramène chaque fois le résultat sous la borne : seul le
     * contrôle de la valeur exacte le refuse.
     */
    public function test_chaque_borne_refuse_sa_valeur_exacte(): void
    {
        $c = $this->calculateur();

        $this->assertRefuse(fn () => $c->enDecimal(self::BORNE_DES_CENTIMES), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enDecimal(-self::BORNE_DES_CENTIMES), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->chiffrerUneVente([self::ligneDeVente(10_000_000_000_000, 10, 2000, 1000)]), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->chiffrerUneVente([self::ligneDeVente(50_000_000_000_000), self::ligneDeVente(50_000_000_000_000)]), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->chiffrerUneVente([[...self::ligneDeVente(1000), 'taux_tva_du_prix' => self::BORNE_DES_CENTIMES]]), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->chiffrerUnAchat([self::ligneDAchat(100_000_000_000_000_000, 10, 2000, 1000)]), 'Montant hors limites.');
        $this->assertRefuse(fn () => SaisieDecimale::enCentiemes(1e12), 'Montant hors limites.');
        $this->assertSame(99_999_999_999_999, $c->chiffrerUneVente([self::ligneDeVente(99_999_999_999_999, 1, 0)])['total']['montant_ttc']);
    }

    /**
     * Les plus grandes écritures que la borne laisse passer se lisent
     * entières, et des zéros de tête ne comptent pas dans les douze
     * chiffres entiers permis ; un prix négatif garde son signe, un retour
     * à la ligne final n'est pas une écriture exacte, et un taux s'écrit
     * avec une virgule décimale.
     */
    public function test_les_plus_grandes_ecritures_permises_se_lisent_entieres(): void
    {
        $c = $this->calculateur();

        $this->assertSame(999_999_999_999_999_999, $c->enMillioniemes('999999999999.999999'));
        $this->assertSame(1_500_000, $c->enMillioniemes('0000000000001.5'));
        $this->assertSame(150, $c->enCentiemes('0000000000001.50'));
        $this->assertSame('1000000.000000', $c->enDecimalAuMillionieme(1_000_000_000_000));
        $this->assertSame('0.000000', $c->enDecimalAuMillionieme(0));
        $this->assertSame(-12_500_000, $c->enMillioniemes('-12.5'));
        $this->assertRefuse(fn () => $c->enMillioniemes("1.5\n"), 'Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
        $this->assertSame(['5,5 %', '20 %', '0 %'], [CalculateurLigne::tauxEnTexte(550), CalculateurLigne::tauxEnTexte(2000), CalculateurLigne::tauxEnTexte(0)]);
    }

    /**
     * Un prix nul (un article offert) se vend et s'achète à 0 € ; un HT
     * écrit nul se garde ; une ligne d'achat de 0,02 € n'a pas de TVA, sans
     * que rien ne soit refusé.
     */
    public function test_un_montant_nul_se_calcule_sans_refus(): void
    {
        $c = $this->calculateur();

        $this->assertSame(['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0], $c->chiffrerUneVente([self::ligneDeVente(0, 3)])['total']);
        $this->assertSame(['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0], $c->chiffrerUnAchat([self::ligneDAchat(0, 3)])['total']);
        $this->assertSame(['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0], $c->chiffrerUnAchat([[...self::ligneDAchat(5_000_000), 'montant_ht' => 0]])['total']);
        $this->assertSame(['montant_ht' => 2, 'montant_tva' => 0, 'montant_ttc' => 2], $c->chiffrerUnAchat([self::ligneDAchat(10_000, 2)])['total']);
    }

    /**
     * Chaque ligne d'un achat rend, à côté du HT retenu (celui qu'écrit la
     * facture, sinon la proposition), le HT que le calcul propose : 3 x
     * 10,01 € font 3 003 centimes proposés et retenus ; 2 x 5,00 € font
     * 1 000 proposés, et la facture en écrit 990.
     */
    public function test_chaque_ligne_d_un_achat_rend_le_ht_propose_a_cote_du_ht_retenu(): void
    {
        $achat = $this->calculateur()->chiffrerUnAchat([self::ligneDAchat(10_010_000, 3), [...self::ligneDAchat(5_000_000, 2), 'montant_ht' => 990]]);

        $this->assertSame([
            ['montant_ht' => 3003, 'montant_tva' => 601, 'montant_ttc' => 3604, 'montant_ht_calcule' => 3003],
            ['montant_ht' => 990, 'montant_tva' => 198, 'montant_ttc' => 1188, 'montant_ht_calcule' => 1000],
        ], $achat['lignes']);
    }

    /**
     * Une TVA écrite qu'aucune facture valable ne porte est refusée, sur
     * une facture d'achat comme sur une note d'avoir : négative, non nulle à
     * 0 %, au-delà de sa base ; égale à sa base, elle est acceptée.
     */
    public function test_une_tva_ecrite_impossible_est_refusee_et_une_tva_egale_a_la_base_acceptee(): void
    {
        $c = $this->calculateur();
        $dix = self::ligneDAchat(10_000_000);

        $this->assertRefuse(fn () => $c->chiffrerUnAchat([$dix], [2000 => -1]), 'La TVA à 20 % ne peut pas être négative.');
        $this->assertRefuse(fn () => $c->chiffrerUnAchat([self::ligneDAchat(10_000_000, 1, 0)], [0 => 5]), CalculateurLigne::REFUS_TVA_A_ZERO);
        $this->assertRefuse(fn () => $c->chiffrerUnAchat([$dix], [2000 => 1001]), 'La TVA à 20 % (10,01 €) ne peut pas dépasser sa base (10,00 €).');
        $this->assertSame(1000, $c->chiffrerUnAchat([$dix], [2000 => 1000])['taux'][2000]['montant_tva']);

        $this->assertRefuse(fn () => $c->repartirUneTvaRetenue([self::part(1000, 200)], 2000, -1), 'La TVA à 20 % ne peut pas être négative.');
        $this->assertRefuse(fn () => $c->repartirUneTvaRetenue([self::part(1000, 0, 0)], 0, 1), CalculateurLigne::REFUS_TVA_A_ZERO);
        $this->assertRefuse(fn () => $c->repartirUneTvaRetenue([self::part(1000, 200)], 2000, 1001), 'La TVA à 20 % (10,01 €) ne peut pas dépasser sa base (10,00 €).');
        $this->assertSame([1000], self::tva($c->repartirUneTvaRetenue([self::part(1000, 200)], 2000, 1000)));
    }

    /**
     * La TVA proposée d'un taux est la somme de ses lignes : retenue égale à
     * cette somme, rien ne bouge.
     */
    public function test_une_tva_retenue_egale_a_la_somme_des_propositions_ne_change_rien(): void
    {
        $this->assertSame([200, 200], self::tva($this->calculateur()->repartirUneTvaRetenue([self::part(1000, 200), self::part(1000, 200)], 2000, 400)));
    }

    /**
     * L'écart d'une TVA retenue se répartit au prorata du HT, par cumul, sans
     * qu'aucune ligne ne reçoive une TVA négative ni plus grande que son HT :
     * - deux lignes de 0,01 € HT et 1 centime d'écart : le cumul de la
     *   première vaut un demi-centime, arrondi à 1 ; la seconde, rien ;
     * - une ligne à 0 € HT dont la proposition portait 5 centimes les rend ;
     * - une ligne déjà à son HT (2 centimes) ne reçoit pas sa part (1
     *   centime), qui passe à la ligne suivante : 2 + 3 ;
     * - un écart négatif qui mettrait une ligne sous 0 la garde à 0, et le
     *   reste se retire de la suivante, puis, en remontant, des précédentes ;
     * - ce que la dernière ligne ne peut pas recevoir remonte d'abord sur
     *   celle qui la précède : 5 + 6 + 1, et non 6 + 5 + 1.
     */
    public function test_l_ecart_d_une_tva_retenue_se_reporte_d_une_ligne_a_l_autre(): void
    {
        $c = $this->calculateur();

        $this->assertSame([1, 0], self::tva($c->repartirUneTvaRetenue([self::part(1, 0), self::part(1, 0)], 2000, 1)));
        $this->assertSame([0], self::tva($c->repartirUneTvaRetenue([self::part(0, 5)], 2000, 0)));
        $this->assertSame([2, 3], self::tva($c->repartirUneTvaRetenue([self::part(2, 2), self::part(10, 0)], 2000, 5)));
        $this->assertSame([0, 0], self::tva($c->repartirUneTvaRetenue([self::part(10, 0), self::part(10, 2)], 2000, 0)));
        $this->assertSame([0, 0], self::tva($c->repartirUneTvaRetenue([self::part(10, 5), self::part(10, 0)], 2000, 0)));
        $this->assertSame([5, 6, 1], self::tva($c->repartirUneTvaRetenue([self::part(10, 0), self::part(10, 0), self::part(1, 1)], 2000, 12)));
        $this->assertSame([7, 0, 12, 10], self::tva($c->repartirUneTvaRetenue([self::part(8, 0), self::part(0, 0), self::part(12, 2), self::part(10, 8)], 2000, 29)));
        $this->assertSame([0, 0, 1], self::tva($c->repartirUneTvaRetenue([self::part(10, 0), self::part(10, 0), self::part(10, 5)], 2000, 1)));
        $this->assertSame([4, 0, 7], self::tva($c->repartirUneTvaRetenue([self::part(7, 5), self::part(6, 0), self::part(12, 9)], 2000, 11)));
    }

    /**
     * Une part d'un demi-centime s'arrondit en s'éloignant de zéro, que
     * l'écart ajoute ou retire de la TVA : retirer 1 centime de deux lignes
     * de 1 centime HT qui en portent chacune 1 le retire de la première,
     * comme en ajouter 1 à deux lignes qui n'en portent pas le donne à la
     * première (voir le test précédent).
     */
    public function test_une_part_negative_d_un_demi_centime_s_arrondit_en_s_eloignant_de_zero(): void
    {
        $this->assertSame([0, 1], self::tva($this->calculateur()->repartirUneTvaRetenue([self::part(1, 1), self::part(1, 1)], 2000, 1)));
    }

    /**
     * Une ligne de 0,01 € HT dont la proposition portait 3 centimes de TVA
     * (une note d'avoir qui écrit un HT plus bas que celui des articles
     * retournés), ramenée à une TVA retenue nulle : la part de sa base d'un
     * centime retire les 3 centimes, et la ligne n'en garde aucun. Divisée
     * par une base de 2 centimes, la part n'en retirerait que 2 et la ligne
     * en garderait 1, au-delà de la TVA retenue.
     */
    public function test_la_part_d_une_base_d_un_centime_retire_toute_la_tva_proposee_en_trop(): void
    {
        $this->assertSame([0], self::tva($this->calculateur()->repartirUneTvaRetenue([self::part(1, 3)], 2000, 0)));
    }

    /**
     * Un retour au fournisseur ne propose jamais un montant négatif : quand
     * les retours précédents ont déjà rendu plus que la proposition des
     * articles retournés jusqu'ici, ou exactement autant, il propose 0 € ;
     * quand la note d'avoir écrit un autre HT et que la TVA déjà rendue
     * dépasse celle de tout le HT retenu, ou l'égale, la TVA proposée est
     * 0 €.
     */
    public function test_un_retour_au_fournisseur_ne_propose_jamais_un_montant_negatif(): void
    {
        $c = $this->calculateur();
        $cables = [...self::ligneDAchat(20_000_000, 5), 'montant_ht' => 10000, 'montant_tva' => 2000];
        $rien = ['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0, 'montant_ht_calcule' => 0];

        $this->assertSame($rien, $c->retourDAchat($cables, 2, ['montant_ht' => 5000, 'montant_tva' => 1000]));
        $this->assertSame($rien, $c->retourDAchat($cables, 2, ['montant_ht' => 4000, 'montant_tva' => 800]));
        $this->assertSame(['montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'montant_ht_calcule' => 3900], $c->retourDAchat($cables, 2, ['montant_ht' => 100, 'montant_tva' => 900], 100));
        $this->assertSame(['montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'montant_ht_calcule' => 3900], $c->retourDAchat($cables, 2, ['montant_ht' => 100, 'montant_tva' => 40], 100));
    }

    /**
     * Le HT d'une ligne d'achat s'arrondit une fois, au centime le plus
     * proche, un demi-centime au centime inférieur, quel que soit le
     * découpage de son calcul : 0,010001 € remisé de 0,01 % fait 0,01 €
     * (0,99999999 centime), et 0,025 € remisé de 60 % aussi (1 centime
     * pile) ; de grands prix et de fortes remises tombent sur le centime que
     * donne le calcul exact.
     */
    public function test_le_ht_d_un_achat_s_arrondit_une_fois_au_centime(): void
    {
        $c = $this->calculateur();
        $ht = fn (int $prix, int $quantite, int $remise): int => $c->chiffrerUnAchat([self::ligneDAchat($prix, $quantite, 2000, $remise)])['total']['montant_ht'];

        $this->assertSame(1, $ht(10_001, 1, 1));
        $this->assertSame(1, $ht(25_000, 1, 6000));
        $this->assertSame(2_060_369_237, $ht(59_210_095_998, 648, 4630));
        $this->assertSame(940_371_898, $ht(88_418_129_245, 159, 3311));
        $this->assertSame(131_204_071, $ht(13_506_364_996, 288, 6627));
    }

    /**
     * Le coût net d'un article acheté, son bénéfice, le coût moyen de lots,
     * la valeur d'un stock et un prix de vente arrondi se calculent au bon
     * dix-millième et au bon centime, y compris sur de grands montants où un
     * découpage approché du calcul s'écarterait : 10 000,00 € tout rond,
     * 7 338 320 113,13 € remisés de 20 % puis escomptés de 9 %, ou 100 €
     * de coût moyen sur 100 articles (10 000,00 € HT et 2 000,00 € de TVA).
     */
    public function test_les_couts_et_les_prix_tombent_au_bon_dix_millieme_et_au_bon_centime(): void
    {
        $c = $this->calculateur();

        // PU HT en millionièmes, coûts en dix-millièmes, montants en
        // centimes, taux, remises et escomptes en centièmes de point.
        $this->assertSame(100000000, $c->coutUnitaireNet(10000000000, 0));
        $this->assertSame(70442877906719, $c->coutUnitaireNet(8911180000850000, 1500, 700));
        $this->assertSame(59933575354109, $c->coutUnitaireNet(9035666418530000, 3300, 100));
        $this->assertSame(15762485459273, $c->coutUnitaireNet(7005549093010000, 7500, 1000));
        $this->assertSame(7677477, $c->beneficeUnitaire(7741616, 6413893));
        $this->assertSame(33890, $c->coutMoyenPondere([['quantite' => 41, 'cout_unitaire' => 20943], ['quantite' => 14, 'cout_unitaire' => 98097], ['quantite' => 21, 'cout_unitaire' => 16361]]));
        $this->assertSame(['montant_ht' => 10000, 'montant_tva' => 2000, 'montant_ttc' => 12000], $c->valeurAuCoutMoyen(1, 1000000, 2000));
        $this->assertSame(['montant_ht' => 1000000, 'montant_tva' => 200000, 'montant_ttc' => 1200000], $c->valeurAuCoutMoyen(100, 1000000, 2000));
        $this->assertSame(21990, $c->prixTtcArrondi(1832540, 2000));
        $this->assertSame(2000, $c->prixTtcArrondi(100000, 10000));
        $this->assertRefuse(fn () => $c->valeurAuCoutMoyen(1, 1000000, 10100), 'Le taux de TVA doit être compris entre 0 et 100 %.');
    }

    /**
     * Le coût unitaire d'une ligne d'achat se tire de son HT écrit : ce HT
     * ÷ la quantité, moins l'escompte de la facture, au dix-millième
     * d'euro, arrondi une seule fois (10,00 € HT pour 3 articles font
     * 3,3333 € chacun, 3,25 € avec 2,5 % d'escompte ; 2,00 € HT pour 3,
     * 0,6667 €). Un prix d'achat se lit tel qu'il est enregistré, écrit
     * comme un montant, son signe compris, sans jamais s'arrondir au
     * centime.
     */
    public function test_le_cout_unitaire_et_le_prix_d_un_achat_se_lisent_au_bon_chiffre(): void
    {
        $c = $this->calculateur();

        $this->assertSame([33333, 32500, 6667], [$c->coutUnitaireDuHt(1000, 3), $c->coutUnitaireDuHt(1000, 3, 250), $c->coutUnitaireDuHt(200, 3)]);
        $this->assertSame(
            ['0,1234 €', '1 234,50 €', '-12,50 €', '-0,1234 €', '0,00 €', '1 000 000,00 €', '999 999,999999 €'],
            array_map($c->prixUnitaireExact(...), [123400, 1234500000, -12500000, -123400, 0, 1_000_000_000_000, 999_999_999_999]),
        );
        $this->assertSame(
            ['0.1234', '12.50', '8.333333', '-0.05', '0.00'],
            array_map($c->prixUnitaireExactEnDecimal(...), [123400, 12500000, 8333333, -50000, 0]),
        );
    }

    /**
     * Le coût moyen après une sortie accepte un stock, une quantité et des
     * coûts nuls : rien ne sort d'un stock vide, une sortie de 0 article
     * garde le coût moyen, un article offert sort à 0 €, et un stock à 0 €
     * reste à 0 €.
     */
    public function test_le_cout_moyen_apres_une_sortie_accepte_des_valeurs_nulles(): void
    {
        $c = $this->calculateur();

        $this->assertNull($c->coutMoyenApresUneSortie(0, 60000, 0, 100000));
        $this->assertSame(60000, $c->coutMoyenApresUneSortie(20, 60000, 0, 100000));
        $this->assertSame(40000, $c->coutMoyenApresUneSortie(10, 20000, 5, 0));
        $this->assertSame(0, $c->coutMoyenApresUneSortie(10, 0, 5, 0));
    }

    /**
     * Un montant reçu absent compte pour 0 € ; une conversion en centimes
     * n'arrondit qu'au demi-centime pile, lu chiffre à chiffre :
     * 0,0049999652 € (sous le demi-centime) n'en vaut aucun ; un nombre
     * transmis en flottant se lit d'abord à six décimales, qui effacent son
     * bruit binaire : 0,004999995 € (bruit sous le millionième) vaut
     * 1 centime.
     */
    public function test_les_conversions_en_centimes_arrondissent_au_bon_endroit(): void
    {
        $c = $this->calculateur();

        $this->assertSame(-1250, $c->sommer([null]) - $c->sommer(['12.50']));
        $this->assertSame(1, SaisieDecimale::enCentiemes(0.004999995));
        $this->assertSame(0, SaisieDecimale::enCentiemes('0.0049999652'));
    }

    /**
     * Une écriture décimale se lit chiffre à chiffre : une seule décimale
     * vaut des dizaines de centimes ou de millionièmes (« 1.5 » vaut 150
     * centièmes, 1 500 000 millionièmes), le signe vaut pour toute
     * l'écriture (« -0.05 » vaut -5), douze chiffres entiers passent et
     * treize atteignent la borne ; un entier se lit en unités entières,
     * jusqu'à la borne exclue.
     */
    public function test_une_ecriture_se_lit_chiffre_a_chiffre_jusqu_a_douze_chiffres_entiers(): void
    {
        $c = $this->calculateur();

        $this->assertSame([150, -5, -1250, 0, 99_999_999_999_999], [$c->enCentiemes('1.5'), $c->enCentiemes('-0.05'), $c->enCentiemes('-12.5'), $c->enCentiemes('-0'), $c->enCentiemes('999999999999.99')]);
        $this->assertSame([1_500_000, -50_000, 999_999_999_999_000_000], [$c->enMillioniemes('1.5'), $c->enMillioniemes('-0.05'), $c->enMillioniemes('999999999999')]);
        $this->assertSame([99_999_999_999_900, -99_999_999_999_900, 999_999_999_999_000_000], [$c->enCentiemes(999_999_999_999), $c->enCentiemes(-999_999_999_999), $c->enMillioniemes(999_999_999_999)]);
        $this->assertRefuse(fn () => $c->enCentiemes('1000000000000'), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enCentiemes('-1000000000000.00'), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enMillioniemes('1000000000000'), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enMillioniemes(1_000_000_000_000), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enMillioniemes(-1_000_000_000_000), 'Montant hors limites.');
        $this->assertRefuse(fn () => $c->enCentiemes("1.50\n"), 'Montant illisible : une chaîne décimale exacte (deux décimales au plus) ou un entier est attendu.');
        $this->assertRefuse(fn () => $c->enMillioniemes('x1.5'), 'Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
    }

    /**
     * Le dernier article d'un stock qui ne reste valoir rien, ou moins que
     * rien, garde un coût moyen de 0 € : la valeur d'un stock ne descend
     * jamais sous 0 €, même pour un seul article restant.
     */
    public function test_le_dernier_article_d_un_stock_sans_valeur_restante_vaut_zero(): void
    {
        $c = $this->calculateur();

        $this->assertSame([0, 0, 15000], [
            $c->coutMoyenApresUneSortie(2, 0, 1, 0),
            $c->coutMoyenApresUneSortie(2, 10000, 1, 50000),
            $c->coutMoyenApresUneSortie(2, 20000, 1, 25000),
        ]);
    }
}
