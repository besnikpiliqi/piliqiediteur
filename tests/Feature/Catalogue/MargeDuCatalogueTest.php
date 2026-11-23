<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\MargeDuCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La marge d'un produit du catalogue (voir MargeDuCatalogue) se lit sur
 * son prix de vente hors taxe : son prix TTC ÷ (1 + son taux) ; en
 * franchise en base, la vente se fait sans TVA, et le prix TTC est tout
 * entier hors taxe.
 */
class MargeDuCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_en_franchise_en_base_le_prix_de_vente_est_tout_entier_hors_taxe(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '1000.00', 'taux_tva' => 20, 'cmup_ht' => '400']);

        $assujetti = MargeDuCatalogue::de($produit, false);
        $enFranchise = MargeDuCatalogue::de($produit, true);

        $this->assertSame([2000, 83333, 43333, 5200], [$assujetti->tauxTva, $assujetti->prixVenteHt, $assujetti->beneficeUnitaireHt, $assujetti->tauxDeMarque]);
        $this->assertSame([0, 100000, 60000, 6000], [$enFranchise->tauxTva, $enFranchise->prixVenteHt, $enFranchise->beneficeUnitaireHt, $enFranchise->tauxDeMarque]);
    }
}
