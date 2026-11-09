<?php

declare(strict_types=1);

namespace App\Domain\Achats\Services;

use App\Domain\Facturation\Services\CalculateurLigne;

/**
 * Ce que la facture d'achat ou la note d'avoir d'un fournisseur écrit pour
 * un taux de TVA, face à ce que PILIQI propose (décision de l'éditeur du
 * 02/11/2026, T1, voir TvaDuFournisseur) : sa base, la TVA proposée et la
 * TVA retenue, et, pour chacune de ses lignes, le HT proposé et le HT
 * retenu. Un HT de ligne s'écarte de la proposition dans la tolérance
 * jusqu'à 0,02 €, une TVA jusqu'à 1 centime par ligne du taux ; au-delà,
 * l'écart se confirme, et le document est marqué « TVA à vérifier ».
 * Tous les montants sont en centimes, le taux en centièmes de point.
 */
final readonly class EcartsDUnTaux
{
    /**
     * L'écart qu'un HT de ligne peut avoir avec sa proposition sans rien
     * demander, en centimes : la tolérance de la règle Peppol R120.
     */
    public const TOLERANCE_DU_HT_D_UNE_LIGNE = 2;

    /**
     * L'écart qu'une TVA de taux peut avoir avec sa proposition sans rien
     * demander, en centimes par ligne du taux : un fournisseur qui arrondit
     * ou tronque la TVA de chaque ligne s'écarte d'au plus autant.
     */
    public const TOLERANCE_DE_LA_TVA_PAR_LIGNE = 1;

    public const AVERTISSEMENT_TVA = 'À %s : la TVA saisie (%s) s\'écarte de %s de la base × taux (%s). Vérifiez la facture. Si sa TVA est fausse, demandez une facture rectificative au fournisseur : une TVA facturée à tort n\'est pas déductible.';

    public const AVERTISSEMENT_HT = '« %s » : le HT saisi (%s) s\'écarte de %s de la quantité × le PU HT, remise déduite (%s). Vérifiez la facture.';

    /**
     * @param  list<array{description: string, reference: string|null, ht_calcule: int, ht_retenu: int}>  $lignes  chaque ligne du taux, dans son ordre
     */
    public function __construct(
        public int $taux,
        public int $base,
        public int $tvaCalculee,
        public int $tvaRetenue,
        public array $lignes,
    ) {}

    /**
     * Les lignes dont le HT retenu s'écarte de plus de 0,02 € de sa
     * proposition.
     *
     * @return list<array{description: string, reference: string|null, ht_calcule: int, ht_retenu: int}>
     */
    private function lignesHorsTolerance(): array
    {
        return array_values(array_filter(
            $this->lignes,
            fn (array $ligne): bool => abs($ligne['ht_retenu'] - $ligne['ht_calcule']) > self::TOLERANCE_DU_HT_D_UNE_LIGNE,
        ));
    }

    /**
     * La TVA retenue s'écarte-t-elle de plus d'un centime par ligne du taux
     * de la TVA proposée ?
     */
    public function tvaHorsTolerance(): bool
    {
        return abs($this->tvaRetenue - $this->tvaCalculee) > self::TOLERANCE_DE_LA_TVA_PAR_LIGNE * count($this->lignes);
    }

    /**
     * Un écart de ce taux demande-t-il une confirmation ?
     */
    public function depasseLaTolerance(): bool
    {
        return $this->tvaHorsTolerance() || $this->lignesHorsTolerance() !== [];
    }

    /**
     * L'empreinte de ce que la confirmation de ces écarts confirme : le
     * taux, sa base, ses deux TVA et, pour chaque ligne, ses deux HT. Le
     * même écart, enregistré de nouveau, ne se reconfirme pas.
     */
    public function empreinte(): string
    {
        return hash('sha256', json_encode([
            $this->taux,
            $this->base,
            $this->tvaCalculee,
            $this->tvaRetenue,
            array_map(fn (array $ligne): array => [$ligne['ht_calcule'], $ligne['ht_retenu']], $this->lignes),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Ce que l'écran et le refus disent de chaque écart au-delà de la
     * tolérance.
     *
     * @return list<string>
     */
    public function avertissements(): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avertissements = array_map(fn (array $ligne): string => sprintf(
            self::AVERTISSEMENT_HT,
            $ligne['description'],
            $calculateur->enEuros($ligne['ht_retenu']),
            $calculateur->enEuros(abs($ligne['ht_retenu'] - $ligne['ht_calcule'])),
            $calculateur->enEuros($ligne['ht_calcule']),
        ), $this->lignesHorsTolerance());

        if ($this->tvaHorsTolerance()) {
            $avertissements[] = sprintf(
                self::AVERTISSEMENT_TVA,
                CalculateurLigne::tauxEnTexte($this->taux),
                $calculateur->enEuros($this->tvaRetenue),
                $calculateur->enEuros(abs($this->tvaRetenue - $this->tvaCalculee)),
                $calculateur->enEuros($this->tvaCalculee),
            );
        }

        return $avertissements;
    }

    /**
     * L'écart tel que le journal d'audit le retient : le taux, la base, la
     * TVA saisie, la TVA calculée et leur écart, et chaque ligne hors
     * tolérance avec sa référence, ses deux HT et leur écart, en centimes.
     *
     * @return array{taux: int, base: int, tva_saisie: int, tva_calculee: int, ecart: int, lignes: list<array{reference: string|null, ht_saisi: int, ht_calcule: int, ecart: int}>}
     */
    public function enTableau(): array
    {
        return [
            'taux' => $this->taux,
            'base' => $this->base,
            'tva_saisie' => $this->tvaRetenue,
            'tva_calculee' => $this->tvaCalculee,
            'ecart' => $this->tvaRetenue - $this->tvaCalculee,
            'lignes' => array_map(fn (array $ligne): array => [
                'reference' => $ligne['reference'],
                'ht_saisi' => $ligne['ht_retenu'],
                'ht_calcule' => $ligne['ht_calcule'],
                'ecart' => $ligne['ht_retenu'] - $ligne['ht_calcule'],
            ], $this->lignesHorsTolerance()),
        ];
    }
}
