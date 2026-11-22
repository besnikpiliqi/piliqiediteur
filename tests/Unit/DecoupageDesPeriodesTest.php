<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Barre;
use App\Domain\TableauDeBord\Support\DecoupageDesPeriodes;
use App\Domain\TableauDeBord\Support\Mois;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les barres d'un graphique par période (choix de l'éditeur du 16/11/2026,
 * lot GRAPH-AN ; décisions de Claude du 22/11/2026, D76), sans base de
 * données : par mois, par trimestre (T1 à T4, ou quatre blocs de trois mois
 * sur « 12 derniers mois »), par année (dix ans, un exercice par barre quand
 * la société ne clôt pas en décembre), le cumul depuis le début de l'année,
 * le détail d'une barre et ce qu'elle vaut un an plus tôt. Le jour de
 * l'écran est écrit en toutes lettres : le dimanche 22 novembre 2026.
 */
class DecoupageDesPeriodesTest extends TestCase
{
    private const AUJOURDHUI = '2026-11-22';

    /**
     * Les barres résumées : niveau, premier et dernier jour, étiquette, et
     * « à venir » ou « en cours ».
     *
     * @param  list<Barre>  $barres
     * @return list<string>
     */
    private static function resume(array $barres): array
    {
        return array_map(fn (Barre $barre): string => "{$barre->niveau->value} {$barre->premierJour} {$barre->dernierJour} {$barre->etiquette}".($barre->aVenir ? ' (à venir)' : '').($barre->enCours ? ' (en cours)' : ''), $barres);
    }

    public function test_par_mois_les_barres_sont_les_douze_derniers_mois_et_le_mois_en_cours_est_en_cours(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Mois, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12);

