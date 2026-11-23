<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un brouillon de facture d'achat se modifie avec le droit de saisir les
 * factures d'achat ; une facture validée, dont le stock est déjà entré, ne
 * se corrige qu'avec en plus le droit de les valider (voir
 * FactureAchatPolicy::update()), et jamais une facture récupérée par API ni
 * une facture qu'on ne voit pas.
 */
class FactureAchatPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_droit_de_valider_seul_un_brouillon_se_modifie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);
        $brouillon = $this->brouillon();
        $validee = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->brouillon());

        $this->assertTrue($vendeur->can('update', $brouillon));
        $this->assertFalse($vendeur->can('update', $validee));
    }

    public function test_avec_le_droit_de_valider_une_facture_validee_se_corrige(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER]);
        $validee = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->brouillon());

        $this->assertTrue($vendeur->can('update', $validee));
    }

    /**
     * Une facture d'achat qu'on ne voit pas ne se modifie jamais : sans le
     * droit de voir les achats, ni depuis un autre magasin de la société ;
     * une facture récupérée par API, une fois validée, ne se modifie pas,
     * même avec tous les droits des achats.
     */
    public function test_une_facture_qu_on_ne_voit_pas_ou_recuperee_par_api_ne_se_modifie_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $sansLaVue = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_CREER]);
        $tousLesDroits = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER]);
        $brouillon = $this->brouillon();
        $recupereeParApi = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->brouillon(['source' => SourceFactureAchat::Api]));
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $responsableDeLAutre = $this->responsable($autre);

        app(TenantContext::class)->runAs($magasin, function () use ($sansLaVue, $tousLesDroits, $brouillon, $recupereeParApi): void {
            $this->assertFalse($sansLaVue->can('update', $brouillon));
            $this->assertTrue($tousLesDroits->can('update', $brouillon));
            $this->assertFalse($tousLesDroits->can('update', $recupereeParApi));
        });
        app(TenantContext::class)->runAs($autre, fn () => $this->assertFalse($responsableDeLAutre->can('update', $brouillon)));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function brouillon(array $options = []): FactureAchat
    {
        return app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 5],
        ], ['date_facture' => '2026-01-15', ...$options]);
    }
}
