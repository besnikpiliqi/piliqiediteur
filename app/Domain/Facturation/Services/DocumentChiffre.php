<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tva\Services\CalculateurLigne;

/**
 * Un document calculé en une seule fois (voir CalculateurLigne) : ses
 * lignes, avec leurs montants, ses montants par taux et ses totaux, tous
 * écrits comme une colonne decimal(12,2) (« 41.67 »). L'enregistrement
 * comme l'écran les lisent ici, sans rien recalculer : chaque taux est la
 * somme exacte de ses lignes, et chaque total la somme exacte de toutes.
 */
final readonly class DocumentChiffre
{
    /**
     * @param  array<array-key, array<string, mixed>>  $lignes  par clé de ligne, chacune avec montant_ht, montant_tva et montant_ttc
     * @param  array<string, array{montant_ht: string, montant_tva: string, montant_ttc: string}>  $parTaux  par taux facturé (« 20.00 »), dans l'ordre où il apparaît
     * @param  array{total_ht: string, total_tva: string, total_ttc: string}  $totaux
     */
    public function __construct(
        public array $lignes,
        public array $parTaux,
        public array $totaux,
    ) {}

    /**
     * Un document sans aucune ligne complète : rien à compter.
     */
    public static function vide(): self
    {
        return new self([], [], ['total_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00']);
    }

    /**
     * Les lignes, dans leur ordre, avec les montants que le calcul leur a
     * donnés dans le même ordre (voir CalculateurLigne::chiffrerUneVente()
     * et chiffrerUnAchat()).
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}  $calcul
     */
    public static function depuisLeCalcul(array $lignes, array $calcul, CalculateurLigne $calculateur): self
    {
        $enDecimal = fn (array $montants): array => array_map($calculateur->enDecimal(...), $montants);
        $chiffrees = [];

        foreach (array_keys($lignes) as $position => $cle) {
            $chiffrees[$cle] = [...$lignes[$cle], ...$enDecimal($calcul['lignes'][$position])];
        }

        $parTaux = [];

        foreach ($calcul['taux'] as $taux => $montants) {
            $parTaux[$calculateur->enDecimal($taux)] = $enDecimal($montants);
        }

        return new self($chiffrees, $parTaux, [
            'total_ht' => $calculateur->enDecimal($calcul['total']['montant_ht']),
            'total_tva' => $calculateur->enDecimal($calcul['total']['montant_tva']),
            'total_ttc' => $calculateur->enDecimal($calcul['total']['montant_ttc']),
        ]);
    }
}
