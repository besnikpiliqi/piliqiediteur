<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * La carte « Écart des prix d'achat » comparée à la maquette validée par
 * l'éditeur le 21/11/2026 : pour chacun des deux affichages et chacun des
 * douze mois, la lecture du jeu d'achats de la maquette est identique, mot
 * pour mot, nombre pour nombre, classe pour classe, nœud pour nœud du dessin
 * (mêmes clés dans le même ordre, mêmes types), au relevé de la maquette
 * (verite.json) ; l'exemple de l'éditeur, les neuf règles et les textes de
 * la page sont ceux de la maquette. Le message d'un échec donne le chemin du
 * premier écart.
 */
class EcartDesPrixDAchatContreLaMaquetteTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function lectures(): array
    {
        $cas = [];

        foreach ([TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS] as $affichage) {
            for ($mois = 0; $mois < 12; $mois++) {
                $cas["affichage {$affichage}, mois {$mois}"] = [$affichage, $mois];
            }
        }

        return $cas;
    }

    #[DataProvider('lectures')]
    public function test_la_lecture_est_celle_de_la_maquette(string $affichage, int $mois): void
    {
        $verite = LectureDesFixtures::verite();
        $calcul = EcartDesPrixDAchat::calculer($verite['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());

        $obtenu = TextesDeLEcartDesPrixDAchat::lecture($calcul, $affichage, $mois);
        $attendu = $verite['etats'][$affichage][$mois];

        $ecart = LectureDesFixtures::premierEcart($obtenu, $attendu);
        $this->assertNull($ecart, 'Premier écart avec la maquette : '.$ecart);
        $this->assertSame($attendu, $obtenu);
    }

    public function test_les_libelles_et_la_periode_sont_ceux_de_la_maquette(): void
    {
        $verite = LectureDesFixtures::verite();
        $calcul = EcartDesPrixDAchat::calculer($verite['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());

        $this->assertSame($verite['jeu']['mois'], $calcul['mois']);
        $this->assertSame($verite['jeu']['court'], $calcul['courts']);
        $this->assertSame($verite['jeu']['periode'], $calcul['periode']);
        $this->assertSame(array_column($verite['jeu']['produits'], 'nom'), $calcul['produits']);
    }

    public function test_l_exemple_de_l_editeur_donne_ses_lignes_et_sa_note(): void
    {
        $statique = LectureDesFixtures::verite()['statique'];

        $exemple = TextesDeLEcartDesPrixDAchat::exemple(TextesDeLEcartDesPrixDAchat::ACHATS_DE_L_EXEMPLE);

        $this->assertSame($statique['lignesExemple'], $exemple['lignes']);
        $this->assertSame($statique['noteExemple'], $exemple['note']);
        $this->assertStringStartsWith("Résultat : +14,00\u{00A0}€ payés en plus", $exemple['note']);
    }

    public function test_les_neuf_regles_et_les_textes_de_la_page_sont_ceux_de_la_maquette(): void
    {
        $statique = LectureDesFixtures::verite()['statique'];

        $this->assertCount(9, TextesDeLEcartDesPrixDAchat::REGLES);
        $this->assertSame($statique['regles'], TextesDeLEcartDesPrixDAchat::REGLES);
        $this->assertSame($statique['titreRegles'], TextesDeLEcartDesPrixDAchat::TITRE_DES_REGLES);
        $this->assertSame($statique['titrePage'], TextesDeLEcartDesPrixDAchat::TITRE_DE_LA_PAGE);
        $this->assertSame($statique['introduction'], TextesDeLEcartDesPrixDAchat::INTRODUCTION_DE_LA_PAGE);
        $this->assertSame($statique['titreExemple'], TextesDeLEcartDesPrixDAchat::TITRE_DE_L_EXEMPLE);
        $this->assertSame($statique['introExemple'], TextesDeLEcartDesPrixDAchat::INTRODUCTION_DE_L_EXEMPLE);
        $this->assertSame($statique['enteteExemple'], TextesDeLEcartDesPrixDAchat::ENTETE_DE_L_EXEMPLE);
    }

    public function test_l_etat_de_depart_est_le_dernier_mois_par_mois(): void
    {
        $verite = LectureDesFixtures::verite();
        $initial = $verite['initial'];
        $calcul = EcartDesPrixDAchat::calculer($verite['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());

        $this->assertSame(TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, $initial['mode']);
        $this->assertSame(11, $initial['choisi']);

        $lecture = TextesDeLEcartDesPrixDAchat::lecture($calcul, $initial['mode'], $initial['choisi']);

        $this->assertSame($initial['moisChoisi'], $lecture['moisChoisi']);
        $this->assertSame($initial['grand'], $lecture['grand']['texte']);
        $this->assertSame('Écart total du mois : nov. 2026', $lecture['moisChoisi']);
        $this->assertSame("\u{2212}1,85\u{00A0}€", $lecture['grand']['texte']);
    }

    public function test_un_affichage_ou_un_mois_inconnu_est_refuse(): void
    {
        $calcul = EcartDesPrixDAchat::calculer([], LectureDesFixtures::moisDeLaMaquette());

        $this->expectException(InvalidArgumentException::class);
        TextesDeLEcartDesPrixDAchat::lecture($calcul, 'autre', 0);
    }

    public function test_un_mois_choisi_hors_de_zero_a_onze_est_refuse(): void
    {
        $calcul = EcartDesPrixDAchat::calculer([], LectureDesFixtures::moisDeLaMaquette());

        $this->expectException(InvalidArgumentException::class);
        TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, 12);
    }
}
