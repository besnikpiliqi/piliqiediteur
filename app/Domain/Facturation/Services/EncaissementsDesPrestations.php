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
 *   d'un même taux ; chaque groupe vaut le TTC de ses lignes, moins ce
 *   que le crédit des avoirs qui les corrigent a déjà consommé : la part
 *   qui réduit ce que doit le ticket lui-même (son imputation sur lui) et
 *   celle qui a réglé une autre vente, réparties sur les groupes de ses
 *   lignes au prorata de leur TTC ; le crédit encore disponible garde sa
 *   place dans le ticket ;
 * - ce qu'il a encaissé est la somme de ses règlements (paiements, crédits
 *   d'avoir imputés, contre-passations déduites), moins le crédit des
 *   avoirs qui le corrigent quand il règle une autre vente ; la part d'un
 *   crédit qui annule les lignes de son avoir sur ce ticket n'est pas un
 *   règlement (voir EReportingDesParticuliers::sansLesReductions()) ;
 * - à chaque mouvement qui change l'un ou l'autre, l'encaissé, borné au
 *   TTC des groupes, se répartit entre eux au prorata de leur TTC, en
 *   cumul dans leur ordre, chaque part arrondie au centime le plus proche
 *   (voir CalculateurLigne::repartirAuProrata()) ; la part de chaque
 *   groupe moins ce qui en est déjà déclaré se déclare à la date du
 *   mouvement, pour les seuls services à un taux de TVA non nul : une
 *   réparation de 60,00 € et un livre de 10,55 € dont 30,00 € sont reçus
 *   déclarent 25,51 € au taux de la réparation, et le dernier règlement
 *   retombe exactement sur le TTC de chaque service ;
 * - un avoir dont le crédit s'impute sur le ticket qu'il corrige change
 *   donc ses groupes le jour de son émission (C-1 : au prorata du TTC
 *   restant après un avoir) : d'une réparation de 60,00 € et d'un article
 *   de 40,00 € dont 30,00 € sont reçus (18,00 € déclarés), l'article rendu
 *   reporte les 30,00 € sur la réparation (12,00 € de plus), et la
 *   réparation annulée les reporte sur l'article (18,00 € déduits) ; le
 *   crédit qui reste au client ne déduit rien tant qu'il n'est pas
 *   utilisé : l'avoir d'une prestation déjà encaissée se déduit le jour
 *   où son crédit règle une autre vente, imputé d'abord sur les tickets
 *   qu'il corrige dans l'ordre de leur identifiant, chacun dans la limite
 *   de ce que l'avoir en corrige moins ce que son crédit y a déjà
 *   consommé ; un crédit jamais utilisé ne déduit rien, et la
 *   contre-passation d'une telle utilisation la rend, dans l'ordre
 *   inverse (T1.3) ;
 * - le crédit d'un avoir de régularisation après la correction du SIREN
 *   de la société n'est jamais un encaissement : ses imputations ne
 *   comptent pas et ne consomment rien ; celui d'une exonération non
 *   prouvée règle la vente à la TVA française qui la remplace comme tout
 *   crédit d'avoir.
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
        private readonly array $groupes,
        private readonly array $lignesDesAvoirs,
        private readonly array $regularisations,
    ) {}

    /**
     * Un règlement d'un document : $montant le règle, à la date $date ;
     * $reduction, la part d'un crédit d'avoir qui annule les lignes de cet
     * avoir sur ce document, ne le règle pas et n'est pas comptée dans
     * $montant, mais retire ces lignes de ses groupes. $compte dit si la
     * clôture compte ce règlement.
     */
    public function reglement(int $factureId, string $date, int $montant, int $reduction, ?int $avoirId, bool $compte): void
    {
        if ($avoirId !== null && in_array($avoirId, $this->regularisations, true)) {
            return;
        }

        if ($avoirId !== null && $reduction !== 0) {
            $this->annule[$avoirId][$factureId] = ($this->annule[$avoirId][$factureId] ?? 0) + $reduction;
        }

        $this->encaisse[$factureId] = ($this->encaisse[$factureId] ?? 0) + $montant;

        if ($avoirId !== null && $montant !== 0) {
            $this->creditUtilise($avoirId, $montant, $date, $compte);
        }

        $this->declarer($factureId, $date, $compte);
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
            $this->encaisse[$factureId] = ($this->encaisse[$factureId] ?? 0) - $sens * $part;
            $reste -= $part;
            $this->declarer($factureId, $date, $compte);
        }
    }

    /**
     * L'encaissé d'un ticket suivi se répartit sur ses groupes tels qu'ils
     * sont maintenant (voir groupesRestants()), et la part de chacun de ses
     * services qui change se déclare à cette date, si la clôture compte ce
     * mouvement.
     */
    private function declarer(int $factureId, string $date, bool $compte): void
    {
        if (! isset($this->groupes[$factureId])) {
            return;
        }

        $ttc = $this->groupesRestants($factureId);
        $parts = $this->calculateur->repartirAuProrata(max(0, min($this->encaisse[$factureId] ?? 0, (int) array_sum($ttc))), $ttc);

        foreach ($this->groupes[$factureId] as $cle => $groupe) {
            $ecart = $parts[$cle] - ($this->declare[$factureId][$cle] ?? 0);
            $this->declare[$factureId][$cle] = $parts[$cle];

            if ($compte && $ecart !== 0 && $groupe['nature'] === NatureLigne::Service && $groupe['taux'] > 0) {
                $this->parDateEtParTaux["{$date}|{$groupe['taux']}"] = ($this->parDateEtParTaux["{$date}|{$groupe['taux']}"] ?? 0) + $ecart;
            }
        }
    }

    /**
     * Le TTC de chaque groupe du ticket, dans leur ordre, moins ce que le
     * crédit de chaque avoir qui le corrige a consommé (annulé sur lui ou
     * utilisé pour une autre vente), réparti sur les groupes des lignes de
     * cet avoir au prorata de leur TTC, en cumul dans l'ordre des groupes ;
     * jamais en dessous de zéro.
     *
     * @return array<string, int>
     */
    private function groupesRestants(int $factureId): array
    {
        $restants = array_map(fn (array $groupe): int => $groupe['ttc'], $this->groupes[$factureId]);

        foreach ($this->lignesDesAvoirs as $avoirId => $parDocument) {
            $corrige = $parDocument[$factureId] ?? [];
            $consomme = min((int) array_sum($corrige), max(0, ($this->annule[$avoirId][$factureId] ?? 0) + ($this->utilise[$avoirId][$factureId] ?? 0)));

            if ($consomme === 0) {
                continue;
            }

            $poids = array_map(fn (string $cle): int => $corrige[$cle] ?? 0, array_combine(array_keys($restants), array_keys($restants)));

            foreach ($this->calculateur->repartirAuProrata($consomme, $poids) as $cle => $part) {
                $restants[$cle] = max(0, $restants[$cle] - $part);
            }
        }

        return $restants;
    }
}
