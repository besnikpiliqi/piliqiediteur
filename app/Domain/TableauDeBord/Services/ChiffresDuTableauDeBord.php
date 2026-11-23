<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Charges\Support\TextesDuCreditDeTva;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Support\CleDuModele;

/**
 * Les quatre chiffres clés du mois en cours, en tête du tableau de bord
 * (décision de l'éditeur du 14/11/2026, « chiffres de G2 »), sur les
 * magasins du filtre où le compte voit leurs documents (voir Perimetre),
 * lus avec les cartes de la page (voir CalculDesSeries) :
 *
 * - le chiffre d'affaires HT du mois : ventes émises moins avoirs émis, et
 *   sa variation sur le même mois de l'année précédente ;
 * - les achats HT du mois, dans une société qui a des articles : factures
 *   d'achat validées moins avoirs fournisseur émis, et leur variation ;
 * - la TVA à payer, une estimation : TVA collectée des ventes du mois,
 *   nette de celle des avoirs, moins la TVA déductible des achats du mois,
 *   nette de celle des avoirs fournisseur, et des charges pour qui les
 *   voit ; sur toute la société, au réel normal, celle de la période en
 *   cours (le mois, ou le trimestre au trimestriel) après le report du
 *   crédit de la période d'avant, avec le crédit à reporter et où en est
 *   le crédit de la déclaration qui sert d'ancre (lot TVA27, voir
 *   ReportDuCreditDeTva) ; rien en franchise en base, ni avant le choix du
 *   régime (décision de l'éditeur du 18/11/2026). L'état de TVA officiel,
 *   avec l'exigibilité des services et les règles de la déclaration,
 *   viendra avec la comptabilité (étape 5) ;
 * - le restant dû des clients, à ce jour, et sa part en retard (voir
 *   Facture::estEnRetard()).
 *
 * Un chiffre que le compte ne voit dans aucun magasin du filtre n'est pas
 * donné (null). Tout est en centimes.
 *
 * @phpstan-type ChiffreDeLaTva array{report: bool, periode: string|null, a_payer: int, credit_a_reporter: int, calcul: string|null, collectee: int, deductible: int, estimation: int|null, avec_les_charges: bool, sans_celles_de_la_societe: bool, pastille: array{texte: string, couleur: string}|null}
 * @phpstan-type Chiffres array{ca: array{valeur: int, variation: string|null}|null, achats: array{valeur: int, variation: string|null}|null, tva: ChiffreDeLaTva|null, restant_du: array{valeur: int, en_retard: int}|null}
 */
final class ChiffresDuTableauDeBord
{
    public function __construct(
        private readonly SeriesDesGraphiques $series,
        private readonly FacturesDuTableauDeBord $factures,
    ) {}

