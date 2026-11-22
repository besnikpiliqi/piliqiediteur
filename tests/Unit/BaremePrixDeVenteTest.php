<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Facturation\Services\SaisieDecimale;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Le calculateur de prix fourni par le magasin : prix de vente TTC =
 * (prix d'achat HT + bénéfice du palier) + TVA, arrondi selon la formule
 * du magasin s'il le veut. Les montants du barème et les prix rendus sont
 * en centimes, le prix d'achat en dix-millièmes d'euro, les taux en
 * centièmes de point.
 */
class BaremePrixDeVenteTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function prixDAchatEtBenefices(): array
    {
        return [
            'sous le premier palier' => [4700, 1000],
            'juste sous 2 €' => [19900, 1000],
            '2 € passe au palier suivant' => [20000, 1500],
            'juste sous 99 €' => [989900, 5000],
            '99 € passe au-delà' => [990000, 6000],
            'bien au-delà' => [5000000, 6000],
        ];
    }

    #[DataProvider('prixDAchatEtBenefices')]
    public function test_le_benefice_depend_du_palier_du_prix_dachat(int $prixAchatHt, int $benefice): void
    {
        $this->assertSame($benefice, BaremePrixDeVente::parDefaut()->beneficePour($prixAchatHt));
    }

    public function test_le_prix_de_vente_ajoute_le_benefice_au_prix_dachat_puis_la_tva(): void
    {
        $bareme = BaremePrixDeVente::parDefaut();

        $this->assertSame(['prix_vente_ttc' => 1256, 'prix_cible_ttc' => 1256, 'benefice' => 1000, 'laisse_tel_quel' => false], $bareme->prixDeVente(4700, 2000));
        $this->assertSame(19200, $bareme->prixDeVente(1000000, 2000)['prix_vente_ttc']);
        $this->assertSame(6330, $bareme->prixDeVente(200000, 550)['prix_vente_ttc'], '(20 + 40) x 1,055 = 63,30.');
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function prixArrondisAuX9(): array
    {
        return [
            '77,60 monte' => [776000, 7900],
            '74,00 descend' => [740000, 6900],
            '134,40 descend' => [1344000, 12900],
            '184,80 monte' => [1848000, 17900],
            '75,00 monte' => [750000, 7900],
            '5,00 donne 9' => [50000, 900],
            'sous 5 € reste tel quel' => [49900, 499],
        ];
    }

    #[DataProvider('prixArrondisAuX9')]
    public function test_l_arrondi_ramene_le_prix_au_x9_le_plus_proche(int $prixCibleTtc, int $prixArrondi): void
    {
        $sansBeneficeNiTva = new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::UnPrixEn9);

        $this->assertSame($prixArrondi, $sansBeneficeNiTva->prixDeVente($prixCibleTtc, 0)['prix_vente_ttc']);
    }

    /**
     * Sans bénéfice au-delà du dernier palier, le barème ne propose rien
     * pour un article plus cher : son prix de vente se saisit à la main.
     */
    public function test_sans_benefice_au_dela_un_article_plus_cher_n_a_pas_de_prix_propose(): void
    {
        $bareme = new BaremePrixDeVente([['jusqu_a' => 5000, 'benefice' => 2000]], null, ArrondiDuPrixDeVente::Aucun);

        $this->assertSame(6000, $bareme->prixDeVente(300000, 2000)['prix_vente_ttc']);
        $this->assertNull($bareme->beneficePour(500000));
        $this->assertNull($bareme->prixDeVente(800000, 2000));
    }

    public function test_un_bareme_vide_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([], null, ArrondiDuPrixDeVente::Aucun);
    }

    /**
     * L'arrondi au X9 part du prix cible exact, comme le calculateur du
     * magasin, et jamais du prix déjà arrondi au centime : (10,83 € + 35 €)
     * x 1,2 = 54,996 € reste sous la demi-dizaine et donne 49 €, même si le
     * prix cible affiché, au centime, est 55,00 €.
     *
     * @return array<string, array{int, int, int, int}>
     */
    public static function prixCiblesJusteSousLaDemiDizaine(): array
    {
        return [
            '54,996 € à 20 %' => [108300, 2000, 4900, 5500],
            '114,995 € à 5,5 %' => [640000, 550, 10900, 11500],
            '64,999 € à 10 %' => [190900, 1000, 5900, 6500],
        ];
    }

    #[DataProvider('prixCiblesJusteSousLaDemiDizaine')]
    public function test_l_arrondi_x9_part_du_prix_cible_exact(int $prixAchatHt, int $tauxTva, int $prixArrondi, int $prixCibleAffiche): void
    {
        $bareme = new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 6000, ArrondiDuPrixDeVente::UnPrixEn9);

        $this->assertSame(['prix_vente_ttc' => $prixArrondi, 'prix_cible_ttc' => $prixCibleAffiche], array_intersect_key(
            $bareme->prixDeVente($prixAchatHt, $tauxTva) ?? [],
            ['prix_vente_ttc' => true, 'prix_cible_ttc' => true],
        ));
    }

    public function test_un_palier_en_double_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([['jusqu_a' => 200, 'benefice' => 1000], ['jusqu_a' => 200, 'benefice' => 1500]], 6000, ArrondiDuPrixDeVente::Aucun);
    }

    public function test_un_benefice_negatif_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([['jusqu_a' => 200, 'benefice' => -100]], 6000, ArrondiDuPrixDeVente::Aucun);
    }

    public function test_les_paliers_sont_ranges_par_prix_dachat(): void
    {
        $bareme = BaremePrixDeVente::depuisTableau([
            'paliers' => [['jusqu_a' => '10', 'benefice' => '30'], ['jusqu_a' => '2', 'benefice' => '10']],
            'benefice_au_dela' => '60',
            'arrondi' => 'aucun',
        ]);

        $this->assertSame(1000, $bareme->beneficePour(10000));
        $this->assertSame(3000, $bareme->beneficePour(50000));
    }

    /**
     * Un palier à 0 € ou en dessous n'existe pas, et un bénéfice au-delà
     * des paliers ne peut pas être négatif, même sans palier.
     */
    public function test_un_palier_a_zero_ou_un_benefice_au_dela_negatif_est_refuse(): void
    {
        foreach ([fn () => new BaremePrixDeVente([['jusqu_a' => 0, 'benefice' => 1000]], 6000, ArrondiDuPrixDeVente::Aucun), fn () => new BaremePrixDeVente([['jusqu_a' => -200, 'benefice' => 1000]], 6000, ArrondiDuPrixDeVente::Aucun)] as $bareme) {
            $this->assertThrows($bareme, InvalidArgumentException::class, 'Chaque palier du barème a son propre prix d\'achat, supérieur à 0.');
        }

        foreach ([fn () => new BaremePrixDeVente([['jusqu_a' => 200, 'benefice' => 1000]], -100, ArrondiDuPrixDeVente::Aucun), fn () => new BaremePrixDeVente([], -100, ArrondiDuPrixDeVente::Aucun)] as $bareme) {
            $this->assertThrows($bareme, InvalidArgumentException::class, 'Un bénéfice du barème ne peut pas être négatif.');
        }
    }

    /**
     * Les montants d'un palier, et le bénéfice au-delà du dernier, sont des
     * centimes entiers : un nombre à virgule, que l'on prendrait pour des
     * euros (10.0 pour 10,00 €), est refusé dès la construction du barème,
     * au lieu de valoir 10 centimes -- même depuis ce fichier, sans
     * strict_types, où PHP convertirait 60.0 en 60 sans rien dire.
     */
    public function test_un_palier_aux_montants_a_virgule_est_refuse(): void
    {
        foreach ([
            fn () => new BaremePrixDeVente([['jusqu_a' => 10.0, 'benefice' => 2000]], null, ArrondiDuPrixDeVente::Aucun),
            fn () => new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 20.0]], null, ArrondiDuPrixDeVente::Aucun),
            fn () => new BaremePrixDeVente([['jusqu_a' => '1000', 'benefice' => 2000]], null, ArrondiDuPrixDeVente::Aucun),
            fn () => new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 2000]], 60.0, ArrondiDuPrixDeVente::Aucun),
            fn () => new BaremePrixDeVente([], '6000', ArrondiDuPrixDeVente::Aucun),
        ] as $bareme) {
            $this->assertThrows($bareme, InvalidArgumentException::class, 'Les montants du barème sont des centimes entiers, jamais un nombre à virgule.');
        }
    }

    /**
     * Un montant du barème saisi s'écrit au centime et ne s'arrondit
     * jamais en silence : un seuil, un bénéfice ou un bénéfice au-delà à
     * trois décimales est refusé, au lieu de devenir 2,01 €, 0,13 € ou
     * 60,00 € ; des zéros après le centime ne comptent pas.
     */
    public function test_un_montant_du_bareme_plus_precis_que_le_centime_est_refuse_jamais_arrondi(): void
    {
        foreach ([
            ['paliers' => [['jusqu_a' => '2.005', 'benefice' => '10']], 'benefice_au_dela' => null],
            ['paliers' => [['jusqu_a' => '50', 'benefice' => '0.125']], 'benefice_au_dela' => null],
            ['paliers' => [['jusqu_a' => '50', 'benefice' => '10']], 'benefice_au_dela' => '60.001'],
        ] as $bareme) {
            $this->assertThrows(fn () => BaremePrixDeVente::depuisTableau([...$bareme, 'arrondi' => 'aucun']), InvalidArgumentException::class, SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        }

        $bareme = BaremePrixDeVente::depuisTableau(['paliers' => [['jusqu_a' => '2.500', 'benefice' => '0.120']], 'benefice_au_dela' => '60.000', 'arrondi' => 'aucun']);

        $this->assertSame([['jusqu_a' => 250, 'benefice' => 12]], $bareme->paliers);
        $this->assertSame(6000, $bareme->beneficeAuDela);
    }

    /**
     * Le barème saisi dans un formulaire, en texte, se lit en nombres, au
     * centime : deux paliers écrits « 2 » et « 2.0 » sont le même prix
     * d'achat, donc refusés ; un bénéfice au-delà écrit se garde, absent il
     * n'y en a pas.
     */
    public function test_le_bareme_d_un_formulaire_se_lit_en_nombres(): void
    {
        $bareme = BaremePrixDeVente::depuisTableau(['paliers' => [['jusqu_a' => '2', 'benefice' => '10']], 'benefice_au_dela' => '60', 'arrondi' => 'x9']);

        $this->assertSame([['jusqu_a' => 200, 'benefice' => 1000]], $bareme->paliers);
        $this->assertSame(6000, $bareme->beneficeAuDela);
        $this->assertNull(BaremePrixDeVente::depuisTableau(['paliers' => [['jusqu_a' => '2', 'benefice' => '10']], 'benefice_au_dela' => '', 'arrondi' => 'aucun'])->beneficeAuDela);
        $this->assertThrows(
            fn () => BaremePrixDeVente::depuisTableau(['paliers' => [['jusqu_a' => '2', 'benefice' => '10'], ['jusqu_a' => '2.0', 'benefice' => '15']], 'benefice_au_dela' => null, 'arrondi' => 'aucun']),
            InvalidArgumentException::class,
            'Chaque palier du barème a son propre prix d\'achat, supérieur à 0.',
        );
    }

    /**
     * Le barème s'enregistre en tableau (voir castUsing()), chaque montant
     * écrit comme le champ de la page des paramètres l'écrit, et se relit
     * à l'identique : ses paliers, son bénéfice au-delà et sa formule
     * d'arrondi.
     */
    public function test_le_bareme_s_ecrit_en_tableau_et_se_relit_a_l_identique(): void
    {
        $tableau = ['paliers' => [['jusqu_a' => '2', 'benefice' => '10'], ['jusqu_a' => '5', 'benefice' => '15']], 'benefice_au_dela' => '60', 'arrondi' => 'x9'];
        $bareme = new BaremePrixDeVente([['jusqu_a' => 200, 'benefice' => 1000], ['jusqu_a' => 500, 'benefice' => 1500]], 6000, ArrondiDuPrixDeVente::UnPrixEn9);

        $this->assertSame($tableau, $bareme->versTableau());
        $this->assertEquals($bareme, BaremePrixDeVente::depuisTableau($tableau));
    }

    /**
     * Le milieu se juge sur le prix EXACT, jamais sur le prix déjà arrondi
     * au centime : 10,495 € s'écrit 10,50 € au centime, mais n'atteint pas
     * le milieu de 10 € et de 11 € ; un prix exact pile au milieu monte.
     */
    public function test_le_milieu_se_juge_sur_le_prix_exact_jamais_sur_le_prix_au_centime(): void
    {
        $euro = new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::EuroLePlusProche);
        $cinq = new BaremePrixDeVente([], 0, ArrondiDuPrixDeVente::MultipleDeCinq);

        $this->assertSame(['prix_vente_ttc' => 1000, 'prix_cible_ttc' => 1050, 'benefice' => 0, 'laisse_tel_quel' => false], $euro->prixDeVente(104950, 0));
        $this->assertSame(1000, $cinq->prixDeVente(104950, 0)['prix_vente_ttc']);
        $this->assertSame(1100, $euro->prixDeVente(105000, 0)['prix_vente_ttc'], '10,50 € exactement monte.');

        // Par le chemin complet : prix d'achat + bénéfice + TVA, sans passer
        // par un prix HT rond.
        $bareme = new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 400]], null, ArrondiDuPrixDeVente::EuroLePlusProche);

        $this->assertSame(['prix_vente_ttc' => 1000, 'prix_cible_ttc' => 1050], array_intersect_key($bareme->prixDeVente(47467, 2000) ?? [], ['prix_vente_ttc' => true, 'prix_cible_ttc' => true]), '(4,7467 + 4) x 1,2 = 10,49604 €.');
        $this->assertSame(1100, $bareme->prixDeVente(47500, 2000)['prix_vente_ttc'], '(4,75 + 4) x 1,2 = 10,50 € exactement.');
    }

    /**
     * @return array<string, array{int, int, array{int, int, int, int}}>
     */
    public static function chaqueFormuleDUnMemePrix(): array
    {
        return [
            '0,47 € d\'achat + 10 € à 20 % (exact 12,564 €)' => [4700, 1000, [1256, 900, 1300, 1500]],
            '5 € d\'achat + 30 € à 20 % (exact 42,00 €)' => [50000, 3000, [4200, 3900, 4200, 4000]],
        ];
    }

    /**
     * @param  array{int, int, int, int}  $prixParFormule  sans arrondi, en 9, à l'euro, au multiple de 5 €
     */
    #[DataProvider('chaqueFormuleDUnMemePrix')]
    public function test_chaque_formule_d_un_meme_prix(int $prixAchatHt, int $benefice, array $prixParFormule): void
    {
        $prix = [];

        foreach ([ArrondiDuPrixDeVente::Aucun, ArrondiDuPrixDeVente::UnPrixEn9, ArrondiDuPrixDeVente::EuroLePlusProche, ArrondiDuPrixDeVente::MultipleDeCinq] as $formule) {
            $prix[] = (new BaremePrixDeVente([['jusqu_a' => 10000, 'benefice' => $benefice]], null, $formule))->prixDeVente($prixAchatHt, 2000)['prix_vente_ttc'];
        }

        $this->assertSame($prixParFormule, $prix);
    }

    /**
     * « laisse_tel_quel » dit que la formule n'a pas pu arrondir ce prix,
     * pas que l'arrondi est tombé sur le prix cible : 18,00 € à l'euro près
     * est un prix arrondi.
     */
    public function test_laisse_tel_quel_dit_quand_la_formule_n_arrondit_pas(): void
    {
        $laisseTelQuel = fn (ArrondiDuPrixDeVente $formule, int $prixEnCentimes): bool => (new BaremePrixDeVente([], 0, $formule))->prixDeVente($prixEnCentimes * 100, 0)['laisse_tel_quel'];

        $this->assertFalse($laisseTelQuel(ArrondiDuPrixDeVente::Aucun, 40));
        $this->assertTrue($laisseTelQuel(ArrondiDuPrixDeVente::UnPrixEn9, 499));
        $this->assertTrue($laisseTelQuel(ArrondiDuPrixDeVente::EuroLePlusProche, 49));
        $this->assertTrue($laisseTelQuel(ArrondiDuPrixDeVente::MultipleDeCinq, 249));
        $this->assertFalse($laisseTelQuel(ArrondiDuPrixDeVente::UnPrixEn9, 500));
        $this->assertFalse($laisseTelQuel(ArrondiDuPrixDeVente::EuroLePlusProche, 50));
        $this->assertFalse($laisseTelQuel(ArrondiDuPrixDeVente::MultipleDeCinq, 250));
        $this->assertFalse($laisseTelQuel(ArrondiDuPrixDeVente::EuroLePlusProche, 1800), 'Un prix que l\'arrondi laisse identique est un prix arrondi.');
    }

    public function test_palier_pour(): void
    {
        $bareme = BaremePrixDeVente::parDefaut();

        $this->assertSame(200, $bareme->palierPour(19900));
        $this->assertSame(500, $bareme->palierPour(20000));
        $this->assertNull($bareme->palierPour(990000), 'Au-delà du dernier palier.');
        $this->assertNull((new BaremePrixDeVente([['jusqu_a' => 5000, 'benefice' => 2000]], null, ArrondiDuPrixDeVente::Aucun))->palierPour(800000));
    }

    public function test_une_formule_inconnue_ou_absente_est_refusee(): void
    {
        $paliers = [['jusqu_a' => '2', 'benefice' => '10']];

        $this->assertThrows(fn () => BaremePrixDeVente::depuisTableau(['paliers' => $paliers, 'benefice_au_dela' => '60', 'arrondi' => 'dix']), InvalidArgumentException::class, ArrondiDuPrixDeVente::REFUS_FORMULE_INCONNUE);
        $this->assertThrows(fn () => BaremePrixDeVente::depuisTableau(['paliers' => $paliers, 'benefice_au_dela' => '60']), UnexpectedValueException::class);
    }

    public function test_un_bareme_de_chaque_formule_s_ecrit_en_tableau_et_se_relit_a_l_identique(): void
    {
        foreach (ArrondiDuPrixDeVente::cases() as $formule) {
            $bareme = new BaremePrixDeVente([['jusqu_a' => 200, 'benefice' => 1000]], 6000, $formule);

            $this->assertSame($formule->value, $bareme->versTableau()['arrondi']);
            $this->assertEquals($bareme, BaremePrixDeVente::depuisTableau($bareme->versTableau()));
        }
    }
}
