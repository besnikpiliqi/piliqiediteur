<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Archivés compris, comme l'index unique de la table : l'erreur est
     * dite clairement plutôt que de casser sur la base.
     */
    public function test_une_reference_deja_prise_est_refusee(): void
    {
        Produit::factory()->create(['reference' => 'MA-REF-PERSO'])->delete();

        $this->expectExceptionObject(new InvalidArgumentException('La référence « MA-REF-PERSO » est déjà celle d\'un autre produit.'));

        Produit::factory()->create(['reference' => 'MA-REF-PERSO']);
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

    /**
     * Une référence saisie à la main peut déjà porter le format automatique
     * : le produit suivant créé sans référence prend le numéro d'après.
     */
    public function test_la_reference_automatique_saute_un_numero_deja_saisi_a_la_main(): void
    {
        Produit::factory()->create(['reference' => 'PRD-000001']);

        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertSame('PRD-000002', $produit->reference);
    }

    public function test_la_reference_automatique_saute_le_numero_d_un_produit_supprime(): void
    {
        Produit::factory()->create(['reference' => 'PRD-000001'])->delete();
        Produit::factory()->create(['reference' => 'PRD-000002']);

        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertSame('PRD-000003', $produit->reference);
    }

    /**
     * @return array<string, array{array<string, float>, string}>
     */
    public static function prixNegatifs(): array
    {
        return [
            'prix de vente TTC' => [['prix_vente_ttc' => -12.0], 'Le prix de vente d\'un produit ne peut pas être négatif.'],
            'prix de vente HT' => [['prix_vente_ht' => -10.0], 'Le prix de vente d\'un produit ne peut pas être négatif.'],
            'coût d\'achat' => [['cmup_ht' => -5.0], 'Le coût d\'achat d\'un produit ne peut pas être négatif.'],
        ];
    }

    /**
     * Quel que soit le chemin d'enregistrement (formulaire, facture
     * d'achat, API), un prix négatif n'est jamais enregistré.
     *
     * @param  array<string, float>  $prix
     */
    #[DataProvider('prixNegatifs')]
    public function test_un_prix_negatif_est_refuse(array $prix, string $message): void
    {
        $this->assertThrows(
            fn () => Produit::factory()->create(['reference' => 'ECR-1', 'taux_tva' => 20, ...$prix]),
            InvalidArgumentException::class,
            $message,
        );

        $this->assertSame(0, Produit::withTrashed()->count());
    }
}
