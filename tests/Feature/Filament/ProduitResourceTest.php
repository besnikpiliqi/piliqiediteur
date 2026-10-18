<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\CreateInventaireOuverture;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le prix de vente d'un produit se saisit et s'enregistre en TTC (voir
 * ProduitResource::prixVenteTtcSchema()) : c'est le prix affiché au client,
 * et c'est lui qu'une ligne de vente reprend. Le HT n'en est que la
 * conversion au centime, affichée sans être enregistrée (voir
 * ProduitResource::detailPrixDeVente()).
 */
class ProduitResourceTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE_REFERENCE_PRISE = 'Cette référence est déjà celle d\'un autre produit, peut-être archivé : affichez les produits archivés pour le restaurer.';

    private function connecterProprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
    }

    /**
     * Le produit naît de la validation de sa facture d'achat, avec le prix
     * de vente TTC saisi sur sa ligne.
     */
    public function test_le_prix_de_vente_ttc_saisi_est_enregistre_tel_quel_et_son_ht_seulement_affiche(): void
    {
        $this->connecterProprietaire();

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Ecran Iphone 18 Pro', 'prix_vente_ttc' => 40, 'taux_tva' => 20], 'quantite' => 1, 'prix_unitaire_ht' => 25],
        ]));

        $produit = Produit::query()->where('nom', 'Ecran Iphone 18 Pro')->firstOrFail();

        $this->assertSame('40.00', $produit->prix_vente_ttc);
        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertSee('Soit 33,33 € HT + 6,67 € de TVA');
    }

    public function test_modifier_le_prix_de_vente_ttc_affiche_son_nouveau_ht(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormSet(['prix_vente_ttc' => '120.00'])
            ->fillForm(['prix_vente_ttc' => 144])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('144.00', $produit->fresh()->prix_vente_ttc);
        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertSee('Soit 120,00 € HT + 24,00 € de TVA');
    }

    public function test_changer_le_taux_de_tva_garde_le_prix_ttc_et_affiche_son_nouveau_ht(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $produit->update(['taux_tva' => 5.5]);

        $this->assertSame('12.00', $produit->fresh()->prix_vente_ttc);
        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertSee('Soit 11,37 € HT + 0,63 € de TVA');
    }

    /**
     * Le taux de TVA a un impact sur la conformité : sans clé de signature,
     * son changement ne peut pas s'inscrire au journal des événements,
     * donc ne s'enregistre pas, et l'écran dit pourquoi.
     */
    public function test_sans_cle_de_signature_le_taux_de_tva_ne_change_pas_et_l_ecran_dit_pourquoi(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['taux_tva' => 5.5])
            ->call('save')
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertSame('20.00', $produit->fresh()->taux_tva);
    }

    /**
     * Reproduit exactement la capture d'écran du magasin : un produit à
     * 40 € TTC choisi sur une ligne de facture, quantité 2, affichait
     * "Total TTC 79,99 €". Le total doit être 80,00 €, à l'écran comme une
     * fois la facture enregistrée.
     */
    public function test_un_produit_a_40_euros_ttc_vendu_par_2_totalise_80_euros_a_lecran_et_en_base(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->create();

        $component = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id]);

        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '40.00')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '33.333333')
            ->assertSee('80,00 €')
            ->assertDontSee('79,99 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('40.00', $ligne->prix_unitaire_ttc);
        $this->assertSame('33.333333', $ligne->prix_unitaire_ht);
        $this->assertSame('80.00', $facture->total_ttc);
        $this->assertSame('66.67', $facture->total_ht);
        $this->assertSame('13.33', $facture->total_tva);
    }

    public function test_le_cmup_est_enregistre_au_dix_millieme(): void
    {
        $this->connecterProprietaire();

        $produit = Produit::factory()->create(['cmup_ht' => 8.33335]);

        $this->assertEquals(8.3334, DB::table('produits')->where('id', $produit->id)->value('cmup_ht'));
    }

    /**
     * Le fournisseur et le coût moyen d'un produit ne se modifient pas dans
     * « Produits » : seuls les achats, les retours et les inventaires les
     * font évoluer. Le coût s'affiche sans se saisir, et une requête forgée
     * qui envoie un autre coût ou un autre fournisseur ne change rien.
     */
    public function test_le_fournisseur_et_le_cout_ne_se_modifient_pas_dans_produits(): void
    {
        $this->connecterProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste du Sud']);
        $autreFournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste du Nord']);
        $produit = Produit::factory()->create(['cmup_ht' => 6.8]);
        $produit->rattacherAuFournisseur($fournisseur->id);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormFieldIsDisabled('cmup_ht')
            ->assertFormSet(['cmup_ht' => '6.80'])
            ->assertSee('Grossiste du Sud')
            ->set('data.cmup_ht', '1.00')
            ->set('data.fournisseur_id', $autreFournisseur->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['6.8000', $fournisseur->id], [$produit->fresh()->cmup_ht, $produit->fresh()->fournisseur_id]);
    }

    /**
     * Un produit reçu sur une facture d'achat affiche le fournisseur chez
     * qui il vient d'être acheté ; un produit jamais acheté n'en a pas, ni
     * un produit que la correction de sa seule facture d'achat validée a
     * retiré.
     */
    public function test_la_fiche_produit_affiche_le_fournisseur_de_sa_facture_dachat(): void
    {
        $this->connecterProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste du Sud']);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit' => ['nom' => 'Câble USB-C', 'prix_vente_ttc' => 10, 'taux_tva' => 20], 'quantite' => 3, 'prix_unitaire_ht' => 0.47],
        ]);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);
        $cable = Produit::query()->sole();

        Livewire::test(EditProduit::class, ['record' => $cable->getRouteKey()])
            ->assertFormSet(['cmup_ht' => '0.47'])
            ->assertSee('Grossiste du Sud')
            ->assertSee('Celui de sa dernière facture d\'achat validée, saisie à la main ou reçue automatiquement.');
        Livewire::test(EditProduit::class, ['record' => Produit::factory()->create()->getRouteKey()])
            ->assertSee('Aucun achat validé');

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, [
            ['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
        ]);

        Livewire::test(EditProduit::class, ['record' => $cable->getRouteKey()])
            ->assertSee('Aucun achat validé')
            ->assertDontSee('Grossiste du Sud');
    }

    /**
     * Ce que le fournisseur dit d'un produit reçu par API (référence, nom,
     * prix d'achat) reste affiché mais figé ; le magasin garde la main sur
     * son prix de vente.
     */
    public function test_un_produit_recu_par_api_garde_ce_que_son_fournisseur_en_dit(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Ecran', 'prix_vente_ttc' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['source' => SourceFactureAchat::Api]);

        $component = Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()]);

        foreach (['reference', 'nom', 'code_barres', 'cmup_ht'] as $champ) {
            $component->assertFormFieldIsDisabled($champ);
        }

        $component
            ->assertFormFieldIsEnabled('prix_vente_ttc')
            ->fillForm(['prix_vente_ttc' => 110])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Ecran', '110.00'], [$produit->fresh()->nom, $produit->fresh()->prix_vente_ttc]);
    }

    public function test_un_produit_saisi_a_la_main_reste_entierement_modifiable(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Ecran']);
        app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormFieldIsEnabled('nom')
            ->fillForm(['nom' => 'Écran OLED'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Écran OLED', $produit->fresh()->nom);
    }

    public function test_une_reference_deja_prise_est_refusee(): void
    {
        $this->connecterProprietaire();
        Produit::factory()->create(['reference' => 'ECR-1']);
        $autre = Produit::factory()->create(['reference' => 'ECR-2', 'nom' => 'Autre écran']);

        Livewire::test(EditProduit::class, ['record' => $autre->getRouteKey()])
            ->fillForm(['reference' => 'ECR-1'])
            ->call('save')
            ->assertHasFormErrors(['reference' => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_REFERENCE_PRISE]]);

        $this->assertSame('ECR-2', $autre->fresh()->reference);
    }

    /**
     * « ABC » et « abc » sont la même référence (voir
     * Produit::normaliserReference()) : la saisie est refusée comme un
     * doublon, au lieu d'échouer sur l'index.
     */
    public function test_une_reference_deja_prise_sous_une_autre_casse_est_refusee(): void
    {
        $this->connecterProprietaire();
        Produit::factory()->create(['reference' => 'ABC']);
        $autre = Produit::factory()->create(['reference' => 'XYZ-1', 'nom' => 'Autre écran']);

        Livewire::test(EditProduit::class, ['record' => $autre->getRouteKey()])
            ->fillForm(['reference' => 'abc'])
            ->call('save')
            ->assertHasFormErrors(['reference' => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_REFERENCE_PRISE]]);

        $this->assertSame(['ABC', 'XYZ-1'], Produit::query()->orderBy('reference')->pluck('reference')->all());
    }

    /**
     * Un autre membre du magasin enregistre « ABC » juste après la
     * validation du formulaire (simulé par un enregistrement direct en
     * base, dès la fin de la requête de validation) : le refus du modèle
     * s'affiche et la saisie reste à l'écran, jamais une page d'erreur.
     */
    public function test_une_reference_prise_juste_apres_la_validation_du_formulaire_est_refusee_par_une_notification(): void
    {
        $this->connecterProprietaire();
        $autre = Produit::factory()->create(['reference' => 'XYZ-1', 'nom' => 'Autre écran']);
        $magasinId = app(TenantContext::class)->id();
        $autreMembreAEnregistre = false;
        DB::listen(static function (QueryExecuted $requete) use (&$autreMembreAEnregistre, $magasinId): void {
            if (! $autreMembreAEnregistre && str_starts_with($requete->sql, 'select') && str_contains($requete->sql, 'reference_normalisee')) {
                $autreMembreAEnregistre = true;
                DB::table('produits')->insert(['magasin_id' => $magasinId, 'reference' => 'ABC', 'reference_normalisee' => 'abc', 'nom' => 'Écran', 'taux_tva' => 20]);
            }
        });

        Livewire::test(EditProduit::class, ['record' => $autre->getRouteKey()])
            ->fillForm(['reference' => 'abc'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('La référence « abc » est déjà celle d\'un autre produit.')
            ->assertFormSet(['reference' => 'abc', 'nom' => 'Autre écran']);

        $this->assertSame(['ABC', 'XYZ-1'], Produit::query()->orderBy('reference')->pluck('reference')->all());
    }

    /**
     * L'unicité ne vaut que dans le magasin : la référence d'un produit
     * d'un autre magasin, sous quelque casse que ce soit, reste libre.
     */
    public function test_une_reference_prise_dans_un_autre_magasin_est_acceptee(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        Produit::factory()->create(['reference' => 'ABC']);
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['reference' => 'XYZ-1', 'nom' => 'Écran']);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['reference' => 'abc'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('produits', ['reference' => 'abc', 'magasin_id' => app(TenantContext::class)->id()]);
    }

    /**
     * La référence d'un produit archivé reste prise, comme dans l'index
     * unique : le message dit où le retrouver. Il contient « : », que
     * Livewire lirait comme le nom d'une règle : il se compare donc aux
     * messages eux-mêmes.
     */
    public function test_la_reference_d_un_produit_archive_est_refusee_avec_un_message_qui_dit_ou_le_retrouver(): void
    {
        $this->connecterProprietaire();
        Produit::factory()->trashed()->create(['reference' => 'ECR-1']);
        $autre = Produit::factory()->create(['reference' => 'ECR-2', 'nom' => 'Autre écran']);

        Livewire::test(EditProduit::class, ['record' => $autre->getRouteKey()])
            ->fillForm(['reference' => 'ECR-1'])
            ->call('save')
            ->assertHasFormErrors(['reference' => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_REFERENCE_PRISE]]);

        $this->assertSame('ECR-2', $autre->fresh()->reference);
    }

    /**
     * Le fournisseur d'un produit est celui de son dernier achat validé :
     * il ne se choisit pas sur la fiche, et un fournisseur glissé dans une
     * requête forgée -- ici celui d'un autre magasin -- est ignoré à la
     * modification.
     */
    public function test_un_fournisseur_force_sur_la_fiche_est_ignore(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $fournisseurDUnAutreMagasin = Fournisseur::factory()->create();
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->set('data.fournisseur_id', $fournisseurDUnAutreMagasin->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($produit->fresh()->fournisseur_id);
    }

    public function test_le_proprietaire_archive_des_produits_en_masse(): void
    {
        $this->connecterProprietaire();
        $produits = Produit::factory()->count(2)->create();

        Livewire::test(ListProduits::class)
            ->callTableBulkAction('archiver', $produits)
            ->assertNotified('Archivés');

        $produits->each(fn (Produit $produit) => $this->assertSoftDeleted($produit));
    }

    /**
     * Sans la permission d'archiver les produits, le vendeur n'a pas le
     * bouton d'archivage en masse, et une requête forgée n'archive rien.
     */
    public function test_un_vendeur_n_archive_pas_de_produits_en_masse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $produits = Produit::factory()->count(2)->create();

        Livewire::test(ListProduits::class)
            ->assertTableBulkActionHidden('archiver')
            ->call('mountTableBulkAction', 'archiver', $produits->modelKeys())
            ->call('callMountedTableBulkAction');

        $produits->each(fn (Produit $produit) => $this->assertNotSoftDeleted($produit));
    }

    /**
     * Le taux se saisit librement (B2) : 19,6 %, qui n'est plus en vigueur
     * en métropole, est signalé sous le champ, et le produit s'enregistre
     * quand même (X8).
     */
    public function test_un_taux_inhabituel_est_signale_et_s_enregistre(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['taux_tva' => '19.6'])
            ->assertSee('Taux inhabituel pour un magasin en métropole (en vigueur : 20 ; 10 ; 5,5 ; 2,1 %) : vérifiez votre saisie.')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('19.60', $produit->fresh()->taux_tva);
    }

    /**
     * Un magasin en franchise en base garde le taux de ses produits, mais
     * la fiche rappelle qu'il vend sans TVA, au prix TTC indiqué (B10).
     */
    public function test_la_fiche_d_un_magasin_en_franchise_rappelle_qu_il_vend_sans_tva(): void
    {
        $this->connecterProprietaire();
        $this->reglerLaSociete(Filament::getTenant(), ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $produit = Produit::factory()->create(['taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertFormSet(['taux_tva' => '20'])
            ->assertSee('Votre société est en franchise en base : vos ventes sont facturées sans TVA (art. 293 B du CGI), au prix TTC indiqué.');
    }

    /**
     * Un taux vidé n'est ni 0 % ni le taux par défaut : à la création d'un
     * produit, sur la ligne d'un inventaire d'ouverture, il est demandé,
     * plutôt que d'échouer sur la base.
     */
    public function test_un_taux_de_tva_vide_est_demande_a_la_creation(): void
    {
        $this->connecterProprietaire();
        $page = Livewire::test(CreateInventaireOuverture::class);
        $cle = array_key_first($page->get('data.lignes'));

        $page
            ->set("data.lignes.{$cle}.produit.nom", 'Écran')
            ->set("data.lignes.{$cle}.produit.prix_vente_ttc", '12')
            ->set("data.lignes.{$cle}.produit.taux_tva", '')
            ->set("data.lignes.{$cle}.quantite", 1)
            ->set("data.lignes.{$cle}.cout_unitaire_ht", '10')
            ->call('create')
            ->assertHasFormErrors(["lignes.{$cle}.produit.taux_tva" => 'required']);

        $this->assertSame(0, InventaireOuverture::query()->count());
        $this->assertSame(0, Produit::query()->count());
    }

    public function test_un_taux_de_tva_vide_est_demande_a_la_modification(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['taux_tva' => ''])
            ->call('save')
            ->assertHasFormErrors(['taux_tva' => 'required']);

        $this->assertSame('20.00', $produit->fresh()->taux_tva);
    }

    /**
     * Le détail HT et TVA du prix de vente ne se calcule pas tant que la
     * TVA est vide.
     */
    public function test_le_detail_du_prix_de_vente_attend_le_taux_de_tva(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['prix_vente_ttc' => '12', 'taux_tva' => '20'])
            ->assertSee('Soit 10,00 € HT + 2,00 € de TVA')
            ->fillForm(['taux_tva' => ''])
            ->assertDontSee('€ de TVA');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function prixHorsLimites(): array
    {
        return [
            'prix de vente négatif' => ['prix_vente_ttc', '-12', 'min'],
            'prix de vente démesuré' => ['prix_vente_ttc', '99999999999', 'max'],
        ];
    }

    /**
     * Le coût d'un nouveau produit se saisit sur la ligne de sa facture
     * d'achat ou de son inventaire d'ouverture (voir
     * InventaireOuvertureResourceTest::test_un_cout_negatif_ou_demesure_est_refuse()).
     */
    #[DataProvider('prixHorsLimites')]
    public function test_un_prix_negatif_ou_demesure_est_refuse(string $champ, string $prix, string $regle): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->fillForm([$champ => $prix])
            ->call('save')
            ->assertHasFormErrors([$champ => $regle]);

        $this->assertSame('12.00', $produit->fresh()->prix_vente_ttc);
    }

    /**
     * Un produit qui ne suit pas son stock n'est ni « En stock » ni
     * « Épuisé », comme dans le widget de la liste -- même s'il gardait une
     * quantité d'avant l'arrêt du suivi.
     */
    public function test_le_statut_du_stock_ne_retient_que_les_produits_suivis(): void
    {
        $this->connecterProprietaire();
        $enStock = Produit::factory()->create(['suivi_stock' => true, 'stock_quantite' => 5]);
        $epuise = Produit::factory()->create(['suivi_stock' => true, 'stock_quantite' => 0]);
        $sansSuiviAZero = Produit::factory()->create(['suivi_stock' => false, 'stock_quantite' => 0]);
        $sansSuiviAvecQuantite = Produit::factory()->create(['suivi_stock' => false, 'stock_quantite' => 3]);

        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'epuise')
            ->assertCanSeeTableRecords([$epuise])
            ->assertCanNotSeeTableRecords([$enStock, $sansSuiviAZero, $sansSuiviAvecQuantite])
            ->filterTable('statut_stock', 'en_stock')
            ->assertCanSeeTableRecords([$enStock])
            ->assertCanNotSeeTableRecords([$epuise, $sansSuiviAZero, $sansSuiviAvecQuantite]);
    }

    /**
     * Archivé depuis sa page de modification, il quitte la liste.
     */
    public function test_le_proprietaire_archive_un_produit_depuis_sa_fiche(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->callAction('archiver')
            ->assertNotified('Produit archivé')
            ->assertRedirect(ProduitResource::getUrl('index'));

        $this->assertSoftDeleted($produit);
    }

    /**
     * Sa référence reste prise (voir Produit::verifierQueSaReferenceEstLibre()) :
     * un produit archivé se retrouve et se restaure depuis la liste.
     */
    public function test_un_produit_archive_se_retrouve_et_se_restaure_depuis_la_liste(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->trashed()->create();

        Livewire::test(ListProduits::class)
            ->assertCanNotSeeTableRecords([$produit])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$produit])
            ->assertTableActionHidden('edit', $produit)
            ->callTableAction('restore', $produit);

        $this->assertNotSoftDeleted($produit);
    }

    /**
     * Sans la permission d'archiver les produits, pas de restauration non
     * plus : ni bouton, ni effet pour une requête forgée.
     */
    public function test_un_vendeur_ne_restaure_pas_un_produit_archive(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));
        Filament::setTenant($magasin);
        $produit = Produit::factory()->trashed()->create();

        Livewire::test(ListProduits::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $produit)
            ->call('mountTableAction', 'restore', (string) $produit->getKey())
            ->call('callMountedTableAction');

        $this->assertSoftDeleted($produit);
    }

    /**
     * Sa page de modification ne trouve plus un produit archivé (voir
     * ProduitPolicy::update()) : sa ligne n'y mène pas.
     */
    public function test_la_ligne_d_un_produit_archive_ne_mene_a_aucune_page(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create();
        $archive = Produit::factory()->trashed()->create();

        $table = Livewire::test(ListProduits::class)
            ->filterTable('trashed', false)
            ->instance()
            ->getTable();

        $this->assertSame(ProduitResource::getUrl('edit', ['record' => $produit]), $table->getRecordUrl($produit));
        $this->assertNull($table->getRecordUrl($archive));
    }

    /**
     * La recherche de produit des devis, des factures et de la caisse (voir
     * ProduitResource::rechercher()) ignore la casse et les accents, sur le
     * nom comme sur la référence.
     */
    public function test_la_recherche_de_produit_a_la_vente_ignore_la_casse_et_les_accents(): void
    {
        $this->connecterProprietaire();
        $ecran = Produit::factory()->create(['nom' => 'Écran OLED', 'reference' => 'ECR-1']);
        $protection = Produit::factory()->create(['nom' => 'Protection', 'reference' => 'Écran-verre']);
        Produit::factory()->create(['nom' => 'Chargeur', 'reference' => 'CHG-1']);

        $selectProduit = collect(DevisResource::ligneSchema())
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'produit_id');

        $resultats = $selectProduit->getSearchResults('ecran');
        ksort($resultats);

        $this->assertSame([$ecran->id => 'Écran OLED (ECR-1)', $protection->id => 'Protection (Écran-verre)'], $resultats);
    }

    /**
     * La recherche de produit des devis, des factures et de la caisse (voir
     * DevisResource::ligneSchema()) ne propose plus un produit archivé.
     */
    public function test_un_produit_archive_n_est_plus_propose_a_la_vente(): void
    {
        $this->connecterProprietaire();
        Produit::factory()->trashed()->create(['nom' => 'Chargeur ancien modèle']);

        $selectProduit = collect(DevisResource::ligneSchema())
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'produit_id');

        $this->assertSame([], $selectProduit->getSearchResults('Chargeur'));
    }

    /**
     * Une requête forgée qui vend malgré tout un produit archivé reçoit un
     * message, plutôt qu'une page d'erreur, et aucune facture n'est créée.
     */
    public function test_une_ligne_forgee_avec_un_produit_archive_est_refusee_avec_un_message(): void
    {
        $this->connecterProprietaire();
        $client = Client::factory()->create();
        $produit = Produit::factory()->trashed()->create(['nom' => 'Chargeur ancien modèle', 'prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ttc' => 24, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Le produit « Chargeur ancien modèle » est archivé : restaurez-le depuis le catalogue, ou retirez-le du document.');

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un produit naît seulement de la validation d'une facture d'achat ou
     * d'un inventaire d'ouverture : la liste des produits n'a plus de
     * bouton « Nouveau », et le dit.
     */
    public function test_la_liste_des_produits_n_a_plus_de_bouton_nouveau(): void
    {
        $this->connecterProprietaire();

        Livewire::test(ListProduits::class)
            ->assertActionDoesNotExist('create')
            ->assertSee('Un produit naît de la validation d\'une facture d\'achat ou d\'un inventaire d\'ouverture');
    }

    /**
     * L'adresse de l'ancienne page de création d'un produit ne mène plus
     * nulle part, même pour le propriétaire.
     */
    public function test_la_page_de_creation_d_un_produit_n_existe_plus(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire)->get("/app/{$magasin->uuid}/produits/create")->assertNotFound();

        $this->assertSame(0, Produit::query()->count());
    }

    /**
     * Le fournisseur du dernier achat reste celui de la fiche, même archivé
     * depuis : affiché, et gardé à l'enregistrement.
     */
    public function test_la_fiche_produit_garde_son_fournisseur_archive(): void
    {
        $this->connecterProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['nom' => 'Écran']);
        $produit->rattacherAuFournisseur($fournisseur->id);
        $fournisseur->delete();

        Livewire::test(EditProduit::class, ['record' => $produit->getRouteKey()])
            ->assertSee('Grossiste fermé')
            ->fillForm(['nom' => 'Écran OLED'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Écran OLED', $fournisseur->id], [$produit->fresh()->nom, $produit->fresh()->fournisseur_id]);
        $this->assertSame('Grossiste fermé', $produit->fresh()->fournisseur->nom);
    }
}
