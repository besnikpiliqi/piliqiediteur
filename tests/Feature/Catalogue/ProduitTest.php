<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La référence produit est libre à la saisie (manuelle ou future
 * intégration API) : voir Produit::booted(). Si elle n'est pas fournie,
 * elle doit être générée automatiquement, par magasin, à la manière d'une
 * séquence de numéro de facture.
 */
class ProduitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_reference_vide_est_generee_automatiquement(): void
    {
        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertNotSame('', $produit->reference);
        $this->assertStringStartsWith('PRD-', $produit->reference);
    }

    public function test_une_reference_fournie_explicitement_est_conservee(): void
    {
        $produit = Produit::factory()->create(['reference' => 'MA-REF-PERSO']);

        $this->assertSame('MA-REF-PERSO', $produit->reference);
    }

    public function test_deux_produits_sans_reference_dans_le_meme_magasin_recoivent_des_numeros_distincts(): void
    {
        $premier = Produit::factory()->create(['reference' => '']);
        $second = Produit::factory()->create(['reference' => '']);

        $this->assertNotSame($premier->reference, $second->reference);
        $this->assertSame('PRD-000001', $premier->reference);
        $this->assertSame('PRD-000002', $second->reference);
    }

    public function test_une_edition_qui_vide_la_reference_en_genere_une_nouvelle(): void
    {
        $produit = Produit::factory()->create(['reference' => 'MA-REF-PERSO']);

        $produit->reference = '';
        $produit->save();

        $this->assertNotSame('', $produit->reference);
        $this->assertStringStartsWith('PRD-', $produit->reference);
    }
}
