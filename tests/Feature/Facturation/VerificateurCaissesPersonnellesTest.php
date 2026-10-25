<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La caisse personnelle d'un vendeur est ouverte au soir d'une journée tant
 * qu'il a des ventes ou des encaissements de cette journée ou d'une journée
 * antérieure qu'aucune de ses fermetures n'a comptés. Elle ne bloque
 * jamais la clôture de la journée (R5) : la fenêtre de clôture nomme ces
 * caisses (voir ListClotureCaisses), et la clôture comme minuit les ferment
 * d'office (voir FermerLesCaissesPersonnellesAction).
 */
class VerificateurCaissesPersonnellesTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFacturePour(User $vendeur, Client $client, Produit $produit): void
    {
        $this->actingAs($vendeur);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_un_vendeur_sans_cloture_personnelle_apparait_en_attente(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'));

        $this->assertTrue($vendeursEnAttente->contains('id', $vendeur->id));
    }

    public function test_un_vendeur_ayant_ferme_sa_caisse_napparait_plus(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'));

        $this->assertFalse($vendeursEnAttente->contains('id', $vendeur->id));
    }

    public function test_seul_le_vendeur_avec_une_vente_non_fermee_apparait(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeurOuvert = $this->vendeurDeLaPremiereHeure($magasin);
        $vendeurFerme = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->emettreFacturePour($vendeurOuvert, $client, $produit);
        $this->emettreFacturePour($vendeurFerme, $client, $produit);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        app(FermerSaCaisseAction::class)->handle($vendeurFerme, $vendeurFerme);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'));

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
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $this->travelTo(self::enUtc('2026-07-14 22:30:00'));
        $this->emettreFacturePour($vendeur, $client, $produit);

        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(CarbonImmutable::parse('2026-07-14'))->isEmpty());
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(CarbonImmutable::parse('2026-07-15'))->contains('id', $vendeur->id));
    }

    public function test_aucun_vendeur_en_attente_sans_aucune_vente_ce_jour_la(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'));

        $this->assertTrue($vendeursEnAttente->isEmpty());
    }

    /**
     * Fermer sa caisse aujourd'hui doit rattraper les ventes oubliées
     * d'hier : une fermeture compte toutes les ventes jamais encore
     * comptées, quelle que soit leur date (voir FermerSaCaisseAction).
     */
    public function test_une_vente_dune_journee_anterieure_est_rattrapee_par_une_fermeture_plus_tardive(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $this->emettreFacturePour($vendeur, $client, $produit);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        // Le vendeur ne ferme sa caisse que deux jours plus tard.
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::aParis('2026-10-07 10:00:00'));

        $this->assertFalse($vendeursEnAttente->contains('id', $vendeur->id));
    }

    /**
     * Même règle que le relevé (B18, voir FermerSaCaisseActionTest) : une
     * vente émise puis reprise le même jour par un avoir reste une vente
     * que la fermeture du vendeur compte dans son TTC vendu, et laisse donc
     * sa caisse ouverte jusqu'à cette fermeture, pas au-delà.
     */
    public function test_une_vente_corrigee_par_un_avoir_laisse_la_caisse_ouverte_jusqua_sa_fermeture(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_CREER]);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->emettreFacturePour($vendeur, $client, $produit);
        $venteCorrigee = Facture::query()->latest('id')->firstOrFail();
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteCorrigee->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'))->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'))->contains('id', $vendeur->id));
    }

    /**
     * Un brouillon créé avant une fermeture mais émis après reste une vente
     * non fermée, jusqu'à ce qu'une fermeture le compte.
     */
    public function test_un_brouillon_emis_apres_une_fermeture_laisse_la_caisse_ouverte_jusqua_la_suivante(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $this->actingAs($vendeur);

        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->emettreFacturePour($vendeur, $client, $produit);
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(now())->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(now())->contains('id', $vendeur->id));
    }

    /**
     * Un encaissement du jour que le vendeur n'a pas fermé laisse sa caisse
     * ouverte, même sans aucune vente de sa part, jusqu'à sa fermeture.
     */
    public function test_un_encaissement_du_jour_non_ferme_laisse_la_caisse_du_vendeur_ouverte(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $collegue = $this->vendeurDeLaPremiereHeure($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $facture = $this->emettreUnTicketPour($collegue, Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($collegue, $collegue);
        $this->actingAs($vendeur);
        $this->encaisser($facture, 12, today());
        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $vendeursEnAttente = $verificateur->vendeursAvecCaisseOuverte(now());

        $this->assertSame([$vendeur->id], $vendeursEnAttente->pluck('id')->all());
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(now())->isEmpty());
    }

    /**
     * La contre-passation que le propriétaire fait aujourd'hui d'un paiement
     * d'hier du vendeur revient à la caisse du vendeur, pas à la sienne.
     */
    public function test_une_contre_passation_du_jour_rouvre_la_caisse_du_vendeur_du_paiement(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $facture = $this->emettreUnTicketPour($vendeur, Client::factory()->create());
        $paiement = $this->encaisser($facture, 12, today());
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->actingAs($proprietaire);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Chèque sans provision');

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(now());

        $this->assertSame([$vendeur->id], $vendeursEnAttente->pluck('id')->all());
    }

    /**
     * Un encaissement est de la journée où il est reçu, pas de celle où il
     * est saisi : un paiement reçu hier, saisi aujourd'hui, laisse ouverte
     * la caisse au soir d'hier, et pas encore au soir d'avant-hier.
     */
    public function test_un_encaissement_compte_dans_la_journee_ou_il_est_recu(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $collegue = $this->vendeurDeLaPremiereHeure($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $facture = $this->emettreUnTicketPour($collegue, Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($collegue, $collegue);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->actingAs($vendeur);
        $this->encaisser($facture, 12, self::aParis('2026-10-08 00:00:00'));
        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $avantHier = $verificateur->vendeursAvecCaisseOuverte(self::aParis('2026-10-07 10:00:00'));
        $hier = $verificateur->vendeursAvecCaisseOuverte(self::aParis('2026-10-08 10:00:00'));

        $this->assertTrue($avantHier->isEmpty());
        $this->assertSame([$vendeur->id], $hier->pluck('id')->all());
    }

    private function emettreUnTicketPour(User $vendeur, Client $client): Facture
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function encaisser(Facture $facture, float $montant, CarbonInterface $recuLe): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => $montant,
            'recu_le' => $recuLe->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
