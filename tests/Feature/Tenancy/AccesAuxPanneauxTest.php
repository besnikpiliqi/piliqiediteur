<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qui ouvre quel panneau (voir User::canAccessPanel(), getTenants() et
 * canAccessTenant()) : seul le panneau des magasins s'ouvre, et un magasin
 * ne s'ouvre qu'à ses membres actifs.
 */
class AccesAuxPanneauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_d_un_magasin_n_ouvre_aucun_autre_panneau(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($proprietaire->canAccessPanel(Panel::make()->id('editeur')));
    }

    /**
     * Suspendu, un membre n'a plus de magasin à ouvrir : le sien ne figure
     * plus parmi ceux que le panneau lui propose, et ne s'ouvre plus.
     */
    public function test_un_membre_suspendu_n_ouvre_pas_ce_magasin(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membreSuspendu($magasin);

        $magasins = $membre->getTenants(Filament::getPanel('app'));

        $this->assertSame([], $magasins->modelKeys());
        $this->assertFalse($membre->canAccessTenant($magasin));
    }

    public function test_sans_aucun_magasin_actif_le_panneau_est_refuse(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membreSuspendu($magasin);

        $reponse = $this->actingAs($membre)->get("/app/{$magasin->uuid}");

        $reponse->assertForbidden();
    }

    private function membreSuspendu(Magasin $magasin): User
    {
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        $magasin->membres()->where('user_id', $membre->getKey())->update(['statut' => StatutMembreMagasin::Suspendu->value]);

        return $membre;
    }
}
