<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qui ouvre quel panneau (voir User::canAccessPanel(), getTenants() et
 * canAccessTenant()) : le panneau de l'éditeur reste fermé à tous, et un
 * magasin ne s'ouvre qu'à ses membres actifs.
 */
class AccesAuxPanneauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_panneau_de_l_editeur_refuse_le_proprietaire_d_un_magasin(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $reponse = $this->actingAs($proprietaire)->get('/super-admin');

        $reponse->assertForbidden();
    }

    /**
     * @return array<string, array{StatutMembreMagasin}>
     */
    public static function statutsQuiNOuvrentPasLeMagasin(): array
    {
        return [
            'suspendu' => [StatutMembreMagasin::Suspendu],
            'invité' => [StatutMembreMagasin::Invite],
        ];
    }

    /**
     * Membre actif d'un autre magasin, il entre dans le panneau, mais ce
     * magasin-ci ne s'ouvre pas et ne figure pas dans son sélecteur.
     */
    #[DataProvider('statutsQuiNOuvrentPasLeMagasin')]
    public function test_un_membre_qui_n_est_pas_actif_n_ouvre_pas_ce_magasin(StatutMembreMagasin $statut): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membreAuStatut($magasin, $statut);
        $autreMagasin = app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $autreMagasin->utilisateurs()->attach($membre->getKey(), ['role' => RoleMagasin::Vendeur->value, 'statut' => StatutMembreMagasin::Actif->value]);

        $reponse = $this->actingAs($membre)->get("/app/{$magasin->uuid}");

        $reponse->assertNotFound();
        $this->assertSame([$autreMagasin->getKey()], $membre->getTenants(Filament::getPanel('app'))->modelKeys());
    }

    #[DataProvider('statutsQuiNOuvrentPasLeMagasin')]
    public function test_sans_aucun_magasin_actif_le_panneau_est_refuse(StatutMembreMagasin $statut): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membreAuStatut($magasin, $statut);

        $reponse = $this->actingAs($membre)->get("/app/{$magasin->uuid}");

        $reponse->assertForbidden();
    }

    private function membreAuStatut(Magasin $magasin, StatutMembreMagasin $statut): User
    {
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        $magasin->membres()->where('user_id', $membre->getKey())->update(['statut' => $statut->value]);

        return $membre;
    }
}
