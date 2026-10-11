<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qui ouvre quel panneau (voir User::canAccessPanel(), getTenants() et
 * canAccessTenant()) : le panneau des magasins s'ouvre à un compte actif
 * qui a un magasin à ouvrir, celui de l'administration de la société à son
 * propriétaire, et aucun autre ; un magasin ne s'ouvre qu'au propriétaire
 * de sa société et à ses membres actifs.
 */
class AccesAuxPanneauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_ouvre_les_panneaux_app_et_societe_et_aucun_autre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('app')));
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('societe')));
        $this->assertFalse($proprietaire->canAccessPanel(Panel::make()->id('editeur')));
        $this->assertSame([$magasin->societe_id], $proprietaire->getTenants(Filament::getPanel('societe'))->modelKeys());
        $this->assertSame([], $proprietaire->getTenants(Panel::make()->id('editeur'))->modelKeys());
    }

    /**
     * Un magasin suspendu reste consultable : son propriétaire l'ouvre
     * toujours, en lecture seule (voir ControleDesDroits).
     */
    public function test_le_proprietaire_d_un_magasin_suspendu_l_ouvre_toujours(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $magasin->forceFill(['suspendu_le' => now()])->save();

        $reponse = $this->actingAs($proprietaire)->get("/app/{$magasin->uuid}");

        $reponse->assertOk();
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('app')));
        $this->assertTrue($proprietaire->canAccessTenant($magasin));
    }

    /**
     * Suspendu, un membre n'a plus de magasin à ouvrir : le sien ne figure
     * plus parmi ceux que le panneau lui propose, et ne s'ouvre plus.
     */
    public function test_un_membre_suspendu_n_ouvre_pas_ce_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membreSuspendu($magasin);

        $magasins = $membre->getTenants(Filament::getPanel('app'));

        $this->assertSame([], $magasins->modelKeys());
        $this->assertFalse($membre->canAccessTenant($magasin));
    }

    /**
     * Suspendu, un compte n'ouvre plus rien, dès la requête suivante :
     * ni le propriétaire, ni un membre, ni aucun de leurs magasins, ni
     * l'administration de la société.
     */
    public function test_un_compte_suspendu_n_ouvre_aucun_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur);

        foreach ([$proprietaire, $membre] as $compte) {
            $compte->forceFill(['statut' => StatutCompte::Suspendu])->save();

            $this->assertSame([], $compte->getTenants(Filament::getPanel('app'))->modelKeys());
            $this->assertSame([], $compte->getTenants(Filament::getPanel('societe'))->modelKeys());
            $this->assertSame([false, false, false, false], [
                $compte->canAccessPanel(Filament::getPanel('app')),
                $compte->canAccessPanel(Filament::getPanel('societe')),
                $compte->canAccessTenant($magasin),
                $compte->canAccessTenant($magasin->societe),
            ]);
            $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertForbidden();
        }
        $this->assertFalse($proprietaire->canAccessTenant($autre));
    }

    public function test_sans_aucun_magasin_actif_le_panneau_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membreSuspendu($magasin);

        $reponse = $this->actingAs($membre)->get("/app/{$magasin->uuid}");

        $reponse->assertForbidden();
    }

    /**
     * Une adresse dont le magasin n'est pas un uuid ne désigne aucun
     * magasin : 404, jamais l'erreur de la colonne uuid, typée sous
     * PostgreSQL.
     */
    public function test_une_adresse_qui_ne_porte_pas_un_uuid_de_magasin_est_introuvable(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $reponse = $this->actingAs($proprietaire)->get('/app/pas-un-uuid');

        $reponse->assertNotFound();
    }

    private function membreSuspendu(Magasin $magasin): User
    {
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        $magasin->membres()->where('user_id', $membre->getKey())->update(['statut' => StatutMembreMagasin::Suspendu->value]);

        return $membre;
    }
}
