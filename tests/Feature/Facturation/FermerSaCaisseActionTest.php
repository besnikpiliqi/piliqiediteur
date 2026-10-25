<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\LecteurDesDroits;
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
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FermerSaCaisseAction (relevé personnel) est délibérément distincte de
 * CloturerCaisseAction (clôture officielle de la journée du magasin, voir
 * CloturerCaisseActionTest) : aucune condition de blocage, pas de FK sur
 * les factures, pas de restriction aux clients particuliers. Son relevé
 * sépare le TTC vendu, les encaissements par mode, le crédit d'avoir
 * utilisé et le reste dû (B18).
 */
class FermerSaCaisseActionTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    private function emettreFacturePour(User $vendeur, Client $client, float $prixUnitaireTtc = 120): void
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixUnitaireTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_agrege_toutes_les_ventes_du_vendeur_depuis_le_debut_de_la_journee(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->professionnel()->create(); // même un pro compte ici, contrairement à ClotureCaisse
        $this->emettreFacturePour($vendeur, $client, 120); // 120 TTC

        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame('120.00', $cloture->total_ttc);
        $this->assertSame($vendeur->id, $cloture->vendeur_id);
    }

    /**
     * Une vente de la veille que le vendeur n'a pas fermée est comptée par
     * le relevé du lendemain : « Depuis » remonte jusqu'à elle.
     */
    public function test_le_releve_part_de_la_plus_ancienne_vente_qu_il_compte(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();
        $this->travelTo(self::aParis('2026-10-07 18:00:00'));
        $this->emettreFacturePour($vendeur, $client, 120);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertMemeInstant(self::aParis('2026-10-07 18:00:00'), $cloture->periode_debut);
    }

    /**
     * Un paiement de la veille que le vendeur n'a pas fermé, sur la facture
     * d'un collègue, est compté par le relevé du lendemain : « Depuis »
     * remonte aussi jusqu'à lui.
     */
    public function test_le_releve_part_du_plus_ancien_paiement_qu_il_compte(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $this->travelTo(self::aParis('2026-10-07 09:00:00'));
        $this->actingAs($this->vendeurDeLaPremiereHeure($this->magasin));
        $factureDUnCollegue = $this->emettreUnDocument(Client::factory()->create(), 45);
        $this->travelTo(self::aParis('2026-10-07 18:00:00'));
        $this->actingAs($vendeur);
        $this->encaisser($factureDUnCollegue, ModePaiement::Especes, 45);

        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame([0, ['especes' => '45.00']], [$cloture->nombre_ventes, $cloture->fresh()->encaissements]);
        $this->assertMemeInstant(self::aParis('2026-10-07 18:00:00'), $cloture->periode_debut);
    }

    public function test_sans_vente_ni_fermeture_le_releve_part_du_debut_de_la_journee(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));

        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(0, $cloture->nombre_ventes);
        $this->assertMemeInstant(self::aParis('2026-10-08 00:00:00'), $cloture->periode_debut);
    }

    public function test_le_releve_suivant_part_de_la_fermeture_precedente(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();
        $this->travelTo(self::aParis('2026-10-08 09:00:00'));
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->emettreFacturePour($vendeur, $client, 120);

        $this->travelTo(self::aParis('2026-10-08 11:00:00'));
        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertMemeInstant(self::aParis('2026-10-08 09:00:00'), $cloture->periode_debut);
    }

    public function test_une_seconde_fermeture_ne_reprend_que_les_ventes_depuis_la_premiere(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();

        $this->emettreFacturePour($vendeur, $client, 120);
        $premiere = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(1, $premiere->nombre_ventes);

        // Aucune nouvelle vente : la seconde fermeture doit être à zéro,
        // pas reprendre la première vente une deuxième fois.
        $seconde = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(0, $seconde->nombre_ventes);
        $this->assertSame('0.00', $seconde->total_ttc);

        $this->emettreFacturePour($vendeur, $client, 60);
        $troisieme = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(1, $troisieme->nombre_ventes);
        $this->assertSame('60.00', $troisieme->total_ttc);
    }

    public function test_ne_touche_pas_aux_ventes_dun_autre_vendeur(): void
    {
        $vendeurA = $this->vendeurDeLaPremiereHeure($this->magasin);
        $vendeurB = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();

        $this->emettreFacturePour($vendeurA, $client, 120);
        $this->emettreFacturePour($vendeurB, $client, 240);

        $clotureA = app(FermerSaCaisseAction::class)->handle($vendeurA, $vendeurA);

        $this->assertSame(1, $clotureA->nombre_ventes);
        $this->assertSame('120.00', $clotureA->total_ttc);
    }

    public function test_naffecte_jamais_les_factures_elles_memes(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();
        $this->emettreFacturePour($vendeur, $client, 120);

        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $facture = Facture::query()->firstOrFail();
        $this->assertNull($facture->cloture_caisse_id, 'Un relevé personnel ne doit jamais marquer une facture comme déjà agrégée pour la clôture officielle.');
    }

    /**
     * Le brouillon F1 est créé AVANT la vente F2 (donc un ID plus petit),
     * mais émis APRÈS la fermeture qui a compté F2 : la fermeture suivante
     * doit le compter -- un curseur sur l'ID le sauterait pour toujours.
     */
    public function test_un_brouillon_emis_apres_une_fermeture_est_compte_par_la_suivante(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $client = Client::factory()->create();
        $this->actingAs($vendeur);

        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->emettreFacturePour($vendeur, $client, 120);

        $premiere = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(1, $premiere->nombre_ventes);
        $this->assertSame('120.00', $premiere->total_ttc);

        app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $seconde = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(1, $seconde->nombre_ventes);
        $this->assertSame('60.00', $seconde->total_ttc);
        $this->assertSame([$brouillon->id], $seconde->factures()->pluck('factures.id')->all());

        $troisieme = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(0, $troisieme->nombre_ventes, 'Une vente déjà comptée ne doit jamais l\'être une seconde fois.');
    }

    /**
     * B18 : le relevé compte en entier, dans le TTC vendu, une vente
     * reprise par un avoir avant la fermeture, et ne déduit pas l'avoir,
     * que la clôture de la journée déduit (voir CloturerCaisseActionTest).
     * L'avoir total solde la vente restée impayée : son crédit d'avoir
     * figure à part, rien n'a été encaissé, et seule la seconde vente
     * reste due.
     */
    public function test_une_vente_corrigee_par_un_avoir_avant_la_fermeture_reste_dans_le_ttc_vendu(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_CREER]);
        $client = Client::factory()->create();
        $this->emettreFacturePour($vendeur, $client, 120);
        $venteCorrigee = Facture::query()->firstOrFail();
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteCorrigee->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');
        $this->emettreFacturePour($vendeur, $client, 60);

        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(0.0, $avoir->montantNonAlloue());
        $this->assertSame(2, $cloture->nombre_ventes);
        $this->assertSame(['180.00', [], '120.00', '60.00'], [$cloture->total_ttc, $cloture->encaissements, $cloture->credit_avoir, $cloture->reste_du]);
        $this->assertContains($venteCorrigee->id, $cloture->factures()->pluck('factures.id')->all());
    }

    /**
     * B18 : le relevé sépare le TTC vendu, les encaissements par mode, le
     * crédit d'avoir utilisé et le reste dû. Martin a 30,00 € de crédit (un
     * ticket de 30,00 € payé puis repris par un avoir) et Durand doit
     * 45,00 € d'un ticket, avant une première fermeture. Puis le vendeur
     * vend à Martin 1 x 60,00 € (50,00 € HT), réglé par les 30,00 € de
     * crédit et 30,00 € en espèces, émet une facture de 1 x 120,00 €
     * (100,00 € HT) à un professionnel, impayée, et encaisse par carte les
     * 45,00 € de l'ancien ticket.
     */
    public function test_le_releve_separe_le_vendu_les_encaissements_le_credit_d_avoir_et_le_reste_du(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::AVOIR_CREER]);
        $martin = Client::factory()->create(['nom' => 'Martin']);
        $this->actingAs($vendeur);
        $ticketRepris = $this->vendreAuComptoir($martin, 30);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $ticketRepris->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $ancienTicket = $this->emettreUnDocument(Client::factory()->create(['nom' => 'Durand']), 45);
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->assertSame(30.0, $martin->soldeCrediteur());

        $this->vendreAuComptoir($martin, 60);
        $this->emettreUnDocument(Client::factory()->professionnel()->create(), 120);
        $this->encaisser($ancienTicket, ModePaiement::CarteBancaire, 45);
        $cloture = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(2, $cloture->nombre_ventes);
        $this->assertSame(['150.00', '30.00', '180.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame(['especes' => '30.00', 'carte_bancaire' => '45.00'], $cloture->fresh()->encaissements);
        $this->assertSame(75.0, $cloture->fresh()->totalEncaisse());
        $this->assertSame(['30.00', '120.00'], [$cloture->credit_avoir, $cloture->reste_du]);
    }

    public function test_une_seconde_fermeture_immediate_est_a_zero_dans_chaque_rubrique(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $this->actingAs($vendeur);
        $ticket = $this->emettreUnDocument(Client::factory()->create(), 45);
        $this->encaisser($ticket, ModePaiement::Especes, 45);
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $seconde = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame([0, '0.00', [], '0.00', '0.00'], [$seconde->nombre_ventes, $seconde->total_ttc, $seconde->fresh()->encaissements, $seconde->credit_avoir, $seconde->reste_du]);
    }

    /**
     * Un paiement de 100,00 € en espèces saisi par erreur par le vendeur,
     * contre-passé par le propriétaire, puis le bon paiement de 10,00 € :
     * la contre-passation revient dans le relevé du vendeur (Espèces
     * 10,00 €) ; le propriétaire, qui ne vend jamais, n'a pas de caisse,
     * même après sa contre-passation ; le paiement d'un autre vendeur n'y
     * figure pas.
     */
    public function test_une_erreur_contre_passee_par_le_proprietaire_revient_dans_le_releve_du_vendeur(): void
    {
        $vendeur = $this->vendeurDeLaPremiereHeure($this->magasin);
        $proprietaire = $this->proprietaireDe($this->magasin);
        $autreVendeur = $this->vendeurDeLaPremiereHeure($this->magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $this->actingAs($vendeur);
        $facture = $this->emettreUnDocument(Client::factory()->professionnel()->create(), 120);
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $erreur = $this->encaisser($facture, ModePaiement::Especes, 100);
        $this->actingAs($proprietaire);
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $erreur, 'Montant erroné');
        $this->actingAs($vendeur);
        $this->encaisser($facture, ModePaiement::Especes, 10);
        $this->actingAs($autreVendeur);
        $this->encaisser($facture, ModePaiement::Cheque, 20);

        $releveDuVendeur = app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);

        $this->assertSame(['especes' => '10.00'], $releveDuVendeur->fresh()->encaissements);
        $this->assertSame(3, $releveDuVendeur->paiements()->count());
        $this->assertFalse($proprietaire->can('fermer', ClotureCaissePersonnelle::class));
        $this->assertThrows(fn () => app(FermerSaCaisseAction::class)->handle($proprietaire, $proprietaire), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, ClotureCaissePersonnelle::query()->where('vendeur_id', $proprietaire->id)->count());
    }

    /**
     * Une vente au comptoir, réglée en espèces après le crédit d'avoir du
     * client.
     */
    private function vendreAuComptoir(Client $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
    }

    /**
     * Un ticket, ou une facture pour un professionnel, émis sans règlement.
     */
    private function emettreUnDocument(Client $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function encaisser(Facture $facture, ModePaiement $mode, float $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => $mode->value,
            'montant' => $montant,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
