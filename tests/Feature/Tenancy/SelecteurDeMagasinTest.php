<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le sélecteur de magasin du panneau (voir User::getTenants()) propose à
 * chacun les magasins qu'il ouvre, et eux seuls : tous ceux de la société
 * au propriétaire, ses magasins à un membre, les magasins réels à
 * l'expert-comptable, jamais un magasin d'une autre société.
 */
class SelecteurDeMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_membre_de_deux_magasins_voit_les_deux(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Bron']);
        $membre = $this->membreDeCesMagasins([$lyon->id => ['role' => RoleMagasin::Vendeur], $villeurbanne->id => ['role' => RoleMagasin::Observateur]], $lyon);

        $this->assertEqualsCanonicalizing([$lyon->id, $villeurbanne->id], $membre->getTenants(Filament::getPanel('app'))->modelKeys());
    }

    public function test_un_vendeur_d_un_seul_magasin_ne_voit_que_lui(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $vendeur = $this->membre($villeurbanne, RoleMagasin::Vendeur);

        $this->assertSame([$villeurbanne->id], $vendeur->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertFalse($vendeur->canAccessTenant($lyon));
    }

    public function test_le_proprietaire_voit_tous_les_magasins_de_sa_societe(): void
    {
        $proprietaire = User::factory()->create();
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon'], $proprietaire);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);

        $this->assertEqualsCanonicalizing([$lyon->id, $villeurbanne->id], $proprietaire->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertFalse($proprietaire->canAccessTenant($ailleurs));
    }

    public function test_un_responsable_de_deux_magasins_voit_les_deux(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Bron']);
        $responsable = $this->responsable($lyon, $villeurbanne);

        $this->assertEqualsCanonicalizing([$lyon->id, $villeurbanne->id], $responsable->getTenants(Filament::getPanel('app'))->modelKeys());
    }

    public function test_l_expert_comptable_voit_tous_les_magasins_reels_et_pas_la_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $lyon = $this->creerLeMagasinDuSiege($societe, 'Lyon');
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        $expert = $this->expertComptable($lyon);

        $magasins = $expert->getTenants(Filament::getPanel('app'))->modelKeys();

        $this->assertEqualsCanonicalizing([$lyon->id, $villeurbanne->id], $magasins);
        $this->assertNotContains($demonstration->id, $magasins);
        $this->assertFalse($expert->canAccessTenant($demonstration));
    }
}
