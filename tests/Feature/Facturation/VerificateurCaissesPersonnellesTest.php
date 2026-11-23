<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-08');

        $this->assertTrue($vendeursEnAttente->contains('id', $vendeur->id));
    }

    /**
     * Un brouillon n'est pas une vente : il n'ouvre pas la caisse.
     */
    public function test_un_brouillon_n_ouvre_pas_la_caisse(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $this->actingAs($vendeur);
        app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 1]]);

        $this->assertTrue(app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-09')->isEmpty());
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

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-08');

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

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-08');

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

        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte('2026-07-14')->isEmpty());
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte('2026-07-15')->contains('id', $vendeur->id));
    }

    /**
     * La dernière seconde de la journée en est : une vente émise à
     * 23:59:59 à Paris laisse la caisse ouverte au soir même ; une vente
     * émise à minuit est du lendemain.
     */
    public function test_la_derniere_seconde_de_la_journee_en_est_et_minuit_est_du_lendemain(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);
        $tardif = $this->vendeurDeLaPremiereHeure($magasin);
        $matinal = $this->vendeurDeLaPremiereHeure($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $this->travelTo(self::aParis('2026-10-09 23:59:59'));
        $this->emettreFacturePour($tardif, $client, $produit);
        $this->travelTo(self::aParis('2026-10-10 00:00:00'));
        $this->emettreFacturePour($matinal, $client, $produit);

        $this->assertSame([$tardif->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-09')->pluck('id')->all());
    }

    public function test_aucun_vendeur_en_attente_sans_aucune_vente_ce_jour_la(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-08');

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

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte('2026-10-07');

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

        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::AVOIR_CREER]);
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
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte('2026-10-08')->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte('2026-10-08')->contains('id', $vendeur->id));
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
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui())->contains('id', $vendeur->id));

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertFalse($verificateur->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui())->contains('id', $vendeur->id));
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
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $facture = $this->emettreUnTicketPour($collegue, Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($collegue, $collegue);
        $this->actingAs($vendeur);
        $this->encaisser($facture, 12, Calendrier::deParis()->aujourdhui());
        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $vendeursEnAttente = $verificateur->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui());

        $this->assertSame([$vendeur->id], $vendeursEnAttente->pluck('id')->all());
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertTrue($verificateur->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui())->isEmpty());
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
        $paiement = $this->encaisser($facture, 12, Calendrier::deParis()->aujourdhui());
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->actingAs($proprietaire);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Chèque sans provision');

        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui());

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
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $facture = $this->emettreUnTicketPour($collegue, Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($collegue, $collegue);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->actingAs($vendeur);
        $this->encaisser($facture, 12, '2026-10-08');
        $verificateur = app(VerificateurCaissesPersonnelles::class);

        $avantHier = $verificateur->vendeursAvecCaisseOuverte('2026-10-07');
        $hier = $verificateur->vendeursAvecCaisseOuverte('2026-10-08');

        $this->assertTrue($avantHier->isEmpty());
        $this->assertSame([$vendeur->id], $hier->pluck('id')->all());
    }

    /**
     * Les caisses de plusieurs magasins se lisent d'un coup, chacune au soir
     * de sa journée et dans son calendrier : le 9 octobre, Jeanne vend à
     * Paris à 10:00, Paul à Paris à 01:00 le 10 (déjà le lendemain à Paris)
     * et Luc à Fort-de-France à 20:00 le 9 (déjà le 10 à Paris). Au soir du 9,
     * Paris a la caisse de Jeanne et la Martinique celle de Luc, chacun dans
     * son seul magasin ; au soir du 10 à Paris, Paul s'y ajoute ; un
     * magasin sans vente n'a aucune caisse ouverte ; sans magasin, rien ne
     * se lit.
     */
    public function test_les_caisses_de_plusieurs_magasins_se_lisent_d_un_coup_chacune_a_sa_journee(): void
    {
        $this->travelTo(self::aParis('2026-10-09 09:00:00'));
        $paris = $this->ouvrirUneSociete(['nom' => 'Paris']);
        $martinique = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Martinique, ['nom' => 'Fort-de-France', 'code' => 'FDF1']);
        $vide = $this->ouvrirUnAutreMagasin($paris, ['nom' => 'Lyon', 'code' => 'LYON2']);
        $jeanne = $this->membre($paris, RoleMagasin::Vendeur, null, ['name' => 'Jeanne']);
        $paul = $this->membre($paris, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $luc = $this->membre($martinique, RoleMagasin::Vendeur, null, ['name' => 'Luc']);
        foreach ([[$paris, $jeanne, self::aParis('2026-10-09 10:00:00')], [$paris, $paul, self::aParis('2026-10-10 01:00:00')], [$martinique, $luc, self::enUtc('2026-10-10 00:00:00')]] as [$magasin, $vendeur, $instant]) {
            $this->travelTo($instant);
            app(TenantContext::class)->runAs($magasin, fn (): Facture => $this->emettreUnTicketPour($vendeur, Client::factory()->create()));
        }
        app(TenantContext::class)->clear();
        $verificateur = app(VerificateurCaissesPersonnelles::class);
        $noms = fn (array $caisses): array => array_map(fn ($vendeurs): array => $vendeurs->pluck('name')->all(), $caisses);

        $this->assertSame(
            [$paris->id => ['Jeanne'], $martinique->id => ['Luc'], $vide->id => []],
            $noms($verificateur->vendeursAvecCaisseOuverteDans([$paris->id => [$paris, '2026-10-09'], $martinique->id => [$martinique, '2026-10-09'], $vide->id => [$vide, '2026-10-09']])),
        );
        $this->assertSame(
            [$paris->id => ['Jeanne', 'Paul'], $martinique->id => ['Luc']],
            $noms($verificateur->vendeursAvecCaisseOuverteDans([$paris->id => [$paris, '2026-10-10'], $martinique->id => [$martinique, '2026-10-09']])),
        );
        $this->assertSame([$martinique->id => []], $noms($verificateur->vendeursAvecCaisseOuverteDans([$martinique->id => [$martinique, '2026-10-08']])));
        DB::enableQueryLog();
        $this->assertSame([], $verificateur->vendeursAvecCaisseOuverteDans([]));
        $this->assertSame([], DB::getQueryLog(), 'Sans magasin, aucune requête : elle ne serait filtrée par aucun magasin.');
    }

    /**
     * Les encaissements de plusieurs magasins se lisent d'un coup, chacun
     * dans son magasin : dans chacun, un vendeur a fermé sa caisse après sa
     * vente, et un collègue encaisse ce ticket le même jour ; seul ce
     * collègue a sa caisse ouverte, par son seul encaissement.
     */
    public function test_les_encaissements_de_plusieurs_magasins_se_lisent_d_un_coup(): void
    {
        $this->travelTo(self::aParis('2026-10-09 09:00:00'));
        $paris = $this->ouvrirUneSociete(['nom' => 'Paris']);
        $lyon = $this->ouvrirUnAutreMagasin($paris, ['nom' => 'Lyon', 'code' => 'LYON2']);

        foreach ([$paris, $lyon] as $magasin) {
            $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
            $encaisseur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Encaisseur de '.$magasin->nom]);
            $this->travelTo(self::aParis('2026-10-09 10:00:00'));
            app(TenantContext::class)->runAs($magasin, function () use ($vendeur, $encaisseur): void {
                $ticket = $this->emettreUnTicketPour($vendeur, Client::factory()->create());
                app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
                $this->actingAs($encaisseur);
                $this->encaisser($ticket, 12, '2026-10-09');
            });
        }
        app(TenantContext::class)->clear();

        $this->assertSame(
            [$paris->id => ['Encaisseur de Paris'], $lyon->id => ['Encaisseur de Lyon']],
            array_map(fn ($vendeurs): array => $vendeurs->pluck('name')->all(), app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverteDans([$paris->id => [$paris, '2026-10-09'], $lyon->id => [$lyon, '2026-10-09']])),
        );
    }

    private function emettreUnTicketPour(User $vendeur, Client $client): Facture
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function encaisser(Facture $facture, float $montant, string $recuLe): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => $montant,
            'recu_le' => $recuLe,
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
