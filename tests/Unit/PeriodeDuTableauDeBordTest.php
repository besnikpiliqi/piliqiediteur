<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le menu « Période » du tableau de bord (choix B de l'éditeur, le
 * 15/11/2026) : quand l'exercice finit en décembre, il est l'année civile,
 * le menu ne montre plus « Exercice en cours » ni « Exercice précédent »
 * et dit « (exercice en cours) » à côté de l'année ; sinon il garde ses
 * cinq choix. Les années se lisent dans le jour donné, jamais écrites en
 * dur.
 */
class PeriodeDuTableauDeBordTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: array<string, string>}>
     */
    public static function menus(): array
    {
        return [
            'exercice qui finit en décembre, le 15 novembre 2026' => ['2026-11-15', 12, [
                'douze_derniers_mois' => '12 derniers mois',
                'annee_en_cours' => 'Année 2026 (exercice en cours)',
                'annee_precedente' => 'Année 2025 (exercice précédent)',
            ]],
            'exercice qui finit en décembre, le 3 janvier 2027' => ['2027-01-03', 12, [
                'douze_derniers_mois' => '12 derniers mois',
                'annee_en_cours' => 'Année 2027 (exercice en cours)',
                'annee_precedente' => 'Année 2026 (exercice précédent)',
            ]],
            'exercice qui finit en juin, le 15 mars 2026' => ['2026-03-15', 6, [
                'douze_derniers_mois' => '12 derniers mois',
                'annee_en_cours' => 'Année 2026',
                'annee_precedente' => 'Année 2025',
                'exercice_en_cours' => 'Exercice en cours (juil. 2025 à juin 2026)',
                'exercice_precedent' => 'Exercice précédent (juil. 2024 à juin 2025)',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $attendu
     */
    #[DataProvider('menus')]
    public function test_le_menu_depend_du_mois_de_fin_d_exercice(string $aujourdhui, int $moisDeFin, array $attendu): void
    {
        $menu = PeriodeDuTableauDeBord::duMenu($moisDeFin);

        $this->assertSame($attendu, array_combine(
            array_map(fn (PeriodeDuTableauDeBord $periode): string => $periode->value, $menu),
            array_map(fn (PeriodeDuTableauDeBord $periode): string => $periode->libelle($aujourdhui, $moisDeFin), $menu),
        ));
    }

    public function test_un_exercice_retenu_d_une_societe_qui_cloture_en_decembre_se_lit_comme_l_annee_de_la_meme_periode(): void
    {
        foreach ([[PeriodeDuTableauDeBord::ExerciceEnCours, PeriodeDuTableauDeBord::AnneeEnCours], [PeriodeDuTableauDeBord::ExercicePrecedent, PeriodeDuTableauDeBord::AnneePrecedente]] as [$exercice, $annee]) {
            $this->assertSame($annee, $exercice->pourLaSociete(12));
            $this->assertSame($exercice->bornes('2026-11-15', 12), $annee->bornes('2026-11-15', 12));
            $this->assertSame($exercice, $exercice->pourLaSociete(6));
        }

        $this->assertSame(PeriodeDuTableauDeBord::DouzeDerniersMois, PeriodeDuTableauDeBord::DouzeDerniersMois->pourLaSociete(12));
    }
}
