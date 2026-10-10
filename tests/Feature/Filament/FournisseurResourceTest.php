<?php

namespace Tests\Feature\Filament;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\FournisseurResource\Pages\CreateFournisseur;
use App\Filament\App\Resources\FournisseurResource\Pages\EditFournisseur;
use App\Filament\App\Resources\FournisseurResource\Pages\ListFournisseurs;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La base refuse deux fournisseurs du magasin avec le même SIRET,
 * archivés compris : le formulaire le dit clairement, et un fournisseur
 * archivé se retrouve et se restaure depuis la liste.
 */
class FournisseurResourceTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE_SIRET_PRIS = 'Ce SIRET est déjà celui d\'un autre fournisseur du magasin, peut-être archivé : affichez les fournisseurs archivés pour le restaurer.';

    /**
     * Le message contient « : », que Livewire lirait comme le nom d'une
     * règle : il se compare donc aux messages eux-mêmes.
     */
    private static function siretPris(): Closure
    {
        return fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_SIRET_PRIS];
    }

    private function connecterLeProprietaire(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
    }

    public function test_le_siret_d_un_autre_fournisseur_du_magasin_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->create(['siret' => '12345678900012']);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste B', 'siret' => '12345678900012'])
            ->call('create')
            ->assertHasFormErrors(['siret' => self::siretPris()]);

        $this->assertSame(1, Fournisseur::query()->count());
    }

    public function test_le_siret_d_un_fournisseur_archive_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->trashed()->create(['siret' => '12345678900012']);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste B', 'siret' => '12345678900012'])
            ->call('create')
            ->assertHasFormErrors(['siret' => self::siretPris()]);

        $this->assertSame(1, Fournisseur::withTrashed()->count());
    }

    public function test_la_modification_refuse_le_siret_d_un_autre_fournisseur_et_garde_le_sien(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->create(['siret' => '12345678900012']);
        $fournisseur = Fournisseur::factory()->create(['siret' => '98765432100019', 'telephone' => '0102030405']);

        Livewire::test(EditFournisseur::class, ['record' => $fournisseur->getRouteKey()])
            ->fillForm(['siret' => '12345678900012'])
            ->call('save')
            ->assertHasFormErrors(['siret' => self::siretPris()]);
        Livewire::test(EditFournisseur::class, ['record' => $fournisseur->getRouteKey()])
            ->fillForm(['nom' => 'Grossiste renommé'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Grossiste renommé', '98765432100019'], [$fournisseur->fresh()->nom, $fournisseur->fresh()->siret]);
    }

    /**
     * La règle ne regarde que le magasin actif : un autre magasin peut
     * acheter chez le même fournisseur.
     */
    public function test_le_siret_d_un_fournisseur_d_un_autre_magasin_est_accepte(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        Fournisseur::factory()->create(['siret' => '12345678900012']);
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'siret' => '12345678900012'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Fournisseur::query()->where('siret', '12345678900012')->count());
    }

    public function test_un_siret_qui_ne_compte_pas_14_chiffres_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'siret' => '1234567890001'])
            ->call('create')
            ->assertHasFormErrors(['siret' => 'Le SIRET compte 14 chiffres, sans espace.']);

        $this->assertSame(0, Fournisseur::query()->count());
    }

    /**
     * Archivé depuis sa page de modification, il quitte la liste.
     */
    public function test_le_proprietaire_archive_un_fournisseur_depuis_sa_fiche(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();

        Livewire::test(EditFournisseur::class, ['record' => $fournisseur->getRouteKey()])
            ->callAction('archiver')
            ->assertNotified('Fournisseur archivé')
            ->assertRedirect(FournisseurResource::getUrl('index'));

        $this->assertSoftDeleted($fournisseur);
    }

    public function test_un_fournisseur_archive_se_retrouve_et_se_restaure_depuis_la_liste(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->trashed()->create();

        Livewire::test(ListFournisseurs::class)
            ->assertCanNotSeeTableRecords([$fournisseur])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$fournisseur])
            ->assertTableActionHidden('edit', $fournisseur)
            ->callTableAction('restore', $fournisseur);

        $this->assertNotSoftDeleted($fournisseur);
    }

    /**
     * Sa page de modification ne trouve plus un fournisseur archivé (voir
     * FournisseurPolicy::update()) : sa ligne n'y mène pas.
     */
    public function test_la_ligne_d_un_fournisseur_archive_ne_mene_a_aucune_page(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();
        $archive = Fournisseur::factory()->trashed()->create();

        $table = Livewire::test(ListFournisseurs::class)
            ->filterTable('trashed', false)
            ->instance()
            ->getTable();

        $this->assertSame(FournisseurResource::getUrl('edit', ['record' => $fournisseur]), $table->getRecordUrl($fournisseur));
        $this->assertNull($table->getRecordUrl($archive));
    }

    /**
     * Sans la permission d'archiver les fournisseurs, pas de restauration
     * non plus.
     */
    public function test_un_observateur_ne_restaure_pas_un_fournisseur_archive(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $observateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Observateur);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);
        $fournisseur = Fournisseur::factory()->trashed()->create();

        Livewire::test(ListFournisseurs::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $fournisseur);

        $this->assertSoftDeleted($fournisseur);
    }
}
