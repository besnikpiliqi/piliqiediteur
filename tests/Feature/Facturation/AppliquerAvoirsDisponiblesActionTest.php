<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un avoir émis n'est jamais remboursé (B5) : ce qu'il n'a pu imputer ni
 * à son émission (voir ImputerAvoirSurSesFacturesAction) ni à la
 * contre-passation d'un paiement (voir ContrePasserPaiementAction), le
 * client n'ayant plus aucune facture due, reste un crédit disponible pour
 * le client (particulier ou professionnel, sans distinction) jusqu'à ce
 * qu'une prochaine facture vienne l'absorber -- automatiquement, à
 * l'émission (voir AppliquerAvoirDisponibleApresEmissionFacture), sans
 * action du vendeur.
 */
class AppliquerAvoirsDisponiblesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function emettreFacture(Client $client, Produit $produit, int $quantite = 1): Facture
    {
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]);

        // ->fresh() : le listener synchrone AppliquerAvoirDisponibleApres
        // EmissionFacture modifie une copie séparément rechargée de la
        // facture (voir son docblock), jamais l'objet $facture détenu ici
        // -- sans ce rechargement, le statut en mémoire resterait "Emise"
        // même si la ligne en base a bien été mise à jour.
        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture)->fresh();
    }

    /**
     * Un achat payé en caisse : l'avoir qui le crédite ne trouve plus rien
     * à réduire sur sa facture, il devient entièrement un crédit.
     */
    private function vendreEnCaisse(Client $client, Produit $produit, int $quantite = 1): Facture
    {
        return self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ], ModePaiement::Especes);
    }

    private function emettreAvoirPourClient(Client $client, Facture $factureOrigine, int $quantite): Avoir
    {
        $ligneFacture = $factureOrigine->lignes->firstOrFail();

        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => $quantite],
        ], 'Produit défectueux');

        return app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);
    }

    public function test_un_credit_suffisant_couvre_integralement_une_nouvelle_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = $this->vendreEnCaisse($client, $produit, 1);
        $this->emettreAvoirPourClient($client, $premiereFacture, 1);

        $this->assertSame(60.0, $client->soldeCrediteur());

        // Nouvel achat de même montant : couvert intégralement et
        // automatiquement, sans aucune action du vendeur.
        $nouvelleFacture = $this->emettreFacture($client, $produit, 1);

        $this->assertSame(StatutFacture::Payee, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantRestant());
        $this->assertSame(60.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(0.0, $client->soldeCrediteur());
    }

    public function test_un_credit_partiel_laisse_un_solde_restant_a_payer_normalement(): void
    {
        $client = Client::factory()->create();
        $produitRetourne = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);

        // 30 € de crédit disponible (25 € HT + 20 % TVA).
        $premiereFacture = $this->vendreEnCaisse($client, $produitRetourne, 1);
        $this->emettreAvoirPourClient($client, $premiereFacture, 1);

        // Nouvel achat de 60 € : 30 € crédités automatiquement, 30 € restent dus.
        $produitPlusCher = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $nouvelleFacture = $this->emettreFacture($client, $produitPlusCher, 1);

        $this->assertSame(StatutFacture::PartiellementPayee, $nouvelleFacture->statut);
        $this->assertSame(30.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(30.0, $nouvelleFacture->montantRestant());
        $this->assertSame(0.0, $client->soldeCrediteur());

        // Le reliquat se règle ensuite normalement, comme un paiement classique.
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 30,
            'recu_le' => now()->toDateString(),
        ], [['facture_id' => $nouvelleFacture->id, 'montant' => 30]]);

        $this->assertSame(StatutFacture::Payee, $nouvelleFacture->fresh()->statut);
    }

    /**
     * Exigence explicite : les avoirs les plus anciens se consomment en
     * premier, jamais l'inverse.
     */
    public function test_plusieurs_avoirs_se_consomment_du_plus_ancien_au_plus_recent(): void
    {
        $client = Client::factory()->create();
        $produitRetourne = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Les deux achats d'abord, AVANT tout avoir : sans quoi le crédit du
        // premier avoir s'appliquerait automatiquement au second achat
        // lui-même (émission de factureB), faussant tout le scénario.
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $factureA = $this->vendreEnCaisse($client, $produitRetourne, 1);

        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $factureB = $this->vendreEnCaisse($client, $produitRetourne, 1);

        $this->travelTo(self::aParis('2026-10-07 11:00:00'));
        $avoirAncien = $this->emettreAvoirPourClient($client, $factureA, 1); // 12 € TTC

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $avoirRecent = $this->emettreAvoirPourClient($client, $factureB, 1); // 12 € TTC

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        // Nouvel achat de 18 € : l'avoir le plus ancien (12 €) est consommé
        // intégralement, puis 6 € du plus récent -- jamais l'inverse.
        $produitNouvelAchat = Produit::factory()->create(['prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => false]);
        $nouvelleFacture = $this->emettreFacture($client, $produitNouvelAchat, 1);

        $this->assertSame(StatutFacture::Payee, $nouvelleFacture->statut);
        $this->assertSame(18.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(0.0, $avoirAncien->fresh()->montantNonAlloue());
        $this->assertSame(6.0, $avoirRecent->fresh()->montantNonAlloue());
        $this->assertSame(6.0, $client->soldeCrediteur());
    }

    public function test_sans_avoir_disponible_le_statut_de_la_facture_nest_pas_modifie(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = $this->emettreFacture($client, $produit, 1);

        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertSame(0.0, $facture->montantCrediteParAvoir());
        $this->assertSame((float) $facture->total_ttc, $facture->montantRestant());
    }

    /**
     * Le crédit d'un client ne doit jamais s'appliquer à la facture d'un
     * autre client, même émise juste après -- même exigence d'isolation
     * que pour tout le reste de l'application.
     */
    public function test_le_credit_dun_client_ne_sapplique_jamais_a_un_autre_client(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        $factureA = $this->vendreEnCaisse($clientA, $produit, 1);
        $this->emettreAvoirPourClient($clientA, $factureA, 1);

        $this->assertSame(60.0, $clientA->soldeCrediteur());

        $factureB = $this->emettreFacture($clientB, $produit, 1);

        $this->assertSame(StatutFacture::Emise, $factureB->statut);
        $this->assertSame(0.0, $factureB->montantCrediteParAvoir());
        $this->assertSame(60.0, $clientA->soldeCrediteur());
    }

    /**
     * Le client comptoir (vente anonyme, voir Client::estComptoir()) est
     * partagé par toutes les ventes au comptoir d'un magasin : le crédit
     * d'un avoir émis "pour lui" n'appartient qu'à qui en présente le
     * numéro, son bon d'avoir (voir EnregistrerVenteCaisseAction), et ne
     * s'applique jamais tout seul à la vente comptoir suivante. Il n'affiche
     * ni n'accumule donc jamais de solde créditeur.
     */
    public function test_le_client_comptoir_naccumule_et_napplique_jamais_de_credit(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        app(TenantContext::class)->set($magasin);

        $clientComptoir = Client::query()->where('nom', Client::NOM_COMPTOIR)->firstOrFail();
        $this->assertTrue($clientComptoir->estComptoir());

        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat comptoir payé puis intégralement retourné : aurait
        // généré 60 € de crédit disponible pour un client nommé.
        $premiereFacture = $this->vendreEnCaisse($clientComptoir, $produit, 1);
        $this->emettreAvoirPourClient($clientComptoir, $premiereFacture, 1);

        $this->assertSame(0.0, $clientComptoir->soldeCrediteur());

        // Nouvel achat comptoir du même montant : ne doit JAMAIS être couvert
        // automatiquement par ce crédit, le client suivant au comptoir
        // n'étant pas la même personne que celle ayant retourné le produit.
        $nouvelleFacture = $this->emettreFacture($clientComptoir, $produit, 1);

        $this->assertSame(StatutFacture::Emise, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(0.0, $clientComptoir->soldeCrediteur());
    }
}
