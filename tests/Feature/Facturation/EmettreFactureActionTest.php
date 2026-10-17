<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Exceptions\IdentiteDeLaSocieteIncompleteException;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmettreFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
    }

    public function test_emettre_une_facture_lui_attribue_un_numero_et_change_son_statut(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertNotNull($facture->numero);
        $this->assertNotNull($facture->date_emission);
    }

    public function test_emettre_decremente_le_stock_des_produits_suivis(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => true]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame(7, $produit->fresh()->stock_quantite);
    }

    /**
     * Un produit archivé après avoir été mis dans un brouillon sort quand
     * même du stock à l'émission, avec son mouvement (voir
     * LigneFacture::produit()), et y revient par l'avoir d'un retour (voir
     * LigneAvoir::produit()).
     */
    public function test_un_produit_archive_depuis_le_brouillon_sort_du_stock_a_l_emission_et_y_revient_par_l_avoir(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 5, 'suivi_stock' => true]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $produit->delete();

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame(3, $produit->fresh()->stock_quantite);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $produit->id, 'type' => 'vente', 'quantite' => 2]);

        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $this->assertSame(4, $produit->fresh()->stock_quantite);
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $produit->id, 'type' => 'retour_client', 'quantite' => 1]);
    }

    public function test_emettre_ne_touche_pas_au_stock_dun_produit_sans_suivi(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    /**
     * Exigence explicite : un ticket de caisse (vente à un particulier) et
     * une vraie facture professionnelle ne doivent jamais partager la même
     * suite numérique, même s'ils restent le même enregistrement Facture
     * en base -- voir le docblock de EmettreFactureAction.
     */
    public function test_une_vente_a_un_particulier_recoit_un_numero_de_ticket_de_caisse(): void
    {
        $client = Client::factory()->create(); // particulier par défaut
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertStringStartsWith('TK-', $facture->numero);
    }

    public function test_une_vente_a_un_professionnel_recoit_un_numero_de_facture(): void
    {
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertStringStartsWith('FAC-', $facture->numero);
    }

    /**
     * Le premier segment du numéro dit pour toujours dans quelle série la
     * vente a été numérotée, quel que soit le code du magasin qui suit.
     */
    public function test_la_serie_se_lit_dans_le_premier_segment_du_numero(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $creerEtEmettre = fn (Client $client) => app(EmettreFactureAction::class)->handle(self::auteur(),
            app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]])
        );

        $ticket = $creerEtEmettre(Client::factory()->create());
        $facture = $creerEtEmettre(Client::factory()->professionnel()->create());

        $this->assertSame(['TK-LYON1-2026-000001', TypeDocument::TicketCaisse], [$ticket->numero, $ticket->serie()]);
        $this->assertSame(['FAC-LYON1-2026-000001', TypeDocument::Facture], [$facture->numero, $facture->serie()]);
    }

    public function test_les_tickets_et_les_factures_ont_des_numerotations_independantes(): void
    {
        $clientParticulier = Client::factory()->create();
        $clientPro = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        $creerEtEmettre = fn (Client $client) => app(EmettreFactureAction::class)->handle(self::auteur(),
            app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]])
        );

        $ticket1 = $creerEtEmettre($clientParticulier);
        $facture1 = $creerEtEmettre($clientPro);
        $ticket2 = $creerEtEmettre($clientParticulier);

        $annee = now()->format('Y');
        $this->assertSame("TK-LYON1-{$annee}-000001", $ticket1->numero);
        $this->assertSame("FAC-LYON1-{$annee}-000001", $facture1->numero);
        $this->assertSame("TK-LYON1-{$annee}-000002", $ticket2->numero);
    }

    /**
     * 23 h 30 UTC le 31 décembre, c'est déjà 0 h 30 le 1er janvier à Paris
     * (heure d'hiver) : la vente appartient à la nouvelle année.
     */
    public function test_une_vente_a_0_h_30_le_1er_janvier_a_paris_est_datee_et_numerotee_sur_la_nouvelle_annee(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame('TK-LYON1-2027-000001', $facture->numero);
        $this->assertSame('2027-01-01 00:30', $facture->fresh()->date_emission->format('Y-m-d H:i'));
    }

    /**
     * Une facture remisée à 100 % ne coûte rien : elle n'attend aucun
     * règlement et passe « payée » dès son émission, sans encaissement,
     * signée avec ce statut.
     */
    public function test_une_facture_qui_ne_coute_rien_est_payee_des_son_emission(): void
    {
        $client = Client::factory()->professionnel()->create();
        $this->completerIdentiteLegale(app(TenantContext::class)->magasin());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 100],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $this->assertSame([StatutFacture::Payee, '0.00', 0.0, 0.0], [$facture->statut, $facture->total_ttc, $facture->montantPaye(), $facture->montantRestant()]);
        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    /**
     * Seul l'avoir d'un client comptoir du magasin vaut bon d'avoir (K6) :
     * l'avoir d'un client nommé, même passé tel quel au règlement
     * comptant, ne règle pas la vente d'un autre client. Le brouillon
     * reste un brouillon, et le crédit de l'avoir reste à son client.
     */
    public function test_l_avoir_d_un_client_nomme_ne_regle_jamais_une_vente_comme_bon_d_avoir(): void
    {
        $martin = Client::factory()->create();
        $achatRendu = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $martin, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $avoirDeMartin = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);

        try {
            app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon, new ReglementComptant(ModePaiement::Especes, bonDAvoir: $avoirDeMartin));
            $this->fail('L\'avoir d\'un client nommé aurait dû être refusé comme bon d\'avoir.');
        } catch (LogicException $refus) {
            $this->assertSame('Ce bon d\'avoir est introuvable ou déjà utilisé.', $refus->getMessage());
        }

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame([12.0, 12.0], [$avoirDeMartin->fresh()->montantNonAlloue(), $martin->soldeCrediteur()]);
    }

    /**
     * Le bon d'avoir d'un autre magasin, même passé tel quel au règlement
     * comptant, ne règle jamais une vente de ce magasin : rien n'est émis.
     */
    public function test_le_bon_d_avoir_d_un_autre_magasin_ne_regle_jamais_une_vente(): void
    {
        $magasin = app(TenantContext::class)->magasin();
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $bonDeLAutreMagasin = app(TenantContext::class)->runAs($autreMagasin, function (): Avoir {
            $comptoir = Client::query()->where('est_comptoir', true)->sole();
            $ticket = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

            return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        });
        // ouvrirUneSociete() a rendu actif le magasin qu'elle a ouvert.
        app(TenantContext::class)->set($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);

        try {
            app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon, new ReglementComptant(ModePaiement::Especes, bonDAvoir: $bonDeLAutreMagasin));
            $this->fail('Le bon d\'avoir d\'un autre magasin aurait dû être refusé.');
        } catch (LogicException $refus) {
            $this->assertSame('Ce bon d\'avoir est introuvable ou déjà utilisé.', $refus->getMessage());
        }

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(12.0, app(TenantContext::class)->runAs($autreMagasin, fn (): float => $bonDeLAutreMagasin->fresh()->montantNonAlloue()));
    }

    public function test_impossible_demettre_une_facture_sans_lignes(): void
    {
        $facture = Facture::factory()->for(Client::factory()->create())->create();

        $this->expectException(LogicException::class);
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_impossible_demettre_deux_fois_la_meme_facture(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->expectException(LogicException::class);
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture->fresh());
    }

    /**
     * Une fois la journée clôturée, la caisse n'émet plus de ticket
     * jusqu'à minuit : le brouillon reste intact et aucun numéro n'est
     * consommé ; il s'émet le lendemain.
     */
    public function test_un_ticket_est_refuse_apres_la_cloture_de_sa_journee_puis_emis_le_lendemain(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        $magasin = app(TenantContext::class)->magasin();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $this->travelTo('2026-10-09 19:30:00');

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon), LogicException::class, 'La journée du 09/10/2026 est clôturée : les ventes reprennent demain.');

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->where('type_document', TypeDocument::TicketCaisse->value)->exists());

        $this->travelTo('2026-10-10 00:01:00');
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $this->assertSame('TK-LYON1-2026-000001', $ticket->numero);
    }

    /**
     * La clôture ne compte que les tickets : une facture à un professionnel
     * s'émet encore après la clôture de la journée.
     */
    public function test_une_facture_a_un_professionnel_s_emet_encore_apres_la_cloture_de_la_journee(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        app(CloturerCaisseAction::class)->handle(self::auteur(), app(TenantContext::class)->magasin(), now());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame('FAC-LYON1-2026-000001', $facture->numero);
    }

    /**
     * Sous PostgreSQL (READ COMMITTED), une lecture verrouillée de
     * clotures_caisse qui a attendu la fin de la clôture de la journée ne
     * revoit que les lignes trouvées avant d'attendre, sans la clôture que
     * celle-ci vient d'inscrire. Le refus se fonde donc sur la journée que
     * la clôture note sur la ligne de verrou du registre fiscal, relue à
     * jour sous ce verrou : ici, la ligne de verrou note la journée alors
     * qu'aucune clôture n'existe en base.
     */
    public function test_le_refus_apres_la_cloture_se_fonde_sur_la_journee_notee_sous_le_verrou_du_registre(): void
    {
        $this->travelTo('2026-10-09 19:30:00');
        (new TeteRegistreFiscal)->forceFill(['derniere_journee_cloturee' => '2026-10-09'])->save();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon), LogicException::class, 'La journée du 09/10/2026 est clôturée : les ventes reprennent demain.');

        $this->assertSame([StatutFacture::Brouillon, 0], [$brouillon->fresh()->statut, ClotureCaisse::query()->count()]);
    }

    /**
     * @return array<string, array{int|null, int, string}> le délai du client, celui du magasin et l'échéance attendue
     */
    public static function delaisDePaiement(): array
    {
        return [
            'délai du client de 45 jours' => [45, 30, '2026-11-23'],
            'client sans délai, magasin à 30 jours' => [null, 30, '2026-11-08'],
            'client sans délai, magasin à 15 jours' => [null, 15, '2026-10-24'],
            'client payable comptant' => [0, 30, '2026-10-09'],
        ];
    }

    /**
     * L'échéance est la date d'émission plus le délai du client, sinon
     * celui du magasin (B7).
     */
    #[DataProvider('delaisDePaiement')]
    public function test_l_echeance_est_la_date_d_emission_plus_le_delai_du_client_sinon_celui_du_magasin(?int $delaiDuClient, int $delaiDuMagasin, string $echeance): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        app(TenantContext::class)->magasin()->update(['delai_paiement_jours' => $delaiDuMagasin]);
        $client = Client::factory()->professionnel()->create(['delai_paiement_jours' => $delaiDuClient]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->uneLigne()));

        $this->assertSame($echeance, $facture->date_echeance->toDateString());
    }

    /**
     * Un brouillon n'a pas d'échéance : elle se calcule le jour où il est
     * émis, jamais le jour où il a été créé, et n'est donc jamais dans le
     * passé.
     */
    public function test_l_echeance_d_un_brouillon_se_calcule_le_jour_de_son_emission(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'Europe/Paris'));
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), $this->uneLigne());
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));

        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $this->assertNull($brouillon->date_echeance);
        $this->assertSame('2026-11-08', $ticket->date_echeance->toDateString());
    }

    /**
     * Sans identité légale complète de la société et de l'établissement,
     * le magasin ne facture pas un professionnel (B11, K7) : le brouillon
     * reste, aucun numéro de facture n'est consommé et rien n'est signé.
     * Une fois l'identité complétée, la facture prend le premier numéro.
     */
    public function test_une_facture_a_un_professionnel_attend_l_identite_complete_du_magasin(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $magasin = $this->magasinSansIdentiteLegale();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->uneLigne());

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            IdentiteDeLaSocieteIncompleteException::class,
            'Pour émettre une facture à un professionnel, complétez d\'abord l\'identité de la société (Administration de la société > Identité) : raison sociale, forme juridique, immatriculation, adresse du siège, n° de TVA intracommunautaire, adresse du magasin, code postal du magasin, ville du magasin.',
        );

        $this->assertSame([StatutFacture::Brouillon, null, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero, $brouillon->fresh()->date_echeance]);
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->where('type_document', TypeDocument::Facture->value)->exists());
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count());

        $this->completerIdentiteLegale($magasin);
        $this->reglerLaSociete($magasin, ['numero_tva' => 'FR88100000009']);
        $magasin->forceFill(['adresse' => '1 rue de la République', 'code_postal' => '69001', 'ville' => 'Lyon'])->save();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);

        $this->assertSame('FAC-MABOUT-2026-000001', $facture->numero);
    }

    /**
     * Les tickets restent possibles sans identité légale.
     */
    public function test_un_ticket_s_emet_sans_identite_legale_du_magasin(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $this->magasinSansIdentiteLegale();

        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), $this->uneLigne()));

        $this->assertSame('TK-MABOUT-2026-000001', $ticket->numero);
    }

    /**
     * En franchise en base, le n° de TVA intracommunautaire ne figure sur
     * la facture que « le cas échéant » : il n'est pas exigé.
     */
    public function test_un_magasin_en_franchise_sans_numero_de_tva_facture_un_professionnel(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $this->reglerLaSociete(app(TenantContext::class)->magasin(), ['regime_tva' => RegimeTva::FranchiseEnBase, 'numero_tva' => null]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->uneLigne()));

        $this->assertSame('FAC-LYON1-2026-000001', $facture->numero);
    }

    public function test_un_magasin_redevable_de_la_tva_sans_numero_de_tva_ne_facture_pas_un_professionnel(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
        $this->reglerLaSociete(app(TenantContext::class)->magasin(), ['regime_tva' => RegimeTva::ReelNormalMensuel, 'numero_tva' => null]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->uneLigne());

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            IdentiteDeLaSocieteIncompleteException::class,
            'Pour émettre une facture à un professionnel, complétez d\'abord l\'identité de la société (Administration de la société > Identité) : n° de TVA intracommunautaire.',
        );

        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);
    }

    /**
     * Un professionnel établi en France est identifié par son SIREN (B12)
     * : sa facture attend qu'il soit renseigné.
     */
    public function test_la_facture_d_un_professionnel_etabli_en_france_attend_son_siren(): void
    {
        $client = Client::factory()->create(['type_client' => TypeClient::Professionnel]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->uneLigne());

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            LogicException::class,
            'Renseignez le SIREN de ce client avant d\'émettre sa facture.',
        );

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
    }

    /**
     * Le magasin MABOUT d'une société qui n'a encore que son SIREN, sans
     * adresse d'établissement (l'INSEE n'ayant pas répondu à sa création),
     * magasin actif.
     */
    private function magasinSansIdentiteLegale(): Magasin
    {
        $magasin = Magasin::factory()->avecLaSociete([
            'raison_sociale' => null,
            'forme_juridique' => null,
            'capital_social' => null,
            'immatriculation' => null,
            'numero_tva' => null,
            'siege_adresse' => null,
            'siege_code_postal' => null,
            'siege_ville' => null,
        ])->create(['code' => 'MABOUT', 'adresse' => null, 'code_postal' => null, 'ville' => null]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Une ligne d'un article à 12,00 € TTC.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function uneLigne(): array
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return [['produit_id' => $produit->id, 'quantite' => 1]];
    }
}
