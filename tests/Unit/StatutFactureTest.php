<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\StatutFacture;
use Tests\TestCase;

/**
 * Verrouille la machine à états : aucun statut ne doit être un cul-de-sac
 * accidentel. PartiellementPayee -> Payee a été un bug réel (corrigé) :
 * une facture partiellement payée ne pouvait jamais atteindre "payee".
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

    public function test_payee_et_annulee_sont_des_statuts_definitifs_sans_transition(): void
    {
        $this->assertSame([], StatutFacture::Payee->transitionsAutorisees());
        $this->assertSame([], StatutFacture::Annulee->transitionsAutorisees());
        $this->assertTrue(StatutFacture::Payee->estDefinitive());
        $this->assertTrue(StatutFacture::Annulee->estDefinitive());
    }

    public function test_un_brouillon_ne_peut_pas_devenir_paye_directement(): void
    {
        $this->assertFalse(StatutFacture::Brouillon->peutTransitionnerVers(StatutFacture::Payee));
    }
}
