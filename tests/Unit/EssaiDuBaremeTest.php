<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Catalogue\Services\EssaiDuBareme;
use Tests\TestCase;

/**
 * La lecture de « Essayer le barème » : le prix de vente avant et après
 * l'arrondi, et le détail du calcul en phrases, dans l'ordre de la maquette
 * validée par l'éditeur le 22/11/2026.
 */
class EssaiDuBaremeTest extends TestCase
{
    private static function bareme(ArrondiDuPrixDeVente $formule): BaremePrixDeVente
    {
        return new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 6000, $formule);
    }

    /**
     * @param  array{avant: string, apres: string, lignes: list<array{texte: string, grise: bool}>}  $lecture
     * @return list<string>
     */
    private static function textes(array $lecture): array
    {
        return array_column($lecture['lignes'], 'texte');
    }

    public function test_un_essai_lisible_avec_arrondi_montre_le_prix_avant_et_apres_et_le_detail(): void
    {
        $lecture = EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::UnPrixEn9), 4700, 2000);

        $this->assertSame('12,56 €', $lecture['avant']);
        $this->assertSame('9,00 €', $lecture['apres']);
        $this->assertSame([
            ['texte' => '(0,47 € + 10,00 €) × (1 + 20 %) = 12,56 €', 'grise' => false],
            ['texte' => 'Le prix exact est 12,564 € ; l\'arrondi se fait sur ce prix exact, pas sur 12,56 €.', 'grise' => true],
            ['texte' => 'Bénéfice du barème : 10,00 € HT, palier « inférieur à 2,00 € ».', 'grise' => true],
            ['texte' => 'Bénéfice réel 7,03 € HT (visé 10,00 €).', 'grise' => true],
        ], $lecture['lignes']);

        $alEuro = EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::EuroLePlusProche), 4700, 2000);

        $this->assertSame(['12,56 €', '13,00 €'], [$alEuro['avant'], $alEuro['apres']]);
    }

    public function test_le_prix_exact_se_dit_quand_il_a_plus_de_deux_decimales_et_seulement_avec_un_arrondi(): void
    {
        $bareme = new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 400]], null, ArrondiDuPrixDeVente::EuroLePlusProche);
        $lecture = EssaiDuBareme::lire($bareme, 47467, 2000);

        $this->assertSame(['10,50 €', '10,00 €'], [$lecture['avant'], $lecture['apres']]);
        $this->assertSame('(4,7467 € + 4,00 €) × (1 + 20 %) = 10,50 €', self::textes($lecture)[0]);
        $this->assertContains('Le prix exact est 10,49604 € ; l\'arrondi se fait sur ce prix exact, pas sur 10,50 €.', self::textes($lecture));

        $sansArrondi = EssaiDuBareme::lire(new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 400]], null, ArrondiDuPrixDeVente::Aucun), 47467, 2000);
        $auCentime = EssaiDuBareme::lire($bareme, 47500, 2000);

        $this->assertNotContains('Le prix exact est 10,49604 € ; l\'arrondi se fait sur ce prix exact, pas sur 10,50 €.', self::textes($sansArrondi));
        $this->assertCount(3, $sansArrondi['lignes'], 'Sans arrondi : le calcul, le bénéfice du barème, le bénéfice réel.');
        $this->assertCount(3, $auCentime['lignes'], 'Un prix exact de 10,50 € n\'a rien de plus à dire.');
        $this->assertSame('11,00 €', $auCentime['apres'], '10,50 € exactement monte.');
    }

    public function test_un_prix_d_achat_s_ecrit_au_dix_millieme_sans_zero_perdu_et_un_taux_sans_zero_inutile(): void
    {
        $this->assertSame('(0,1234 € + 10,00 €) × (1 + 8,5 %) = 10,98 €', self::textes(EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::Aucun), 1234, 850))[0]);
        $this->assertSame('(0,12 € + 10,00 €) × (1 + 5,5 %) = 10,68 €', self::textes(EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::Aucun), 1200, 550))[0]);
        $this->assertSame('(1 234,50 € + 60,00 €) × (1 + 20 %) = 1 553,40 €', self::textes(EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::Aucun), 12345000, 2000))[0]);
    }

    public function test_le_palier_ou_le_dela_du_dernier_palier_se_dit(): void
    {
        $this->assertContains('Bénéfice du barème : 10,00 € HT, palier « inférieur à 2,00 € ».', self::textes(EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::Aucun), 4700, 2000)));
        $this->assertContains('Bénéfice du barème : 60,00 € HT, au-delà du dernier palier.', self::textes(EssaiDuBareme::lire(self::bareme(ArrondiDuPrixDeVente::Aucun), 990000, 2000)));
    }

    public function test_un_prix_que_la_formule_n_arrondit_pas_le_dit(): void
    {
        $enNeuf = EssaiDuBareme::lire(new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::UnPrixEn9), 49900, 0);
        $alEuro = EssaiDuBareme::lire(new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::EuroLePlusProche), 400, 0);
        $arrondi = EssaiDuBareme::lire(new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::EuroLePlusProche), 185000, 0);

        $this->assertSame('4,99 €', $enNeuf['apres']);
        $this->assertContains('Pas d\'arrondi à ce prix : sous 5 €, le prix reste tel quel.', self::textes($enNeuf));
        $this->assertSame('0,04 €', $alEuro['apres']);
        $this->assertContains('Pas d\'arrondi à ce prix : l\'arrondi donnerait 0 €, le prix reste tel quel.', self::textes($alEuro));
        $this->assertSame('19,00 €', $arrondi['apres']);
        $this->assertNotContains('Pas d\'arrondi à ce prix : sous 5 €, le prix reste tel quel.', self::textes($arrondi));
    }

    public function test_sans_benefice_prevu_l_essai_le_dit_et_ne_montre_aucun_prix(): void
    {
        $lecture = EssaiDuBareme::lire(new BaremePrixDeVente([['jusqu_a' => 5000, 'benefice' => 2000]], null, ArrondiDuPrixDeVente::Aucun), 800000, 2000);

        $this->assertSame([
            'avant' => '—',
            'apres' => '—',
            'lignes' => [['texte' => 'Aucun bénéfice prévu à ce prix d\'achat : le prix de vente se saisit à la main.', 'grise' => false]],
        ], $lecture);
    }

    public function test_un_essai_illisible_demande_les_montants(): void
    {
        $this->assertSame([
            'avant' => '—',
            'apres' => '—',
            'lignes' => [['texte' => 'Saisissez un prix d\'achat HT, une TVA et un barème lisibles : des montants au centime (au dix-millième pour le prix d\'achat), sans lettre.', 'grise' => true]],
        ], EssaiDuBareme::illisible());
    }
}
