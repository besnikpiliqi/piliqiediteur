<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\CategorieEReporting;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\ApercuEReporting;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EReportingDesParticuliersTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($this->magasin);
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
    private function journeeDExemple(): ClotureCaisse
    {
        $this->travelTo('2026-10-05 10:00:00');
        $particulier = Client::factory()->create();
        $cable = $this->produit(50);
        $deuxCables = self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 2]], ModePaiement::Especes);
        self::vendreAuComptant(self::auteur(), $particulier, [
            ['produit_id' => $this->produit(10.55, 5.5)->id, 'quantite' => 1],
            $this->reparation(60),
        ], ModePaiement::CarteBancaire);
        self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        $this->payer($this->emettre(Client::factory()->professionnel()->create(), [['produit_id' => $cable->id, 'quantite' => 1]]), 50, ModePaiement::Virement, '2026-10-05');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $particulier, [['ligne_facture_id' => $deuxCables->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->travelTo('2026-10-05 19:00:00');

        return $this->cloturer('2026-10-05');
    }

    private function produit(float $prixTtc, float $taux = 20): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => $taux, 'suivi_stock' => false]);
    }

    /**
     * La ligne d'un service de ce prix, à ce taux.
     *
     * @return array<string, mixed>
     */
    private function reparation(float $prixTtc, float $taux = 20): array
    {
        return ['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => $prixTtc, 'taux_tva' => $taux, 'quantite' => 1];
    }

    /**
     * Une vente émise à ce client, sans règlement à l'émission.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, array $lignes, array $attributs = []): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, $lignes, $attributs));
    }

    private function payer(Facture $facture, float $montant, ModePaiement $mode, string $recuLe): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => $mode->value,
            'montant' => $montant,
            'recu_le' => $recuLe,
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    private function cloturer(string $journee): ClotureCaisse
    {
        return app(CloturerCaisseAction::class)->handle(self::auteur(), $this->magasin, $journee);
    }

    private function apercu(ClotureCaisse $cloture): ApercuEReporting
    {
        return app(EReportingDesParticuliers::class)->apercu($cloture);
    }

    /**
     * Les transactions de l'aperçu, chacune réduite à sa catégorie, son
     * taux, sa base HT et sa TVA.
     *
     * @return list<array{0: CategorieEReporting, 1: int, 2: int, 3: int}>
     */
    private static function totaux(ApercuEReporting $apercu): array
    {
        return array_map(fn (array $transaction): array => [$transaction['categorie'], $transaction['taux'], $transaction['ht'], $transaction['tva']], $apercu->transactions);
    }

    /**
     * Point 26 : les biens à 20 % valent les deux câbles du premier ticket
     * et celui du troisième, moins le câble rendu (83,33 + 41,67 −
     * 41,67) ; le livre à 5,5 % ; la réparation à 20 %. Aucun nom, aucun
     * numéro de ticket, aucun moyen de paiement.
     */
    public function test_une_journee_donne_une_ligne_par_categorie_et_par_taux(): void
    {
        $cloture = $this->journeeDExemple();

        $apercu = $this->apercu($cloture);

        $this->assertSame([
            ['categorie' => CategorieEReporting::LivraisonsDeBiens, 'taux' => 2000, 'ht' => 8333, 'tva' => 1667, 'motif' => null, 'remarque' => null],
            ['categorie' => CategorieEReporting::LivraisonsDeBiens, 'taux' => 550, 'ht' => 1000, 'tva' => 55, 'motif' => null, 'remarque' => null],
            ['categorie' => CategorieEReporting::PrestationsDeServices, 'taux' => 2000, 'ht' => 5000, 'tva' => 1000, 'motif' => null, 'remarque' => null],
        ], $apercu->transactions);
        $this->assertSame(['2026-10-05', false], [$apercu->journee, $apercu->demonstration]);
    }

    /**
     * L'aperçu et la clôture additionnent les mêmes documents enregistrés,
     * sans recalculer la TVA d'un total : avec deux tickets de 25,00 € en
     * plus (20,83 € HT chacun), les biens à 20 % font 124,99 € HT et
     * 25,01 € de TVA, et non 125,00 € et 25,00 €.
     */
    public function test_l_apercu_egale_le_net_de_la_cloture(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $client = Client::factory()->create();
        $billet = $this->produit(25);
        self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $billet->id, 'quantite' => 1]]);
        self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $billet->id, 'quantite' => 1]]);
        $cloture = $this->journeeDExemple();

        $apercu = $this->apercu($cloture);

        $this->assertSame([CategorieEReporting::LivraisonsDeBiens, 2000, 12499, 2501], self::totaux($apercu)[0]);
        $this->assertSame([18499, 3556], [$apercu->totalHt(), $apercu->totalTva()]);
        $this->assertSame(['184.99', '35.56'], [$cloture->total_ht, $cloture->total_tva]);
        $this->assertTrue(app(EReportingDesParticuliers::class)->egaleLeNetDe($apercu, $cloture));
    }

    /**
     * Deux écrans à 60,00 € et un livre à 10,55 €, puis un écran rendu le
     * même jour : seuls les biens à 20 % baissent.
     */
    public function test_un_avoir_du_jour_se_deduit_de_sa_categorie_et_de_son_taux(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        $ecran = $this->produit(60);
        $vente = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $ecran->id, 'quantite' => 2], ['produit_id' => $this->produit(10.55, 5.5)->id, 'quantite' => 1]]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $vente->lignes->firstWhere('produit_id', $ecran->id)->id, 'quantite' => 1]], 'Retour');

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([
            [CategorieEReporting::LivraisonsDeBiens, 2000, 5000, 1000],
            [CategorieEReporting::LivraisonsDeBiens, 550, 1000, 55],
        ], self::totaux($apercu));
    }

    public function test_une_journee_qui_n_a_que_des_avoirs_donne_des_montants_negatifs(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        $vente = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(60)->id, 'quantite' => 1]]);
        $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 10:00:00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $vente->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[CategorieEReporting::LivraisonsDeBiens, 2000, -5000, -1000]], self::totaux($apercu));
        $this->assertTrue($apercu->aDeclarer());
    }

    /**
     * La facture d'une réparation à un professionnel, réglée par virement,
     * puis rendue : ni ses montants, ni son règlement, ni son avoir. Seul
     * le ticket du particulier y est.
     */
    public function test_une_facture_a_un_professionnel_n_entre_jamais_dans_l_apercu(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $professionnel = Client::factory()->professionnel()->create();
        $facture = $this->emettre($professionnel, [$this->reparation(60)]);
        $this->payer($facture, 60, ModePaiement::Virement, '2026-10-05');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $professionnel, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[CategorieEReporting::LivraisonsDeBiens, 2000, 1000, 200]], self::totaux($apercu));
        $this->assertSame([[], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
    }

    /**
     * Rien n'est déclaré pour un jour sans opération : la journée du
     * lendemain d'une vente, clôturée à zéro, n'a rien à déclarer et
     * n'est pas une journée à déclarer.
     */
    public function test_une_journee_sans_operation_n_a_rien_a_declarer(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);
        $avecVente = $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 19:00:00');

        $aZero = $this->cloturer('2026-10-06');

        $this->assertFalse($this->apercu($aZero)->aDeclarer());
        $this->assertSame([], $this->apercu($aZero)->transactions);
        $this->assertSame([$avecVente->id], EReportingDesParticuliers::journeesADeclarer($this->magasin)->pluck('id')->all());
    }

    /**
     * Un écran de 12,00 € expédié par le magasin à un particulier hors de
     * l'Union : vendu sans TVA française, au prix sans la TVA de son taux,
     * il reste à déclarer, parmi les opérations non soumises à la TVA en
     * France, avec son motif ; sa transmission attend la prise en charge
     * de ce motif.
     */
    public function test_une_vente_sans_tva_francaise_se_range_dans_les_operations_non_soumises_en_attente_de_son_motif(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $this->emettre(Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[
            'categorie' => CategorieEReporting::NonSoumisesALaTvaEnFrance,
            'taux' => 0,
            'ht' => 1000,
            'tva' => 0,
            'motif' => 'Export hors UE expédié par le magasin',
            'remarque' => 'À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).',
        ]], $apercu->transactions);
    }

    /**
     * En franchise en base, la vente de 12,00 € se fait à 0 % au même prix
     * TTC : elle se range aussi parmi les opérations non soumises à la TVA
     * en France, une catégorie que les notes du projet disent à vérifier.
     */
    public function test_une_vente_en_franchise_en_base_se_range_dans_les_operations_non_soumises_categorie_a_verifier(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $this->reglerLaSociete($this->magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[
            'categorie' => CategorieEReporting::NonSoumisesALaTvaEnFrance,
            'taux' => 0,
            'ht' => 1200,
            'tva' => 0,
            'motif' => 'Franchise en base de TVA (art. 293 B du CGI)',
            'remarque' => 'Catégorie à vérifier. À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).',
        ]], $apercu->transactions);
    }

    /**
     * Un câble à 10,00 € vendu en France à un taux saisi de 0 % reste une
     * livraison de biens, à déclarer, sa transmission attendant comme
     * toute vente à 0 % la prise en charge de son motif.
     */
    public function test_une_vente_en_france_a_zero_pour_cent_garde_sa_categorie_en_attente_de_son_motif(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(10, 0)->id, 'quantite' => 1]]);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[
            'categorie' => CategorieEReporting::LivraisonsDeBiens,
            'taux' => 0,
            'ht' => 1000,
            'tva' => 0,
            'motif' => 'Vente en France à 0 %',
            'remarque' => 'À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).',
        ]], $apercu->transactions);
    }

    /**
     * Une réparation à 60,00 € à 20 % par carte, un cours à 30,00 € à 10 %
     * en espèces, et un écran : les encaissements des prestations se
     * déclarent par taux, à leur date, l'écran n'en donne aucun, et la
     * fiche les marque en attente de la confirmation de l'expert-comptable.
     */
    public function test_l_encaissement_d_un_service_est_ventile_par_taux_et_marque_a_confirmer(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        self::vendreAuComptant(self::auteur(), $client, [$this->reparation(60)], ModePaiement::CarteBancaire);
        self::vendreAuComptant(self::auteur(), $client, [$this->reparation(30, 10)]);
        self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);
        $cloture = $this->cloturer('2026-10-05');

        $apercu = $this->apercu($cloture);

        $this->assertSame([
            ['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000],
            ['date' => '2026-10-05', 'taux' => 1000, 'montant' => 3000],
        ], $apercu->encaissementsDeServices);
        $this->actingAs(self::auteur());
        Filament::setTenant($this->magasin);
        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSeeInOrder(['Encaissements des prestations de services', 'En attente de la confirmation de l\'expert-comptable de la société.', '05/10/2026', '20 %', '60,00 €', '05/10/2026', '10 %', '30,00 €']);
    }

    /**
     * Q13 : un écran de 50,00 € payé en espèces le 5 et une réparation de
     * 80,00 € laissée due ; le 6, l'écran rendu donne un crédit qui règle
     * 50,00 € de la réparation. Ces 50,00 € comptent parmi les
     * encaissements des prestations le 6, jour où le crédit est utilisé.
     */
    public function test_un_service_regle_par_un_credit_d_avoir_compte_parmi_les_encaissements_a_la_date_de_son_utilisation(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        $ecran = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(50)->id, 'quantite' => 1]]);
        $this->emettre($client, [$this->reparation(80)]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 10:00:00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ecran->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertSame([], $this->apercu($duCinq)->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => 5000]], $this->apercu($duSix)->encaissementsDeServices);
    }

    /**
     * Point 26 : le livre et la réparation du deuxième ticket, payés
     * ensemble par carte à son émission, ne déclarent que les 60,00 € de
     * la réparation ; les câbles, des biens, ne déclarent rien.
     */
    public function test_un_ticket_mixte_paye_en_une_fois_ne_declare_que_la_part_du_service(): void
    {
        $cloture = $this->journeeDExemple();

        $apercu = $this->apercu($cloture);

        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $apercu->encaissementsDeServices);
        $this->assertSame([], $apercu->encaissementsARepartir);
    }

    /**
     * Une réparation de 60,00 € laissée due le 5, puis rendue le 6 : le
     * crédit de l'avoir, imputé sur le ticket qu'il corrige, réduit la
     * vente au lieu de la régler. Rien n'est encaissé.
     */
    public function test_le_credit_d_un_avoir_impute_sur_le_ticket_qu_il_corrige_n_est_pas_un_encaissement(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        $reparation = $this->emettre($client, [$this->reparation(60)]);
        $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 10:00:00');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $reparation->lignes->sole()->id, 'quantite' => 1]], 'Annulée');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[CategorieEReporting::PrestationsDeServices, 2000, -5000, -1000]], self::totaux($apercu));
        $this->assertSame([[], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
    }

    /**
     * Un livre et une réparation laissés dus, puis 30,00 € reçus : la part
     * de la réparation dans ce règlement partiel attend la règle de
     * l'expert-comptable ; le montant reste à répartir, à sa date.
     */
    public function test_un_reglement_partiel_d_un_ticket_mixte_reste_a_repartir(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $mixte = $this->emettre(Client::factory()->create(), [['produit_id' => $this->produit(10.55, 5.5)->id, 'quantite' => 1], $this->reparation(60)]);
        $this->payer($mixte, 30, ModePaiement::Especes, '2026-10-05');

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([], $apercu->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-05', 'montant' => 3000]], $apercu->encaissementsARepartir);
    }

    /**
     * Une réparation de 60,00 € payée par carte le 5, dont le paiement est
     * contre-passé le 6 : l'encaissement se déduit le 6.
     */
    public function test_la_contre_passation_d_un_paiement_de_service_se_deduit_a_sa_date(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [$this->reparation(60)], ModePaiement::CarteBancaire);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 10:00:00');
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $ticket->allocationsPaiement()->sole()->paiement, 'Carte refusée');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duCinq)->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => -6000]], $this->apercu($duSix)->encaissementsDeServices);
    }

    /**
     * Une réparation laissée due le 5 et réglée le 6 sans autre opération
     * ce jour-là : le 6 est à déclarer ; un écran laissé dû et réglé le 7
     * ne rend pas le 7 à déclarer, un bien ne donnant aucun encaissement à
     * déclarer.
     */
    public function test_une_journee_qui_n_encaisse_qu_un_service_est_a_declarer_mais_pas_celle_qui_n_encaisse_qu_un_bien(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $client = Client::factory()->create();
        $reparation = $this->emettre($client, [$this->reparation(60)]);
        $ecran = $this->emettre($client, [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo('2026-10-06 10:00:00');
        $this->payer($reparation, 60, ModePaiement::Especes, '2026-10-06');
        $duSix = $this->cloturer('2026-10-06');
        $this->travelTo('2026-10-07 10:00:00');
        $this->payer($ecran, 12, ModePaiement::Especes, '2026-10-07');

        $duSept = $this->cloturer('2026-10-07');

        $this->assertSame([$duCinq->id, $duSix->id], EReportingDesParticuliers::journeesADeclarer($this->magasin)->orderBy('date_cloture')->pluck('id')->all());
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duSix)->encaissementsDeServices);
        $this->assertFalse($this->apercu($duSept)->aDeclarer());
    }

    /**
     * Le magasin de démonstration n'est jamais déclaré : sa journée, qui a
     * un ticket, n'a rien à déclarer et n'est pas une journée à déclarer.
     */
    public function test_le_magasin_de_demonstration_n_a_rien_a_declarer(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        app(TenantContext::class)->set($demonstration);
        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, now());

        $apercu = $this->apercu($cloture);

        $this->assertSame([1, true, false, []], [$cloture->nombre_ventes, $apercu->demonstration, $apercu->aDeclarer(), $apercu->transactions]);
        $this->assertSame(0, EReportingDesParticuliers::journeesADeclarer($demonstration)->count());
    }

    /**
     * Deux magasins, même journée : l'aperçu ne lit que les tickets, les
     * avoirs et le registre du magasin de la clôture.
     */
    public function test_l_apercu_ne_lit_que_les_documents_du_magasin_de_la_cloture(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $autreMagasin = Magasin::factory()->create();
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [$this->reparation(60)], ModePaiement::CarteBancaire);
        app(TenantContext::class)->set($autreMagasin);
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1], $this->reparation(30, 10)]);
        $clotureAutre = app(CloturerCaisseAction::class)->handle(self::auteur(), $autreMagasin, '2026-10-05');
        app(TenantContext::class)->set($this->magasin);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[CategorieEReporting::PrestationsDeServices, 2000, 5000, 1000]], self::totaux($apercu));
        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $apercu->encaissementsDeServices);
        $this->assertNotContains($clotureAutre->id, EReportingDesParticuliers::journeesADeclarer($this->magasin)->pluck('id')->all());
    }
}
