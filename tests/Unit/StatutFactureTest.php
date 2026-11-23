<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\StatutFacture;
use Tests\TestCase;

/**
 * Verrouille la machine à états : aucun statut ne doit être un cul-de-sac
 * accidentel (une facture partiellement payée doit pouvoir devenir payée),
 * et une facture payée ne redevient due que par la contre-passation d'un
 * paiement saisi par erreur (B4, voir ContrePasserPaiementActionTest). Une
 * facture émise ne se corrige que par un avoir (voir
 * CorrectionParAvoirTest).
 */
class StatutFactureTest extends TestCase
{
    public function test_une_facture_partiellement_payee_peut_devenir_payee(): void
    {
        $this->assertTrue(StatutFacture::PartiellementPayee->peutTransitionnerVers(StatutFacture::Payee));
    }

    public function test_une_facture_partiellement_payee_peut_devenir_en_retard(): void
    {
        $this->assertTrue(StatutFacture::PartiellementPayee->peutTransitionnerVers(StatutFacture::EnRetard));
    }

    public function test_une_facture_payee_rouverte_par_une_contre_passation_redevient_partiellement_payee_en_retard_emise_ou_envoyee(): void
    {
        $this->assertSame(
            [StatutFacture::PartiellementPayee, StatutFacture::EnRetard, StatutFacture::Emise, StatutFacture::Envoyee],
            StatutFacture::Payee->transitionsAutorisees(),
        );
    }

    public function test_une_facture_partiellement_payee_rouverte_par_une_contre_passation_redevient_emise_ou_envoyee(): void
    {
        $this->assertTrue(StatutFacture::PartiellementPayee->peutTransitionnerVers(StatutFacture::Emise));
        $this->assertTrue(StatutFacture::PartiellementPayee->peutTransitionnerVers(StatutFacture::Envoyee));
    }

    /**
     * Une facture en retard le reste tant qu'un montant reste dû après
     * l'échéance (G1), même après la contre-passation d'un paiement.
     */
    public function test_une_facture_en_retard_ne_quitte_ce_statut_qu_une_fois_payee(): void
    {
        $this->assertSame([StatutFacture::Payee], StatutFacture::EnRetard->transitionsAutorisees());
    }

    public function test_aucun_statut_n_est_un_cul_de_sac(): void
    {
        $culsDeSac = array_filter(
            StatutFacture::cases(),
            fn (StatutFacture $statut): bool => $statut->transitionsAutorisees() === [],
        );

        $this->assertSame([], array_values($culsDeSac));
    }

    public function test_aucun_statut_ne_ramene_une_facture_emise_en_brouillon(): void
    {
        $statutsQuiRamenentEnBrouillon = array_filter(
            StatutFacture::cases(),
            fn (StatutFacture $statut): bool => $statut->peutTransitionnerVers(StatutFacture::Brouillon),
        );

        $this->assertSame([], array_values($statutsQuiRamenentEnBrouillon));
    }

    public function test_un_brouillon_ne_peut_pas_devenir_paye_directement(): void
    {
        $this->assertFalse(StatutFacture::Brouillon->peutTransitionnerVers(StatutFacture::Payee));
    }
}
