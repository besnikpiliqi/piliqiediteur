<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\ViewInventaireOuverture;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Filament\App\Widgets\AFaireApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * L'« À faire » du tableau de bord du magasin (demande de l'éditeur du
 * 26/10/2026, voir ChosesAFaireDuMagasin et AFaireApercu) : chaque chose
 * en attente, à faire pour qui fait le geste, à surveiller pour le
 * propriétaire, un administrateur ou un responsable qui la voit sans le
 * faire, chacune menant à sa fiche ou à la liste filtrée qui montre
 * exactement ces documents ; jamais rien d'un autre magasin ni d'une autre
 * société. Les factures à encaisser ont leurs propres tests (voir
 * FacturesAEncaisserTest).
 */
class AFaireDuMagasinTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Q-AF10 (décision de l'éditeur du 01/11/2026) : une facture d'export
     * du 2 octobre sans sa preuve de douane est en orange jusqu'au
     * 1er novembre, en rouge à partir du 2 novembre, en jours du
     * calendrier du magasin ; la preuve notée, la ligne disparaît.
     */
    public function test_la_preuve_d_export_est_en_orange_jusqu_au_30e_jour_puis_en_rouge_a_partir_du_31e(): void
    {
        $this->travelTo(self::aParis('2026-10-02 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $export = $this->venteALExport($responsable);

        $this->travelTo(self::aParis('2026-11-01 23:30:00'));
        $chose = $this->chose($responsable, $magasin, 'preuves');
        $this->assertSame([UrgenceAFaire::Orange, 1, false, 'Ajouter la preuve', FactureResource::getUrl('view', ['record' => $export]), '2026-10-02'], [$chose?->urgence, $chose?->nombre, $chose?->aSurveiller, $chose?->bouton, $chose?->url, $chose?->date]);
        $this->assertStringContainsString('MRN', (string) $chose?->precision);

        $this->travelTo(self::aParis('2026-11-02 00:30:00'));
        $this->assertSame(UrgenceAFaire::Rouge, $this->chose($responsable, $magasin, 'preuves')?->urgence);

        app(TenantContext::class)->runAs($magasin, fn () => app(AjouterPreuveOperationAction::class)->handle($responsable, $export, TypePreuve::Mrn, '26FR000000123456E1', '2026-10-05', 'Douane'));
        $this->assertNull($this->chose($responsable, $magasin, 'preuves'));
    }

    /**
     * Q-AF12 (décision de l'éditeur du 01/11/2026), exemple posé : un
     * export de 500,00 € HT le 20/10, le magasin fermé le 25/10, le MRN
     * reçu et noté le 28/10 : dans le magasin en lecture seule, la ligne
     * garde « Ajouter la preuve », la preuve se note, et la ligne
     * disparaît.
     */
    public function test_la_preuve_d_un_export_se_note_encore_apres_la_fermeture_du_magasin(): void
    {
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 600, 'taux_tva' => 20, 'suivi_stock' => false]);
        $export = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $produit->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
        $this->assertSame('500.00', (string) $export->total_ht);

        $this->travelTo(self::aParis('2026-10-25 18:00:00'));
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');

        $this->travelTo(self::aParis('2026-10-28 09:00:00'));
        $chose = $this->chose($responsable, $magasin, 'preuves');
        $this->assertSame([true, 'Ajouter la preuve', false], [$magasin->fresh()->estEnLectureSeule(), $chose?->bouton, $chose?->aSurveiller]);

        app(AjouterPreuveOperationAction::class)->handle($responsable, $export, TypePreuve::Mrn, '26FR000000123456E1', '2026-10-27', 'Douane');

        $this->assertNull($this->chose($responsable, $magasin, 'preuves'));
    }

    /**
     * Deux ventes à l'export sans preuve mènent à la liste filtrée « Preuves
     * à fournir », qui montre exactement ces deux factures, chacune avec sa
     * raison ; une vente en France n'y est jamais.
     */
    public function test_plusieurs_preuves_menent_a_la_liste_filtree_qui_montre_exactement_ces_factures(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $premiere = $this->venteALExport($responsable);
        $seconde = $this->venteALExport($responsable);
        $enFrance = $this->facture($responsable, 'Atelier Martin');
        $this->dansLeMagasin($magasin, $responsable);

        $chose = $this->chose($responsable, $magasin, 'preuves');

        $this->assertSame([2, FactureResource::urlDesPreuvesAFournir()], [$chose?->nombre, $chose?->url]);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_PREUVES_A_FOURNIR)
            ->assertCanSeeTableRecords([$premiere, $seconde])
            ->assertCanNotSeeTableRecords([$enFrance])
            ->assertTableColumnStateSet('a_faire', ['À encaisser', 'Preuve à fournir'], $premiere);
    }

    /**
     * Règle 5 : un brouillon de facture entre dans l'« À faire » dès sa
     * création, avec sa date ; son auteur l'a à faire, avec « Émettre » et
     * sa fiche, où il l'émet (sa page de modification n'émet pas, règle
     * 2) ; le propriétaire, qui n'émet pas, l'a à surveiller, avec « Voir »
     * et sa fiche. Émis, il en sort.
     */
    public function test_un_brouillon_de_facture_entre_dans_l_a_faire_des_sa_creation(): void
    {
        $this->travelTo(self::aParis('2026-10-21 10:00:00'));
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->dansLeMagasin($magasin, $vendeur);
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $pourLeVendeur = $this->chose($vendeur, $magasin, 'brouillons_de_facture');
        $this->assertSame(['1 brouillon de facture pas encore émis, du 21/10/2026.', false, 'Émettre', FactureResource::getUrl('view', ['record' => $brouillon]), '2026-10-21'], [$pourLeVendeur?->phrase, $pourLeVendeur?->aSurveiller, $pourLeVendeur?->bouton, $pourLeVendeur?->url, $pourLeVendeur?->date]);

        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);
        $pourLeProprietaire = $this->chose($proprietaire, $magasin, 'brouillons_de_facture');
        $this->assertSame([true, 'Voir', FactureResource::getUrl('view', ['record' => $brouillon])], [$pourLeProprietaire?->aSurveiller, $pourLeProprietaire?->bouton, $pourLeProprietaire?->url]);

        $this->dansLeMagasin($magasin, $vendeur);
        $this->get((string) $pourLeVendeur?->url)->assertOk();
        Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])->callAction('emettre')->assertHasNoActionErrors();
        $this->assertNotNull($brouillon->fresh()?->numero);
        $this->assertNull($this->chose($vendeur, $magasin, 'brouillons_de_facture'));
    }

    /**
     * Qui émet les factures sans en créer (un vendeur à qui l'on a coché
     * « émettre » et « voir celles de toute l'équipe », sans « créer »)
     * n'ouvre pas la page de modification d'un brouillon (voir
     * FacturePolicy::update()) : l'« À faire » le mène à sa fiche, qui
     * s'ouvre, sans « Modifier », et où il l'émet.
     */
    public function test_qui_emet_sans_creer_les_factures_ouvre_la_fiche_du_brouillon_et_l_emet(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $emetteur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_EMETTRE]);
        $this->dansLeMagasin($magasin, $responsable);
        $brouillon = app(CreerFactureAction::class)->handle($responsable, Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $chose = $this->chose($emetteur, $magasin, 'brouillons_de_facture');

        $this->assertSame([false, 'Émettre', FactureResource::getUrl('view', ['record' => $brouillon])], [$chose?->aSurveiller, $chose?->bouton, $chose?->url]);
        $this->get((string) $chose?->url)->assertOk();
        $this->get(FactureResource::getUrl('edit', ['record' => $brouillon]))->assertForbidden();
        Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertActionHidden('edit')
            ->callAction('emettre')
            ->assertHasNoActionErrors();
        $this->assertNotNull($brouillon->fresh()?->numero);
    }

    /**
     * Un devis en brouillon seul mène à sa fiche, qui porte son envoi
     * (règle 2) : « Envoyer par e-mail » et « Numéroter sans e-mail », et
     * « Modifier » pour changer sa validité ; numéroté sans e-mail depuis
     * la fiche, il sort de l'« À faire ».
     */
    public function test_un_devis_en_brouillon_seul_s_envoie_depuis_la_fiche_ou_mene_l_a_faire(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $this->dansLeMagasin($magasin, $responsable);
        $devis = app(CreerDevisAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $chose = $this->chose($responsable, $magasin, 'brouillons_de_devis');

        $this->assertSame(['Envoyer', DevisResource::getUrl('view', ['record' => $devis])], [$chose?->bouton, $chose?->url]);
        $this->get((string) $chose?->url)->assertOk();
        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertActionVisible('envoyer_par_email')
            ->assertActionHasUrl('edit', DevisResource::getUrl('edit', ['record' => $devis]))
            ->callAction('envoyer')
            ->assertHasNoActionErrors()
            ->assertActionHidden('envoyer');
        $this->assertSame(StatutDevis::Envoye, $devis->fresh()?->statut);
        $this->assertNull($this->chose($responsable, $magasin, 'brouillons_de_devis'));
    }

    /**
     * Règle 5 : un devis envoyé, valable jusqu'au 31/10 inclus, entre dans
     * l'« À faire » le 24/10, sept jours avant la fin de sa validité, pas
     * le 23/10 ; deux devis qui expirent mènent au filtre de la liste, qui
     * les montre exactement, chacun avec « Expire le … ».
     */
    public function test_un_devis_envoye_entre_sept_jours_avant_la_fin_de_sa_validite(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $devis = $this->devisEnvoye($responsable, '2026-10-31');
        $autre = $this->devisEnvoye($responsable, '2026-11-03');
        $lointain = $this->devisEnvoye($responsable, '2026-12-31');

        $this->travelTo(self::aParis('2026-10-23 12:00:00'));
        $this->assertNull($this->chose($responsable, $magasin, 'devis_qui_expirent'));

        $this->travelTo(self::aParis('2026-10-24 08:00:00'));
        $seul = $this->chose($responsable, $magasin, 'devis_qui_expirent');
        $this->assertSame(["Le devis {$devis->numero} expire le 31/10/2026 sans réponse du client.", DevisResource::getUrl('view', ['record' => $devis])], [$seul?->phrase, $seul?->url]);

        $this->travelTo(self::aParis('2026-10-27 08:00:00'));
        $this->dansLeMagasin($magasin, $responsable);
        $this->assertSame([2, DevisResource::urlDesDevisQuiExpirent()], [$this->chose($responsable, $magasin, 'devis_qui_expirent')?->nombre, $this->chose($responsable, $magasin, 'devis_qui_expirent')?->url]);
        Livewire::test(ListDevis::class)
            ->filterTable('expirent_bientot')
            ->assertCanSeeTableRecords([$devis, $autre])
            ->assertCanNotSeeTableRecords([$lointain])
            ->assertTableColumnStateSet('a_faire', ['Expire le 31/10/2026'], $devis);
    }

    /**
     * Règle 5 : un devis accepté entre tout de suite dans l'« À faire »,
     * à transformer en facture par qui crée les factures (le responsable) ;
     * le propriétaire, qui ne vend pas, l'a à surveiller ; transformé, il
     * en sort.
     */
    public function test_un_devis_accepte_entre_tout_de_suite_et_se_transforme_par_qui_cree_les_factures(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $devis = app(AccepterDevisAction::class)->handle($responsable, $this->devisEnvoye($responsable, null));
        $proprietaire = $this->proprietaireDe($magasin);

        $pourLeResponsable = $this->chose($responsable, $magasin, 'devis_a_transformer');
        $pourLeProprietaire = $this->chose($proprietaire, $magasin, 'devis_a_transformer');

        $this->assertSame([false, 'Transformer en facture', DevisResource::getUrl('view', ['record' => $devis])], [$pourLeResponsable?->aSurveiller, $pourLeResponsable?->bouton, $pourLeResponsable?->url]);
        $this->assertSame([true, 'Voir'], [$pourLeProprietaire?->aSurveiller, $pourLeProprietaire?->bouton]);
        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListDevis::class)->filterTable('a_transformer')->assertCanSeeTableRecords([$devis])->assertTableColumnStateSet('a_faire', ['À transformer en facture'], $devis);
    }

    /**
     * Une facture d'achat en brouillon entre dans l'« À faire » de qui
     * valide les achats, et mène à sa fiche, où « Valider la réception »
     * la valide (règle 2) ; un inventaire d'ouverture en brouillon aussi,
     * à valider avant la première vente, à supprimer après elle, depuis sa
     * fiche, où mène l'« À faire » (sa page de modification est fermée
     * après la vente).
     */
    public function test_les_achats_et_l_inventaire_en_brouillon_attendent_leur_validation(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $achat = app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(['nom' => 'Grossiste Dupont']), [
            ['produit_id' => $this->produit()->id, 'quantite' => 5, 'prix_unitaire_ht' => '4.00', 'taux_tva' => 20],
        ], ['date_facture' => '2026-01-15']);
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle($responsable, [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $this->dansLeMagasin($magasin, $responsable);

        $achats = $this->chose($responsable, $magasin, 'achats_en_brouillon');
        $this->assertSame(['Valider', FactureAchatResource::getUrl('view', ['record' => $achat])], [$achats?->bouton, $achats?->url]);
        $this->assertStringContainsString('Validez-le avant la première vente.', (string) $this->chose($responsable, $magasin, 'inventaires_en_brouillon')?->phrase);
        $this->get((string) $achats?->url)->assertOk();
        Livewire::test(ViewFactureAchat::class, ['record' => $achat->getRouteKey()])
            ->callAction('valider')
            ->assertHasNoActionErrors()
            ->assertActionHidden('valider');
        $this->assertSame(StatutFactureAchat::Validee, $achat->fresh()?->statut);
        $this->assertNull($this->chose($responsable, $magasin, 'achats_en_brouillon'));

        $this->facture($responsable, 'Atelier Martin');
        $inventaires = $this->chose($responsable, $magasin, 'inventaires_en_brouillon');
        $this->assertSame(['Supprimer', InventaireOuvertureResource::getUrl('view', ['record' => $inventaire])], [$inventaires?->bouton, $inventaires?->url]);
        $this->assertStringContainsString('le magasin a déjà vendu', (string) $inventaires?->phrase);
        $this->get((string) $inventaires?->url)->assertOk();
        Livewire::test(ViewInventaireOuverture::class, ['record' => $inventaire->getRouteKey()])
            ->assertActionHidden('valider')
            ->assertActionHidden('edit')
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertRedirect(InventaireOuvertureResource::getUrl('index'));
        $this->assertSame(0, InventaireOuverture::query()->count());
        $this->assertNull($this->chose($responsable, $magasin, 'inventaires_en_brouillon'));
    }

    /**
     * Plusieurs produits en stock négatif, en rouge, ou en stock bas, en
     * orange, mènent à la liste des produits filtrée sur leur statut, qui
     * les montre exactement, chacun avec sa raison sous sa quantité.
     */
    public function test_le_stock_negatif_et_le_stock_bas_de_plusieurs_produits_menent_a_leur_liste_filtree(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $negatif = Produit::factory()->create(['nom' => 'Câble USB', 'suivi_stock' => true]);
        self::vendreJusquAuStockNegatif($negatif, 2);
        $autreNegatif = Produit::factory()->create(['nom' => 'Câble HDMI', 'suivi_stock' => true]);
        self::vendreJusquAuStockNegatif($autreNegatif, 1);
        $bas = Produit::factory()->create(['nom' => 'Chargeur', 'suivi_stock' => true, 'seuil_alerte' => 5]);
        $this->recevoir($responsable, $bas, 3);
        $autreBas = Produit::factory()->create(['nom' => 'Batterie', 'suivi_stock' => true, 'seuil_alerte' => 4]);
        $this->recevoir($responsable, $autreBas, 2);
        $assez = Produit::factory()->create(['nom' => 'Housse', 'suivi_stock' => true, 'seuil_alerte' => 2]);
        $this->recevoir($responsable, $assez, 10);
        $this->dansLeMagasin($magasin, $responsable);

        $this->assertSame([UrgenceAFaire::Rouge, 2, ProduitResource::urlDuStock(ProduitResource::STOCK_NEGATIF)], [$this->chose($responsable, $magasin, 'stock_negatif')?->urgence, $this->chose($responsable, $magasin, 'stock_negatif')?->nombre, $this->chose($responsable, $magasin, 'stock_negatif')?->url]);
        $this->assertSame([2, ProduitResource::urlDuStock(ProduitResource::STOCK_BAS)], [$this->chose($responsable, $magasin, 'stock_bas')?->nombre, $this->chose($responsable, $magasin, 'stock_bas')?->url]);
        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', ProduitResource::STOCK_BAS)
            ->assertCanSeeTableRecords([$bas, $autreBas])
            ->assertCanNotSeeTableRecords([$negatif, $autreNegatif, $assez])
            ->assertSee('À réapprovisionner');
    }

    /**
     * Règle 1 : un seul produit en stock négatif, ou en stock bas, mène à
     * sa fiche pour qui peut l'ouvrir (le responsable) ; pour qui voit les
     * produits et ajuste le stock sans pouvoir ouvrir leur fiche (un
     * vendeur sans « modifier les produits », voir ProduitPolicy::update()),
     * à la liste filtrée, qui le montre seul.
     */
    public function test_un_seul_produit_en_stock_negatif_ou_bas_mene_a_sa_fiche(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $negatif = Produit::factory()->create(['nom' => 'Câble USB', 'suivi_stock' => true]);
        self::vendreJusquAuStockNegatif($negatif, 2);
        $bas = Produit::factory()->create(['nom' => 'Chargeur', 'suivi_stock' => true, 'seuil_alerte' => 5]);
        $this->recevoir($responsable, $bas, 3);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::PRODUIT_AJUSTER_STOCK, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VIEW]);
        $this->dansLeMagasin($magasin, $responsable);

        $this->assertSame([1, ProduitResource::getUrl('edit', ['record' => $negatif])], [$this->chose($responsable, $magasin, 'stock_negatif')?->nombre, $this->chose($responsable, $magasin, 'stock_negatif')?->url]);
        $this->assertSame([1, ProduitResource::getUrl('edit', ['record' => $bas])], [$this->chose($responsable, $magasin, 'stock_bas')?->nombre, $this->chose($responsable, $magasin, 'stock_bas')?->url]);
        $this->get((string) $this->chose($responsable, $magasin, 'stock_negatif')?->url)->assertOk();

        $this->assertSame([false, ProduitResource::urlDuStock(ProduitResource::STOCK_NEGATIF)], [$this->chose($vendeur, $magasin, 'stock_negatif')?->aSurveiller, $this->chose($vendeur, $magasin, 'stock_negatif')?->url]);
        $this->assertSame(ProduitResource::urlDuStock(ProduitResource::STOCK_BAS), $this->chose($vendeur, $magasin, 'stock_bas')?->url);
    }

    /**
     * La caisse ouverte du vendeur et la journée du jour se font toutes
     * seules : en gris, après ce qui attend un geste.
     */
    public function test_ce_qui_se_fera_tout_seul_est_en_gris_apres_le_reste(): void
    {
        $magasin = $this->magasin();
        $responsable = $this->responsable($magasin);
        $this->dansLeMagasin($magasin, $responsable);
        $this->facture($responsable, 'Atelier Martin');

        $aFaire = ChosesAFaireDuMagasin::pour($responsable, $magasin)['a_faire'];
        $cles = array_map(fn (ChoseAFaire $chose): string => $chose->cle, $aFaire);

        $this->assertSame(['a_encaisser', 'journee_du_jour', 'ma_caisse'], array_values(array_intersect($cles, ['a_encaisser', 'ma_caisse', 'journee_du_jour'])));
        $this->assertSame([UrgenceAFaire::Gris, UrgenceAFaire::Gris], [$this->chose($responsable, $magasin, 'ma_caisse')?->urgence, $this->chose($responsable, $magasin, 'journee_du_jour')?->urgence]);
    }

    /**
     * Un e-mail en échec reste dans l'« À faire » de qui peut renvoyer son
     * document, tant qu'aucun envoi plus récent du même document n'a
     * réussi ; un vendeur qui ne voit pas la facture n'en sait rien.
     */
    public function test_un_e_mail_en_echec_reste_tant_qu_aucun_envoi_plus_recent_n_a_reussi(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $facture = $this->facture($responsable, 'Atelier Martin');
        EnvoiDocument::factory()->enEchec()->for($facture, 'document')->create(['destinataire' => 'compta@atelier-martin.test']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $chose = $this->chose($responsable, $magasin, 'emails_en_echec');
        $this->assertSame([1, 'Renvoyer', FactureResource::getUrl('view', ['record' => $facture]), 'compta@atelier-martin.test'], [$chose?->nombre, $chose?->bouton, $chose?->url, $chose?->lignes[0]['description'] ?? null]);
        $this->assertNull($this->chose($vendeur, $magasin, 'emails_en_echec'));

        EnvoiDocument::factory()->envoye()->for($facture, 'document')->create();
        $this->assertNull($this->chose($responsable, $magasin, 'emails_en_echec'));
    }

    /**
     * Un reçu de paiement n'a pas de fiche : l'e-mail en échec d'un reçu
     * mène à la fiche de la facture qu'il règle, dont « Paiements reçus »
     * le renvoie. Avec deux reçus en échec, le tableau de bord s'ouvre,
     * chaque ligne avec son lien, les factures des reçus lues une seule fois
     * pour tous (sans chargement paresseux, qui ferait échouer le test).
     */
    public function test_l_e_mail_en_echec_d_un_recu_de_paiement_mene_a_la_facture_qu_il_regle(): void
    {
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $this->dansLeMagasin($magasin, $responsable);
        $premiere = $this->facture($responsable, 'Atelier Martin');
        $this->encaisser($responsable, $premiere);
        EnvoiDocument::factory()->enEchec()->for(RecuPaiement::query()->sole(), 'document')->create(['destinataire' => 'compta@atelier-martin.test']);

        $chose = $this->chose($responsable, $magasin, 'emails_en_echec');
        $this->assertSame([1, 'Renvoyer', FactureResource::getUrl('view', ['record' => $premiere])], [$chose?->nombre, $chose?->bouton, $chose?->url]);
        $this->assertStringStartsWith('Reçu de paiement RP-', (string) ($chose?->lignes[0]['libelle'] ?? ''));

        $seconde = $this->facture($responsable, 'Hôtel des Pins');
        $this->encaisser($responsable, $seconde);
        EnvoiDocument::factory()->enEchec()->for(RecuPaiement::query()->latest('id')->firstOrFail(), 'document')->create(['destinataire' => 'accueil@hotel-des-pins.test']);

        $chose = $this->chose($responsable, $magasin, 'emails_en_echec');
        $this->assertSame([2, null, [FactureResource::getUrl('view', ['record' => $premiere]), FactureResource::getUrl('view', ['record' => $seconde])]], [$chose?->nombre, $chose?->url, array_column($chose?->lignes ?? [], 'url')]);
        $this->get('/app/'.$magasin->uuid)->assertOk()->assertSee('2 e-mails ne sont pas partis');
    }

    /**
     * Le mot de passe d'un vendeur encore égal à son identifiant : à faire
     * pour lui, dans son profil ; à surveiller pour son responsable ; et,
     * sans aucun poste reconnu, en rouge pour le responsable, qui reconnaît
     * le poste, puisque ce compte ne peut pas se connecter.
     */
    public function test_le_mot_de_passe_encore_identifiant_et_aucun_poste_reconnu(): void
    {
        $magasin = $this->magasin();
        $responsable = $this->responsable($magasin);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Paris']);
        $this->compteAuMotDePasseIdentifiant($autre, RoleMagasin::Vendeur);
        $this->assertNull($this->chose($this->proprietaireDe($magasin), $magasin, 'aucun_poste_reconnu'), 'Le vendeur d\'un autre magasin ne compte pas ici.');

        [$vendeur] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);

        $this->assertSame(['Choisir mon mot de passe', Filament::getProfileUrl()], [$this->chose($vendeur, $magasin, 'mon_mot_de_passe')?->bouton, $this->chose($vendeur, $magasin, 'mon_mot_de_passe')?->url]);
        $this->assertTrue($this->chose($responsable, $magasin, 'mots_de_passe_de_l_equipe')?->aSurveiller);
        $this->assertSame([UrgenceAFaire::Rouge, false], [$this->chose($responsable, $magasin, 'aucun_poste_reconnu')?->urgence, $this->chose($responsable, $magasin, 'aucun_poste_reconnu')?->aSurveiller]);
        $this->assertNull($this->chose($vendeur, $magasin, 'aucun_poste_reconnu'));
    }

    /**
     * Une société de services n'a ni produits, ni stock, ni achats, ni
     * preuves d'export : aucune de ces choses n'existe dans son « À
     * faire », même pour son propriétaire.
     */
    public function test_dans_une_societe_de_services_aucune_ligne_de_stock_d_achat_ni_de_preuve(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);

        $cles = $this->cles($proprietaire, $magasin);

        $this->assertSame([], array_intersect($cles, ['preuves', 'achats_en_brouillon', 'pieces_des_achats', 'pieces_des_avoirs_fournisseur', 'inventaires_en_brouillon', 'stock_negatif', 'stock_bas']));
        $this->assertSame('Aucun service n\'est au catalogue : rien ne peut se vendre.', $this->chose($proprietaire, $magasin, 'catalogue_vide')?->phrase);
    }

    /**
     * Isolation : l'« À faire » d'un magasin ne lit rien d'un autre
     * magasin de la société ni d'une autre société, et ne se lit que pour
     * le magasin actif.
     */
    public function test_l_a_faire_ne_lit_que_le_magasin_actif(): void
    {
        $lyon = $this->magasin();
        $proprietaire = $this->proprietaireDe($lyon);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $ailleurs = $this->magasin();
        app(TenantContext::class)->runAs($paris, fn (): Facture => $this->facture(self::responsableDeTest($paris), 'Client de Paris'));
        app(TenantContext::class)->runAs($ailleurs, fn (): Facture => $this->facture(self::responsableDeTest($ailleurs), 'Client d\'ailleurs'));

        $this->dansLeMagasin($lyon, $proprietaire);
        $this->assertSame([], array_intersect($this->cles($proprietaire, $lyon), ['a_encaisser', 'brouillons_de_facture']));
        Livewire::test(AFaireApercu::class)->assertDontSee(['Client de Paris', 'Client d\'ailleurs']);

        $this->expectException(LogicException::class);
        ChosesAFaireDuMagasin::pour($proprietaire, $paris);
    }

    /**
     * Rien n'attend : l'« À faire » le dit, sans partie « À surveiller ».
     */
    public function test_rien_a_faire_le_dit(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(AFaireApercu::class)->assertSee('Rien à faire pour le moment.')->assertDontSee('À surveiller');
    }

    /**
     * Règle 7 : aucun menu n'affiche plus de nombre, pas même celui des
     * factures quand une preuve manque.
     */
    public function test_aucun_menu_n_affiche_de_nombre(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $this->venteALExport($responsable);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        foreach (Filament::getCurrentPanel()?->getNavigationItems() ?? [] as $item) {
            $this->assertNull($item->getBadge(), "Le menu « {$item->getLabel()} » affiche un nombre.");
        }
    }

    /**
     * Un magasin dont la société peut facturer un professionnel.
     */
    private function magasin(): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1']));
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une facture émise par cet auteur pour un nouveau client professionnel
     * de ce nom, dans le magasin actif.
     */
    private function facture(User $auteur, string $client): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->professionnel()->create(['nom' => $client]), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ]));
    }

    /**
     * Cet auteur encaisse toute cette facture par virement, après son
     * émission, ce qui lui donne un reçu de paiement.
     */
    private function encaisser(User $auteur, Facture $facture): void
    {
        app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client()->firstOrFail(), ['mode_paiement' => ModePaiement::Virement->value, 'montant' => (string) $facture->total_ttc, 'reference' => 'VIR-'.$facture->id, 'recu_le' => $facture->magasin()->firstOrFail()->calendrier()->aujourdhui()], [
            ['facture_id' => $facture->id, 'montant' => (string) $facture->total_ttc],
        ], (string) Str::uuid());
    }

    /**
     * Une vente à Acme Inc., expédiée à New York, émise par cet auteur.
     */
    private function venteALExport(User $auteur): Facture
    {
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $this->produit()->id, 'quantite' => 2]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
    }

    /**
     * Un devis envoyé par cet auteur, valable jusqu'à ce jour (celui par
     * défaut du magasin sans jour).
     */
    private function devisEnvoye(User $auteur, ?string $validite): Devis
    {
        return app(EmettreDevisAction::class)->handle($auteur, app(CreerDevisAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], $validite === null ? [] : ['date_validite' => $validite]));
    }

    /**
     * Une réception validée de cette quantité de ce produit.
     */
    private function recevoir(User $auteur, Produit $produit, int $quantite): void
    {
        app(ValiderFactureAchatAction::class)->handle($auteur, app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => '4.00', 'taux_tva' => 20],
        ], ['date_facture' => '2026-01-15']));
    }

    /**
     * La chose de cette clé dans l'« À faire » de ce compte, dans ce
     * magasin, quelle que soit sa partie ; le compte s'y connecte, et le
     * magasin devient le magasin actif.
     */
    private function chose(User $compte, Magasin $magasin, string $cle): ?ChoseAFaire
    {
        $this->dansLeMagasin($magasin, $compte->fresh() ?? $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte->fresh() ?? $compte, $magasin->fresh() ?? $magasin);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', $cle);
    }

    /**
     * @return list<string>
     */
    private function cles(User $compte, Magasin $magasin): array
    {
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin);

        return array_map(fn (ChoseAFaire $chose): string => $chose->cle, [...$choses['a_faire'], ...$choses['a_surveiller']]);
    }
}