        $this->assertCount(12, $barres);
        $this->assertSame(['mois 2025-12-01 2025-12-31 déc. 2025', 'mois 2026-01-01 2026-01-31 janv. 2026'], array_slice(self::resume($barres), 0, 2));
        $this->assertSame('mois 2026-11-01 2026-11-30 nov. 2026 (en cours)', self::resume($barres)[11]);
        $this->assertSame('Décembre 2025', $barres[0]->intitule);
    }

    public function test_par_mois_sur_l_annee_en_cours_les_mois_a_venir_sont_a_venir(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Mois, PeriodeDuTableauDeBord::AnneeEnCours, false, self::AUJOURDHUI, 12);

        $this->assertSame(['mois 2026-11-01 2026-11-30 nov. 2026 (en cours)', 'mois 2026-12-01 2026-12-31 déc. 2026 (à venir)'], array_slice(self::resume($barres), 10, 2));
    }

    /**
     * Sur une année, quatre trimestres, de T1 à T4, quel que soit le mois
     * de fin d'exercice quand la période est l'année civile.
     */
    #[DataProvider('moisDeFinDExercice')]
    public function test_par_trimestre_sur_l_annee_en_cours_les_barres_sont_t1_a_t4(int $moisDeFin): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::AnneeEnCours, false, self::AUJOURDHUI, $moisDeFin);

        $this->assertSame([
            'trimestre 2026-01-01 2026-03-31 T1 2026',
            'trimestre 2026-04-01 2026-06-30 T2 2026',
            'trimestre 2026-07-01 2026-09-30 T3 2026',
            'trimestre 2026-10-01 2026-12-31 T4 2026 (en cours)',
        ], self::resume($barres));
        $this->assertSame('1er trimestre 2026 (janv.–mars)', $barres[0]->intitule);
        $this->assertSame('4e trimestre 2026 (oct.–déc.)', $barres[3]->intitule);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function moisDeFinDExercice(): array
    {
        return ['exercice en décembre' => [12], 'exercice en juin' => [6]];
    }

    /**
     * Sur « 12 derniers mois », quatre blocs de trois mois, le dernier
     * finissant ce mois-ci.
     */
    public function test_par_trimestre_sur_les_douze_derniers_mois_les_blocs_finissent_ce_mois_ci(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12);

        $this->assertSame([
            'trimestre 2025-12-01 2026-02-28 déc. 2025 à févr. 2026',
            'trimestre 2026-03-01 2026-05-31 mars 2026 à mai 2026',
            'trimestre 2026-06-01 2026-08-31 juin 2026 à août 2026',
            'trimestre 2026-09-01 2026-11-30 sept. 2026 à nov. 2026 (en cours)',
        ], self::resume($barres));
        $this->assertSame('Décembre 2025 à février 2026', $barres[0]->intitule);
    }

    /**
     * Le cumul garde les trimestres de l'année en cours, même sur « 12
     * derniers mois » : la carte part du début de l'année.
     */
    public function test_par_trimestre_avec_le_cumul_sur_les_douze_derniers_mois_part_du_debut_de_l_annee(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::DouzeDerniersMois, true, self::AUJOURDHUI, 12);

        $this->assertSame(['T1 2026', 'T2 2026', 'T3 2026', 'T4 2026'], array_map(fn (Barre $barre): string => $barre->etiquette, $barres));
    }

    /**
     * Un exercice qui finit en juin : les trimestres de l'exercice en
     * cours, de juillet 2026 à juin 2027, portent son nom, et ceux qui ne
     * sont pas commencés sont à venir.
     */
    public function test_par_trimestre_sur_un_exercice_decale_les_trimestres_portent_le_nom_de_l_exercice(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::ExerciceEnCours, false, self::AUJOURDHUI, 6);

        $this->assertSame([
            'trimestre 2026-07-01 2026-09-30 T1 2026-27',
            'trimestre 2026-10-01 2026-12-31 T2 2026-27 (en cours)',
            'trimestre 2027-01-01 2027-03-31 T3 2026-27 (à venir)',
            'trimestre 2027-04-01 2027-06-30 T4 2026-27 (à venir)',
        ], self::resume($barres));
        $this->assertSame('1er trimestre 2026-27 (juil.–sept.)', $barres[0]->intitule);
    }

    /**
     * Par année : les dix dernières années, la dernière étant l'année en
     * cours.
     */
    public function test_par_annee_les_barres_sont_les_dix_dernieres_annees(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12);

        $this->assertCount(DecoupageDesPeriodes::ANNEES, $barres);
        $this->assertSame('annee 2017-01-01 2017-12-31 2017', self::resume($barres)[0]);
        $this->assertSame('annee 2026-01-01 2026-12-31 2026 (en cours)', self::resume($barres)[9]);
        $this->assertSame('Année 2026', $barres[9]->intitule);
    }

    /**
     * La période ne joue plus par année : toutes les périodes montrent les
     * mêmes dix ans, et le cumul n'y change rien.
     */
    #[DataProvider('periodes')]
    public function test_par_annee_la_periode_et_le_cumul_ne_changent_rien(PeriodeDuTableauDeBord $periode): void
    {
        $reference = self::resume(DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12));

        $this->assertSame($reference, self::resume(DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, $periode, false, self::AUJOURDHUI, 12)));
        $this->assertSame($reference, self::resume(DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, $periode, true, self::AUJOURDHUI, 12)));
    }

    /**
     * @return array<string, array{0: PeriodeDuTableauDeBord}>
     */
    public static function periodes(): array
    {
        return array_combine(array_map(fn (PeriodeDuTableauDeBord $periode): string => $periode->value, PeriodeDuTableauDeBord::cases()), array_map(fn (PeriodeDuTableauDeBord $periode): array => [$periode], PeriodeDuTableauDeBord::cases()));
    }

    /**
     * Une société qui clôt en juin : chaque barre est un exercice, de
     * juillet à juin, le dernier étant l'exercice en cours.
     */
    public function test_par_annee_une_societe_qui_cloture_en_juin_a_une_barre_par_exercice(): void
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::AnneeEnCours, false, self::AUJOURDHUI, 6);

        $this->assertCount(10, $barres);
        $this->assertSame('annee 2017-07-01 2018-06-30 2017-18', self::resume($barres)[0]);
        $this->assertSame('annee 2026-07-01 2027-06-30 2026-27 (en cours)', self::resume($barres)[9]);
        $this->assertSame('Exercice 2026-2027 (juil. 2026 à juin 2027)', $barres[9]->intitule);
    }

    /**
     * Ce que l'écran compte pour ses cartes de répartition : la période, ou
     * les dix ans quand le découpage est l'année ; le cumul de « 12 derniers
     * mois » part du début de l'exercice en cours.
     */
    public function test_la_fenetre_et_celle_du_cumul(): void
    {
        $this->assertSame(['2025-12', '2026-11'], DecoupageDesPeriodes::fenetre(DecoupageDuTableauDeBord::Mois, PeriodeDuTableauDeBord::DouzeDerniersMois, self::AUJOURDHUI, 12));
        $this->assertSame(['2025-12', '2026-11'], DecoupageDesPeriodes::fenetre(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::DouzeDerniersMois, self::AUJOURDHUI, 12));
        $this->assertSame(['2017-01', '2026-12'], DecoupageDesPeriodes::fenetre(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::AnneePrecedente, self::AUJOURDHUI, 12));
        $this->assertSame(['2017-07', '2027-06'], DecoupageDesPeriodes::fenetre(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::AnneePrecedente, self::AUJOURDHUI, 6));
        $this->assertSame(['2026-01', '2026-12'], DecoupageDesPeriodes::fenetreDuCumul(PeriodeDuTableauDeBord::DouzeDerniersMois, self::AUJOURDHUI, 12));
        $this->assertSame(['2026-07', '2027-06'], DecoupageDesPeriodes::fenetreDuCumul(PeriodeDuTableauDeBord::DouzeDerniersMois, self::AUJOURDHUI, 6));
        $this->assertSame(['2025-01', '2025-12'], DecoupageDesPeriodes::fenetreDuCumul(PeriodeDuTableauDeBord::AnneePrecedente, self::AUJOURDHUI, 12));
    }

    /**
     * Une année et un trimestre se redécoupent en mois, un mois en jours,
     * un jour ne se redécoupe pas ; les jours qui suivent aujourd'hui sont
     * à venir.
     */
    public function test_le_detail_d_une_barre_la_redecoupe_plus_finement(): void
    {
        $annee = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12)[9];
        $trimestre = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Trimestre, PeriodeDuTableauDeBord::AnneeEnCours, false, self::AUJOURDHUI, 12)[3];
        $mois = DecoupageDesPeriodes::sousBarres($annee, self::AUJOURDHUI, 12)[10];
        $jours = DecoupageDesPeriodes::sousBarres($mois, self::AUJOURDHUI, 12);

        $this->assertSame(NiveauDeLaBarre::Mois, DecoupageDesPeriodes::sousBarres($annee, self::AUJOURDHUI, 12)[0]->niveau);
        $this->assertCount(12, DecoupageDesPeriodes::sousBarres($annee, self::AUJOURDHUI, 12));
        $this->assertSame(['oct. 2026', 'nov. 2026', 'déc. 2026'], array_map(fn (Barre $barre): string => $barre->etiquette, DecoupageDesPeriodes::sousBarres($trimestre, self::AUJOURDHUI, 12)));
        $this->assertSame('mois 2026-11-01 2026-11-30 nov. 2026 (en cours)', self::resume([$mois])[0]);
        $this->assertCount(30, $jours);
        $this->assertSame(['jour 2026-11-21 2026-11-21 21', 'jour 2026-11-22 2026-11-22 22', 'jour 2026-11-23 2026-11-23 23 (à venir)'], array_slice(self::resume($jours), 20, 3));
        $this->assertSame('Dimanche 22 novembre 2026', $jours[21]->intitule);
        $this->assertSame([], DecoupageDesPeriodes::sousBarres($jours[0], self::AUJOURDHUI, 12));
        $this->assertTrue($annee->peutSeDetailler());
        $this->assertFalse($jours[0]->peutSeDetailler());
    }

    /**
     * Ce qu'une barre valait un an plus tôt : la barre entière, sauf la barre
     * en cours, qui se compare aux mêmes jours de l'an dernier ; un jour, le
     * même quantième, le 28 février quand il n'y avait pas de 29.
     *
     * @param  array{0: string, 1: string}  $attendu
     */
    #[DataProvider('barresUnAnPlusTot')]
    public function test_une_barre_un_an_plus_tot(NiveauDeLaBarre $niveau, string $premier, string $dernier, string $aujourdhui, array $attendu): void
    {
        $barre = new Barre($niveau, $premier, $dernier, '', '', $premier > $aujourdhui, $premier <= $aujourdhui && $dernier > $aujourdhui);

        $this->assertSame($attendu, $barre->unAnPlusTot($aujourdhui));
    }

    /**
     * @return array<string, array{0: NiveauDeLaBarre, 1: string, 2: string, 3: string, 4: array{0: string, 1: string}}>
     */
    public static function barresUnAnPlusTot(): array
    {
        return [
            'le mois en cours : les mêmes jours' => [NiveauDeLaBarre::Mois, '2026-11-01', '2026-11-30', '2026-11-22', ['2025-11-01', '2025-11-22']],
            'un mois passé : le mois entier' => [NiveauDeLaBarre::Mois, '2026-10-01', '2026-10-31', '2026-11-22', ['2025-10-01', '2025-10-31']],
            'février d\'une année bissextile, passé' => [NiveauDeLaBarre::Mois, '2028-02-01', '2028-02-29', '2028-10-15', ['2027-02-01', '2027-02-28']],
            'l\'année en cours : jusqu\'aux mêmes jours' => [NiveauDeLaBarre::Annee, '2026-01-01', '2026-12-31', '2026-11-22', ['2025-01-01', '2025-11-22']],
            'un exercice décalé en cours' => [NiveauDeLaBarre::Annee, '2026-07-01', '2027-06-30', '2026-11-22', ['2025-07-01', '2025-11-22']],
            'un trimestre passé' => [NiveauDeLaBarre::Trimestre, '2026-07-01', '2026-09-30', '2026-11-22', ['2025-07-01', '2025-09-30']],
            'un jour' => [NiveauDeLaBarre::Jour, '2026-11-10', '2026-11-10', '2026-11-22', ['2025-11-10', '2025-11-10']],
            'le 29 février' => [NiveauDeLaBarre::Jour, '2028-02-29', '2028-02-29', '2028-10-15', ['2027-02-28', '2027-02-28']],
            'aujourd\'hui le 29 février, le mois n\'est plus en cours' => [NiveauDeLaBarre::Mois, '2028-02-01', '2028-02-29', '2028-02-29', ['2027-02-01', '2027-02-28']],
        ];
    }

    public function test_le_jeton_designe_la_barre_et_le_mois_de_fin_d_un_exercice(): void
    {
        $barre = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Mois, PeriodeDuTableauDeBord::DouzeDerniersMois, false, self::AUJOURDHUI, 12)[11];

        $this->assertSame('mois:2026-11-01:2026-11-30', $barre->jeton());
        $this->assertSame(['2025-07', '2026-06'], DecoupageDesPeriodes::exercice('2026-05', 6));
        $this->assertSame(['2026-07', '2027-06'], DecoupageDesPeriodes::exercice('2026-07', 6));
        $this->assertSame(['2026-01', '2026-12'], DecoupageDesPeriodes::exercice('2026-11', 12));
    }

    /**
     * Les petits calculs de mois et de jours du tableau de bord.
     */
    public function test_les_mois_et_les_jours_du_tableau_de_bord(): void
    {
        $this->assertSame('2026-02-01', Mois::premierJour('2026-02'));
        $this->assertSame('2026-02-28', Mois::dernierJour('2026-02'));
        $this->assertSame('2028-02-29', Mois::dernierJour('2028-02'));
        $this->assertSame('2026-12-31', Mois::dernierJour('2026-12'));
        $this->assertSame('Février 2026', Mois::intitule('2026-02'));
        $this->assertSame('2027-02-28', Mois::memeJourUnAnPlusTot('2028-02-29'));
        $this->assertSame('2027-03-31', Mois::memeJourUnAnPlusTot('2028-03-31'));
        $this->assertSame('2025-11-22', Mois::memeJourUnAnPlusTot('2026-11-22'));
    }

    public function test_les_mots_de_chaque_niveau(): void
    {
        $this->assertSame(['mois par mois', 'trimestre par trimestre', 'année par année', 'exercice par exercice', 'jour par jour'], [
            NiveauDeLaBarre::Mois->pas(false), NiveauDeLaBarre::Trimestre->pas(false), NiveauDeLaBarre::Annee->pas(false), NiveauDeLaBarre::Annee->pas(true), NiveauDeLaBarre::Jour->pas(false),
        ]);
        $this->assertSame(['par mois', 'par trimestre', 'par année', 'par exercice', 'par jour'], [
            NiveauDeLaBarre::Mois->parTerme(false), NiveauDeLaBarre::Trimestre->parTerme(false), NiveauDeLaBarre::Annee->parTerme(false), NiveauDeLaBarre::Annee->parTerme(true), NiveauDeLaBarre::Jour->parTerme(false),
        ]);
        $this->assertSame(['mois', 'trimestres', 'ans', 'exercices', 'jours'], [
            NiveauDeLaBarre::Mois->pluriel(false), NiveauDeLaBarre::Trimestre->pluriel(false), NiveauDeLaBarre::Annee->pluriel(false), NiveauDeLaBarre::Annee->pluriel(true), NiveauDeLaBarre::Jour->pluriel(false),
        ]);
        $this->assertSame([NiveauDeLaBarre::Mois, NiveauDeLaBarre::Mois, NiveauDeLaBarre::Jour, null], [
            NiveauDeLaBarre::Annee->plusFin(), NiveauDeLaBarre::Trimestre->plusFin(), NiveauDeLaBarre::Mois->plusFin(), NiveauDeLaBarre::Jour->plusFin(),
        ]);
        $this->assertSame(NiveauDeLaBarre::Trimestre, DecoupageDuTableauDeBord::Trimestre->niveau());
    }
}
