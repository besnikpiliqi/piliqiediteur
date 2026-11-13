<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\NatureLigne;

/**
 * Ce que chaque ticket déclaré qui vend un service a encaissé, rejoué dans
 * l'ordre du registre fiscal, et la part de ses prestations de services
 * que l'e-reporting des paiements déclare, par jour et par taux (voir
 * EReportingDesParticuliers) :
 *
 * - le ticket se divise en groupes de lignes de même nature et de même
 *   taux, du plus fort taux au plus faible, les biens avant les services
 *   d'un même taux ; chaque groupe vaut le TTC de ses lignes, moins celui
 *   des lignes des avoirs émis jusque-là qui les corrigent ;
 * - ce qu'il a encaissé est la somme de ses règlements (paiements, crédits
 *   d'avoir imputés, contre-passations déduites), moins le crédit des
 *   avoirs qui le corrigent quand il règle une autre vente ; la part
 *   d'un crédit qui annule les lignes de son avoir sur ce ticket n'est pas
 *   un règlement (voir EReportingDesParticuliers::sansLesReductions()) ;
 * - à chaque changement de ce montant, l'encaissé, borné au TTC du ticket,
 *   se répartit entre les groupes au prorata de leur TTC, en cumul dans
 *   leur ordre, chaque part arrondie au centime le plus proche (voir
 *   CalculateurLigne::repartirAuProrata()) ; la part de chaque groupe moins
 *   ce qui en est déjà déclaré se déclare à la date du mouvement, pour les
 *   seuls services à un taux de TVA non nul : une réparation de 60,00 €
 *   et un livre de 10,55 € dont 30,00 € sont reçus déclarent 25,51 € au
 *   taux de la réparation, et le dernier règlement retombe exactement sur
 *   le TTC de chaque service ;
 * - l'émission d'un avoir ne déclare rien : l'avoir d'une prestation déjà
 *   encaissée se déduit le jour où son crédit règle une autre vente,
 *   imputé d'abord sur les tickets qu'il corrige dans l'ordre de leur
 *   identifiant, chacun dans la limite de ce que l'avoir en corrige moins
 *   ce que son crédit y a déjà annulé ; un crédit jamais utilisé ne déduit
 *   rien, et la contre-passation d'une telle utilisation la rend, dans
 *   l'ordre inverse ;
 * - le crédit d'un avoir de régularisation après la correction du SIREN
 *   de la société n'est jamais un encaissement : ses imputations ne
 *   comptent pas ; celui d'une exonération non prouvée règle la vente à la
 *   TVA française qui la remplace comme tout crédit d'avoir.
 *
 * Seuls les mouvements que compte la clôture s'ajoutent aux encaissements
 * déclarés ; les précédents servent à savoir ce que chaque ticket a déjà
 * encaissé et déclaré. Tous les montants sont en centimes.
 */
final class EncaissementsDesPrestations
{
    /**
     * @var array<int, array<int, int>> par avoir puis par document corrigé, ce que son crédit y a annulé
     */
    private array $annule = [];

    /**
     * @var array<int, array<int, int>> par avoir puis par document corrigé, le crédit qui en vient et règle une autre vente
     */
    private array $utilise = [];

    /**
     * @var array<int, int> par ticket suivi, ce qu'il a encaissé
     */
    private array $encaisse = [];

    /**
     * @var array<int, array<string, int>> par ticket suivi puis par groupe, ce qui en est déclaré
     */
    private array $declare = [];

    /**
     * @var array<string, int> par « date|taux », les encaissements de services déclarés par la clôture
     */
    private array $parDateEtParTaux = [];

