<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\ContenuDeLEtat;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Charges\Support\TextesDesCharges;
use App\Domain\Fichiers\CelluleDeTableur;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\Tenancy\Support\ResultatDePhp;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * L'Excel de l'état des charges (modèle E2 choisi par l'éditeur, voir
 * EtatDesCharges), écrit par OpenSpout : un classeur .xlsx de trois
 * feuilles.
 * - « Tableau » : une ligne de titre (période, magasin, vue), puis la grille
 *   « type de charge × mois » de la page, chaque case avec le TTC de ses
 *   charges en vrai nombre (format « # ##0,00 ») sur le fond de la couleur
 *   de son état, « ◦ » sur fond gris pour des pièces sans chiffres, la
 *   colonne « Total TTC » et le « Dernier état » de chaque type (l'état de
 *   sa case du dernier mois qui en a une), la ligne « Total TTC du mois »,
 *   puis la légende des couleurs.
 * - « Liste des charges » : une ligne par charge de la vue, montants en
 *   vrais nombres, dates en vraies dates, la ligne des titres figée et
 *   munie d'un filtre automatique.
 * - « TVA par mois » : la TVA de chaque mois telle que la page la compte,
 *   avec ses phrases.
 * Aucune formule : chaque valeur est écrite par PILIQI, aucune ne
 * recalcule la TVA ; chaque texte passe par CelluleDeTableur, qui
 * désamorce un texte qui commencerait comme une formule.
 */
final class EtatDesChargesEnExcel
{
    /** Le format d'un montant : deux décimales, séparateur des milliers. */
    public const FORMAT_DES_MONTANTS = '#,##0.00';

    public const FORMAT_DES_DATES = 'dd/mm/yyyy';

    /**
     * Le contenu du fichier .xlsx.
     */
    public function generer(ContenuDeLEtat $contenu): string
    {
        $chemin = ResultatDePhp::texte(tempnam(sys_get_temp_dir(), 'etat-des-charges-'), 'La création du fichier temporaire de l\'état des charges');

        try {
            $classeur = new Writer;
            $classeur->openToFile($chemin);
            $this->tableau($classeur, $contenu);
            $this->liste($classeur, $contenu);
            $this->tva($classeur, $contenu);
            $classeur->close();

            return ResultatDePhp::texte(file_get_contents($chemin), 'La lecture du fichier de l\'état des charges');
        } finally {
            @unlink($chemin);
        }
    }

    private function tableau(Writer $classeur, ContenuDeLEtat $contenu): void
    {
        $feuille = $classeur->getCurrentSheet();
        $feuille->setName('Tableau');
        $feuille->setColumnWidth(30, 1);
        $feuille->setColumnWidthForRange(12, 2, count($contenu->mois) + 2);
        $feuille->setColumnWidth(20, count($contenu->mois) + 3);
        $gras = (new Style)->setFontBold();

        $classeur->addRow(new Row([self::texte($contenu->titre().' · '.$contenu->lesFiltres(), $gras)]));
        $classeur->addRow(new Row([
            self::texte('Type de charge', $gras),
            ...array_map(fn (string $mois): Cell => self::texte(TextesDesCharges::abregeDuMois($mois).' '.substr($mois, 0, 4), (new Style)->setFontBold()->setCellAlignment(CellAlignment::CENTER)), $contenu->mois),
            self::texte('Total TTC', $gras),
            self::texte('Dernier état', $gras),
        ]));

        foreach ($contenu->lignes as $ligne) {
            $cellules = [self::texte($ligne['libelle'])];

            foreach ($contenu->mois as $mois) {
                $case = $ligne['cases'][$mois] ?? null;
                $cellules[] = match (true) {
                    $case === null => new EmptyCell(null, null),
                    $case['ttc'] === null => self::texte('◦', self::styleDeLEtat($case['etat'])->setCellAlignment(CellAlignment::CENTER)),
                    default => self::montant($case['ttc'], self::styleDeLEtat($case['etat'])),
                };
            }

            $cellules[] = self::montant($ligne['total'], (new Style)->setFontBold());
            $cellules[] = self::texte($ligne['dernier_etat']?->getLabel() ?? '');
            $classeur->addRow(new Row($cellules));
        }

        $classeur->addRow(new Row([
            self::texte('Total TTC du mois', $gras),
            ...array_map(fn (string $mois): Cell => self::montant($contenu->totauxDuMois[$mois] ?? 0, (new Style)->setFontBold()), $contenu->mois),
            self::montant($contenu->total, (new Style)->setFontBold()),
        ]));
        $classeur->addRow(new Row([]));
        $classeur->addRow(new Row([
            self::texte('Couleur de la case : état le moins avancé de ses charges ; nombre : leur TTC en euros ; « ◦ » : pièces sans chiffres.'),
        ]));
        $classeur->addRow(new Row(array_map(fn (EtatDeLaCharge $etat): Cell => self::texte($etat->symbole().' '.$etat->getLabel(), self::styleDeLEtat($etat)), EtatDeLaCharge::cases())));
    }

    private function liste(Writer $classeur, ContenuDeLEtat $contenu): void
    {
        $feuille = $classeur->addNewSheetAndMakeItCurrent();
        $feuille->setName('Liste des charges');
        $colonnes = ['Mois de TVA', 'Type', 'Magasin', 'Fournisseur', 'N° de la pièce', 'Date de la pièce', 'Date de paiement', 'HT', 'TVA', 'TTC', 'TVA déductible', 'Autoliquidation', 'Origine', 'État', 'Validée par', 'Validée le', 'Motif du renvoi'];
        $feuille->setColumnWidth(12, 1, 6, 7, 8, 9, 10, 11, 12, 16);
        $feuille->setColumnWidth(24, 2, 3, 4, 5, 13, 14, 15);
        $feuille->setColumnWidth(40, 17);
        $feuille->setSheetView((new SheetView)->setFreezeRow(2));
        $gras = (new Style)->setFontBold();
        $classeur->addRow(new Row(array_map(fn (string $colonne): Cell => self::texte($colonne, $gras), $colonnes)));

        foreach ($contenu->charges as $charge) {
            $classeur->addRow(new Row(self::ligneDeLaCharge($charge)));
        }

        $feuille->setAutoFilter(new AutoFilter(0, 1, count($colonnes) - 1, count($contenu->charges) + 1));
    }

    private function tva(Writer $classeur, ContenuDeLEtat $contenu): void
    {
        $feuille = $classeur->addNewSheetAndMakeItCurrent();
        $feuille->setName('TVA par mois');
        $feuille->setColumnWidth(20, 1);
        $feuille->setColumnWidthForRange(22, 2, 11);
        $gras = (new Style)->setFontBold();

        if ($contenu->tva === null) {
            $classeur->addRow(new Row([self::texte($contenu->sansTva ?? 'Aucune TVA à afficher.')]));

            return;
        }

        $complete = $contenu->tva['avec_les_ventes'];
        $avecLeReport = $contenu->montreLeReport();
        $colonnes = match (true) {
            $avecLeReport => ['Mois', 'TVA collectée', 'Déductible des achats', 'Déductible des charges validées', 'TVA du mois (réelle)', 'TVA des charges à valider', 'TVA du mois (estimation)', 'Crédit reporté de la période d\'avant', 'TVA à payer', 'Crédit de TVA à reporter', 'Estimation après le report (négative : crédit à reporter)'],
            $complete => ['Mois', 'TVA collectée', 'Déductible des achats', 'Déductible des charges validées', 'TVA à payer (réelle)', 'TVA des charges à valider', 'TVA à payer (estimation)'],
            default => ['Mois', 'Déductible des charges validées', 'TVA des charges à valider'],
        };
        $classeur->addRow(new Row(array_map(fn (string $colonne): Cell => self::texte($colonne, $gras), $colonnes)));

        foreach ($contenu->tva['montants'] as $mois => $montants) {
            $classeur->addRow(new Row([
                self::texte(ucfirst(TextesDesCharges::nomDuMois($mois))),
                ...self::montantsDuMois($montants, $complete, $avecLeReport),
                ...($avecLeReport ? self::reportDuMois($contenu->reportDuMois($mois)) : []),
            ]));
        }

        $classeur->addRow(new Row([]));

        foreach (self::phrasesDeLaTva($contenu) as $phrase) {
            $classeur->addRow(new Row([self::texte($phrase)]));
        }
    }

    /**
     * La ligne d'une charge dans la feuille « Liste des charges ».
     *
     * @return list<Cell>
     */
    private static function ligneDeLaCharge(Charge $charge): array
    {
        $chiffres = $charge->etat !== EtatDeLaCharge::PieceSansChiffres;
        $montant = fn (int $centimes): Cell => $chiffres ? self::montant($centimes) : new EmptyCell(null, null);

        return [
            self::texte($charge->mois_tva),
            self::texte($charge->libelleDuType()),
            self::texte($charge->nomDuMagasin()),
            self::texte($charge->fournisseur ?? ''),
            self::texte($charge->numero_piece ?? ''),
            self::date($charge->date_piece),
            self::date($charge->date_paiement),
            $montant($charge->total_ht),
            $montant($charge->total_tva),
            $montant($charge->total_ttc),
            $montant($charge->total_tva_deductible),
            self::texte($charge->fournisseur_hors_france ? 'Oui' : 'Non'),
            self::texte($charge->origine->getLabel()),
            self::texte($charge->etat->getLabel(), self::styleDeLEtat($charge->etat)),
            self::texte($charge->validee_par?->getLabel() ?? ''),
            self::date($charge->jourDeValidation()),
            self::texte($charge->motif_renvoi ?? ''),
        ];
    }

    /**
     * La TVA d'un mois : en gras, la TVA à payer réelle, ou, avec le report
     * du crédit, la TVA à payer qu'il laisse (voir reportDuMois()).
     *
     * @return list<Cell>
     */
    private static function montantsDuMois(MontantsDeTva $montants, bool $complete, bool $avecLeReport): array
    {
        return $complete
            ? [self::montant($montants->collectee()), self::montant($montants->deductibleDesAchats), self::montant($montants->deductibleDesChargesValidees), self::montant($montants->reelle(), $avecLeReport ? null : (new Style)->setFontBold()), self::montant($montants->deductibleDesChargesAValider), self::montant($montants->estimee())]
            : [self::montant($montants->deductibleDesChargesValidees), self::montant($montants->deductibleDesChargesAValider)];
    }

    /**
     * Le report du crédit de TVA que montre un mois (voir
     * ContenuDeLEtat::reportDuMois()) : le crédit reporté de la période
     * d'avant, la TVA à payer, en gras, le crédit à reporter et
     * l'estimation après le report, en vrais nombres ; des cases vides sur
     * un mois qui ne montre pas de résultat, « déclarée » pour la période
     * de la déclaration qui sert de point de départ.
     *
     * @param  array{credit_reporte: int|null, a_payer: int|null, credit_a_reporter: int, estimation: int|null, declaree: bool}|null  $report
     * @return list<Cell>
     */
    private static function reportDuMois(?array $report): array
    {
        if ($report === null) {
            return [new EmptyCell(null, null), new EmptyCell(null, null), new EmptyCell(null, null), new EmptyCell(null, null)];
        }

        return [
            $report['credit_reporte'] === null ? new EmptyCell(null, null) : self::montant($report['credit_reporte']),
            $report['declaree'] ? self::texte('déclarée') : self::montant($report['a_payer'] ?? 0, (new Style)->setFontBold()),
            self::montant($report['credit_a_reporter']),
            $report['estimation'] === null ? new EmptyCell(null, null) : self::montant($report['estimation']),
        ];
    }

    /**
     * Les phrases sous la TVA par mois, comme dans le PDF.
     *
     * @return list<string>
     */
    private static function phrasesDeLaTva(ContenuDeLEtat $contenu): array
    {
        $tva = $contenu->tva;

        if ($tva === null) {
            return [];
        }

        return array_values(array_filter([
            $tva['avec_les_ventes'] ? 'Telle que le tableau de bord la compte. Estimation : la TVA à payer si les charges à valider l\'étaient telles qu\'elles sont saisies.' : 'Les seules charges que vous voyez, sans les ventes ni les achats, que vous ne voyez pas.',
            ...$contenu->phrasesDuReport(),
            $tva['sans_celles_de_la_societe'] ? SeriesDesGraphiques::SANS_LES_CHARGES_DE_LA_SOCIETE : null,
            $tva['magasins_non_comptes'],
            'Document de travail, n\'est pas une déclaration de TVA.',
        ], fn (?string $phrase): bool => $phrase !== null));
    }

    /**
     * Un texte, jamais lu comme une formule (voir CelluleDeTableur).
     */
    private static function texte(string $valeur, ?Style $style = null): StringCell
    {
        return new StringCell(CelluleDeTableur::texte($valeur), $style);
    }

    /**
     * Un montant en centimes, écrit en euros, en vrai nombre.
     */
    private static function montant(int $centimes, ?Style $style = null): NumericCell
    {
        return new NumericCell($centimes / 100, ($style ?? new Style)->setFormat(self::FORMAT_DES_MONTANTS));
    }

    /**
     * Un jour « AAAA-MM-JJ », en vraie date, ou une case vide.
     */
    private static function date(?string $jour): Cell
    {
        return $jour === null
            ? new EmptyCell(null, null)
            : new DateTimeCell(Calendrier::lireUnJour($jour), (new Style)->setFormat(self::FORMAT_DES_DATES));
    }

    /**
     * Le fond et le texte de la couleur de cet état (voir
     * EtatDeLaCharge::couleursImprimees()).
     */
    private static function styleDeLEtat(EtatDeLaCharge $etat): Style
    {
        $couleurs = $etat->couleursImprimees();

        return (new Style)->setBackgroundColor($couleurs['fond'])->setFontColor($couleurs['texte']);
    }
}
