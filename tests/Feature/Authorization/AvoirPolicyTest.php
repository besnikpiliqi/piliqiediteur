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
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\DefinirPermissionsSupplementairesMembreAction;
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
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, true],
            'vendeur' => [RoleMagasin::Vendeur, false],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_envoyer_un_avoir_selon_le_role(RoleMagasin $role, bool $peutEnvoyer): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $avoir = $this->emettreUnAvoirEnTantQue($proprietaire);

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $avoir));
    }

    public function test_personne_n_envoie_l_avoir_d_un_autre_magasin(): void
    {
        $proprietaireA = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $avoirDeA = $this->emettreUnAvoirEnTantQue($proprietaireA);
        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $avoirDeA));
    }

    /**
     * Sans facture.view_toutes, on ne voit que les avoirs que l'on a
     * soi-même créés, même avec la permission de voir les avoirs.
     */
    public function test_sans_vue_totale_un_membre_ne_voit_que_ses_propres_avoirs(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $vendeurB = $this->vendeurQuiVoitLesAvoirs($magasin, 'jean@example.test');
        $avoirDeA = $this->emettreUnAvoirEnTantQue($vendeurA);
        $avoirDeB = $this->emettreUnAvoirEnTantQue($vendeurB);

        $this->assertTrue($vendeurB->can('view', $avoirDeB));
        $this->assertFalse($vendeurB->can('view', $avoirDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_avoirs_de_toute_l_equipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->vendeurQuiVoitLesAvoirs($magasin, 'paul@example.test');
        $comptable = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Comptable', 'email' => 'comptable@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Comptable);
        $avoirDuVendeur = $this->emettreUnAvoirEnTantQue($vendeur);

        $this->assertTrue($comptable->can('view', $avoirDuVendeur));
    }

    private function vendeurQuiVoitLesAvoirs(Magasin $magasin, string $email): User
    {
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => $email, 'email' => $email, 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        app(DefinirPermissionsSupplementairesMembreAction::class)->handle(
            $magasin->membres()->where('user_id', $vendeur->getKey())->firstOrFail(),
            [PermissionRegistry::AVOIR_VIEW],
        );

        return $vendeur;
    }

    private function emettreUnAvoirEnTantQue(User $auteur): Avoir
    {
        $this->actingAs($auteur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]));
    }
}
