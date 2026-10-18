<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\CategorieEReporting;

/**
 * Ce que l'e-reporting déclarera pour la journée d'une clôture (voir
 * EReportingDesParticuliers), en centimes, les taux en centièmes de point :
 *
 * - les transactions, une par catégorie, par taux et par motif, avec leur
 *   base HT et leur TVA, les avoirs déduits (négatives sur une journée qui
 *   n'a que des avoirs) ; le motif et la remarque disent pourquoi une vente
 *   sans TVA française reste à déclarer et ce qui attend sa transmission ;
 * - les encaissements des prestations de services, par date et par taux ;
 * - les règlements d'une vente qui mêle biens et services, ou plusieurs
 *   taux de services, reçus en plusieurs fois, par date, dont la
 *   répartition attend la règle de l'expert-comptable de la société.
 *
 * Le magasin de démonstration n'a jamais rien à déclarer.
 */
final readonly class ApercuEReporting
{
    /**
     * @param  string  $journee  la journée clôturée, AAAA-MM-JJ
     * @param  list<array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null, remarque: string|null}>  $transactions
     * @param  list<array{date: string, taux: int, montant: int}>  $encaissementsDeServices
     * @param  list<array{date: string, montant: int}>  $encaissementsARepartir
     */
    public function __construct(
        public string $journee,
        public bool $demonstration,
        public array $transactions,
        public array $encaissementsDeServices,
        public array $encaissementsARepartir,
    ) {}

    /**
     * La somme des bases HT de ses transactions, en centimes.
     */
    public function totalHt(): int
    {
        return (int) array_sum(array_column($this->transactions, 'ht'));
    }

    /**
     * La somme des TVA de ses transactions, en centimes.
     */
    public function totalTva(): int
    {
        return (int) array_sum(array_column($this->transactions, 'tva'));
    }

    /**
     * La journée a-t-elle quelque chose à déclarer ? Rien n'est déclaré
     * pour un jour sans opération.
     */
    public function aDeclarer(): bool
    {
        return $this->transactions !== [] || $this->encaissementsDeServices !== [] || $this->encaissementsARepartir !== [];
    }
}
