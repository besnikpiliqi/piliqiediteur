<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Suite non négociable (voir le prompt maître du projet, §29) : pour
 * chaque modèle utilisant BelongsToMagasin, un magasin ne doit JAMAIS
 * pouvoir lire, trouver, ou créer par usurpation une ressource d'un autre
 * magasin -- ni par requête classique, ni par accès direct par ID, ni par
 * un payload malveillant qui tenterait de forcer magasin_id.
 *
 * Ce test est paramétré : ajouter un nouveau modèle BelongsToMagasin doit
 * simplement ajouter une entrée à modelesTenant() pour être couvert ici.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasinA;

    private Magasin $magasinB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magasinA = Magasin::factory()->create();
        $this->magasinB = Magasin::factory()->create();
    }

    /**
     * @return array<string, array{0: class-string<Model>, 1: string}>
     */
    public static function modelesTenant(): array
    {
        return [
            'Client' => [Client::class, 'factory'],
            'Fournisseur' => [Fournisseur::class, 'factory'],
            'Produit' => [Produit::class, 'factory'],
        ];
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

    public function test_un_super_admin_peut_explicitement_contourner_le_scope(): void
    {
        $contexte = app(TenantContext::class);

        $contexte->set($this->magasinA);
        Client::factory()->create();

        $contexte->set($this->magasinB);
        Client::factory()->create();

        $contexte->clear();

        // Contournement explicite, jamais implicite : c'est la seule façon
        // légitime de faire une requête cross-tenant (ex: service Super Admin audité).
        $tous = Client::query()->withoutGlobalScope(MagasinScope::class)->get();

        $this->assertCount(2, $tous);
    }
}
