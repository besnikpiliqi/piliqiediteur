<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un vendeur qui oublie de fermer sa caisse personnelle ne bloque jamais
 * la clôture officielle de la journée (qui agrège directement les
 * factures, voir CloturerCaisseAction) : ce service sert uniquement à
 * alerter le propriétaire avant qu'il ne clôture manuellement -- voir
 * ListClotureCaisses::getHeaderActions().
 */
class VerificateurCaissesPersonnellesTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFacturePour(User $vendeur, Client $client, Produit $produit): void
    {
        $this->actingAs($vendeur);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle($facture);
    }

    public function test_un_vendeur_sans_cloture_personnelle_apparait_en_attente(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDay());
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelBack();

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDay());

        $this->assertTrue($vendeursEnAttente->contains('id', $vendeur->id));
    }

    public function test_un_vendeur_ayant_ferme_sa_caisse_napparait_plus(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDay());
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelBack();

        app(FermerSaCaisseAction::class)->handle($vendeur);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDay());

        $this->assertFalse($vendeursEnAttente->contains('id', $vendeur->id));
    }

    public function test_seul_le_vendeur_avec_une_vente_non_fermee_apparait(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeurOuvert = User::factory()->create();
        $vendeurFerme = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDay());
        $this->emettreFacturePour($vendeurOuvert, $client, $produit);
        $this->emettreFacturePour($vendeurFerme, $client, $produit);
        $this->travelBack();

        app(FermerSaCaisseAction::class)->handle($vendeurFerme);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDay());

        $this->assertTrue($vendeursEnAttente->contains('id', $vendeurOuvert->id));
        $this->assertFalse($vendeursEnAttente->contains('id', $vendeurFerme->id));
    }

    public function test_aucun_vendeur_en_attente_sans_aucune_vente_ce_jour_la(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDay());

        $this->assertTrue($vendeursEnAttente->isEmpty());
    }

    /**
     * Fermer sa caisse aujourd'hui doit rattraper les ventes oubliées
     * d'hier : une fermeture compte toutes les ventes jamais encore
     * comptées, quelle que soit leur date (voir FermerSaCaisseAction).
     */
    public function test_une_vente_dune_journee_anterieure_est_rattrapee_par_une_fermeture_plus_tardive(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDays(2));
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelBack();

        // Le vendeur ne ferme sa caisse que deux jours plus tard.
        app(FermerSaCaisseAction::class)->handle($vendeur);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now()->subDays(2));

        $this->assertFalse($vendeursEnAttente->contains('id', $vendeur->id));
    }

    /**
     * Une vente émise puis annulée le même jour n'est jamais comptée par
     * une fermeture : elle ne doit donc jamais bloquer la clôture de la
     * journée, avant comme après la fermeture du vendeur.
     */
    public function test_une_vente_annulee_ne_laisse_pas_la_caisse_ouverte(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDay());
        $this->emettreFacturePour($vendeur, $client, $produit);
        app(AnnulerFactureAction::class)->handle(Facture::query()->latest('id')->firstOrFail(), 'Erreur de saisie');
        $this->travelBack();

        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(now()->subDay())->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(now()->subDay())->contains('id', $vendeur->id));
    }

    /**
     * Un brouillon créé avant une fermeture mais émis après reste une vente
     * non fermée, jusqu'à ce qu'une fermeture le compte.
     */
    public function test_un_brouillon_emis_apres_une_fermeture_laisse_la_caisse_ouverte_jusqua_la_suivante(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $this->actingAs($vendeur);

        $brouillon = app(CreerFactureAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->emettreFacturePour($vendeur, $client, $produit);
        app(FermerSaCaisseAction::class)->handle($vendeur);
        app(EmettreFactureAction::class)->handle($brouillon);

        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(now())->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(now())->contains('id', $vendeur->id));
    }
}
