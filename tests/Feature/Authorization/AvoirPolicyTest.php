<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Voir un avoir : sans facture.view_toutes, seulement ceux que l'on a
 * créés. L'envoyer par e-mail : seulement un avoir que l'on voit, avec la
 * permission d'envoyer les avoirs.
 */
class AvoirPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function roles(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), true],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_envoyer_un_avoir_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutEnvoyer): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $avoir = $this->emettreUnAvoirEnTantQue($proprietaire);

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $avoir));
    }

    /**
     * L'expert-comptable lit les avoirs de tout le magasin, mais n'en envoie
     * aucun, contrairement à l'ancien comptable de magasin.
     */
    public function test_l_expert_comptable_voit_les_avoirs_de_toute_l_equipe_sans_les_envoyer(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $expert = $this->expertComptable($magasin);
        $avoir = $this->emettreUnAvoirEnTantQue($vendeur);

        $this->assertTrue($expert->can('view', $avoir));
        $this->assertFalse($expert->can('envoyer', $avoir));
    }

    public function test_personne_n_envoie_l_avoir_d_un_autre_magasin(): void
    {
        $proprietaireA = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique A'], $proprietaireA);
        $avoirDeA = $this->emettreUnAvoirEnTantQue($proprietaireA);
        $proprietaireB = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $avoirDeA));
    }

    /**
     * Sans facture.view_toutes, on ne voit que les avoirs que l'on a
     * soi-même créés, même avec la permission de voir les avoirs.
     */
    public function test_sans_vue_totale_un_membre_ne_voit_que_ses_propres_avoirs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeurA = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $vendeurB = $this->vendeurQuiVoitLesAvoirs($magasin, 'jean@example.test');
        $avoirDeA = $this->emettreUnAvoirEnTantQue($vendeurA);
        $avoirDeB = $this->emettreUnAvoirEnTantQue($vendeurB);

        $this->assertTrue($vendeurB->can('view', $avoirDeB));
        $this->assertFalse($vendeurB->can('view', $avoirDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_avoirs_de_toute_l_equipe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable(), ['name' => 'Comptable', 'email' => 'comptable@example.test']);
        $avoirDuVendeur = $this->emettreUnAvoirEnTantQue($vendeur);

        $this->assertTrue($comptable->can('view', $avoirDuVendeur));
    }

    private function vendeurQuiVoitLesAvoirs(Magasin $magasin, string $email): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_VIEW], ['name' => $email, 'email' => $email]);
    }

    private function emettreUnAvoirEnTantQue(User $auteur): Avoir
    {
        $this->actingAs($auteur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]));
    }
}
