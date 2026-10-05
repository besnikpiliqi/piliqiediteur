<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\PrixReference;
use PHPUnit\Framework\TestCase;

class PrixReferenceTest extends TestCase
{
    public function test_le_prix_de_reference_explicite_lemporte(): void
    {
        $this->assertSame(PrixReference::Ht, PrixReference::depuisSaisie(
            ['prix_reference' => 'ht', 'prix_unitaire_ht' => 8.33, 'prix_unitaire_ttc' => 10],
            PrixReference::Ttc,
        ));
    }

    /**
     * Appel d'API ou import qui ne fournit qu'un seul prix : c'est lui qui
     * fait foi, jamais le prix par défaut.
     */
    public function test_le_seul_prix_fourni_fait_foi(): void
    {
        $this->assertSame(PrixReference::Ht, PrixReference::depuisSaisie(['prix_unitaire_ht' => 8.33], PrixReference::Ttc));
        $this->assertSame(PrixReference::Ttc, PrixReference::depuisSaisie(['prix_unitaire_ttc' => 40], PrixReference::Ht));
    }

    public function test_sans_prix_ou_avec_les_deux_le_prix_par_defaut_sapplique(): void
    {
        $this->assertSame(PrixReference::Ttc, PrixReference::depuisSaisie([], PrixReference::Ttc));
        $this->assertSame(PrixReference::Ht, PrixReference::depuisSaisie(['prix_unitaire_ht' => 1, 'prix_unitaire_ttc' => 1.2], PrixReference::Ht));
        $this->assertSame(PrixReference::Ttc, PrixReference::depuisSaisie(['prix_reference' => 'inconnu'], PrixReference::Ttc));
    }
}
