<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use InvalidArgumentException;

/**
 * Les montants d'un avoir fournisseur (décision de l'éditeur du 02/11/2026,
 * T1) : la note d'avoir du fournisseur prime (BOI-TVA-DECLA-30-20-20-20
 * § 260). Pour chaque ligne retournée, PILIQI propose le HT et la TVA que
 * donne la règle des retours (pour tous les articles de la ligne d'achat
 * retournés jusqu'ici, la TVA de leur HT, moins ce que les retours
 * précédents ont rendu, voir LigneAchat::montantsDuRetour()) ; le HT que la
 * note d'avoir écrit pour une ligne remplace sa proposition, et la TVA
 * proposée de la ligne suit alors ce HT, comme la TVA proposée d'une
 * facture d'achat suit sa base. Pour chaque taux, la TVA proposée est la
 * somme de celles de ses lignes, et celle que la note d'avoir écrit la
 * remplace, l'écart se répartissant sur les lignes du taux comme sur une
 * facture d'achat (voir CalculateurLigne::repartirUneTvaRetenue()).
 *
 * Le chiffrage d'un brouillon est provisoire : l'émission le refait, sur
 * les retours déjà émis, en gardant ce que la note d'avoir écrit (voir
 * EmettreAvoirFournisseurAction).
 */
class ChiffrageDUnAvoirFournisseur
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Chaque retour, sous sa clé, avec la ligne d'achat qu'il retourne, sa
     * quantité et le HT qu'écrit la note d'avoir, s'il y en a un ; la TVA
     * qu'écrit la note d'avoir, par taux. Rend le document chiffré : chaque
     * ligne avec son HT retenu (montant_ht), son HT proposé
     * (montant_ht_calcule), sa TVA et son TTC, chaque taux avec sa base, sa
     * TVA retenue et sa TVA proposée (montant_tva_calcule), et les totaux,
     * sommes exactes des lignes.
     *
     * @template TCle of array-key
     *
     * @param  array<TCle, array{ligne_achat: LigneAchat, quantite: int, montant_ht_saisi: string|null}>  $retours
     * @param  array<int, int>  $tvaEcriteParTaux  en centimes, par taux en centièmes de point
     *
     * @throws InvalidArgumentException
     */
    public function chiffrer(array $retours, array $tvaEcriteParTaux): DocumentChiffre
    {
        $lignes = [];

        foreach ($retours as $cle => $retour) {
            $ligneAchat = $retour['ligne_achat'];
            $montants = $ligneAchat->montantsDuRetour(
                $retour['quantite'],
                $retour['montant_ht_saisi'] === null ? null : $this->calculateur->enCentiemes($retour['montant_ht_saisi']),
            );

            $lignes[$cle] = [
                'taux' => $this->calculateur->enCentiemes($ligneAchat->taux_tva),
                'taux_tva' => $ligneAchat->taux_tva,
                'description' => $ligneAchat->description,
                'reference' => $ligneAchat->reference,
                'montant_ht' => $montants['montant_ht'],
                'montant_ht_calcule' => $montants['montant_ht_calcule'],
                'montant_tva' => $montants['montant_tva'],
            ];
        }

        $tvaProposeeParTaux = [];

        foreach ($lignes as $ligne) {
            $tvaProposeeParTaux[$ligne['taux']] = ($tvaProposeeParTaux[$ligne['taux']] ?? 0) + $ligne['montant_tva'];
        }

        foreach (array_keys($tvaEcriteParTaux) as $taux) {
            if (! isset($tvaProposeeParTaux[$taux])) {
                throw new InvalidArgumentException(sprintf(CalculateurLigne::REFUS_TVA_SANS_LIGNE, CalculateurLigne::tauxEnTexte($taux)));
            }
        }

        foreach ($tvaProposeeParTaux as $taux => $proposee) {
            $lignes = $this->calculateur->repartirUneTvaRetenue($lignes, $taux, $tvaEcriteParTaux[$taux] ?? $proposee);
        }

        return $this->document($lignes, $tvaProposeeParTaux);
    }

    /**
     * Le document chiffré, écrit comme ses colonnes (« 41.67 »).
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array<int, int>  $tvaProposeeParTaux
     */
    private function document(array $lignes, array $tvaProposeeParTaux): DocumentChiffre
    {
        $enDecimal = $this->calculateur->enDecimal(...);
        $parTaux = [];
        $totaux = ['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0];

        foreach ($lignes as $ligne) {
            $cle = $enDecimal($ligne['taux']);
            $parTaux[$cle] ??= ['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0, 'montant_tva_calcule' => $tvaProposeeParTaux[$ligne['taux']]];

            foreach (['montant_ht', 'montant_tva', 'montant_ttc'] as $montant) {
                $parTaux[$cle][$montant] += $ligne[$montant];
                $totaux[$montant] += $ligne[$montant];
            }
        }

        return new DocumentChiffre(
            array_map(fn (array $ligne): array => [
                ...$ligne,
                'montant_ht' => $enDecimal($ligne['montant_ht']),
                'montant_ht_calcule' => $enDecimal($ligne['montant_ht_calcule']),
                'montant_tva' => $enDecimal($ligne['montant_tva']),
                'montant_ttc' => $enDecimal($ligne['montant_ttc']),
            ], $lignes),
            array_map(fn (array $montants): array => array_map($enDecimal, $montants), $parTaux),
            [
                'total_ht' => $enDecimal($totaux['montant_ht']),
                'total_tva' => $enDecimal($totaux['montant_tva']),
                'total_ttc' => $enDecimal($totaux['montant_ttc']),
            ],
        );
    }
}
