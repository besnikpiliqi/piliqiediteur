<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\SoumissionEReportingResource;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Resources\Resource as RessourceFilament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Isolation des magasins sur les modèles qui utilisent BelongsToMagasin.
 *
 * Sans magasin actif, toute lecture échoue au lieu de tout renvoyer : vérifié
 * sur chaque modèle de magasin (modelesDeMagasin()). Un magasin ne lit ni ne
 * trouve par son identifiant un enregistrement d'un autre magasin, et un
 * enregistrement créé avec le magasin_id d'un autre magasin reste rattaché
 * au magasin actif : vérifié sur chaque modèle que ces tests créent
 * (modelesCrees()).
 *
 * Un nouveau modèle de magasin qui n'apparaît ni dans MODELES_AVEC_FABRIQUE
 * ni dans MODELES_SANS_FABRIQUE fait échouer
 * test_chaque_modele_de_magasin_est_liste_ici().
 *
 * Côté panneau, chaque ressource dont un enregistrement a sa propre URL
 * répond 404 à un membre d'un autre magasin, sans révéler que
 * l'enregistrement existe.
 *
 * Deux magasins d'une même société restent isolés de la même façon : un
 * retour ou un paiement se fait dans le magasin qui a vendu (J6). Seuls
 * l'identité légale et les réglages fiscaux leur sont communs, portés par
 * leur société (voir IsolationDesSocietesTest).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modèles de magasin qui ont une fabrique.
     *
     * @var list<class-string<Model>>
     */
    private const MODELES_AVEC_FABRIQUE = [
        AvoirFournisseur::class,
        Client::class,
        ClotureCaisse::class,
        ClotureCaissePersonnelle::class,
        Devis::class,
        EnvoiDocument::class,
        Facture::class,
        FactureAchat::class,
        Fournisseur::class,
        PanierCaisse::class,
        Produit::class,
        Service::class,
    ];

    /**
     * Modèles de magasin qui n'ont pas de fabrique : les documents fiscaux
     * et le registre ne s'écrivent que signés, par leurs actions, la trace
     * d'un événement technique qu'avec lui, et une vérification VIES, une
     * preuve d'exonération ou un bordereau de détaxe, que par la sienne.
     * Avoir, AvoirAllocation, BordereauDetaxe, ConsultationVies,
     * EnregistrementFiscal, Paiement, PaiementAllocation, PreuveOperation,
     * SoumissionEReporting, TeteRegistreFiscal et TraceEvenement sont aussi
     * dans modelesCrees(), écrits directement par ces tests (voir
     * creerEnregistrement()).
     *
     * @var list<class-string<Model>>
     */
    private const MODELES_SANS_FABRIQUE = [
        Avoir::class,
        AvoirAllocation::class,
        BordereauDetaxe::class,
        ConsultationVies::class,
        EnregistrementFiscal::class,
        LigneAchat::class,
        LigneAvoir::class,
        LigneAvoirFournisseur::class,
        LigneDevis::class,
        LigneFacture::class,
        MouvementStock::class,
        Paiement::class,
        PaiementAllocation::class,
        PreuveOperation::class,
        SoumissionEReporting::class,
        TeteRegistreFiscal::class,
        TraceEvenement::class,
        TransmissionPdp::class,
    ];

    private Magasin $magasinA;

    private Magasin $magasinB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasinA = Magasin::factory()->create();
        $this->magasinB = Magasin::factory()->create();
    }

    /**
     * Tous les modèles de magasin, pour les tests qui ne créent aucun
     * enregistrement.
     *
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function modelesDeMagasin(): array
    {
        return collect([...self::MODELES_AVEC_FABRIQUE, ...self::MODELES_SANS_FABRIQUE])
            ->mapWithKeys(static fn (string $modele): array => [class_basename($modele) => [$modele]])
            ->all();
    }

    /**
     * Les modèles de magasin que ces tests créent, chacun avec la création
     * d'un enregistrement aux attributs imposés, même hors $fillable comme
     * le permet une fabrique : par sa fabrique, ou par
     * creerEnregistrement() avec les attributs qu'exige sa table.
     *
     * @return array<string, array{0: class-string<Model>, 1: Closure(array<string, mixed>=): Model}>
     */
    public static function modelesCrees(): array
    {
        return collect(self::MODELES_AVEC_FABRIQUE)
            ->mapWithKeys(static fn (string $modele): array => [class_basename($modele) => [
                $modele,
                static fn (array $attributs = []): Model => $modele::factory()->create($attributs),
            ]])
            ->merge(collect([Avoir::class, AvoirAllocation::class, BordereauDetaxe::class, ConsultationVies::class, EnregistrementFiscal::class, Paiement::class, PaiementAllocation::class, PreuveOperation::class, SoumissionEReporting::class, TeteRegistreFiscal::class, TraceEvenement::class])
                ->mapWithKeys(static fn (string $modele): array => [class_basename($modele) => [
                    $modele,
                    static fn (array $attributs = []): Model => self::creerEnregistrement($modele, $attributs),
                ]]))
            ->all();
    }

    public function test_chaque_modele_de_magasin_est_liste_ici(): void
    {
        $modelesListes = [...self::MODELES_AVEC_FABRIQUE, ...self::MODELES_SANS_FABRIQUE];
        sort($modelesListes);

        $this->assertSame($this->modelesQuiUtilisentBelongsToMagasin(), $modelesListes);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  Closure(array<string, mixed>=): Model  $creer
     */
    #[DataProvider('modelesCrees')]
    public function test_un_magasin_ne_voit_pas_les_enregistrements_dun_autre(string $modelClass, Closure $creer): void
    {
        $contexte = app(TenantContext::class);

        $contexte->set($this->magasinA);
        $enregistrementA = $creer();

        $contexte->set($this->magasinB);
        $enregistrementB = $creer();

        // Sous le contexte B, seule la liste de B doit apparaître.
        $ids = $modelClass::query()->pluck($enregistrementB->getKeyName())->all();
        $this->assertSame([$enregistrementB->getKey()], $ids);

        // Un find() direct sur l'ID de A doit échouer comme si l'enregistrement n'existait pas.
        $this->assertNull($modelClass::find($enregistrementA->getKey()));

        $this->expectException(ModelNotFoundException::class);
        $modelClass::findOrFail($enregistrementA->getKey());
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  Closure(array<string, mixed>=): Model  $creer
     */
    #[DataProvider('modelesCrees')]
    public function test_magasin_id_est_toujours_force_par_le_contexte_actif(string $modelClass, Closure $creer): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);

        // Même si on tente de forcer magasin_id vers B via le payload
        // (simulant un payload HTTP malveillant), le trait BelongsToMagasin
        // doit l'écraser par le tenant réellement actif.
        $enregistrement = $creer(['magasin_id' => $this->magasinB->id]);

        $this->assertSame($this->magasinA->id, $enregistrement->fresh()->magasin_id);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('modelesDeMagasin')]
    public function test_acces_sans_contexte_tenant_echoue_fermee(string $modelClass): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(TenantContextMissingException::class);
        $modelClass::query()->get();
    }

    /**
     * La seule lecture de plusieurs magasins à la fois : retirer
     * MagasinScope par son nom, ce que conseille
     * TenantContextMissingException. Le retrait fonctionne même sans
     * magasin actif.
     */
    public function test_retirer_explicitement_magasin_scope_lit_tous_les_magasins_sans_contexte(): void
    {
        $contexte = app(TenantContext::class);

        $contexte->set($this->magasinA);
        Client::factory()->create();

        $contexte->set($this->magasinB);
        Client::factory()->create();

        $contexte->clear();

        $tous = Client::query()->withoutGlobalScope(MagasinScope::class)->get();

        $this->assertCount(2, $tous);
    }

    /**
     * Les fiches qui s'archivent (voir Proposable).
     *
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function fichesArchivables(): array
    {
        return [
            'client' => [Client::class],
            'fournisseur' => [Fournisseur::class],
            'produit' => [Produit::class],
        ];
    }

    /**
     * Une fiche archivée reste au magasin qui l'a créée : withTrashed() ne
     * retire que le filtre des fiches archivées, jamais MagasinScope. La
     * règle « exists » d'un formulaire du magasin B refuse les fiches du
     * magasin A, qu'elle exclue les fiches archivées ou qu'elle les accepte
     * (voir BelongsToMagasin::regleExisteDansLeMagasin()), alors qu'elle
     * accepte les mêmes fiches du magasin B.
     *
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('fichesArchivables')]
    public function test_une_fiche_archivee_d_un_autre_magasin_reste_invisible(string $modelClass): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);
        $ficheA = $modelClass::factory()->create();
        $ficheArchiveeA = $modelClass::factory()->trashed()->create();
        $contexte->set($this->magasinB);
        $ficheB = $modelClass::factory()->create();
        $ficheArchiveeB = $modelClass::factory()->trashed()->create();
        $existe = fn (Model $fiche, ?Builder $parmi = null): bool => Validator::make(['fiche' => $fiche->getKey()], ['fiche' => [$modelClass::regleExisteDansLeMagasin($parmi)]])->passes();

        $this->assertNull($modelClass::withTrashed()->find($ficheArchiveeA->id));
        $this->assertSame([$ficheArchiveeB->id], $modelClass::onlyTrashed()->pluck('id')->all());

        $this->assertFalse($existe($ficheA));
        $this->assertTrue($existe($ficheB));
        $this->assertFalse($existe($ficheArchiveeA, $modelClass::withTrashed()));
        $this->assertTrue($existe($ficheArchiveeB, $modelClass::withTrashed()));
    }

    /**
     * Le fournisseur d'un produit se lit archivé compris (voir
     * Produit::fournisseur()), mais jamais celui d'un autre magasin, même
     * si l'identifiant y pointe.
     */
    public function test_le_fournisseur_archive_d_un_autre_magasin_ne_se_lit_pas_par_la_relation(): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);
        $fournisseurA = Fournisseur::factory()->trashed()->create();

        $contexte->set($this->magasinB);
        $produitB = Produit::factory()->create();
        Produit::query()->whereKey($produitB->id)->update(['fournisseur_id' => $fournisseurA->id]);

        $this->assertNull($produitB->fresh()->fournisseur);
    }

    /**
     * Un retour se fait dans le magasin qui a vendu (J6), même entre deux
     * magasins d'une même société : depuis l'autre magasin, la ligne de
     * la facture est introuvable, et aucun avoir ne naît.
     */
    public function test_un_magasin_de_la_meme_societe_ne_fait_pas_d_avoir_sur_une_vente_d_un_autre(): void
    {
        $memeSociete = $this->ouvrirUnAutreMagasin($this->magasinA);
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);
        $ligneVendue = $this->venteEmise()->lignes()->sole();
        $contexte->set($memeSociete);

        $this->assertThrows(
            fn () => app(CreerAvoirAction::class)->handle(Client::factory()->create(), [['ligne_facture_id' => $ligneVendue->id, 'quantite' => 1]]),
            InvalidArgumentException::class,
            'Cette ligne de facture est introuvable.',
        );

        $this->assertSame(0, Avoir::query()->withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * Un paiement se fait dans le magasin qui a vendu (J6) : depuis un
     * autre magasin de la même société, la facture est introuvable, et
     * rien n'est encaissé.
     */
    public function test_un_magasin_de_la_meme_societe_n_encaisse_pas_une_vente_d_un_autre(): void
    {
        $memeSociete = $this->ouvrirUnAutreMagasin($this->magasinA);
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);
        $vente = $this->venteEmise();
        $contexte->set($memeSociete);

        $this->assertThrows(
            fn () => app(EnregistrerPaiementAction::class)->handle(Client::factory()->create(), ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $vente->id, 'montant' => '12.00']]),
            ModelNotFoundException::class,
        );

        $this->assertSame(0, Paiement::query()->withoutGlobalScope(MagasinScope::class)->count());
        $contexte->set($this->magasinA);
        $this->assertSame(StatutFacture::Emise, $vente->fresh()->statut);
    }

    /**
     * Une preuve d'exonération ou un bordereau de détaxe se note dans le
     * magasin qui a vendu : depuis un autre magasin de la même société,
     * la vente est introuvable, et rien ne s'enregistre.
     */
    public function test_un_magasin_de_la_meme_societe_ne_justifie_pas_une_vente_d_un_autre(): void
    {
        $memeSociete = $this->ouvrirUnAutreMagasin($this->magasinA);
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);
        $ticket = $this->venteEmise();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $export = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
        $contexte->set($memeSociete);

        $this->assertThrows(fn () => app(AjouterPreuveOperationAction::class)->handle($export, TypePreuve::Mrn, '26FR000000123456E1'), ModelNotFoundException::class);
        $this->assertThrows(fn () => app(NoterBordereauDetaxeAction::class)->handle($ticket, '2026FR000123456'), ModelNotFoundException::class);

        $this->assertSame(0, PreuveOperation::query()->withoutGlobalScope(MagasinScope::class)->count());
        $this->assertSame(0, BordereauDetaxe::query()->withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * @return array<string, array{0: class-string<RessourceFilament>, 1: string, 2: Closure(Magasin): Model}>
     */
    public static function pagesDUnEnregistrement(): array
    {
        return [
            'avoir fournisseur, détail' => [AvoirFournisseurResource::class, 'view', static fn (): Model => AvoirFournisseur::factory()->create()],
            'avoir, détail' => [AvoirResource::class, 'view', static fn (): Model => self::creerEnregistrement(Avoir::class)],
            'caisse sécurisée, détail' => [EnregistrementFiscalResource::class, 'view', static fn (): Model => self::creerEnregistrement(EnregistrementFiscal::class)],
            'client, modification' => [ClientResource::class, 'edit', static fn (): Model => Client::factory()->create()],
            'clôture de caisse, détail' => [ClotureCaisseResource::class, 'view', static fn (): Model => ClotureCaisse::factory()->create()],
            'devis, détail' => [DevisResource::class, 'view', static fn (): Model => Devis::factory()->create()],
            'devis, modification' => [DevisResource::class, 'edit', static fn (): Model => Devis::factory()->create()],
            'facture d\'achat, détail' => [FactureAchatResource::class, 'view', static fn (): Model => FactureAchat::factory()->create()],
            'facture d\'achat, modification' => [FactureAchatResource::class, 'edit', static fn (): Model => FactureAchat::factory()->create()],
            'facture, détail' => [FactureResource::class, 'view', static fn (): Model => Facture::factory()->create()],
            'facture, modification' => [FactureResource::class, 'edit', static fn (): Model => Facture::factory()->create()],
            'fournisseur, modification' => [FournisseurResource::class, 'edit', static fn (): Model => Fournisseur::factory()->create()],
            'membre de l\'équipe, modification' => [MembreEquipeResource::class, 'edit', static fn (Magasin $magasin): Model => $magasin->membres()
                ->where('user_id', app(CreerMembreMagasinAction::class)->handle($magasin->societe->proprietaire, $magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur)->getKey())
                ->firstOrFail()],
            'produit, modification' => [ProduitResource::class, 'edit', static fn (): Model => Produit::factory()->create()],
            'soumission e-reporting, détail' => [SoumissionEReportingResource::class, 'view', static fn (): Model => self::creerEnregistrement(SoumissionEReporting::class)],
        ];
    }

    /**
     * L'URL d'un enregistrement du magasin A, placée sous le magasin B
     * dont le visiteur est propriétaire (toutes les permissions) : 404,
     * comme pour un identifiant qui n'existe pas. La même page s'ouvre
     * sous le magasin A pour son propriétaire : le 404 ne vient pas d'une
     * URL fausse.
     *
     * @param  class-string<RessourceFilament>  $ressource
     * @param  Closure(Magasin): Model  $creerEnregistrement
     */
    #[DataProvider('pagesDUnEnregistrement')]
    public function test_l_url_d_un_enregistrement_d_un_autre_magasin_repond_404(string $ressource, string $page, Closure $creerEnregistrement): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A'], $proprietaireA);
        $enregistrementDeA = $creerEnregistrement($magasinA);
        $proprietaireB = User::factory()->create();
        $magasinB = $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);
        $this->actingAs($proprietaireA)
            ->get($ressource::getUrl($page, ['record' => $enregistrementDeA, 'tenant' => $magasinA]))
            ->assertSuccessful();

        $reponse = $this->actingAs($proprietaireB)
            ->get($ressource::getUrl($page, ['record' => $enregistrementDeA, 'tenant' => $magasinB]));

        $reponse->assertNotFound();
    }

    /**
     * Un enregistrement du magasin actif d'un modèle sans fabrique, écrit
     * directement, sans l'action qui l'écrit d'ordinaire : un avoir
     * brouillon, un paiement ou une imputation non signés, une
     * vérification VIES sans appel à VIES, une preuve d'exonération ou un
     * bordereau de détaxe sur un brouillon, une ligne du registre fiscal
     * sans l'événement d'initialisation du magasin, une ligne de verrou,
     * une soumission d'e-reporting sans clôture. Seule
     * l'isolation est vérifiée ici. Comme une fabrique, il accepte des
     * attributs hors $fillable.
     *
     * @param  class-string<Model>  $modele
     * @param  array<string, mixed>  $attributs
     */
    private static function creerEnregistrement(string $modele, array $attributs = []): Model
    {
        $exiges = match ($modele) {
            Avoir::class => ['client_id' => Client::factory()->create()->getKey(), 'type_client' => TypeClient::Particulier],
            AvoirAllocation::class => [
                'avoir_id' => self::creerEnregistrement(Avoir::class)->getKey(),
                'facture_id' => Facture::factory()->create()->getKey(),
                'montant_alloue' => '20.00',
            ],
            BordereauDetaxe::class => [
                'facture_id' => Facture::factory()->create()->getKey(),
                'numero' => '2026FR000123456',
            ],
            ConsultationVies::class => [
                'client_id' => Client::factory()->etranger('DE', 'DE123456789')->create()->getKey(),
                'numero_tva' => 'DE123456789',
                'valide' => true,
                'consultee_le' => CarbonImmutable::parse('2026-10-09 12:32:10', 'UTC'),
                'reponse' => '{"valid":true}',
            ],
            EnregistrementFiscal::class => [
                'chaine' => ChaineFiscale::Vente,
                'sequence' => 1,
                'type' => 'ticket',
                'horodatage' => CarbonImmutable::parse('2026-10-09 08:00:00', 'UTC'),
                'contenu' => '{"format":1}',
                'signature' => 'signature',
                'algorithme' => 'ES256',
                'cle_id' => str_repeat('0', 64),
                'version_module' => '1.0',
            ],
            Paiement::class => [
                'client_id' => Client::factory()->create()->getKey(),
                'mode_paiement' => ModePaiement::Virement,
                'montant' => '120.00',
                'recu_le' => '2026-10-09',
            ],
            PaiementAllocation::class => [
                'paiement_id' => self::creerEnregistrement(Paiement::class)->getKey(),
                'facture_id' => Facture::factory()->create()->getKey(),
                'montant_alloue' => '120.00',
            ],
            PreuveOperation::class => [
                'facture_id' => Facture::factory()->create()->getKey(),
                'type' => TypePreuve::Mrn,
                'reference' => '26FR000000123456E1',
            ],
            SoumissionEReporting::class => [
                'periode_debut' => '2026-09-01',
                'periode_fin' => '2026-09-30',
                'date_limite_transmission' => '2026-10-10',
            ],
            TeteRegistreFiscal::class => [],
            TraceEvenement::class => [
                'enregistrement_fiscal_id' => self::creerEnregistrement(EnregistrementFiscal::class)->getKey(),
                'ip' => '203.0.113.7',
                'agent_utilisateur' => 'Navigateur de test',
                'expire_le' => CarbonImmutable::parse('2027-10-09 08:00:00', 'UTC'),
            ],
        };

        return Model::unguarded(static fn (): Model => $modele::create([...$exiges, ...$attributs]));
    }

    /**
     * Un ticket de 12,00 € émis dans le magasin actif, non réglé.
     */
    private function venteEmise(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelesQuiUtilisentBelongsToMagasin(): array
    {
        $modeles = collect(File::allFiles(app_path()))
            ->map(fn (SplFileInfo $fichier): string => 'App\\'.Str::of($fichier->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
            ->filter(fn (string $classe): bool => class_exists($classe) && in_array(BelongsToMagasin::class, class_uses_recursive($classe), true))
            ->values()
            ->all();
        sort($modeles);

        return $modeles;
    }
}
