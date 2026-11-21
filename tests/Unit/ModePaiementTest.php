<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\ModePaiement;
use PHPUnit\Framework\TestCase;

/**
 * Les modes de paiement en abrégé des relevés de caisse (demandes de
 * l'éditeur du 15/11/2026 ; abréviations : décision de Claude, D76) :
 * chaque mode a la sienne, à côté de son nom entier, que les autres écrans
 * gardent, et l'ordre fixe des relevés les compte tous une fois.
 */
class ModePaiementTest extends TestCase
{
    public function test_chaque_mode_a_son_abreviation_a_cote_de_son_nom_entier(): void
    {
        $this->assertSame(
            ['virement' => ['VIR', 'Virement'], 'cheque' => ['CHQ', 'Chèque'], 'especes' => ['ESP', 'Espèces'], 'carte_bancaire' => ['CB', 'Carte bancaire'], 'prelevement' => ['PRLV', 'Prélèvement']],
            array_combine(
                array_map(fn (ModePaiement $mode): string => $mode->value, ModePaiement::cases()),
                array_map(fn (ModePaiement $mode): array => [$mode->abreviation(), $mode->getLabel()], ModePaiement::cases()),
            ),
        );
    }

    public function test_l_ordre_des_releves_compte_chaque_mode_une_fois_les_especes_d_abord(): void
    {
        $this->assertSame([ModePaiement::Especes, ModePaiement::CarteBancaire, ModePaiement::Cheque, ModePaiement::Virement, ModePaiement::Prelevement], ModePaiement::dansLOrdreDesReleves());
        $this->assertEqualsCanonicalizing(ModePaiement::cases(), ModePaiement::dansLOrdreDesReleves());
    }
}
