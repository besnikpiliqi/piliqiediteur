<?php

namespace Tests\Feature\Authorization;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\DevisResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Envoyer un devis (le numéroter, l'envoyer par e-mail) et le supprimer :
 * chacun selon sa permission, jamais pour un autre magasin.
 */
class DevisPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, false],
            'vendeur' => [RoleMagasin::Vendeur, true],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_envoyer_un_devis_selon_le_role(RoleMagasin $role, bool $peutEnvoyer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $devis = $this->creerUnDevis();

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $devis));
    }

    /**
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function rolesQuiModifientLesDevis(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, false],
            'vendeur' => [RoleMagasin::Vendeur, true],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    /**
     * Supprimer, un par un ou en masse : ceux qui modifient les devis,
     * jamais l'observateur en lecture seule.
     */
    #[DataProvider('rolesQuiModifientLesDevis')]
    public function test_supprimer_des_devis_selon_le_role(RoleMagasin $role, bool $peutSupprimer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $devis = $this->creerUnDevis();

        $this->assertSame($peutSupprimer, $membre->can('deleteAny', Devis::class));
        $this->assertSame($peutSupprimer, $membre->can('delete', $devis));
    }

    /**
     * La facture créée depuis un devis garde son devis d'origine : même le
     * propriétaire ne le supprime plus.
     */
    public function test_un_devis_converti_en_facture_ne_se_supprime_plus(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $devis = $this->creerUnDevis();
        app(AccepterDevisAction::class)->handle(app(EmettreDevisAction::class)->handle($devis));
        app(CreerFactureDepuisDevisAction::class)->handle($devis->fresh());

        $this->assertFalse($proprietaire->can('delete', $devis->fresh()));
    }

    /**
     * Un devis supprimé ne revient pas et ne s'efface pas de la base, même
     * pour le propriétaire. Vérifié par ce que Filament consulte : le Gate
     * de Laravel refuse déjà une capacité absente de la policy, alors que
     * Filament l'autorise (helper Filament\authorize).
     */
    public function test_personne_ne_restaure_ni_n_efface_definitivement_un_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $devis = $this->creerUnDevis();

        $this->assertFalse(DevisResource::canRestore($devis));
        $this->assertFalse(DevisResource::canForceDelete($devis));
    }

    public function test_personne_ne_supprime_le_devis_d_un_autre_magasin(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $devisDeA = $this->creerUnDevis();
        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('delete', $devisDeA));
    }

    public function test_personne_n_envoie_le_devis_d_un_autre_magasin(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], User::factory()->create());
        $devisDeA = $this->creerUnDevis();
        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $devisDeA));
    }

    private function creerUnDevis(): Devis
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        return app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
    }
}
