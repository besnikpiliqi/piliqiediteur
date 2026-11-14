<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\Mois;

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
 *   nette de celle des avoirs fournisseur ; « TVA non applicable » en
 *   franchise en base. L'état de TVA officiel, avec l'exigibilité des
 *   services et les règles de la déclaration, viendra avec la comptabilité
 *   (étape 5) ;
 * - le restant dû des clients, à ce jour, et sa part en retard (voir
 *   Facture::estEnRetard()).
 *
 * Un chiffre que le compte ne voit dans aucun magasin du filtre n'est pas
 * donné (null). Tout est en centimes.
 *
 * @phpstan-type Chiffres array{ca: array{valeur: int, variation: string|null}|null, achats: array{valeur: int, variation: string|null}|null, tva: array{franchise: bool, a_payer: int, collectee: int, deductible: int}|null, restant_du: array{valeur: int, en_retard: int}|null}
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
        $ventes = $perimetre->magasinsAvec(SourceDesGraphiques::Ventes)->modelKeys();
        $achats = $societe->aDesArticles() ? $perimetre->magasinsAvec(SourceDesGraphiques::Achats)->modelKeys() : [];
        $factures = $perimetre->magasinsAvec(SourceDesGraphiques::Factures)->modelKeys();
        $tva = $societe->aDesArticles() ? $perimetre->magasinsAvec(SourceDesGraphiques::Ventes, SourceDesGraphiques::Achats)->modelKeys() : $ventes;

        $somme = fn (array $lignes, array $magasins, string $mois, string $champ): int => $calcul->sommeParMois($lignes, $magasins, [$mois], fn (array $ligne): int => $calcul->signe($ligne) * $ligne[$champ])[$mois];
        $documentsDeVente = $ventes === [] && $tva === [] ? [] : $this->series->documentsDeVente($calcul);
        $documentsDAchat = $achats === [] ? [] : $this->series->documentsDAchat($calcul);

        $chiffres = ['ca' => null, 'achats' => null, 'tva' => null, 'restant_du' => null];

        if ($ventes !== []) {
            $chiffres['ca'] = self::avecSaVariation($somme($documentsDeVente, $ventes, $ceMois, 'ht'), $somme($documentsDeVente, $ventes, $ilYAUnAn, 'ht'));
        }

        if ($achats !== []) {
            $chiffres['achats'] = self::avecSaVariation($somme($documentsDAchat, $achats, $ceMois, 'ht'), $somme($documentsDAchat, $achats, $ilYAUnAn, 'ht'));
        }

        if ($tva !== []) {
            $collectee = $somme($documentsDeVente, $tva, $ceMois, 'tva');
            $deductible = $societe->aDesArticles() ? $somme($documentsDAchat, $tva, $ceMois, 'tva') : 0;
            $chiffres['tva'] = $societe->estEnFranchiseDeTva()
                ? ['franchise' => true, 'a_payer' => 0, 'collectee' => 0, 'deductible' => 0]
                : ['franchise' => false, 'a_payer' => $collectee - $deductible, 'collectee' => $collectee, 'deductible' => $deductible];
        }

        if ($factures !== []) {
            $totaux = $this->factures->totaux($calcul->facturesAEncaisser($this->factures));
            $chiffres['restant_du'] = ['valeur' => $totaux['restant_du'], 'en_retard' => $totaux['restant_du_en_retard']];
        }

        return $chiffres;
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
