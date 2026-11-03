<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ModePaiement;

/**
 * Ce qui a réglé un ticket ou une facture à son émission, tel que son
 * contenu signé l'a retenu (voir ContenuFiscal) : chaque paiement
 * encaissé dans la même transaction, avec son mode, sa référence et son
 * montant, chaque crédit d'avoir imputé (le crédit du client ou un bon
 * d'avoir présenté à la caisse), et ce qui restait dû ensuite. Le PDF
 * l'imprime dans son bloc « Règlements » (Q5) ; un paiement reçu ensuite
 * reste sur la fiche du document, jamais sur son PDF, qui reproduit le
 * document tel qu'émis. Tous les montants sont en centimes, lus dans le
 * contenu signé et dans le total TTC figé du document, jamais recalculés.
 */
final readonly class ReglementsALEmission
{
    /**
     * @param  list<array{mode: ModePaiement, reference: string|null, montant: int}>  $paiements
     * @param  list<array{numero: string, montant: int}>  $creditsDAvoir
     * @param  int  $restantDu  le total TTC moins les paiements et les crédits, en centimes
     */
    public function __construct(
        public array $paiements,
        public array $creditsDAvoir,
        public int $restantDu,
    ) {}

    /**
     * Les règlements que le contexte signé d'un document émis a retenus ;
     * aucun pour un brouillon, qui n'en a pas reçu.
     *
     * @param  array<string, mixed>|null  $contexteSigne  le bloc « contexte » signé, null pour un brouillon
     * @param  int  $totalTtc  le total TTC du document, en centimes
     */
    public static function depuisLeContexte(?array $contexteSigne, int $totalTtc): self
    {
        $paiements = array_map(fn (array $reglement): array => [
            'mode' => ModePaiement::from($reglement['mode']),
            'reference' => $reglement['reference'],
            'montant' => $reglement['montant'],
        ], $contexteSigne['reglements'] ?? []);
        $creditsDAvoir = array_map(fn (array $credit): array => [
            'numero' => $credit['numero'],
            'montant' => $credit['montant'],
        ], $contexteSigne['credits_avoir'] ?? []);

        return new self(
            array_values($paiements),
            array_values($creditsDAvoir),
            $totalTtc - array_sum(array_column($paiements, 'montant')) - array_sum(array_column($creditsDAvoir, 'montant')),
        );
    }

    /**
     * Le document a-t-il reçu un paiement ou un crédit d'avoir à son
     * émission ?
     */
    public function existent(): bool
    {
        return $this->paiements !== [] || $this->creditsDAvoir !== [];
    }

    /**
     * Le document était-il réglé en entier dès son émission ?
     */
    public function soldentLeDocument(): bool
    {
        return $this->restantDu <= 0;
    }
}
