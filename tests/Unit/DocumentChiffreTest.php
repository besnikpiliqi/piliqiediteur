<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\DocumentChiffre;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * La TVA que PILIQI propose pour un taux d'un document chiffré (voir
 * DocumentChiffre::tvaProposee()) : celle de chaque taux d'un achat, à
 * côté de celle que le fournisseur écrit ; un taux que le document ne
 * facture pas, ou une vente, qui n'en propose aucune, est refusé.
 */
class DocumentChiffreTest extends TestCase
{
    public function test_la_tva_proposee_d_un_taux_d_un_achat_est_celle_que_piliqi_a_calculee(): void
    {
        $achat = new DocumentChiffre([], [
            '20.00' => ['montant_ht' => '100.00', 'montant_tva' => '20.01', 'montant_ttc' => '120.01', 'montant_tva_calcule' => '20.00'],
            '5.50' => ['montant_ht' => '10.00', 'montant_tva' => '0.55', 'montant_ttc' => '10.55', 'montant_tva_calcule' => '0.55'],
        ], ['total_ht' => '110.00', 'total_tva' => '20.56', 'total_ttc' => '130.56']);

        $this->assertSame('20.00', $achat->tvaProposee('20.00'));
        $this->assertSame('0.55', $achat->tvaProposee('5.50'));
    }

    public function test_un_taux_que_l_achat_ne_facture_pas_est_refuse(): void
    {
        $achat = new DocumentChiffre([], [
            '20.00' => ['montant_ht' => '100.00', 'montant_tva' => '20.00', 'montant_ttc' => '120.00', 'montant_tva_calcule' => '20.00'],
        ], ['total_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Le document ne propose aucune TVA au taux 5.50.');

        $achat->tvaProposee('5.50');
    }

    public function test_une_vente_ne_propose_aucune_tva(): void
    {
        $vente = new DocumentChiffre([], [
            '20.00' => ['montant_ht' => '41.67', 'montant_tva' => '8.33', 'montant_ttc' => '50.00'],
        ], ['total_ht' => '41.67', 'total_tva' => '8.33', 'total_ttc' => '50.00']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Le document ne propose aucune TVA au taux 20.00.');

        $vente->tvaProposee('20.00');
    }
}
