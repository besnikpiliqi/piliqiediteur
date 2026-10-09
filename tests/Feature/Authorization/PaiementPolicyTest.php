<?php

namespace Tests\Feature\Authorization;

use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un paiement révèle le chiffre d'affaires encaissé : sans
 * facture.view_toutes, on ne voit que les paiements que l'on a soi-même
 * enregistrés (voir PaiementPolicy::view()).
 */
class PaiementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_vue_totale_un_vendeur_ne_voit_que_ses_propres_paiements(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->membre($magasin, 'paul@example.test', RoleMagasin::Vendeur);
        $vendeurB = $this->membre($magasin, 'jean@example.test', RoleMagasin::Vendeur);
        $this->actingAs($vendeurA);
        $paiementDeA = Paiement::factory()->create();
        $this->actingAs($vendeurB);
        $paiementDeB = Paiement::factory()->create();

        $this->assertTrue($vendeurB->can('view', $paiementDeB));
        $this->assertFalse($vendeurB->can('view', $paiementDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_paiements_de_toute_l_equipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, 'paul@example.test', RoleMagasin::Vendeur);
        $comptable = $this->membre($magasin, 'comptable@example.test', RoleMagasin::Comptable);
        $this->actingAs($vendeur);
        $paiementDuVendeur = Paiement::factory()->create();

        $this->assertTrue($comptable->can('view', $paiementDuVendeur));
    }

    private function membre(Magasin $magasin, string $email, RoleMagasin $role): User
    {
        return app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => $email, 'email' => $email, 'password' => 'mot-de-passe-sur'], $role);
    }
}
