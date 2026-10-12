<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les services suivent les permissions du catalogue (voir ServicePolicy) :
 * tout le monde les voit, seuls ceux qui gèrent les produits les créent et
 * les modifient, et personne ne les supprime.
 */
class ServicePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function roles(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_chaque_role_voit_les_services_et_seuls_les_gestionnaires_les_modifient(RoleMagasin $role, ?array $autorisations, bool $gereLeCatalogue): void
    {
        $magasin = $this->creerMagasin();
        $membre = $this->membre($magasin, $role, $autorisations, [
            'name' => 'Membre', 'email' => 'membre@example.test',
        ]);
        $service = Service::factory()->create();

        $this->assertTrue($membre->can('viewAny', Service::class));
        $this->assertTrue($membre->can('view', $service));
        $this->assertSame($gereLeCatalogue, $membre->can('create', Service::class));
        $this->assertSame($gereLeCatalogue, $membre->can('update', $service));
        $this->assertFalse($membre->can('delete', $service));
    }

    public function test_le_proprietaire_cree_et_modifie_mais_ne_supprime_jamais_un_service(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $service = Service::factory()->create();

        $this->assertTrue($proprietaire->can('create', Service::class));
        $this->assertTrue($proprietaire->can('update', $service));
        $this->assertFalse($proprietaire->can('delete', $service));
    }

    public function test_un_service_d_un_autre_magasin_reste_refuse_meme_avec_la_permission(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->creerMagasin();
        $serviceAutreMagasin = Service::factory()->create();
        app(TenantContext::class)->set($magasin);

        $this->assertFalse($proprietaire->can('view', $serviceAutreMagasin));
        $this->assertFalse($proprietaire->can('update', $serviceAutreMagasin));
    }

    private function creerMagasin(): Magasin
    {
        return $this->ouvrirUneSociete(['nom' => 'Boutique']);
    }
}