    /**
     * @return Chiffres
     */
    public function pour(CalculDesSeries $calcul): array
    {
        $perimetre = $calcul->perimetre;
        $societe = $perimetre->societe;
        $ceMois = $calcul->ceMois();
        $ilYAUnAn = Mois::decaler($ceMois, -12);
        $ventes = CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Ventes));
        $achats = $societe->aDesArticles() ? CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Achats)) : [];
        $factures = CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Factures));
        $tva = $societe->aDesArticles() ? CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Ventes, SourceDesGraphiques::Achats)) : $ventes;
        $documentsDeVente = $ventes === [] && $tva === [] ? [] : $this->series->documentsDeVente($calcul);
        $documentsDAchat = $achats === [] ? [] : $this->series->documentsDAchat($calcul);

        $chiffres = ['ca' => null, 'achats' => null, 'tva' => null, 'restant_du' => null];

        if ($ventes !== []) {
            $chiffres['ca'] = self::avecSaVariation(self::somme($calcul, $documentsDeVente, $ventes, $ceMois, 'ht'), self::somme($calcul, $documentsDeVente, $ventes, $ilYAUnAn, 'ht'));
        }

        if ($achats !== []) {
            $chiffres['achats'] = self::avecSaVariation(self::somme($calcul, $documentsDAchat, $achats, $ceMois, 'ht'), self::somme($calcul, $documentsDAchat, $achats, $ilYAUnAn, 'ht'));
        }

        if ($perimetre->montreLaCarteDeLaTva()) {
            $chiffres['tva'] = $this->tvaReportee($calcul) ?? self::tva($this->series->tvaDesMois($calcul, $tva, [$ceMois])[$ceMois], $perimetre->laisseLesChargesDeLaSociete($tva));
        }

        if ($factures !== []) {
            $totaux = $this->factures->totaux($calcul->facturesAEncaisser($this->factures));
            $chiffres['restant_du'] = ['valeur' => $totaux['restant_du'], 'en_retard' => $totaux['restant_du_en_retard']];
        }

        return $chiffres;
    }

    /**
     * Le chiffre clé de la TVA à payer du mois (voir TvaDeLaPeriode), sans
     * le report du crédit : la réelle, avec les seules charges validées,
     * et, quand des charges à valider la changent, son estimation avec
     * elles ; sans les charges de « Toute la société » quand un magasin
     * n'est pas compté.
     *
     * @return ChiffreDeLaTva
     */
    private static function tva(MontantsDeTva $tva, bool $sansCellesDeLaSociete): array
    {
        return [
            'report' => false,
            'periode' => null,
            'a_payer' => $tva->reelle(),
            'credit_a_reporter' => 0,
            'calcul' => null,
            'collectee' => $tva->collectee(),
            'deductible' => $tva->deductible(),
            'estimation' => $tva->aDesChargesAValider() ? $tva->estimee() : null,
            'avec_les_charges' => $tva->avecLesCharges,
            'sans_celles_de_la_societe' => $tva->avecLesCharges && $sansCellesDeLaSociete,
            'pastille' => null,
        ];
    }

    /**
     * Le chiffre clé de la TVA à payer de la période en cours avec le
     * report du crédit (voir SeriesDesGraphiques::tvaReportee()) : la TVA à
     * payer réelle, après le crédit reporté de la période d'avant, ou le
     * crédit à reporter ; le calcul qui y mène ; son estimation avec les
     * charges à valider, positive à payer, négative à reporter, quand elles
     * la changent ; la pastille qui dit où en est le crédit de la
     * déclaration qui sert d'ancre. Null quand le périmètre ne reporte pas
     * le crédit.
     *
     * @return ChiffreDeLaTva|null
     */
    private function tvaReportee(CalculDesSeries $calcul): ?array
    {
        $reportee = $this->series->tvaReportee($calcul);
        $periode = $reportee?->deLaPeriodeDu($calcul->ceMois());

        if ($reportee === null || $periode === null) {
            return null;
        }

        return [
            'report' => true,
            'periode' => $reportee->cadence->libelle($periode->periode).($reportee->cadence === CadenceDeLaTva::Trimestrielle ? ' (en cours)' : ''),
            'a_payer' => $periode->reelle->aPayer ?? 0,
            'credit_a_reporter' => $periode->reelle->creditAReporter,
            'calcul' => TextesDuCreditDeTva::calculDeLaPeriode($reportee->cadence, $periode->periode, $periode->reelle, $periode->statut),
            'collectee' => $periode->montants->collectee(),
            'deductible' => $periode->montants->deductible(),
            'estimation' => $periode->estimationDifferente() ? $periode->estimee->signe() : null,
            'avec_les_charges' => true,
            'sans_celles_de_la_societe' => false,
            'pastille' => TextesDuCreditDeTva::pastilleDeLAncre($calcul->etatDuCredit, ! in_array($calcul->perimetre->ecran, [EcranDuTableauDeBord::Societe, EcranDuTableauDeBord::Magasin], true)),
        ];
    }

    /**
     * La somme, sur ce mois, de ce montant de ces documents de ces
     * magasins, un avoir se déduisant.
     *
     * @param  list<array{document: string, magasin: int, jour: string, ht: int, tva: int}>  $documents
     * @param  list<int>  $magasins
     * @param  'ht'|'tva'  $champ
     */
    private static function somme(CalculDesSeries $calcul, array $documents, array $magasins, string $mois, string $champ): int
    {
        return $calcul->sommeParMois($documents, $magasins, [$mois], fn (array $document): int => $calcul->signe($document) * $document[$champ])[$mois];
    }

    /**
     * Ce montant, et sa variation sur celui d'il y a un an, « +6,2 % sur un
     * an », ou rien quand il n'y avait rien il y a un an.
     *
     * @return array{valeur: int, variation: string|null}
     */
    private static function avecSaVariation(int $valeur, int $ilYAUnAn): array
    {
        if ($ilYAUnAn === 0) {
            return ['valeur' => $valeur, 'variation' => null];
        }

        $centiemes = CalculDesSeries::diviserArrondi(($valeur - $ilYAUnAn) * 10000, abs($ilYAUnAn));

        return ['valeur' => $valeur, 'variation' => ($centiemes > 0 ? '+' : '').CalculDesSeries::pourcentage($centiemes).' sur un an'];
    }
}
