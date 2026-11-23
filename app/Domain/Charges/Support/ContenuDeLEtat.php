<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Enums\StatutDuReport;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;

/**
 * Ce que montre l'état téléchargeable des charges (voir EtatDesCharges),
 * lu une seule fois et écrit tel quel en PDF comme en Excel : la société,
 * les filtres, qui l'édite et quand ; le tableau « type de charge × mois »
 * de la page, chaque case avec l'état le moins avancé de ses charges, leur
 * nombre et leur TTC (null quand aucune n'a ses chiffres), le total TTC de
 * chaque type, de chaque mois et de la période ; la liste des charges de
 * la vue ; la TVA de chaque mois telle que la page la compte, avec le
 * report du crédit de TVA quand la page le montre (lot TVA27, voir
 * reportDuMois()), null hors du réel normal, avec la phrase qui dit
 * pourquoi ($sansTva). Montants en centimes, additionnés dans le code.
 *
 * @phpstan-import-type TvaDeLaPage from TvaDeLaPeriode
 */
final readonly class ContenuDeLEtat
{
    /**
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<array{cle: string, libelle: string, cases: array<string, array{etat: EtatDeLaCharge, nombre: int, ttc: int|null}>, total: int, dernier_etat: EtatDeLaCharge|null}>  $lignes
     * @param  array<string, int>  $totauxDuMois
     * @param  list<Charge>  $charges  la liste de la vue, triée par mois de TVA puis par type
     * @param  TvaDeLaPage|null  $tva
     * @param  string|null  $sansTva  pourquoi l'état ne montre aucune TVA : la franchise en base, ou le régime à choisir
     */
    public function __construct(
        public string $denomination,
        public string $siren,
        public FiltresDeLEtat $filtres,
        public string $edition,
        public array $mois,
        public array $lignes,
        public array $totauxDuMois,
        public int $total,
        public array $charges,
        public ?array $tva,
        public ?string $sansTva = null,
    ) {}

    /**
     * L'état montre-t-il le report du crédit de TVA (sa TVA compte toute la
     * société, au réel normal) ?
     */
    public function montreLeReport(): bool
    {
        return $this->tva !== null && $this->tva['report'] !== null;
    }

    /**
     * Le report du crédit de TVA que ce mois montre : au mensuel, celui du
     * mois ; au trimestriel, celui de tout le trimestre, sur son dernier
     * mois (ou le mois en cours pour le trimestre en cours, voir
     * TvaReportee::montrePar()) ; null sur un autre mois, hors de la
     * chaîne, ou quand l'état ne montre pas le report. En centimes : le
     * crédit reporté de la période d'avant, la TVA à payer, le crédit de
     * TVA à reporter et, quand des charges à valider la changent,
     * l'estimation après le report (positive à payer, négative à
     * reporter) ; la période de la déclaration qui sert de point de départ
     * est « déclarée », sans TVA à payer ni crédit reporté connus.
     *
     * @return array{credit_reporte: int|null, a_payer: int|null, credit_a_reporter: int, estimation: int|null, declaree: bool}|null
     */
    public function reportDuMois(string $mois): ?array
    {
        $periode = $this->tva === null || $this->tva['report'] === null ? null : $this->tva['report']->montrePar($mois);

        if ($periode === null) {
            return null;
        }

        $declaree = $periode->statut === StatutDuReport::Declaree;

        return [
            'credit_reporte' => $declaree ? null : $periode->reelle->creditReporte,
            'a_payer' => $periode->reelle->aPayer,
            'credit_a_reporter' => $periode->reelle->creditAReporter,
            'estimation' => $periode->estimationDifferente() ? $periode->estimee->signe() : null,
            'declaree' => $declaree,
        ];
    }

    /**
     * Les phrases grises du report du crédit sous la TVA par mois, comme
     * sous son graphique : au trimestriel, où se lit le résultat de chaque
     * trimestre, et, quand la période de l'état s'arrête au milieu d'un
     * trimestre (un exercice qui ne finit pas avec un trimestre), que ce
     * trimestre se lit dans la période suivante ; les remboursements qui
     * n'y sont pas ; sans le report, ce qui le retire de l'état.
     *
     * @return list<string>
     */
    public function phrasesDuReport(): array
    {
        if ($this->tva === null || ! $this->tva['avec_les_ventes']) {
            return [];
        }

        $report = $this->tva['report'];

        if ($report === null) {
            return [TextesDuCreditDeTva::SANS_LE_REPORT];
        }

        $trimestriel = $report->cadence === CadenceDeLaTva::Trimestrielle;
        $dernier = $this->mois === [] ? null : $this->mois[count($this->mois) - 1];
        $coupe = $trimestriel && $dernier !== null && $report->deLaPeriodeDu($dernier) !== null && ! $report->fermeSaPeriode($dernier)
            && $report->cadence->dernierMois($report->cadence->periodeDu($dernier)) > $dernier;

        return array_values(array_filter([
            $trimestriel ? 'Au trimestriel, la TVA à payer et le crédit à reporter de chaque trimestre se lisent sur son dernier mois, ou sur le mois en cours pour le trimestre en cours.' : null,
            $coupe ? 'Le '.$report->cadence->libelle($report->cadence->periodeDu($dernier)).' se termine après cette période : sa TVA à payer et son crédit à reporter se lisent dans la période suivante.' : null,
            TextesDuCreditDeTva::SANS_LES_REMBOURSEMENTS,
        ]));
    }

    /**
     * Le titre de l'état : « État des charges — année 2026 » ; sur le site
     * de démonstration, précédé de « Démonstration — sans valeur · » (lot
     * DEMO-COPIE, C.10), comme le nom du fichier.
     */
    public function titre(): string
    {
        return self::marqueDeLaDemonstration(' · ').'État des charges — '.TextesDesCharges::enMinuscule($this->filtres->periode);
    }

    /**
     * « Démonstration — sans valeur » suivi de ce séparateur, sur le site de
     * démonstration seulement ; rien au réel.
     */
    public static function marqueDeLaDemonstration(string $separateur): string
    {
        return SiteDePiliqi::estLaDemonstration() ? 'Démonstration — sans valeur'.$separateur : '';
    }

    /**
     * Le nom du fichier : « Charges 2026 — Dénomination.pdf » pour une
     * année civile, « Charges exercice de juillet 2025 à juin 2026 —
     * Dénomination.xlsx » pour un exercice, sans caractère interdit dans un
     * nom de fichier (voir NomDeFichier::dUnFichierProduit()).
     */
    public function nomDuFichier(FormatDeLEtat $format): string
    {
        $premier = $this->mois[0] ?? '';
        $dernier = $this->mois[count($this->mois) - 1] ?? '';
        $civile = str_ends_with($premier, '-01') && $dernier === substr($premier, 0, 4).'-12';
        $periode = $civile ? substr($premier, 0, 4) : TextesDesCharges::enMinuscule($this->filtres->periode);

        return NomDeFichier::dUnFichierProduit(self::marqueDeLaDemonstration(' — ')."Charges {$periode} — {$this->denomination}", $format->value);
    }

    /**
     * Les filtres en une ligne : « Toute la société et tous les magasins ·
     * Toutes les charges ».
     */
    public function lesFiltres(): string
    {
        return $this->filtres->libelleDuMagasin.' · '.$this->filtres->libelleDeLaVue();
    }
}
