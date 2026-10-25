<?php

namespace Tests\Feature\Facturation;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
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
use App\Domain\Facturation\Enums\StatutFacture;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 19:00:00'));

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
        $this->travelTo(self::aParis('2026-10-05 09:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $vente = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(60)->id, 'quantite' => 1]]);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);
        $avecVente = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 19:00:00'));

        $aZero = $this->cloturer('2026-10-06');

        $this->assertFalse($this->apercu($aZero)->aDeclarer());
        $this->assertSame([], $this->apercu($aZero)->transactions);
        $this->assertSame([$avecVente->id], app(EReportingDesParticuliers::class)->journeesADeclarer($this->magasin)->pluck('id')->all());
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
     * Un câble à 10,00 € vendu en France à un taux saisi de 0 % : la France
     * n'a pas de taux zéro, une vente sans TVA française se range parmi les
     * opérations non soumises à la TVA en France, à déclarer, sa catégorie
     * restant à vérifier comme celle de la franchise en base.
     */
    public function test_une_vente_en_france_a_zero_pour_cent_se_range_dans_les_operations_non_soumises_categorie_a_verifier(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(10, 0)->id, 'quantite' => 1]]);

        $apercu = $this->apercu($this->cloturer('2026-10-05'));

        $this->assertSame([[
            'categorie' => CategorieEReporting::NonSoumisesALaTvaEnFrance,
            'taux' => 0,
            'ht' => 1000,
            'tva' => 0,
            'motif' => 'Vente en France à 0 %',
            'remarque' => 'Catégorie à vérifier. À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).',
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $ecran = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(50)->id, 'quantite' => 1]]);
        $this->emettre($client, [$this->reparation(80)]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ecran->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertSame([], $this->apercu($duCinq)->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => 5000]], $this->apercu($duSix)->encaissementsDeServices);
    }

    /**
     * Point soumis à l'éditeur, calcul gardé jusqu'à sa réponse : le 5,
     * deux réparations de 60,00 €, l'une payée par carte, l'autre laissée
     * due ; le 6, la première est annulée et son crédit règle la seconde.
     * Le 5 déclare 60,00 € encaissés et le 6 encore 60,00 €, sans déduire
     * l'annulation d'une prestation déjà encaissée ; la fiche le signale.
     */
    public function test_l_avoir_d_une_prestation_deja_encaissee_ne_se_deduit_pas_de_ses_encaissements_en_attendant_l_editeur(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $payee = self::vendreAuComptant(self::auteur(), $client, [$this->reparation(60)], ModePaiement::CarteBancaire);
        $this->emettre($client, [$this->reparation(60)]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $payee->lignes->sole()->id, 'quantite' => 1]], 'Annulée');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duCinq)->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duSix)->encaissementsDeServices);
        $this->actingAs(self::auteur());
        Filament::setTenant($this->magasin);
        Livewire::test(ViewClotureCaisse::class, ['record' => $duSix->getRouteKey()])
            ->assertSee('Point soumis à l\'éditeur : l\'avoir d\'une prestation déjà encaissée ne se déduit pas de ses encaissements, même quand son crédit règle ensuite une autre vente.');
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
     * Une réparation de 60,00 € laissée due le 5, puis rendue le 6 : tout
     * le crédit de l'avoir, imputé sur le ticket qu'il corrige, annule ses
     * propres lignes sur ce ticket ; il réduit la vente au lieu de la
     * régler. Rien n'est encaissé.
     */
    public function test_le_credit_d_un_avoir_impute_sur_le_ticket_qu_il_corrige_n_est_pas_un_encaissement(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $reparation = $this->emettre($client, [$this->reparation(60)]);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $reparation->lignes->sole()->id, 'quantite' => 1]], 'Annulée');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[CategorieEReporting::PrestationsDeServices, 2000, -5000, -1000]], self::totaux($apercu));
        $this->assertSame([[], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
    }

    /**
     * Le 5, un écran de 50,00 € payé en espèces et six heures de service à
     * 10,00 € laissées dues ; le 6, un seul avoir rend l'écran et une heure
     * (60,00 €), dont tout le crédit s'impute sur le service : 10,00 €
     * réduisent la vente, et les 50,00 € venus de l'écran la règlent, le 6.
     */
    public function test_le_credit_d_un_avoir_sur_deux_tickets_ne_retire_des_encaissements_que_ce_qu_il_corrige_du_ticket_regle(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $ecran = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(50)->id, 'quantite' => 1]]);
        $heures = $this->emettre($client, [[...$this->reparation(10), 'quantite' => 6]]);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ecran->lignes->sole()->id, 'quantite' => 1],
            ['ligne_facture_id' => $heures->lignes->sole()->id, 'quantite' => 1],
        ], 'Retour');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[['date' => '2026-10-06', 'taux' => 2000, 'montant' => 5000]], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
        $this->assertSame(StatutFacture::Payee, $heures->fresh()->statut);
    }

    /**
     * Le 5, un écran de 40,00 € et deux réparations de 60,00 €, payés
     * chacun par carte ; le 6, un seul avoir rend l'écran et une
     * réparation, son crédit de 100,00 € reste au client, tous ses tickets
     * étant payés ; le 7, le paiement des réparations est contre-passé, et
     * le crédit s'y réimpute : 60,00 € réduisent la vente, 40,00 € la
     * règlent. Le 7 déclare donc 120,00 € rendus et 40,00 € reçus.
     */
    public function test_le_credit_reimpute_apres_une_contre_passation_compte_au_dela_de_ce_qu_il_corrige(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $ecran = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(40)->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        $reparations = self::vendreAuComptant(self::auteur(), $client, [[...$this->reparation(60), 'quantite' => 2]], ModePaiement::CarteBancaire);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ecran->lignes->sole()->id, 'quantite' => 1],
            ['ligne_facture_id' => $reparations->lignes->sole()->id, 'quantite' => 1],
        ], 'Retour');
        $duSix = $this->cloturer('2026-10-06');
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $reparations->allocationsPaiement()->sole()->paiement, 'Carte refusée');

        $duSept = $this->cloturer('2026-10-07');

        $this->assertSame([], $this->apercu($duSix)->encaissementsDeServices);
        $this->assertSame([[['date' => '2026-10-07', 'taux' => 2000, 'montant' => -8000]], []], [$this->apercu($duSept)->encaissementsDeServices, $this->apercu($duSept)->encaissementsARepartir]);
        $this->assertSame(2000, $reparations->fresh()->resteDuEnCentimes());
    }

    /**
     * Un livre et une réparation laissés dus, puis 30,00 € reçus : la part
     * de la réparation dans ce règlement partiel attend la règle de
     * l'expert-comptable ; le montant reste à répartir, à sa date.
     */
    public function test_un_reglement_partiel_d_un_ticket_mixte_reste_a_repartir(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [$this->reparation(60)], ModePaiement::CarteBancaire);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $ticket->allocationsPaiement()->sole()->paiement, 'Carte refusée');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duCinq)->encaissementsDeServices);
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => -6000]], $this->apercu($duSix)->encaissementsDeServices);
    }

    /**
     * Un livre à 10,55 € et une réparation à 60,00 €, payés ensemble par
     * carte à la caisse le 5, puis le paiement contre-passé le 6 : le 6
     * rend exactement les 60,00 € de la réparation déclarés le 5, et rien
     * ne reste à répartir.
     */
    public function test_la_contre_passation_du_reglement_d_un_ticket_mixte_solde_en_une_fois_rend_la_part_du_service(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(10.55, 5.5)->id, 'quantite' => 1], $this->reparation(60)], ModePaiement::CarteBancaire);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $ticket->allocationsPaiement()->sole()->paiement, 'Carte refusée');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[['date' => '2026-10-06', 'taux' => 2000, 'montant' => -6000]], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
    }

    /**
     * Un livre à 10,55 € et une réparation à 60,00 € laissés dus le 5, puis
     * soldés le 6 par un seul paiement de 70,55 € : la part de la
     * réparation est connue, comme pour un ticket soldé à son émission.
     */
    public function test_un_ticket_mixte_solde_en_un_seul_reglement_apres_son_emission_declare_la_part_du_service(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $mixte = $this->emettre(Client::factory()->create(), [['produit_id' => $this->produit(10.55, 5.5)->id, 'quantite' => 1], $this->reparation(60)]);
        $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $this->payer($mixte, 70.55, ModePaiement::Especes, '2026-10-06');

        $apercu = $this->apercu($this->cloturer('2026-10-06'));

        $this->assertSame([[['date' => '2026-10-06', 'taux' => 2000, 'montant' => 6000]], []], [$apercu->encaissementsDeServices, $apercu->encaissementsARepartir]);
    }

    /**
     * Une réparation de 60,00 € laissée due par un particulier le 5 ; le 6,
     * devenu professionnel, il reçoit une facture de 40,00 € qu'il paie ;
     * le 7, l'avoir de cette facture, fait à un professionnel, règle 40,00 €
     * de la réparation. Le 7 n'a ni vente ni avoir à un particulier, mais
     * cet encaissement le rend à déclarer.
     */
    public function test_une_journee_dont_seul_l_avoir_d_un_professionnel_regle_une_prestation_d_un_particulier_est_a_declarer(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $this->emettre($client, [$this->reparation(60)]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $client->update(['type_client' => TypeClient::Professionnel, 'siren' => '123456789']);
        $facture = $this->emettre($client->fresh(), [['produit_id' => $this->produit(40)->id, 'quantite' => 1]]);
        $this->payer($facture, 40, ModePaiement::Virement, '2026-10-06');
        $this->cloturer('2026-10-06');
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client->fresh(), [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $duSept = $this->cloturer('2026-10-07');

        $this->assertSame([['date' => '2026-10-07', 'taux' => 2000, 'montant' => 4000]], $this->apercu($duSept)->encaissementsDeServices);
        $this->assertSame([$duCinq->id, $duSept->id], app(EReportingDesParticuliers::class)->journeesADeclarer($this->magasin)->orderBy('date_cloture')->pluck('id')->all());
    }

    /**
     * Une réparation laissée due le 5, payée le 6 puis contre-passée le
     * même jour : le 6 n'a rien encaissé, son aperçu n'a rien à déclarer,
     * et il n'est pas une journée à déclarer.
     */
    public function test_une_journee_dont_le_paiement_d_une_prestation_est_contre_passe_le_jour_meme_n_est_pas_a_declarer(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $reparation = $this->emettre(Client::factory()->create(), [$this->reparation(60)]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $paiement = $this->payer($reparation, 60, ModePaiement::Especes, '2026-10-06');
        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Saisie en double');

        $duSix = $this->cloturer('2026-10-06');

        $this->assertFalse($this->apercu($duSix)->aDeclarer());
        $this->assertSame([$duCinq->id], app(EReportingDesParticuliers::class)->journeesADeclarer($this->magasin)->pluck('id')->all());
    }

    /**
     * Une réparation laissée due le 5 et réglée le 6 sans autre opération
     * ce jour-là : le 6 est à déclarer ; un écran laissé dû et réglé le 7
     * ne rend pas le 7 à déclarer, un bien ne donnant aucun encaissement à
     * déclarer.
     */
    public function test_une_journee_qui_n_encaisse_qu_un_service_est_a_declarer_mais_pas_celle_qui_n_encaisse_qu_un_bien(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $reparation = $this->emettre($client, [$this->reparation(60)]);
        $ecran = $this->emettre($client, [['produit_id' => $this->produit(12)->id, 'quantite' => 1]]);
        $duCinq = $this->cloturer('2026-10-05');
        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $this->payer($reparation, 60, ModePaiement::Especes, '2026-10-06');
        $duSix = $this->cloturer('2026-10-06');
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $this->payer($ecran, 12, ModePaiement::Especes, '2026-10-07');

        $duSept = $this->cloturer('2026-10-07');

        $this->assertSame([$duCinq->id, $duSix->id], app(EReportingDesParticuliers::class)->journeesADeclarer($this->magasin)->orderBy('date_cloture')->pluck('id')->all());
        $this->assertSame([['date' => '2026-10-06', 'taux' => 2000, 'montant' => 6000]], $this->apercu($duSix)->encaissementsDeServices);
        $this->assertFalse($this->apercu($duSept)->aDeclarer());
    }

    /**
     * Le magasin de démonstration n'est jamais déclaré : sa journée, qui a
     * un ticket, n'a rien à déclarer et n'est pas une journée à déclarer.
     */
    public function test_le_magasin_de_demonstration_n_a_rien_a_declarer(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        app(TenantContext::class)->set($demonstration);
        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, Calendrier::deParis()->aujourdhui());

        $apercu = $this->apercu($cloture);

        $this->assertSame([1, true, false, []], [$cloture->nombre_ventes, $apercu->demonstration, $apercu->aDeclarer(), $apercu->transactions]);
        $this->assertSame(0, app(EReportingDesParticuliers::class)->journeesADeclarer($demonstration)->count());
    }

    /**
     * Deux magasins, même journée : l'aperçu ne lit que les tickets et les
     * avoirs du magasin de la clôture, et chaque lecture du registre
     * fiscal, celles de l'aperçu comme celle des journées à déclarer, est
     * limitée à ce magasin, sans compter sur les lectures suivantes des
     * documents pour écarter ceux de l'autre magasin.
     */
    public function test_l_apercu_ne_lit_que_les_documents_du_magasin_de_la_cloture(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $autreMagasin = Magasin::factory()->create();
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [$this->reparation(60)], ModePaiement::CarteBancaire);
        app(TenantContext::class)->set($autreMagasin);
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit(12)->id, 'quantite' => 1], $this->reparation(30, 10)]);
        app(CloturerCaisseAction::class)->handle(self::auteur(), $autreMagasin, '2026-10-05');
        app(TenantContext::class)->set($this->magasin);
        $cloture = $this->cloturer('2026-10-05');
        $lectures = [];
        DB::listen(function (QueryExecuted $requete) use (&$lectures): void {
            if (str_contains($requete->sql, '"enregistrements_fiscaux"')) {
                $lectures[] = $requete;
            }
        });

        $apercu = $this->apercu($cloture);
        $lecturesDeLApercu = count($lectures);
        $journees = app(EReportingDesParticuliers::class)->journeesADeclarer($this->magasin)->pluck('id')->all();

        $this->assertSame([[CategorieEReporting::PrestationsDeServices, 2000, 5000, 1000]], self::totaux($apercu));
        $this->assertSame([['date' => '2026-10-05', 'taux' => 2000, 'montant' => 6000]], $apercu->encaissementsDeServices);
        $this->assertSame([$cloture->id], $journees);
        $this->assertGreaterThan(0, $lecturesDeLApercu);
        $this->assertGreaterThan($lecturesDeLApercu, count($lectures));
        foreach ($lectures as $lecture) {
            $this->assertTrue(self::limiteLeRegistreAuMagasin($lecture, $this->magasin->id), $lecture->sql);
        }
    }

    /**
     * Cette requête limite-t-elle chacune de ses lectures du registre
     * fiscal à ce magasin ? Chaque table du registre qu'elle lit compare sa
     * colonne magasin_id à l'identifiant du magasin, ou à la colonne
     * magasin_id d'une clôture, et chaque identifiant de magasin qu'elle
     * lie, pour le registre comme pour les clôtures, est celui-là.
     */
    private static function limiteLeRegistreAuMagasin(QueryExecuted $requete, int $magasinId): bool
    {
        preg_match_all('/"(?:enregistrements_fiscaux|clotures_caisse)"\."magasin_id" = \?/', $requete->sql, $filtres, PREG_OFFSET_CAPTURE);
        $magasins = array_map(fn (array $filtre): int => (int) $requete->bindings[substr_count(substr($requete->sql, 0, $filtre[1]), '?')], $filtres[0]);

        return substr_count($requete->sql, 'from "enregistrements_fiscaux"') === substr_count($requete->sql, '"enregistrements_fiscaux"."magasin_id" = ')
            && $magasins !== []
            && array_values(array_unique($magasins)) === [$magasinId];
    }
}
