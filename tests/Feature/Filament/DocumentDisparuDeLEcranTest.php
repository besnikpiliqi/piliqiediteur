<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClientResource\Pages\EditClient;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\FournisseurResource\Pages\EditFournisseur;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\EditInventaireOuverture;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\ListInventairesOuverture;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\ViewInventaireOuverture;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un écran du magasin resté ouvert pendant qu'une autre personne supprime
 * son document (un brouillon de facture, de facture d'achat, de devis ou
 * d'inventaire d'ouverture), comme le fait sa liste : deux onglets, ou deux
 * personnes. Au clic suivant, la page du document revient à la liste, qui
 * dit que ce document n'existe pas, ou plus, dans ce magasin, et la
 * fenêtre ouverte sur une ligne de la liste se ferme en le disant ; jamais
 * une page introuvable ni une erreur du serveur, et rien ne s'exécute.
 *
 * La page du document passe par une vraie requête Livewire, comme celle
 * du navigateur (voir requeteLivewire()) : Livewire relit le document
 * avant tout code de la page, et seul le gestionnaire des exceptions de
 * l'application rattrape son absence (voir ReponseAUnDocumentDisparu).
 *
 * De même pour la page de modification d'une fiche (un client, un
 * fournisseur, un produit) qu'une autre personne archive pendant qu'elle
 * est ouverte : son enregistrement est refusé, avec un message qui dit
 * de restaurer la fiche, la saisie restant à l'écran ; jamais une page
 * interdite, et rien ne s'écrit (voir EnregistreLesCorrectionsDeLaFiche).
 */
class DocumentDisparuDeLEcranTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->responsable = self::responsableDeTest($this->magasin);
        $this->actingAs($this->responsable);
    }

    /**
     * La page du document, le document, et le geste qu'on y fait, avec ses
     * paramètres.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<string>}>
     */
    public static function pagesEtGestes(): array
    {
        return [
            'enregistrer un brouillon de facture' => [EditFacture::class, 'facture', 'save', []],
            'supprimer un brouillon de facture depuis sa page' => [EditFacture::class, 'facture', 'mountAction', ['delete']],
            'enregistrer un brouillon de facture d\'achat' => [EditFactureAchat::class, 'factureAchat', 'save', []],
            'supprimer un brouillon de facture d\'achat depuis sa page' => [EditFactureAchat::class, 'factureAchat', 'mountAction', ['delete']],
            'enregistrer un inventaire d\'ouverture' => [EditInventaireOuverture::class, 'inventaire', 'save', []],
            'valider un inventaire d\'ouverture depuis sa fiche' => [ViewInventaireOuverture::class, 'inventaire', 'mountAction', ['valider']],
            'enregistrer un devis' => [EditDevis::class, 'devis', 'save', []],
            'supprimer un devis depuis sa page' => [EditDevis::class, 'devis', 'mountAction', ['delete']],
            'envoyer un devis depuis sa fiche' => [ViewDevis::class, 'devis', 'mountAction', ['envoyer_par_email']],
        ];
    }

    /**
     * @param  class-string  $page
     * @param  list<string>  $parametres
     */
    #[DataProvider('pagesEtGestes')]
    public function test_un_geste_sur_la_page_d_un_document_supprime_entre_temps_revient_a_la_liste_qui_dit_pourquoi(string $page, string $document, string $geste, array $parametres): void
    {
        [$enregistrement, $ressource, $supprimer, $refus] = $this->{$document}();
        $cle = array_search($page, array_map(fn ($inscription) => $inscription->getPage(), $ressource::getPages()), true);
        $ecran = $this->get($ressource::getUrl($cle, ['record' => $enregistrement], tenant: $this->magasin))->assertOk()->getContent();
        $instantane = $this->instantaneLivewire($ecran, app(ComponentRegistry::class)->getName($page));
        $supprimer();

        $reponse = $this->requeteLivewire($instantane, [], $geste, $parametres);

        $liste = $ressource::getUrl('index', tenant: $this->magasin);
        $reponse->assertOk()->assertJsonPath('components.0.effects.redirect', $liste);
        $this->assertSame($instantane, $reponse->json('components.0.snapshot'));
        $this->get($liste)->assertOk()->assertSee($refus);
    }

    /**
     * Hors d'une requête Livewire, l'adresse d'un document supprimé reste
     * une page introuvable, comme celle d'un document qui n'a jamais
     * existé.
     */
    public function test_l_adresse_d_un_brouillon_supprime_reste_introuvable(): void
    {
        [$brouillon, $ressource, $supprimer] = $this->facture();
        $supprimer();

        $this->get($ressource::getUrl('edit', ['record' => $brouillon], tenant: $this->magasin))->assertNotFound();
    }

    /**
     * La liste, le document d'une de ses lignes, et le bouton de cette
     * ligne, qui ouvre une fenêtre.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function listesEtBoutons(): array
    {
        return [
            'supprimer un brouillon de facture' => [ListFactures::class, 'facture', 'delete'],
            'valider un brouillon de facture d\'achat' => [ListFacturesAchat::class, 'factureAchat', 'valider'],
            'supprimer un inventaire d\'ouverture' => [ListInventairesOuverture::class, 'inventaire', 'delete'],
            'numéroter un devis' => [ListDevis::class, 'devis', 'envoyer'],
        ];
    }

    /**
     * La fenêtre de confirmation ouverte sur la ligne, le document supprimé
     * avant la confirmation : la fenêtre se ferme, et la liste le dit.
     *
     * @param  class-string  $liste
     */
    #[DataProvider('listesEtBoutons')]
    public function test_la_fenetre_ouverte_sur_une_ligne_supprimee_entre_temps_se_ferme_et_la_liste_le_dit(string $liste, string $document, string $bouton): void
    {
        Filament::setTenant($this->magasin);
        [$enregistrement, , $supprimer] = $this->{$document}();
        $ecran = Livewire::test($liste)->mountTableAction($bouton, $enregistrement)->assertTableActionMounted($bouton);
        $supprimer();

        $ecran->callMountedTableAction()
            ->assertHasNoErrors()
            ->assertNotified($liste::LIGNE_DISPARUE)
            ->assertSet('mountedTableActions', [])
            ->assertSet('mountedTableActionRecord', null);
    }

    /**
     * Le bouton d'une ligne affichée, cliqué après la suppression de son
     * document (l'aide mountTableAction() de Filament ne clique pas sur une
     * ligne absente : le clic passe par la requête du navigateur) : aucune
     * fenêtre ne s'ouvre, et la liste le dit.
     *
     * @param  class-string  $liste
     */
    #[DataProvider('listesEtBoutons')]
    public function test_le_bouton_d_une_ligne_supprimee_entre_temps_le_dit(string $liste, string $document, string $bouton): void
    {
        Filament::setTenant($this->magasin);
        [$enregistrement, , $supprimer] = $this->{$document}();
        $ecran = Livewire::test($liste)->assertCanSeeTableRecords([$enregistrement]);
        $supprimer();

        $ecran->call('mountTableAction', $bouton, (string) $enregistrement->getKey())
            ->assertNotified($liste::LIGNE_DISPARUE)
            ->assertSet('mountedTableActions', []);
    }

    /**
     * La page de modification de la fiche, sa ressource, la fiche, et la
     * correction qu'on y saisit.
     *
     * @return array<string, array{0: class-string, 1: class-string, 2: Closure(): Model, 3: string, 4: string}>
     */
    public static function fichesArchiveesEntreTemps(): array
    {
        return [
            'un client' => [EditClient::class, ClientResource::class, static fn (): Model => Client::factory()->create(['nom' => 'Martin', 'telephone' => '01 02 03 04 05']), 'telephone', '04 72 00 00 00'],
            'un fournisseur' => [EditFournisseur::class, FournisseurResource::class, static fn (): Model => Fournisseur::factory()->create(['nom' => 'Grossiste', 'telephone' => '01 02 03 04 05']), 'telephone', '04 72 00 00 00'],
            'un produit' => [EditProduit::class, ProduitResource::class, static fn (): Model => Produit::factory()->create(['nom' => 'Coque rouge']), 'nom', 'Coque bleue'],
        ];
    }

    /**
     * @param  class-string  $page
     * @param  class-string  $ressource
     * @param  Closure(): Model  $creer
     */
    #[DataProvider('fichesArchiveesEntreTemps')]
    public function test_l_enregistrement_d_une_fiche_archivee_entre_temps_est_refuse_et_le_dit(string $page, string $ressource, Closure $creer, string $champ, string $correction): void
    {
        $fiche = $creer();
        $avant = $fiche->getAttribute($champ);
        $ecran = $this->get($ressource::getUrl('edit', ['record' => $fiche], tenant: $this->magasin))->assertOk()->getContent();
        $instantane = $this->instantaneLivewire($ecran, app(ComponentRegistry::class)->getName($page));
        app(ArchiverUneFicheAction::class)->archiver($this->responsable, $fiche->fresh());

        $reponse = $this->requeteLivewire($instantane, ["data.{$champ}" => $correction], 'save');

        $reponse->assertOk()->assertJsonMissingPath('components.0.effects.redirect');
        $this->assertSame([ModifierUneFicheAction::REFUS_ARCHIVEE], collect(session('filament.notifications'))->pluck('title')->all());
        $this->assertTrue($fiche->fresh()->trashed());
        $this->assertSame($avant, $fiche->fresh()->getAttribute($champ));
    }

    /**
     * Un brouillon de facture, sa ressource, sa suppression par une autre
     * requête, et ce que dit l'écran.
     *
     * @return array{0: Model, 1: class-string, 2: Closure(): void, 3: string}
     */
    private function facture(): array
    {
        $brouillon = app(CreerFactureAction::class)->handle($this->responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return [$brouillon, FactureResource::class, fn () => app(SupprimerFactureAction::class)->handle($this->responsable, Facture::query()->findOrFail($brouillon->getKey())), Facture::REFUS_INTROUVABLE];
    }

    /**
     * @return array{0: Model, 1: class-string, 2: Closure(): void, 3: string}
     */
    private function factureAchat(): array
    {
        $brouillon = app(CreerFactureAchatAction::class)->handle($this->responsable, Fournisseur::factory()->create(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
        ]);

        return [$brouillon, FactureAchatResource::class, fn () => app(SupprimerFactureAchatAction::class)->handle($this->responsable, FactureAchat::query()->findOrFail($brouillon->getKey())), FactureAchat::REFUS_INTROUVABLE];
    }

    /**
     * @return array{0: Model, 1: class-string, 2: Closure(): void, 3: string}
     */
    private function inventaire(): array
    {
        $brouillon = app(CreerInventaireOuvertureAction::class)->handle($this->responsable, [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);

        return [$brouillon, InventaireOuvertureResource::class, fn () => app(SupprimerInventaireOuvertureAction::class)->handle($this->responsable, InventaireOuverture::query()->findOrFail($brouillon->getKey())), InventaireOuverture::REFUS_INTROUVABLE];
    }

    /**
     * Un devis brouillon, que sa suppression garde en base.
     *
     * @return array{0: Model, 1: class-string, 2: Closure(): void, 3: string}
     */
    private function devis(): array
    {
        $brouillon = app(CreerDevisAction::class)->handle($this->responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return [$brouillon, DevisResource::class, fn () => app(SupprimerDevisAction::class)->handle($this->responsable, Devis::query()->findOrFail($brouillon->getKey())), Devis::REFUS_INTROUVABLE];
    }

    /**
     * Un produit vendu 12,00 € TTC, sans suivi de stock.
     */
    private function produit(): Produit
    {
        return Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
    }
}
