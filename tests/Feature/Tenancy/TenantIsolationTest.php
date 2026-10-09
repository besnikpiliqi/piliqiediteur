<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
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
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
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
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\SoumissionEReportingResource;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Closure;
use Filament\Resources\Resource as RessourceFilament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Pour chaque modèle qui utilise BelongsToMagasin, un magasin ne doit
 * jamais lire, trouver par son identifiant, ni créer en le rattachant à un
 * autre magasin, un enregistrement d'un autre magasin ; sans magasin actif,
 * toute lecture échoue au lieu de tout renvoyer.
 *
 * modelesTenant() liste les modèles qui ont une fabrique,
 * MODELES_SANS_FABRIQUE les autres : un nouveau modèle de magasin qui
 * n'apparaît dans aucune des deux listes fait échouer
 * test_chaque_modele_de_magasin_est_liste_ici().
 *
 * Côté panneau, chaque ressource dont un enregistrement a sa propre URL
 * répond 404 à un membre d'un autre magasin, sans révéler que
 * l'enregistrement existe.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modèles de magasin sans fabrique, créés seulement par les actions
     * métier : lignes avec leur document, mouvements avec leur produit,
     * soumission d'e-reporting et transmission PDP par leur action. Ils
     * utilisent le même trait que ceux de modelesTenant(), mais ces tests
     * ne savent pas les créer seuls.
     *
     * @var list<class-string<Model>>
     */
    private const MODELES_SANS_FABRIQUE = [
        LigneAchat::class,
        LigneAvoir::class,
        LigneAvoirFournisseur::class,
        LigneDevis::class,
        LigneFacture::class,
        MouvementStock::class,
        SoumissionEReporting::class,
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
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function modelesTenant(): array
    {
        return [
            'Avoir' => [Avoir::class],
            'AvoirAllocation' => [AvoirAllocation::class],
            'AvoirFournisseur' => [AvoirFournisseur::class],
            'Client' => [Client::class],
            'ClotureCaisse' => [ClotureCaisse::class],
            'ClotureCaissePersonnelle' => [ClotureCaissePersonnelle::class],
            'Devis' => [Devis::class],
            'EnvoiDocument' => [EnvoiDocument::class],
            'Facture' => [Facture::class],
            'FactureAchat' => [FactureAchat::class],
            'Fournisseur' => [Fournisseur::class],
            'Paiement' => [Paiement::class],
            'PaiementAllocation' => [PaiementAllocation::class],
            'Produit' => [Produit::class],
            'Service' => [Service::class],
        ];
    }

    public function test_chaque_modele_de_magasin_est_liste_ici(): void
    {
        $modelesListes = [
            ...array_column(self::modelesTenant(), 0),
            ...self::MODELES_SANS_FABRIQUE,
        ];
        sort($modelesListes);

        $this->assertSame($this->modelesQuiUtilisentBelongsToMagasin(), $modelesListes);
    }

    #[DataProvider('modelesTenant')]
    public function test_un_magasin_ne_voit_pas_les_enregistrements_dun_autre(string $modelClass): void
    {
        $contexte = app(TenantContext::class);

        $contexte->set($this->magasinA);
        $enregistrementA = $modelClass::factory()->create();

        $contexte->set($this->magasinB);
        $enregistrementB = $modelClass::factory()->create();

        // Sous le contexte B, seule la liste de B doit apparaître.
        $ids = $modelClass::query()->pluck('id')->all();
        $this->assertSame([$enregistrementB->id], $ids);

        // Un find() direct sur l'ID de A doit échouer comme si l'enregistrement n'existait pas.
        $this->assertNull($modelClass::find($enregistrementA->id));

        $this->expectException(ModelNotFoundException::class);
        $modelClass::findOrFail($enregistrementA->id);
    }

    #[DataProvider('modelesTenant')]
    public function test_magasin_id_est_toujours_force_par_le_contexte_actif(string $modelClass): void
    {
        $contexte = app(TenantContext::class);
        $contexte->set($this->magasinA);

        // Même si on tente de forcer magasin_id vers B via le payload
        // (simulant un payload HTTP malveillant), le trait BelongsToMagasin
        // doit l'écraser par le tenant réellement actif.
        $enregistrement = $modelClass::factory()->create(['magasin_id' => $this->magasinB->id]);

        $this->assertSame($this->magasinA->id, $enregistrement->fresh()->magasin_id);
    }

    #[DataProvider('modelesTenant')]
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
     * @return array<string, array{0: class-string<RessourceFilament>, 1: string, 2: Closure(Magasin): Model}>
     */
    public static function pagesDUnEnregistrement(): array
    {
        return [
            'avoir fournisseur, détail' => [AvoirFournisseurResource::class, 'view', static fn (): Model => AvoirFournisseur::factory()->create()],
            'avoir, détail' => [AvoirResource::class, 'view', static fn (): Model => Avoir::factory()->create()],
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
                ->where('user_id', app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur)->getKey())
                ->firstOrFail()],
            'produit, modification' => [ProduitResource::class, 'edit', static fn (): Model => Produit::factory()->create()],
            'soumission e-reporting, détail' => [SoumissionEReportingResource::class, 'view', static fn (): Model => SoumissionEReporting::create([
                'periode_debut' => '2026-09-01',
                'periode_fin' => '2026-09-30',
                'date_limite_transmission' => '2026-10-10',
            ])],
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
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $enregistrementDeA = $creerEnregistrement($magasinA);
        $proprietaireB = User::factory()->create();
        $magasinB = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);
        $this->actingAs($proprietaireA)
            ->get($ressource::getUrl($page, ['record' => $enregistrementDeA, 'tenant' => $magasinA]))
            ->assertSuccessful();

        $reponse = $this->actingAs($proprietaireB)
            ->get($ressource::getUrl($page, ['record' => $enregistrementDeA, 'tenant' => $magasinB]));

        $reponse->assertNotFound();
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
