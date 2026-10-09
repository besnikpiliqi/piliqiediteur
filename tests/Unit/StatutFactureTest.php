<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\StatutFacture;
use Tests\TestCase;

/**
 * Verrouille la machine à états : aucun statut ne doit être un cul-de-sac
 * accidentel (une facture partiellement payée doit pouvoir devenir payée),
 * et seule « payée » est définitive. Une facture émise ne se corrige que
 * par un avoir (voir CorrectionParAvoirTest).
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

    public function test_une_facture_payee_n_a_plus_aucune_transition(): void
    {
        $this->assertSame([], StatutFacture::Payee->transitionsAutorisees());
    }

    public function test_une_facture_non_payee_peut_encore_changer_de_statut(): void
    {
        $statutsNonDefinitifs = array_filter(
            StatutFacture::cases(),
            fn (StatutFacture $statut): bool => $statut !== StatutFacture::Payee,
        );

        $culsDeSac = array_filter(
            $statutsNonDefinitifs,
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
