<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Tests\TestCase;

/**
 * Un refus de compte et une opération simultanée du même compte, chacun
 * dans son processus, sur une vraie base PostgreSQL : le refus tient les
 * registres des magasins du compte, puis sa ligne (voir JournalDesDroits) ;
 * l'opération attend, relit le compte sous les mêmes verrous, et le
 * trouve retiré. Aucune vente ne passe après le refus, aucun droit ne lui
 * reste.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class RefusDUnCompteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_un_refus_et_une_vente_simultanes_du_meme_compte_ne_laissent_jamais_passer_une_vente_apres_le_refus(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->assertTrue($vendeur->peutRefuserSonCompte());
        [$vendeurId, $magasinId] = [$vendeur->id, $magasin->id];
        $produitId = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($vendeurId, $magasinId, $produitId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::vendreAuComptant(User::query()->findOrFail($vendeurId), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produitId, 'quantite' => 1]], ModePaiement::Especes);
        }, $concurrente);

        app(RefuserSonCompteAction::class)->handle($vendeur);

        $this->assertStringContainsString(LecteurDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame(StatutCompte::Retire, $vendeur->refresh()->statut);
        $this->assertSame(0, Facture::query()->count());
    }

    public function test_un_refus_et_une_modification_simultanee_des_droits_se_suivent_sans_laisser_de_droits(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        [$vendeurId, $magasinId, $proprietaireId] = [$vendeur->id, $magasin->id, $this->proprietaireDe($magasin)->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($vendeurId, $magasinId, $proprietaireId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($vendeurId), [$magasinId => ['role' => RoleMagasin::Vendeur, 'autorisations' => PermissionRegistry::toutes()]]);
        }, $concurrente);

        app(RefuserSonCompteAction::class)->handle($vendeur);

        $this->assertStringContainsString(PorteeDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame(StatutCompte::Retire, $vendeur->refresh()->statut);
        $this->assertSame(0, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Le processus concurrent a échoué : son message d'erreur.
     */
    private function attendreLEchec(?InvokedProcess $concurrente): string
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful(), 'L\'opération concurrente aurait dû être refusée.');

        return $resultat->errorOutput();
    }
}
