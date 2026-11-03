<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un brouillon de facture d'achat se modifie avec le droit de saisir les
 * factures d'achat ; une facture validée, dont le stock est déjà entré, ne
 * se corrige qu'avec en plus le droit de les valider (voir
 * FactureAchatPolicy::update()).
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

    private function brouillon(): FactureAchat
    {
        return app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 5],
        ]);
    }
}
