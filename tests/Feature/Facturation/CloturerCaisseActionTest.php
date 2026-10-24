<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\CategorieEReporting;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CloturerCaisseActionTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    /**
     * Les tests commencent le 9 octobre 2026 à 10 h, heure de Paris : la
     * journée qu'ils clôturent ne dépend pas du jour où ils tournent, et
     * minuit ne passe jamais entre une vente et sa clôture.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00:00');
        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
    }

    /**
     * Le paiement de toute la facture, dans ce mode, reçu ce jour-là.
     */
    private function payer(Facture $facture, ModePaiement $mode, string $recuLe): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => $mode->value,
            'montant' => $facture->total_ttc,
            'recu_le' => $recuLe,
        ], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);
    }

    /**
     * Un ticket de ce montant à 20 % au client, réglé à la caisse dans ce
     * mode.
     */
    private function vendreUnTicket(Client $client, float $prixTtc, ModePaiement $mode = ModePaiement::Especes): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], $mode);
    }

    /**
     * La journée chiffrée du point 26, le lundi 5 octobre 2026 : trois
     * tickets à un particulier (deux câbles à 50,00 € à 20 % en espèces ;
     * un livre à 10,55 € à 5,5 % et une réparation à 60,00 € à 20 % par
     * carte ; un câble par carte), une facture d'un câble à un
     * professionnel réglée par virement, puis le retour d'un câble du
     * premier ticket, dont le client garde le crédit. La journée se
     * clôture à 19 h.
     */
    private function journeeDExemple(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $particulier = Client::factory()->create();
        $cable = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['prix_vente_ttc' => 10.55, 'taux_tva' => 5.5, 'suivi_stock' => false]);
        $reparation = Service::factory()->create();
        $deuxCables = self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 2]], ModePaiement::Especes);
        self::vendreAuComptant(self::auteur(), $particulier, [
            ['produit_id' => $livre->id, 'quantite' => 1],
            ['service_id' => $reparation->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1],
        ], ModePaiement::CarteBancaire);
        self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        $this->payer($this->emettreFacturePour(Client::factory()->professionnel()->create(), 50), ModePaiement::Virement, '2026-10-05');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $particulier, [['ligne_facture_id' => $deuxCables->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->travelTo('2026-10-05 19:00:00');
    }

    /**
     * Les encaissements du récapitulatif de la clôture, mode par mode :
     * nombre de paiements, de contre-passations et montant en centimes.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    private static function encaissementsDe(ClotureCaisse $cloture): array
    {
        return collect($cloture->fresh()->recapitulatif['encaissements'])
            ->mapWithKeys(fn (array $mode): array => [$mode['mode'] => [$mode['paiements'], $mode['contre_passations'], $mode['montant']]])
            ->all();
    }

    /**
     * Les événements de clôture (JET 50) du magasin actif, dans l'ordre de
     * sa chaîne.
     *
     * @return Collection<int, EnregistrementFiscal>
     */
    private static function evenementsDeCloture(): Collection
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::CloturePeriode->value)
            ->orderBy('sequence')
            ->get();
    }

    private function emettreFacturePour(Client $client, float $prixUnitaireTtc = 120): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixUnitaireTtc, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    public function test_agrege_uniquement_les_ventes_a_des_clients_particuliers(): void
    {
        $particulier = Client::factory()->create();
        $professionnel = Client::factory()->professionnel()->create();

        $factureParticulier = $this->emettreFacturePour($particulier, 120); // 120 TTC
        $this->emettreFacturePour($professionnel, 60); // ne doit jamais être agrégée

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame('120.00', $cloture->total_ttc);
        $this->assertSame($cloture->id, $factureParticulier->fresh()->cloture_caisse_id);
    }

    /**
     * Une vente corrigée le jour même par un avoir total reste une vente
     * de la journée, et l'avoir s'en déduit : la journée ne garde rien de
     * cette vente, sans qu'aucun document disparaisse.
     */
    public function test_exclut_les_brouillons_et_deduit_une_vente_corrigee_par_un_avoir_total(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Brouillon jamais émis : pas de date_emission, jamais inclus.
        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $venteCorrigee = $this->emettreFacturePour($client);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteCorrigee->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame('0.00', $cloture->total_ttc);
    }

    /**
     * Un retour se compte le jour où il a lieu : l'avoir émis aujourd'hui
     * sur une vente d'hier, déjà clôturée, se déduit des ventes
     * d'aujourd'hui -- jamais en rouvrant la clôture d'hier.
     */
    public function test_un_avoir_se_deduit_des_ventes_du_jour_de_son_emission(): void
    {
        $client = Client::factory()->create();
        $this->travelTo('2026-10-08 10:00:00');
        $venteDHier = $this->emettreFacturePour($client, 60); // 60 TTC
        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFacturePour($client, 120); // 120 TTC
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteDHier->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame(['50.00', '10.00', '60.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 50.0, 'montant_tva' => 10.0, 'montant_ttc' => 60.0],
        ], $cloture->ventilation_tva);
        $this->assertSame($cloture->id, $avoir->fresh()->cloture_caisse_id);
    }

    /**
     * Une journée de caisse va de minuit à minuit à Paris, alors que la
     * date d'émission s'enregistre en UTC : 0 h 30 le 15 juillet à Paris,
     * c'est 22 h 30 UTC le 14. La vente de 23 h 30 se compte le 14 ; la
     * vente et l'avoir émis après minuit, le 15.
     */
    public function test_une_journee_de_caisse_va_de_minuit_a_minuit_a_paris(): void
    {
        $client = Client::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-07-14 21:30:00', 'UTC'));
        $venteDu14 = $this->emettreFacturePour($client, 60);
        $this->travelTo(CarbonImmutable::parse('2026-07-14 22:30:00', 'UTC'));
        $venteDu15 = $this->emettreFacturePour($client, 120);
        $avoirDu15 = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteDu14->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);

        $clotureDu14 = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-07-14');
        $clotureDu15 = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-07-15');

        $this->assertSame([1, 0, '60.00'], [$clotureDu14->nombre_ventes, $clotureDu14->nombre_avoirs, $clotureDu14->total_ttc]);
        $this->assertSame([1, 1, '60.00'], [$clotureDu15->nombre_ventes, $clotureDu15->nombre_avoirs, $clotureDu15->total_ttc]);
        $this->assertSame(
            [$clotureDu14->id, $clotureDu15->id, $clotureDu15->id],
            [$venteDu14->fresh()->cloture_caisse_id, $venteDu15->fresh()->cloture_caisse_id, $avoirDu15->fresh()->cloture_caisse_id],
        );
    }

    /**
     * La déclaration des ventes aux particuliers de la journée sépare les
     * livraisons de biens des prestations de services (voir
     * EReportingDesParticuliers) : un écran à 60 € et sa pose à 30 €, puis
     * l'écran rendu.
     */
    public function test_la_cloture_separe_les_biens_et_les_services(): void
    {
        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $pose = Service::factory()->create();
        $vente = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['service_id' => $pose->id, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'quantite' => 1],
        ]));
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $vente->lignes->firstWhere('produit_id', $ecran->id)->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame([
            ['categorie' => CategorieEReporting::LivraisonsDeBiens, 'taux' => 2000, 'ht' => 5000, 'tva' => 1000, 'motif' => null, 'remarque' => null],
            ['categorie' => CategorieEReporting::PrestationsDeServices, 'taux' => 2000, 'ht' => 2500, 'tva' => 500, 'motif' => null, 'remarque' => null],
        ], app(EReportingDesParticuliers::class)->apercu($cloture)->transactions);
    }

    /**
     * Un client archivé le jour même garde ses ventes et ses retours dans
     * la clôture (voir Facture::client() et Avoir::client()).
     */
    public function test_les_ventes_et_les_retours_d_un_client_archive_restent_dans_la_cloture(): void
    {
        $client = Client::factory()->create();
        $this->emettreFacturePour($client, 120); // 120 TTC
        $venteRendue = $this->emettreFacturePour($client, 60); // 60 TTC
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $venteRendue->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $client->delete();

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(2, $cloture->nombre_ventes);
        $this->assertSame(1, $cloture->nombre_avoirs);
        $this->assertSame('120.00', $cloture->total_ttc);
    }

    public function test_un_avoir_a_un_professionnel_nest_jamais_deduit_de_la_cloture(): void
    {
        $professionnel = Client::factory()->professionnel()->create();
        $facture = $this->emettreFacturePour($professionnel, 60);
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $professionnel, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(0, $cloture->nombre_avoirs);
        $this->assertSame('0.00', $cloture->total_ttc);
        $this->assertNull($avoir->fresh()->cloture_caisse_id);
    }

    public function test_numerote_la_cloture(): void
    {
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertNotNull($cloture->numero);
        $this->assertNotNull($cloture->cloturee_a);
    }

    public function test_une_journee_deja_cloturee_ne_peut_pas_letre_deux_fois(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now()), LogicException::class, 'La journée du 09/10/2026 est déjà clôturée.');
    }

    public function test_deux_journees_differentes_peuvent_chacune_etre_cloturees(): void
    {
        $hier = now()->subDay();
        $aujourdhui = now();

        $clotureHier = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, $hier);
        $clotureAujourdhui = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, $aujourdhui);

        $this->assertNotSame($clotureHier->id, $clotureAujourdhui->id);
    }

    /**
     * Les numéros suivent l'ordre des journées : tant que le magasin vend
     * déjà, une journée ne se clôture qu'une fois sa veille clôturée.
     */
    public function test_une_journee_dont_la_veille_n_est_pas_cloturee_est_refusee(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $this->emettreFacturePour(Client::factory()->create());
        $this->travelTo('2026-10-09 19:00:00');

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09'), LogicException::class, "Clôturez d'abord la journée du 08/10/2026.");
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    /**
     * Fermé du 06/10 au soir au 08/10 au matin, le magasin n'a pas de
     * journée de caisse le 07/10 : elle ne se clôture pas, et la journée
     * du 08/10 suit directement celle du 06/10.
     */
    public function test_une_journee_hors_des_periodes_d_activite_est_refusee_et_ne_retient_pas_la_suivante(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $this->emettreFacturePour(Client::factory()->create());
        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-06');
        PeriodeActivite::query()->where('magasin_id', $this->magasin->id)->sole()->forceFill(['fin' => CarbonImmutable::parse('2026-10-06 19:00:00', config('app.timezone')), 'motif_fin' => MotifFermeture::Temporaire])->save();
        (new PeriodeActivite)->forceFill(['magasin_id' => $this->magasin->id, 'debut' => CarbonImmutable::parse('2026-10-08 09:00:00', config('app.timezone'))])->save();
        $this->travelTo('2026-10-08 19:00:00');

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-07'), LogicException::class, "Le magasin était fermé le 07/10/2026 : cette journée n'a pas de clôture.");
        $this->assertSame('2026-10-08', app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-08')->date_cloture->toDateString());
        $this->assertSame(2, ClotureCaisse::query()->count());
    }

    public function test_une_journee_pas_encore_commencee_est_refusee(): void
    {
        $this->travelTo('2026-10-09 19:00:00');

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10'), LogicException::class, "La journée du 10/10/2026 n'est pas encore commencée.");
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    public function test_une_journee_anterieure_a_la_derniere_journee_cloturee_est_refusee(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-07'), LogicException::class, "La journée du 07/10/2026 précède la dernière journée clôturée, celle du 09/10/2026 : les journées se clôturent dans l'ordre.");
        $this->assertSame(1, ClotureCaisse::query()->count());
    }

    /**
     * Une vente restée ouverte à la caisse quand sa journée se clôture est
     * abandonnée : le journal des événements l'inscrit avec son vendeur,
     * son nombre de lignes et d'articles et son montant, avant la clôture
     * elle-même (JET 50), et son panier s'efface. Une vente commencée après
     * minuit appartient à la journée suivante.
     */
    public function test_la_cloture_abandonne_les_ventes_restees_ouvertes_a_la_caisse_ce_jour_la(): void
    {
        $this->travelTo('2026-10-09 18:00:00');
        $vendeur = User::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 120, 'taux_tva' => 20]);
        PanierCaisse::factory()->avecLeProduit($ecran, 3)->create(['user_id' => $vendeur->id]);
        $this->travelTo('2026-10-10 00:00:30');
        $vendeurDeNuit = User::factory()->create();
        PanierCaisse::factory()->create(['user_id' => $vendeurDeNuit->id]);

        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $evenements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get();
        $this->assertSame(['260', '320', '50'], $evenements->pluck('type')->all());
        $this->assertSame(
            ['origine' => 'cloture', 'journee' => '2026-10-09', 'vendeur_id' => $vendeur->id, 'nombre_lignes' => 1, 'nombre_articles' => 3, 'total_ttc' => 36000],
            $evenements[1]->contenuSigne()['donnees']['details'],
        );
        $this->assertSame([$vendeurDeNuit->id], PanierCaisse::query()->pluck('user_id')->all());
    }

    /**
     * Q8 : une vente restée ouverte dont un prix vient de baisser, sans
     * s'être arrêté, se fige à la clôture de sa journée : la baisse
     * s'inscrit, avec la journée et le vendeur, avant l'abandon de la vente
     * et la clôture elle-même.
     */
    public function test_la_cloture_inscrit_la_baisse_d_un_prix_pas_encore_arrete_avant_d_abandonner_la_vente(): void
    {
        $this->travelTo('2026-10-09 18:00:00');
        $vendeur = User::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 11', 'prix_vente_ttc' => 150, 'taux_tva' => 20]);
        $jeton = (string) Str::uuid();
        $lignes = fn (string $prix): array => ['ligne' => ['produit_id' => $ecran->id, 'description' => $ecran->nom, 'quantite' => 3, 'prix_unitaire_ttc' => $prix, 'taux_tva' => '20', 'remise_pourcentage' => '0']];
        app(PanierDeCaisse::class)->enregistrer($vendeur, $jeton, null, $lignes('150.00'));
        app(PanierDeCaisse::class)->enregistrer($vendeur, $jeton, null, $lignes('120.00'));
        $this->travelTo('2026-10-09 19:00:00');

        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $evenements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get();
        $this->assertSame(['260', '323', '320', '50'], $evenements->pluck('type')->all());
        $this->assertSame(
            ['origine' => 'cloture', 'journee' => '2026-10-09', 'vendeur_id' => $vendeur->id, 'produit_id' => $ecran->id, 'service_id' => null, 'description' => 'Écran iPhone 11', 'quantite' => 3, 'prix_unitaire_ttc' => 15000, 'prix_unitaire_ttc_apres' => 12000],
            $evenements[1]->contenuSigne()['donnees']['details'],
        );
        $this->assertSame(0, PanierCaisse::query()->count());
    }

    /**
     * Point 21 : chaque clôture s'inscrit au journal des événements (JET
     * 50), au nom de son auteur, avec son numéro, sa journée, ses nombres
     * de tickets et d'avoirs, son net, ses encaissements par mode, le
     * crédit d'avoir imputé et les grands totaux, en centimes.
     */
    public function test_chaque_cloture_inscrit_l_evenement_50_avec_son_numero_et_ses_totaux(): void
    {
        $this->journeeDExemple();

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-05');

        $evenement = self::evenementsDeCloture()->sole();
        $this->assertSame([
            'numero' => "Z-{$this->magasin->code}-2026-000001",
            'journee' => '2026-10-05',
            'nombre_ventes' => 3,
            'nombre_avoirs' => 1,
            'total_ht' => 14333,
            'total_tva' => 2722,
            'total_ttc' => 17055,
            'encaissements' => ['especes' => 10000, 'carte_bancaire' => 12055, 'cheque' => 0, 'virement' => 5000, 'prelevement' => 0],
            'credit_avoir_impute' => 0,
            'grands_totaux' => ['perpetuel_reel' => 22055, 'perpetuel_absolu' => 32055],
        ], $evenement->contenuSigne()['donnees']['details']);
        $this->assertSame(['type' => 'cloture_caisse', 'id' => $cloture->id], $evenement->contenuSigne()['document']);
        $this->assertSame(['id' => self::auteur()->id, 'nom' => self::auteur()->name], $evenement->contenuSigne()['operateur']);
    }

    /**
     * Une journée sans aucune opération a son ticket Z et son événement de
     * clôture, à zéro.
     */
    public function test_une_cloture_a_zero_inscrit_aussi_l_evenement_50(): void
    {
        $this->travelTo('2026-10-09 19:00:00');

        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $this->assertSame([
            'numero' => "Z-{$this->magasin->code}-2026-000001",
            'journee' => '2026-10-09',
            'nombre_ventes' => 0,
            'nombre_avoirs' => 0,
            'total_ht' => 0,
            'total_tva' => 0,
            'total_ttc' => 0,
            'encaissements' => ['especes' => 0, 'carte_bancaire' => 0, 'cheque' => 0, 'virement' => 0, 'prelevement' => 0],
            'credit_avoir_impute' => 0,
            'grands_totaux' => ['perpetuel_reel' => 0, 'perpetuel_absolu' => 0],
        ], self::evenementsDeCloture()->sole()->contenuSigne()['donnees']['details']);
    }

    /**
     * Les grands totaux de l'événement sont ceux que le registre signe sur
     * la dernière vente de la journée : 220,55 € de ventes moins 50,00 €
     * d'avoir, plus 50,00 € de facture à un professionnel (réel), et
     * 320,55 € en valeur absolue.
     */
    public function test_l_evenement_50_porte_les_grands_totaux_perpetuels_du_registre(): void
    {
        $this->journeeDExemple();

        app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-05');

        $derniereVente = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->orderByDesc('sequence')->firstOrFail();
        $grandsTotaux = self::evenementsDeCloture()->sole()->contenuSigne()['donnees']['details']['grands_totaux'];
        $this->assertSame(['perpetuel_reel' => 22055, 'perpetuel_absolu' => 32055], $grandsTotaux);
        $this->assertSame([$derniereVente->contenuSigne()['gt']['perpetuel_reel'], $derniereVente->contenuSigne()['gt']['perpetuel_absolu']], array_values($grandsTotaux));
    }

    /**
     * Une clôture refusée n'écrit rien : ni clôture, ni événement.
     */
    public function test_une_cloture_refusee_n_inscrit_aucun_evenement(): void
    {
        $this->travelTo('2026-10-09 19:00:00');

        $this->assertThrows(fn () => app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10'), LogicException::class, "La journée du 10/10/2026 n'est pas encore commencée.");

        $this->assertSame(0, ClotureCaisse::query()->count());
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->count());
    }

    /**
     * Points 15 et 24 : la clôture garde les encaissements de la journée
     * par mode de paiement, factures aux professionnels comprises (270,55
     * € pour 170,55 € de net : le client a gardé le crédit de son avoir).
     */
    public function test_la_cloture_totalise_les_encaissements_de_la_journee_par_mode_de_paiement(): void
    {
        $this->journeeDExemple();

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-05');

        $this->assertSame([
            'especes' => [1, 0, 10000],
            'carte_bancaire' => [2, 0, 12055],
            'cheque' => [0, 0, 0],
            'virement' => [1, 0, 5000],
            'prelevement' => [0, 0, 0],
        ], self::encaissementsDe($cloture));
        $this->assertSame(27055, $cloture->fresh()->totalEncaisseEnCentimes());
        $this->assertSame('170.55', $cloture->fresh()->total_ttc);
    }

    /**
     * Un paiement erroné contre-passé, puis le bon paiement : la
     * contre-passation se déduit du mode du paiement qu'elle annule.
     */
    public function test_une_contre_passation_se_deduit_du_mode_du_paiement_contre_passe(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $facture = $this->emettreFacturePour(Client::factory()->professionnel()->create(), 50);
        $virementErrone = $this->payer($facture, ModePaiement::Virement, '2026-10-09');
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $virementErrone, 'Virement saisi deux fois');
        $this->payer($facture->fresh(), ModePaiement::Cheque, '2026-10-09');
        $this->travelTo('2026-10-09 19:00:00');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $this->assertSame([
            'especes' => [0, 0, 0],
            'carte_bancaire' => [0, 0, 0],
            'cheque' => [1, 0, 5000],
            'virement' => [1, 1, 0],
            'prelevement' => [0, 0, 0],
        ], self::encaissementsDe($cloture));
    }

    /**
     * Un paiement enregistré le soir, après la clôture de sa journée, ne
     * peut plus entrer dans cette clôture : il compte dans la suivante.
     */
    public function test_un_paiement_enregistre_apres_la_cloture_compte_dans_la_cloture_suivante(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $facture = $this->emettreFacturePour(Client::factory()->professionnel()->create(), 50);
        $this->travelTo('2026-10-09 18:00:00');
        $clotureDuJour = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');
        $this->travelTo('2026-10-09 19:00:00');
        $this->payer($facture, ModePaiement::Virement, '2026-10-09');
        $this->travelTo('2026-10-10 19:00:00');

        $clotureDuLendemain = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10');

        $this->assertSame([0, 0, 0], self::encaissementsDe($clotureDuJour)['virement']);
        $this->assertSame([1, 0, 5000], self::encaissementsDe($clotureDuLendemain)['virement']);
    }

    /**
     * Une clôture repart de la dernière séquence d'encaissement de la
     * précédente : un paiement déjà compté ne l'est plus jamais.
     */
    public function test_un_paiement_ne_compte_jamais_dans_deux_clotures(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->vendreUnTicket(Client::factory()->create(), 12, ModePaiement::CarteBancaire);
        $this->travelTo('2026-10-09 19:00:00');
        $premiere = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');
        $this->travelTo('2026-10-10 19:00:00');

        $seconde = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10');

        $this->assertSame([1, 0, 1200], self::encaissementsDe($premiere)['carte_bancaire']);
        $this->assertSame([0, 0, 0], self::encaissementsDe($seconde)['carte_bancaire']);
        $this->assertSame([1, 1], [$premiere->fresh()->derniere_sequence_encaissement, $seconde->fresh()->derniere_sequence_encaissement]);
    }

    /**
     * Un paiement reçu la veille, journée déjà clôturée, mais saisi
     * aujourd'hui compte dans la clôture d'aujourd'hui, jour de son
     * enregistrement : la clôture d'hier ne se rouvre jamais.
     */
    public function test_un_paiement_date_d_un_jour_deja_cloture_compte_le_jour_de_son_enregistrement(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $facture = $this->emettreFacturePour(Client::factory()->professionnel()->create(), 50);
        $this->travelTo('2026-10-09 19:00:00');
        $clotureDuJour = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');
        $this->travelTo('2026-10-10 10:00:00');
        $this->payer($facture, ModePaiement::Cheque, '2026-10-09');
        $this->travelTo('2026-10-10 19:00:00');

        $clotureDuLendemain = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10');

        $this->assertSame([0, 0, 0], self::encaissementsDe($clotureDuJour)['cheque']);
        $this->assertSame([1, 0, 5000], self::encaissementsDe($clotureDuLendemain)['cheque']);
    }

    /**
     * Un crédit d'avoir qui règle une vente ne fait entrer aucun argent :
     * il se montre à part, jamais dans un mode de paiement. Ticket de
     * 50,00 € en espèces, rendu ; puis un ticket de 80,00 € réglé par les
     * 50,00 € de crédit et 30,00 € par carte.
     */
    public function test_le_credit_d_avoir_impute_se_montre_a_part_des_encaissements_par_mode(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $client = Client::factory()->create();
        $rendu = $this->vendreUnTicket($client, 50);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $rendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->vendreUnTicket($client, 80, ModePaiement::CarteBancaire);
        $this->travelTo('2026-10-09 19:00:00');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $this->assertSame([
            'especes' => [1, 0, 5000],
            'carte_bancaire' => [1, 0, 3000],
            'cheque' => [0, 0, 0],
            'virement' => [0, 0, 0],
            'prelevement' => [0, 0, 0],
        ], self::encaissementsDe($cloture));
        $this->assertSame(5000, $cloture->fresh()->recapitulatif['credit_avoir_impute']);
        $this->assertSame('80.00', $cloture->fresh()->total_ttc);
    }

    /**
     * Les ventes et les avoirs aux particuliers gardent chacun leur nombre
     * et leurs montants par taux, les avoirs en négatif : un écran à 60,00
     * € à 20 % et un livre à 10,55 € à 5,5 %, puis le livre rendu.
     */
    public function test_la_cloture_separe_les_ventes_et_les_avoirs_par_taux(): void
    {
        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['prix_vente_ttc' => 10.55, 'taux_tva' => 5.5, 'suivi_stock' => false]);
        $vente = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $ecran->id, 'quantite' => 1], ['produit_id' => $livre->id, 'quantite' => 1]]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $vente->lignes->firstWhere('produit_id', $livre->id)->id, 'quantite' => 1]], 'Retour');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $recapitulatif = $cloture->fresh()->recapitulatif;
        $this->assertSame(['nombre' => 1, 'ht' => 6000, 'tva' => 1055, 'ttc' => 7055, 'par_taux' => [
            ['taux' => 2000, 'ht' => 5000, 'tva' => 1000, 'ttc' => 6000],
            ['taux' => 550, 'ht' => 1000, 'tva' => 55, 'ttc' => 1055],
        ]], $recapitulatif['ventes_particuliers']);
        $this->assertSame(['nombre' => 1, 'ht' => -1000, 'tva' => -55, 'ttc' => -1055, 'par_taux' => [
            ['taux' => 550, 'ht' => -1000, 'tva' => -55, 'ttc' => -1055],
        ]], $recapitulatif['avoirs_particuliers']);
        $this->assertSame(['50.00', '10.00', '60.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
    }

    /**
     * Toutes les ventes entrent dans les clôtures : les factures aux
     * professionnels et leurs avoirs figurent au récapitulatif, pour
     * information, sans jamais entrer dans le net déclaré.
     */
    public function test_les_factures_aux_professionnels_sont_dans_le_recapitulatif_mais_pas_dans_le_net_declare(): void
    {
        $professionnel = Client::factory()->professionnel()->create();
        $this->emettreFacturePour(Client::factory()->create(), 120);
        $facture = $this->emettreFacturePour($professionnel, 60);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $professionnel, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $recapitulatif = $cloture->fresh()->recapitulatif;
        $this->assertSame(['nombre' => 1, 'ht' => 5000, 'tva' => 1000, 'ttc' => 6000, 'par_taux' => [['taux' => 2000, 'ht' => 5000, 'tva' => 1000, 'ttc' => 6000]]], $recapitulatif['factures_professionnels']);
        $this->assertSame(['nombre' => 1, 'ht' => -5000, 'tva' => -1000, 'ttc' => -6000, 'par_taux' => [['taux' => 2000, 'ht' => -5000, 'tva' => -1000, 'ttc' => -6000]]], $recapitulatif['avoirs_professionnels']);
        $this->assertSame([1, 0, '120.00'], [$cloture->nombre_ventes, $cloture->nombre_avoirs, $cloture->total_ttc]);
    }

    /**
     * Exigence 8 du point 15 : la clôture additionne les montants
     * enregistrés des documents, sans recalculer la TVA du total. Deux
     * tickets de 25,00 €, de 20,83 € HT et 4,17 € de TVA chacun, font
     * 41,66 € HT et 8,34 € de TVA, et non 41,67 € et 8,33 €.
     */
    public function test_la_cloture_reprend_les_montants_enregistres_sans_rien_recalculer(): void
    {
        $client = Client::factory()->create();
        $this->vendreUnTicket($client, 25);
        $this->vendreUnTicket($client, 25);

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame(['nombre' => 2, 'ht' => 4166, 'tva' => 834, 'ttc' => 5000, 'par_taux' => [
            ['taux' => 2000, 'ht' => 4166, 'tva' => 834, 'ttc' => 5000],
        ]], $cloture->fresh()->recapitulatif['ventes_particuliers']);
        $this->assertSame(['41.66', '8.34', '50.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
    }

    /**
     * Les grands totaux perpétuels ne repartent jamais de zéro : la
     * clôture du lendemain, où le ticket de la veille est rendu, garde 0,00
     * € de grand total réel et 24,00 € d'absolu.
     */
    public function test_le_recapitulatif_reprend_les_grands_totaux_du_registre(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $client = Client::factory()->create();
        $ticket = $this->vendreUnTicket($client, 12);
        $this->travelTo('2026-10-09 19:00:00');
        $veille = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');
        $this->travelTo('2026-10-10 10:00:00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->travelTo('2026-10-10 19:00:00');

        $lendemain = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10');

        $this->assertSame(['perpetuel_reel' => 1200, 'perpetuel_absolu' => 1200], $veille->fresh()->recapitulatif['grands_totaux']);
        $this->assertSame(['perpetuel_reel' => 0, 'perpetuel_absolu' => 2400], $lendemain->fresh()->recapitulatif['grands_totaux']);
    }

    /**
     * Une journée rattrapée le lendemain matin, après les premières
     * ventes du lendemain, garde les grands totaux de sa propre fin et ne
     * compte que ses propres paiements.
     */
    public function test_une_cloture_rattrapee_garde_les_grands_totaux_et_les_paiements_de_sa_journee(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $client = Client::factory()->create();
        $this->vendreUnTicket($client, 12);
        $this->travelTo('2026-10-10 09:00:00');
        $this->vendreUnTicket($client, 30, ModePaiement::CarteBancaire);
        $this->travelTo('2026-10-10 09:30:00');

        $rattrapee = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');

        $this->assertSame(['perpetuel_reel' => 1200, 'perpetuel_absolu' => 1200], $rattrapee->fresh()->recapitulatif['grands_totaux']);
        $this->assertSame([[1, 0, 1200], [0, 0, 0]], [self::encaissementsDe($rattrapee)['especes'], self::encaissementsDe($rattrapee)['carte_bancaire']]);
    }

    /**
     * Une facture à un professionnel émise le soir, après la clôture de sa
     * journée, compte dans la clôture suivante, jamais dans aucune ou dans
     * deux.
     */
    public function test_une_facture_a_un_professionnel_emise_apres_la_cloture_compte_dans_la_cloture_suivante(): void
    {
        $this->travelTo('2026-10-09 18:00:00');
        $clotureDuJour = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-09');
        $this->travelTo('2026-10-09 19:00:00');
        $this->emettreFacturePour(Client::factory()->professionnel()->create(), 60);
        $this->travelTo('2026-10-10 19:00:00');

        $clotureDuLendemain = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, '2026-10-10');

        $this->assertSame(0, $clotureDuJour->fresh()->recapitulatif['factures_professionnels']['nombre']);
        $this->assertSame([1, 6000], [$clotureDuLendemain->fresh()->recapitulatif['factures_professionnels']['nombre'], $clotureDuLendemain->fresh()->recapitulatif['factures_professionnels']['ttc']]);
    }

    /**
     * Point 23 : un ticket réglé en deux modes compte chaque part dans son
     * mode.
     */
    public function test_un_ticket_paye_en_deux_modes_compte_dans_chacun(): void
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]], new ReglementComptant([
            new PartDeReglement(ModePaiement::Especes, 4000),
            new PartDeReglement(ModePaiement::CarteBancaire, 4000),
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame([[1, 0, 4000], [1, 0, 4000]], [self::encaissementsDe($cloture)['especes'], self::encaissementsDe($cloture)['carte_bancaire']]);
    }

    /**
     * Deux magasins, même journée : chaque clôture ne lit que le registre
     * de son magasin.
     */
    public function test_deux_magasins_ne_melangent_jamais_leurs_encaissements(): void
    {
        $autreMagasin = Magasin::factory()->create();
        $this->vendreUnTicket(Client::factory()->create(), 12);
        app(TenantContext::class)->set($autreMagasin);
        $this->vendreUnTicket(Client::factory()->create(), 60, ModePaiement::CarteBancaire);

        $clotureAutre = app(CloturerCaisseAction::class)->handle(self::auteur(), $autreMagasin, now());
        app(TenantContext::class)->set($this->magasin);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertSame([[1, 0, 1200], [0, 0, 0]], [self::encaissementsDe($cloture)['especes'], self::encaissementsDe($cloture)['carte_bancaire']]);
        $this->assertSame(['perpetuel_reel' => 1200, 'perpetuel_absolu' => 1200], $cloture->fresh()->recapitulatif['grands_totaux']);
        $this->assertSame([[0, 0, 0], [1, 0, 6000]], app(TenantContext::class)->runAs($autreMagasin, fn (): array => [self::encaissementsDe($clotureAutre)['especes'], self::encaissementsDe($clotureAutre)['carte_bancaire']]));
    }

    /**
     * Le récapitulatif s'écrit une seule fois : une clôture faite ne se
     * modifie plus et ne se supprime jamais.
     */
    public function test_une_cloture_faite_ne_se_modifie_plus_ni_ne_se_supprime(): void
    {
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, now());

        $this->assertThrows(
            fn () => $cloture->fresh()->forceFill(['recapitulatif' => ['credit_avoir_impute' => 1]])->save(),
            DonneeFiscaleInalterableException::class,
            'Une clôture de caisse ne se modifie plus : son récapitulatif et ses totaux s\'écrivent une seule fois, à la clôture. Modification refusée : recapitulatif.',
        );
        $this->assertThrows(fn () => $cloture->fresh()->delete(), DonneeFiscaleInalterableException::class, 'Suppression refusée.');
        $this->assertSame(0, $cloture->fresh()->recapitulatif['credit_avoir_impute']);
    }
}
