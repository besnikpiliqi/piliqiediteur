<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    /**
     * La journée se lit à Paris : une vente émise à 0 h 30 le 15 juillet
     * à Paris (22 h 30 UTC le 14) laisse la caisse ouverte le 15, pas le
     * 14.
     */
    public function test_une_vente_apres_minuit_a_paris_ouvre_la_caisse_du_lendemain(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-07-14 22:30:00', 'UTC'));
        $this->emettreFacturePour($vendeur, $client, $produit);

        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(CarbonImmutable::parse('2026-07-14'))->isEmpty());
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(CarbonImmutable::parse('2026-07-15'))->contains('id', $vendeur->id));
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
     * Comportement constaté, qui suit celui du relevé (voir
     * FermerSaCaisseActionTest) : une vente émise puis reprise le même jour
     * par un avoir reste une vente que la fermeture du vendeur compte, et
     * laisse donc sa caisse ouverte jusqu'à cette fermeture, pas au-delà.
     * Le contenu du relevé reste à décider par l'éditeur.
     */
    public function test_une_vente_corrigee_par_un_avoir_laisse_la_caisse_ouverte_jusqua_sa_fermeture(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = User::factory()->create();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(now()->subDay());
        $this->emettreFacturePour($vendeur, $client, $produit);
        $venteCorrigee = Facture::query()->latest('id')->firstOrFail();
        app(EnregistrerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $venteCorrigee->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');
        $this->travelBack();

        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(now()->subDay())->contains('id', $vendeur->id));

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