    /**
     * @param  array<int, array<string, array{nature: NatureLigne, taux: int, ttc: int}>>  $groupes  par ticket suivi, ses groupes dans leur ordre
     * @param  array<int, array<int, array<string, int>>>  $lignesDesAvoirs  par avoir, par document corrigé (dans l'ordre de leur identifiant) puis par groupe, le TTC de ses lignes
     * @param  list<int>  $regularisations  les avoirs de régularisation après la correction du SIREN de la société
     */
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private array $groupes,
        private readonly array $lignesDesAvoirs,
        private readonly array $regularisations,
    ) {}

    /**
     * L'émission d'un avoir : le TTC de ses lignes quitte les groupes
     * des tickets suivis qu'il corrige, sans rien déclarer.
     */
    public function avoirEmis(int $avoirId): void
    {
        foreach ($this->lignesDesAvoirs[$avoirId] ?? [] as $factureId => $parGroupe) {
            foreach ($parGroupe as $cle => $ttc) {
                if (isset($this->groupes[$factureId][$cle])) {
                    $this->groupes[$factureId][$cle]['ttc'] = max(0, $this->groupes[$factureId][$cle]['ttc'] - $ttc);
                }
            }
        }
    }

    /**
     * Un règlement d'un document : $montant le règle, à la date $date ;
     * $reduction, la part d'un crédit d'avoir qui annule les lignes de cet
     * avoir sur ce document, ne le règle pas et n'est pas comptée dans
     * $montant. $compte dit si la clôture compte ce règlement.
     */
    public function reglement(int $factureId, string $date, int $montant, int $reduction, ?int $avoirId, bool $compte): void
    {
        if ($avoirId !== null && in_array($avoirId, $this->regularisations, true)) {
            return;
        }

        if ($avoirId !== null) {
            $this->annule[$avoirId][$factureId] = ($this->annule[$avoirId][$factureId] ?? 0) + $reduction;
        }

        if ($montant === 0) {
            return;
        }

        $this->ajouter($factureId, $montant, $date, $compte);

        if ($avoirId !== null) {
            $this->creditUtilise($avoirId, $montant, $date, $compte);
        }
    }

    /**
     * Les encaissements de services que déclare la clôture, par date puis
     * du plus fort taux au plus faible, sans somme nulle.
     *
     * @return list<array{date: string, taux: int, montant: int}>
     */
    public function declares(): array
    {
        $parDateEtParTaux = array_filter($this->parDateEtParTaux);
        uksort($parDateEtParTaux, function (string $a, string $b): int {
            [$dateA, $tauxA] = explode('|', $a);
            [$dateB, $tauxB] = explode('|', $b);

            return [$dateA, -(int) $tauxA] <=> [$dateB, -(int) $tauxB];
        });

        return array_map(function (string $cle, int $montant): array {
            [$date, $taux] = explode('|', $cle);

            return ['date' => $date, 'taux' => (int) $taux, 'montant' => $montant];
        }, array_keys($parDateEtParTaux), array_values($parDateEtParTaux));
    }

    /**
     * Le crédit d'un avoir qui règle une autre vente que ce qu'il annule :
     * il quitte l'encaissé des tickets qu'il corrige, dans l'ordre de leur
     * identifiant, chacun dans la limite de ce que l'avoir en corrige moins
     * ce que son crédit y a annulé et ce qui en a déjà réglé une autre
     * vente ; rendu par une contre-passation ($montant négatif), il y
     * revient dans l'ordre inverse.
     */
    private function creditUtilise(int $avoirId, int $montant, string $date, bool $compte): void
    {
        $corriges = $this->lignesDesAvoirs[$avoirId] ?? [];
        $reste = abs($montant);

        foreach ($montant > 0 ? $corriges : array_reverse($corriges, true) as $factureId => $parGroupe) {
            if ($reste === 0) {
                return;
            }

            $dejaUtilise = $this->utilise[$avoirId][$factureId] ?? 0;
            $part = $montant > 0
                ? min($reste, max(0, (int) array_sum($parGroupe) - ($this->annule[$avoirId][$factureId] ?? 0) - $dejaUtilise))
                : min($reste, $dejaUtilise);

            if ($part === 0) {
                continue;
            }

            $sens = $montant <=> 0;
            $this->utilise[$avoirId][$factureId] = $dejaUtilise + $sens * $part;
            $reste -= $part;
            $this->ajouter($factureId, -$sens * $part, $date, $compte);
        }
    }

    /**
     * Ce montant s'ajoute à l'encaissé d'un ticket suivi, et la part de
     * chacun de ses services qui change se déclare à cette date, si la
     * clôture compte ce mouvement.
     */
    private function ajouter(int $factureId, int $montant, string $date, bool $compte): void
    {
        if (! isset($this->groupes[$factureId])) {
            return;
        }

        $this->encaisse[$factureId] = ($this->encaisse[$factureId] ?? 0) + $montant;
        $groupes = $this->groupes[$factureId];
        $ttc = array_map(fn (array $groupe): int => $groupe['ttc'], $groupes);
        $parts = $this->calculateur->repartirAuProrata(max(0, min($this->encaisse[$factureId], (int) array_sum($ttc))), $ttc);

        foreach ($groupes as $cle => $groupe) {
            $ecart = $parts[$cle] - ($this->declare[$factureId][$cle] ?? 0);
            $this->declare[$factureId][$cle] = $parts[$cle];

            if ($compte && $ecart !== 0 && $groupe['nature'] === NatureLigne::Service && $groupe['taux'] > 0) {
                $this->parDateEtParTaux["{$date}|{$groupe['taux']}"] = ($this->parDateEtParTaux["{$date}|{$groupe['taux']}"] ?? 0) + $ecart;
            }
        }
    }
}
