<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Enums\TonDUnChiffre;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\SommesParJour;
use App\Domain\Tenancy\Support\CleDuModele;

/**
 * Les quatre chiffres clés du mois en cours, en tête du tableau de bord
 * (décision de l'éditeur du 14/11/2026, « chiffres de G2 »), sur les
 * magasins du filtre où le compte voit leurs documents (voir Perimetre),
 * lus avec les cartes de la page (voir CalculDesSeries) :
 *
 * - le chiffre d'affaires HT du mois : ventes émises moins avoirs émis, et
 *   sa variation sur les mêmes jours de l'année précédente (le 1er au 22 du
 *   mois en cours contre le 1er au 22 du même mois un an plus tôt, comme
 *   l'écart en % sur un an des graphiques par période) ;
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
 * La ligne sous un chiffre a sa couleur, que l'écran donne selon son ton
 * (voir TonDUnChiffre) : la variation sur un an est verte quand elle
 * monte, rouge quand elle baisse (« positif », « negatif »), neutre à 0 ou
 * sans année d'avant ; « dont … en retard » est rouge quand un retard
 * existe ; « collectée − déductible » reste neutre (choix de l'éditeur du
 * 16/11/2026 ; décision de Claude du 22/11/2026, D76 : la couleur suit le
 * sens, jamais le mérite).
 *
 * @phpstan-type Chiffres array{ca: array{valeur: int, variation: string|null, ton: string}|null, achats: array{valeur: int, variation: string|null, ton: string}|null, tva: array{franchise: bool, a_payer: int, collectee: int, deductible: int, estimation: int|null, avec_les_charges: bool, sans_celles_de_la_societe: bool}|null, restant_du: array{valeur: int, en_retard: int, ton_du_retard: string}|null}
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
        $duMois = [Mois::premierJour($ceMois), Mois::dernierJour($ceMois)];
        $ilYAUnAn = [Mois::premierJour(Mois::decaler($ceMois, -12)), Mois::memeJourUnAnPlusTot($calcul->aujourdhui())];
        $ventes = CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Ventes));
        $achats = $societe->aDesArticles() ? CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Achats)) : [];
        $factures = CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Factures));
        $tva = $societe->aDesArticles() ? CleDuModele::desModeles($perimetre->magasinsAvec(SourceDesGraphiques::Ventes, SourceDesGraphiques::Achats)) : $ventes;
        $documentsDeVente = $ventes === [] && $tva === [] ? [] : $this->series->documentsDeVente($calcul);
        $documentsDAchat = $achats === [] ? [] : $this->series->documentsDAchat($calcul);

        $chiffres = ['ca' => null, 'achats' => null, 'tva' => null, 'restant_du' => null];

        if ($ventes !== []) {
            $sommes = self::sommes($calcul, $documentsDeVente, $ventes);
            $chiffres['ca'] = self::avecSaVariation($sommes->entre(...$duMois), $sommes->entre(...$ilYAUnAn));
        }

        if ($achats !== []) {
            $sommes = self::sommes($calcul, $documentsDAchat, $achats);
            $chiffres['achats'] = self::avecSaVariation($sommes->entre(...$duMois), $sommes->entre(...$ilYAUnAn));
        }

        if ($tva !== []) {
            $chiffres['tva'] = $societe->estEnFranchiseDeTva()
                ? ['franchise' => true, 'a_payer' => 0, 'collectee' => 0, 'deductible' => 0, 'estimation' => null, 'avec_les_charges' => false, 'sans_celles_de_la_societe' => false]
                : self::tva($this->series->tvaDesMois($calcul, $tva, [$ceMois])[$ceMois], $perimetre->laisseLesChargesDeLaSociete($tva));
        }

        if ($factures !== []) {
            $totaux = $this->factures->totaux($calcul->facturesAEncaisser($this->factures));
            $chiffres['restant_du'] = ['valeur' => $totaux['restant_du'], 'en_retard' => $totaux['restant_du_en_retard'], 'ton_du_retard' => ($totaux['restant_du_en_retard'] > 0 ? TonDUnChiffre::Negatif : TonDUnChiffre::Neutre)->value];
        }

        return $chiffres;
    }

    /**
     * Le chiffre clé de la TVA à payer du mois (voir TvaDeLaPeriode) : la
     * réelle, avec les seules charges validées, et, quand des charges à
     * valider la changent, son estimation avec elles ; sans les charges de
     * « Toute la société » quand un magasin n'est pas compté.
     *
     * @return array{franchise: bool, a_payer: int, collectee: int, deductible: int, estimation: int|null, avec_les_charges: bool, sans_celles_de_la_societe: bool}
     */
    private static function tva(MontantsDeTva $tva, bool $sansCellesDeLaSociete): array
    {
        return [
            'franchise' => false,
            'a_payer' => $tva->reelle(),
            'collectee' => $tva->collectee(),
            'deductible' => $tva->deductible(),
            'estimation' => $tva->aDesChargesAValider() ? $tva->estimee() : null,
            'avec_les_charges' => $tva->avecLesCharges,
            'sans_celles_de_la_societe' => $tva->avecLesCharges && $sansCellesDeLaSociete,
        ];
    }

    /**
     * Les montants HT de ces documents de ces magasins, un avoir se
     * déduisant, rangés par jour.
     *
     * @param  list<array{document: string, magasin: int, jour: string, ht: int}>  $documents
     * @param  list<int>  $magasins
     */
    private static function sommes(CalculDesSeries $calcul, array $documents, array $magasins): SommesParJour
    {
        return $calcul->sommesParJour($documents, $magasins, fn (array $document): int => $calcul->signe($document) * $document['ht']);
    }

    /**
     * Ce montant, et sa variation sur celui des mêmes jours d'il y a un an,
     * « +6,2 % sur un an », ou rien quand il n'y avait rien il y a un an.
     *
     * @return array{valeur: int, variation: string|null, ton: string}
     */
    private static function avecSaVariation(int $valeur, int $ilYAUnAn): array
    {
        $centiemes = CalculDesSeries::ecartSurUnAn($valeur, $ilYAUnAn);

        if ($centiemes === null) {
            return ['valeur' => $valeur, 'variation' => null, 'ton' => TonDUnChiffre::Neutre->value];
        }

        return ['valeur' => $valeur, 'variation' => ($centiemes > 0 ? '+' : '').CalculDesSeries::pourcentage($centiemes).' sur un an', 'ton' => TonDUnChiffre::dUneVariation($centiemes)->value];
    }
}
