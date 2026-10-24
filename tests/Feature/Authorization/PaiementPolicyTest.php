<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un paiement révèle le chiffre d'affaires encaissé : sans
 * facture.view_toutes, on ne voit que les paiements que l'on a soi-même
 * enregistrés (voir PaiementPolicy::view()). Le contre-passer exige en plus
 * l'autorisation dédiée (voir PaiementPolicy::contrePasser()).
 */
class PaiementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_vue_totale_un_vendeur_ne_voit_que_ses_propres_paiements(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur);
        $paiementDeA = $this->encaisserUnPaiementEnTantQue($vendeurA);
        $paiementDeB = $this->encaisserUnPaiementEnTantQue($vendeurB);

        $this->assertTrue($vendeurB->can('view', $paiementDeB));
        $this->assertFalse($vendeurB->can('view', $paiementDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_paiements_de_toute_l_equipe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable());
        $expert = $this->expertComptable($magasin);
        $paiementDuVendeur = $this->encaisserUnPaiementEnTantQue($vendeur);

        $this->assertTrue($comptable->can('view', $paiementDuVendeur));
        $this->assertTrue($expert->can('view', $paiementDuVendeur));
    }

    /**
     * @return array<string, array{0: RoleMagasin, 1: list<string>|null}>
     */
    public static function rolesQuiContrePassentDOffice(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur()],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable()],
            'responsable, sans case' => [RoleMagasin::Responsable, null],
        ];
    }

    public function test_le_proprietaire_contre_passe_un_paiement(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $paiement = $this->encaisserUnPaiementEnTantQue($this->responsableDeTest($magasin));

        $this->assertTrue($proprietaire->can('contrePasser', $paiement));
    }

    #[DataProvider('rolesQuiContrePassentDOffice')]
    public function test_le_responsable_et_un_vendeur_qui_en_a_la_case_contre_passent_un_paiement(RoleMagasin $role, ?array $autorisations): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $this->membre($magasin, $role, $autorisations);
        $paiement = $this->encaisserUnPaiementEnTantQue($vendeur);

        $this->assertTrue($membre->can('contrePasser', $paiement));
    }

    public function test_un_vendeur_ne_contre_passe_pas_par_defaut(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertFalse($vendeur->can('contrePasser', $this->encaisserUnPaiementEnTantQue($vendeur)));
    }

    /**
     * Cochée pour un vendeur, l'autorisation vaut pour les paiements qu'il
     * voit : les siens, sans facture.view_toutes.
     */
    public function test_un_vendeur_autorise_ne_contre_passe_que_les_paiements_qu_il_voit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autorise = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PAIEMENT_CONTRE_PASSER]);
        $autreVendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $sonPaiement = $this->encaisserUnPaiementEnTantQue($autorise);
        $paiementDUnAutre = $this->encaisserUnPaiementEnTantQue($autreVendeur);

        $this->assertTrue($autorise->can('contrePasser', $sonPaiement));
        $this->assertFalse($autorise->can('contrePasser', $paiementDUnAutre));
    }

    public function test_ni_une_contre_passation_ni_un_paiement_deja_contre_passe_ne_se_contre_passent(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $paiement = $this->encaisserUnPaiementEnTantQue($this->responsableDeTest($magasin));
        $this->actingAs($proprietaire);

        $contrePassation = app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Saisi deux fois');

        $this->assertFalse($proprietaire->can('contrePasser', $contrePassation));
        $this->assertFalse($proprietaire->can('contrePasser', $paiement->fresh()));
    }

    private function encaisserUnPaiementEnTantQue(User $auteur): Paiement
    {
        $this->actingAs($auteur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $facture->client,
            ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => '12.00']],
        );
    }
}
