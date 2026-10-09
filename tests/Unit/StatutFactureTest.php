<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\StatutFacture;
use Tests\TestCase;

/**
 * Verrouille la machine à états : aucun statut ne doit être un cul-de-sac
 * accidentel (une facture partiellement payée doit pouvoir devenir payée),
 * et seuls « payée » et « annulée » sont définitifs.
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

    public function test_payee_et_annulee_n_ont_aucune_transition(): void
    {
        $this->assertSame([], StatutFacture::Payee->transitionsAutorisees());
        $this->assertSame([], StatutFacture::Annulee->transitionsAutorisees());
    }

    public function test_une_facture_ni_payee_ni_annulee_peut_encore_changer_de_statut(): void
    {
        $statutsNonDefinitifs = array_filter(
            StatutFacture::cases(),
            fn (StatutFacture $statut): bool => ! in_array($statut, [StatutFacture::Payee, StatutFacture::Annulee], true),
        );

        $culsDeSac = array_filter(
            $statutsNonDefinitifs,
            fn (StatutFacture $statut): bool => $statut->transitionsAutorisees() === [],
        );

        $this->assertSame([], array_values($culsDeSac));
    }

    public function test_un_brouillon_ne_peut_pas_devenir_paye_directement(): void
    {
        $this->assertFalse(StatutFacture::Brouillon->peutTransitionnerVers(StatutFacture::Payee));
    }
}
