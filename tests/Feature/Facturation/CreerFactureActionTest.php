<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreerFactureActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_facture_est_creee_en_brouillon_avec_ses_lignes_et_ses_totaux(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertNull($facture->numero);
        $this->assertCount(1, $facture->lignes);
        $this->assertSame('200.00', $facture->total_ht);
        $this->assertSame('40.00', $facture->total_tva);
        $this->assertSame('240.00', $facture->total_ttc);
    }

    /**
     * Un produit est un bien, même si autre chose est envoyé ; un service du
     * magasin est un service. La description est celle du catalogue, jamais
     * celle envoyée avec la ligne.
     */
    public function test_chaque_ligne_sait_si_elle_vend_un_bien_ou_un_service(): void
    {
        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Écran iPhone 13']);
        $mainDOeuvre = Service::factory()->create(['nom' => 'Pose écran']);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'nature' => 'service', 'description' => 'Autre chose', 'quantite' => 1],
            ['service_id' => $mainDOeuvre->id, 'description' => 'Autre chose', 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $lignes = $facture->lignes->sortBy('ordre')->values();

        $this->assertSame([NatureLigne::Bien, NatureLigne::Service], $lignes->pluck('nature')->all());
        $this->assertSame(['Écran iPhone 13', 'Pose écran'], $lignes->pluck('description')->all());
    }

    public function test_une_ligne_sans_produit_ni_service_est_refusee(): void
    {
        $client = Client::factory()->create();

        $this->expectExceptionObject(new InvalidArgumentException('Une ligne vend un produit ou un service du catalogue.'));

        try {
            app(CreerFactureAction::class)->handle(self::auteur(), $client, [
                ['description' => 'Coque', 'prix_unitaire_ttc' => 10, 'taux_tva' => 20, 'quantite' => 1],
            ]);
        } finally {
            $this->assertSame(0, Facture::query()->count());
        }
    }

    /**
     * Une quantité est toujours un nombre entier d'articles : 1,5 est
     * refusé, jamais tronqué en 1 (ce qui fausserait la facture en
     * silence).
     */
    public function test_une_quantite_non_entiere_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité doit être un nombre entier d'articles, au moins 1.");

        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1.5],
        ]);
    }

    public function test_une_quantite_nulle_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20]);

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 0],
        ]);
    }

    public function test_une_facture_sans_lignes_leve_une_exception(): void
    {
        $client = Client::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle(self::auteur(), $client, []);
    }

    public function test_une_remise_reduit_le_montant_ht_de_la_ligne(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 10],
        ]);

        $ligne = $facture->lignes->first();
        $this->assertSame('90.00', $ligne->montant_ht);
        $this->assertSame('18.00', $ligne->montant_tva);
    }

    /**
     * Le taux d'une ligne de produit est celui de sa fiche (C15) : un autre
     * taux envoyé avec la ligne est ignoré.
     */
    public function test_le_taux_d_une_ligne_de_produit_est_celui_de_sa_fiche(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'taux_tva' => 0, 'quantite' => 3],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame(['20.00', '10.00', '30.00', '6.00', '36.00'], [$ligne->taux_tva, $ligne->prix_unitaire_ht, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
    }

    /**
     * Le taux d'un service se saisit ; sans taux, c'est celui du
     * territoire du magasin (B2) : 8,5 % à la Martinique.
     */
    public function test_une_ligne_de_service_sans_taux_prend_celui_du_territoire_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code_postal' => '97200']));
        $client = Client::factory()->create();
        $service = Service::factory()->create();

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => '108.50', 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame(['8.50', '100.00', '8.50', '108.50'], [$ligne->taux_tva, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
    }

    public function test_le_snapshot_de_la_ligne_ne_change_pas_si_le_produit_change_ensuite(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $produit->update(['prix_vente_ttc' => 525, 'taux_tva' => 5]);

        $ligne = $facture->lignes->first()->fresh();
        $this->assertSame('100.00', $ligne->prix_unitaire_ht);
        $this->assertSame('20.00', $ligne->taux_tva);
    }

    /**
     * Une vente part toujours de son PU TTC, quel que soit le client,
     * multiplié par la quantité ; son PU HT en est la conversion au
     * centime, affichée : 40 € TTC x 2 = 80,00 € TTC ; 10,05 € TTC (8,375 €
     * -> 8,38 € HT) x 3 = 30,15 € TTC. Le HT du taux, 110,15 ÷ 1,2 =
     * 91,791... -> 91,79 €, se répartit par cumul : 66,67 € (80,00 ÷ 1,2),
     * puis 25,12 €.
     */
    public function test_une_vente_part_toujours_du_pu_ttc(): void
    {
        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['taux_tva' => 20]);
        $cable = Produit::factory()->create(['taux_tva' => 20]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $coque->id, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20],
            ['produit_id' => $cable->id, 'quantite' => 3, 'prix_unitaire_ttc' => '10.05', 'taux_tva' => 20],
        ]);

        [$ligneCoque, $ligneCable] = $facture->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(['33.33', '40.00'], [$ligneCoque->prix_unitaire_ht, $ligneCoque->prix_unitaire_ttc]);
        $this->assertSame(['66.67', '13.33', '80.00'], [$ligneCoque->montant_ht, $ligneCoque->montant_tva, $ligneCoque->montant_ttc]);
        $this->assertSame(['8.38', '10.05'], [$ligneCable->prix_unitaire_ht, $ligneCable->prix_unitaire_ttc]);
        $this->assertSame(['25.12', '5.03', '30.15'], [$ligneCable->montant_ht, $ligneCable->montant_tva, $ligneCable->montant_ttc]);
        $this->assertSame(['91.79', '18.36', '110.15'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Sans prix saisi, la ligne reprend tel quel le prix de vente TTC
     * enregistré sur la fiche du produit -- arrondi une seule fois, à son
     * enregistrement -- et son PU HT en est la conversion au centime : rien
     * ne se recalcule à la vente.
     */
    public function test_sans_prix_saisi_la_ligne_reprend_tels_quels_les_prix_du_produit(): void
    {
        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12.01, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $coque->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 3],
        ]);

        [$ligneCoque, $ligneCable] = $facture->lignes->sortBy('ordre')->values()->all();

        foreach ([[$ligneCoque, $coque->fresh()], [$ligneCable, $cable->fresh()]] as [$ligne, $produit]) {
            $this->assertSame($produit->prix_vente_ttc, $ligne->prix_unitaire_ttc);
        }

        $this->assertSame(['33.33', '40.00'], [$ligneCoque->prix_unitaire_ht, $ligneCoque->prix_unitaire_ttc]);
        $this->assertSame(['10.01', '12.01'], [$ligneCable->prix_unitaire_ht, $ligneCable->prix_unitaire_ttc]);
        $this->assertSame(['96.69', '19.34', '116.03'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Un même panier a les mêmes montants pour un particulier et pour un
     * professionnel : pour chaque taux, le HT est le TTC de ses lignes ÷
     * (1 + taux), arrondi une fois et réparti par cumul ; la TVA est la
     * différence (point 15, réponse Q1-a). Articles : [PU TTC, taux,
     * quantité, remise] ; montants : [HT, TVA, TTC] de chaque ligne puis du
     * document.
     *
     * - 1 000 x 1,00 € : 1 000 / 1,2 = 833,33 € HT et 166,67 € de TVA,
     *   jamais 1 000 x 0,83 € = 830,00 € HT.
     * - Trois lignes de 0,10 € : 0,30 / 1,2 = 0,25 € HT, répartis 0,08,
     *   0,09 et 0,08 € ; le client paie bien les 0,30 € affichés.
     * - 2 x 49,90 € à 20 % et 3 x 4,20 € à 5,5 % : 83,17 € HT (99,80 / 1,2
     *   = 83,166...) et 11,94 € HT (12,60 / 1,055 = 11,943...).
     * - 7 x 3,99 € à 5,5 % remisés de 10 % : 27,93 € moins 2,79 € de
     *   remise, 25,14 / 1,055 = 23,829... -> 23,83 € HT.
     *
     * @return array<string, array{bool, list<array{string, int|float, int, int}>, list<list<string>>, list<string>}>
     */
    public static function paniersVendusAUnProfessionnelOuAUnParticulier(): array
    {
        return [
            'professionnel, 1 000 x 1,00 € à 20 %' => [true, [['1.00', 20, 1000, 0]], [['833.33', '166.67', '1000.00']], ['833.33', '166.67', '1000.00']],
            'particulier, 1 000 x 1,00 € à 20 %' => [false, [['1.00', 20, 1000, 0]], [['833.33', '166.67', '1000.00']], ['833.33', '166.67', '1000.00']],
            'professionnel, trois lignes de 0,10 € à 20 %' => [true, [['0.10', 20, 1, 0], ['0.10', 20, 1, 0], ['0.10', 20, 1, 0]], [['0.08', '0.02', '0.10'], ['0.09', '0.01', '0.10'], ['0.08', '0.02', '0.10']], ['0.25', '0.05', '0.30']],
            'particulier, trois lignes de 0,10 € à 20 %' => [false, [['0.10', 20, 1, 0], ['0.10', 20, 1, 0], ['0.10', 20, 1, 0]], [['0.08', '0.02', '0.10'], ['0.09', '0.01', '0.10'], ['0.08', '0.02', '0.10']], ['0.25', '0.05', '0.30']],
            'professionnel, 2 x 49,90 € à 20 % et 3 x 4,20 € à 5,5 %' => [true, [['49.90', 20, 2, 0], ['4.20', 5.5, 3, 0]], [['83.17', '16.63', '99.80'], ['11.94', '0.66', '12.60']], ['95.11', '17.29', '112.40']],
            'particulier, 2 x 49,90 € à 20 % et 3 x 4,20 € à 5,5 %' => [false, [['49.90', 20, 2, 0], ['4.20', 5.5, 3, 0]], [['83.17', '16.63', '99.80'], ['11.94', '0.66', '12.60']], ['95.11', '17.29', '112.40']],
            'professionnel, 7 x 3,99 € à 5,5 % remisés de 10 %' => [true, [['3.99', 5.5, 7, 10]], [['23.83', '1.31', '25.14']], ['23.83', '1.31', '25.14']],
            'particulier, 7 x 3,99 € à 5,5 % remisés de 10 %' => [false, [['3.99', 5.5, 7, 10]], [['23.83', '1.31', '25.14']], ['23.83', '1.31', '25.14']],
        ];
    }

    /**
     * @param  list<array{string, int|float, int, int}>  $articles
     * @param  list<list<string>>  $lignesAttendues
     * @param  list<string>  $totauxAttendus
     */
    #[DataProvider('paniersVendusAUnProfessionnelOuAUnParticulier')]
    public function test_un_meme_panier_a_les_memes_montants_pour_un_particulier_et_pour_un_professionnel(bool $professionnel, array $articles, array $lignesAttendues, array $totauxAttendus): void
    {
        $client = $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create();

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->lignesDesArticles($articles));

        $this->assertSame($lignesAttendues, $facture->lignes->map(fn (LigneFacture $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all());
        $this->assertSame($totauxAttendus, [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Le récapitulatif par taux d'une facture donne, pour chaque taux, le
     * HT de son TTC, arrondi une fois, et la TVA par différence : 0,30 ÷
     * 1,2 = 0,25 € HT à 20 %, 12,60 ÷ 1,055 = 11,94 € HT à 5,5 %, les lignes
     * d'un taux n'étant pas consécutives.
     */
    public function test_le_recapitulatif_de_chaque_taux_a_pour_ht_le_ttc_du_taux_arrondi_une_fois(): void
    {
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->lignesDesArticles([
            ['0.10', 20, 1, 0], ['4.20', 5.5, 3, 0], ['0.10', 20, 1, 0], ['0.10', 20, 1, 0],
        ]));

        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 0.25, 'montant_tva' => 0.05, 'montant_ttc' => 0.3],
            ['taux_tva' => '5.50', 'montant_ht' => 11.94, 'montant_tva' => 0.66, 'montant_ttc' => 12.6],
        ], $facture->ventilation_tva);
        $this->assertSame(['12.19', '0.71', '12.90'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * En franchise en base, un professionnel paie le même prix TTC, sans
     * TVA : 1 000 x 1,00 € font 1 000,00 € HT (B10).
     */
    public function test_une_vente_a_un_professionnel_en_franchise_est_sans_tva_au_meme_prix(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => RegimeTva::FranchiseEnBase])->create());

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), $this->lignesDesArticles([['1.00', 20, 1000, 0]]));

        $this->assertSame(['1000.00', '0.00', '1000.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    public function test_une_ligne_de_service_reprend_le_nom_du_service_et_se_calcule_depuis_le_ttc_saisi(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['nom' => 'Main-d\'œuvre']);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => '15.03', 'taux_tva' => 20, 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertNull($ligne->produit_id);
        $this->assertSame('Main-d\'œuvre', $ligne->description);
        $this->assertSame('12.53', $ligne->montant_ht);
        $this->assertSame('2.50', $ligne->montant_tva);
        $this->assertSame('15.03', $ligne->montant_ttc);
    }

    public function test_une_ligne_de_service_sans_prix_saisi_est_refusee(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['service_id' => $service->id, 'taux_tva' => 20, 'quantite' => 1],
        ]);
    }

    public function test_une_ligne_a_la_fois_produit_et_service_est_refusee(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $service = Service::factory()->create();

        try {
            app(CreerFactureAction::class)->handle(self::auteur(), $client, [
                ['produit_id' => $produit->id, 'service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
            ]);
            $this->fail('La ligne aurait dû être refusée.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Une ligne est un produit ou un service, jamais les deux.', $exception->getMessage());
        }

        $this->assertSame(0, Facture::query()->count());
    }

    public function test_un_produit_d_un_autre_magasin_est_introuvable(): void
    {
        $produitAutreMagasin = Produit::factory()->create();
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produitAutreMagasin->id, 'quantite' => 1],
        ]);
    }

    public function test_un_service_d_un_autre_magasin_est_introuvable(): void
    {
        $serviceAutreMagasin = Service::factory()->create();
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['service_id' => $serviceAutreMagasin->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);
    }

    /**
     * Une ligne par article, chacun un produit du magasin à ce prix et à ce
     * taux, sans suivi du stock.
     *
     * @param  list<array{string, int|float, int, int}>  $articles  [PU TTC, taux, quantité, remise]
     * @return list<array{produit_id: int, quantite: int, remise_pourcentage: int}>
     */
    private function lignesDesArticles(array $articles): array
    {
        return array_map(fn (array $article): array => [
            'produit_id' => Produit::factory()->create(['prix_vente_ttc' => $article[0], 'taux_tva' => $article[1], 'suivi_stock' => false])->id,
            'quantite' => $article[2],
            'remise_pourcentage' => $article[3],
        ], $articles);
    }
}
