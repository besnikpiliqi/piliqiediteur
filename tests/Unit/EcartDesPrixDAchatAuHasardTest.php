<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * La carte « Écart des prix d'achat » comparée à la référence de calcul de la
 * maquette sur 40 jeux d'achats tirés une fois pour toutes (graine 36, dont
 * les douze cas forcés : aucun achat, achats uniques, tout au même prix,
 * baisses seules, hausses seules, un seul mois, beaucoup d'achats, gros
 * montants, prix à zéro, même jour, écart d'un centime) : pour chacun, le
 * SHA-256 de chacune des 13 lectures de PHP (l'affichage par mois du mois 0
 * au mois 11, puis l'affichage des 12 mois, mois choisi 11) est celui écrit
 * dans jeux-au-hasard.json par la référence JavaScript de la maquette. Les
 * jeux sont figés dans le dépôt : aucun test ne dépend du hasard ni de la
 * date.
 */
class EcartDesPrixDAchatAuHasardTest extends TestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function jeux(): array
    {
        $cas = [];

        foreach (LectureDesFixtures::jeuxAuHasard()['jeux'] as $jeu) {
            $cas['jeu '.$jeu['numero'].' (forme '.$jeu['forme'].')'] = [$jeu['numero']];
        }

        return $cas;
    }

    public function test_les_jeux_sont_les_quarante_de_la_graine_trente_six(): void
    {
        $fichier = LectureDesFixtures::jeuxAuHasard();

        $this->assertSame(36, $fichier['graine']);
        $this->assertCount(40, $fichier['jeux']);
        $this->assertSame(range(0, 11), array_map(fn (array $jeu): int => $jeu['forme'] - 1, array_slice($fichier['jeux'], 0, 12)));

        foreach ($fichier['jeux'] as $jeu) {
            $this->assertCount(13, $jeu['empreintes']);
        }
    }

    #[DataProvider('jeux')]
    public function test_les_treize_lectures_ont_les_empreintes_de_la_reference(int $numero): void
    {
        $jeu = LectureDesFixtures::jeuxAuHasard()['jeux'][$numero];
        $calcul = EcartDesPrixDAchat::calculer($jeu['produits'], LectureDesFixtures::moisDeLaMaquette());

        for ($mois = 0; $mois < 12; $mois++) {
            $this->assertSame(
                $jeu['empreintes'][$mois],
                LectureDesFixtures::empreinte(LectureDesFixtures::avecLesBoutonsDeLaMaquette(TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, $mois))),
                "Jeu {$numero}, affichage par mois, mois {$mois} : la lecture diffère de la référence.",
            );
        }

        $this->assertSame(
            $jeu['empreintes'][12],
            LectureDesFixtures::empreinte(LectureDesFixtures::avecLesBoutonsDeLaMaquette(TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS, 11))),
            "Jeu {$numero}, affichage des 12 mois : la lecture diffère de la référence.",
        );
    }
}
