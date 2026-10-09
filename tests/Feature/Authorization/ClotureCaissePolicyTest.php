<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sans cloture_caisse.view_toutes, un membre à qui le propriétaire a
 * ouvert les clôtures ne voit que celles qu'il a lui-même déclenchées
 * (voir ClotureCaissePolicy::view()).
 */
class ClotureCaissePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_vue_totale_un_membre_ne_voit_que_ses_propres_clotures(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->vendeurQuiVoitLesClotures($magasin, 'paul@example.test');
        $vendeurB = $this->vendeurQuiVoitLesClotures($magasin, 'jean@example.test');
        $this->actingAs($vendeurA);
        $clotureDeA = ClotureCaisse::factory()->create(['date_cloture' => '2026-10-07']);
        $this->actingAs($vendeurB);
        $clotureDeB = ClotureCaisse::factory()->create(['date_cloture' => '2026-10-08']);

        $this->assertTrue($vendeurB->can('view', $clotureDeB));
        $this->assertFalse($vendeurB->can('view', $clotureDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_clotures_de_toute_l_equipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->vendeurQuiVoitLesClotures($magasin, 'paul@example.test');
        $comptable = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Comptable', 'email' => 'comptable@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Comptable);
        $this->actingAs($vendeur);
        $clotureDuVendeur = ClotureCaisse::factory()->create();

        $this->assertTrue($comptable->can('view', $clotureDuVendeur));
    }

    private function vendeurQuiVoitLesClotures(Magasin $magasin, string $email): User
    {
        return app(CreerMembreMagasinAction::class)->handle(
            $this->proprietaireDe($magasin),
            $magasin,
            ['name' => $email, 'email' => $email, 'password' => 'mot-de-passe-sur'],
            RoleMagasin::Vendeur,
            [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CLOTURE_CAISSE_VIEW],
        );
    }
}
