<?php

namespace Tests\Feature\Filament;

use App\Domain\Contacts\Models\Fournisseur;
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

    private const MESSAGE_PROPRE_ENTREPRISE = 'Ce SIRET est celui de votre propre entreprise : un achat entre magasins d\'une même société n\'est pas un achat.';

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
    }

    public function test_le_siret_d_un_autre_fournisseur_du_magasin_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->create(['siret' => '12121212000012']);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste B', 'siret' => '12121212000012'])
            ->call('create')
            ->assertHasFormErrors(['siret' => self::siretPris()]);

        $this->assertSame(1, Fournisseur::query()->count());
    }

    public function test_le_siret_d_un_fournisseur_archive_est_refuse(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->trashed()->create(['siret' => '12121212000012']);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste B', 'siret' => '12121212000012'])
            ->call('create')
            ->assertHasFormErrors(['siret' => self::siretPris()]);

        $this->assertSame(1, Fournisseur::withTrashed()->count());
    }

    public function test_la_modification_refuse_le_siret_d_un_autre_fournisseur_et_garde_le_sien(): void
    {
        $this->connecterLeProprietaire();
        Fournisseur::factory()->create(['siret' => '12121212000012']);
        $fournisseur = Fournisseur::factory()->create(['siret' => '98765432100019', 'telephone' => '0102030405']);

        Livewire::test(EditFournisseur::class, ['record' => $fournisseur->getRouteKey()])
            ->fillForm(['siret' => '12121212000012'])
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
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        Fournisseur::factory()->create(['siret' => '12121212000012']);
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'siret' => '12121212000012'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Fournisseur::query()->where('siret', '12121212000012')->count());
    }

    public function test_un_siret_qui_ne_compte_pas_14_chiffres_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'siret' => '1212121200001'])
            ->call('create')
            ->assertHasFormErrors(['siret' => 'Le SIRET compte 14 chiffres, sans espace.']);

        $this->assertSame(0, Fournisseur::query()->count());
    }

    public function test_un_numero_de_tva_sans_le_code_du_pays_est_refuse(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'numero_tva' => '32 123 456 789'])
            ->call('create')
            ->assertHasFormErrors(['numero_tva' => 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de 2 à 13 chiffres ou lettres ; les espaces, points et tirets ne comptent pas.']);

        $this->assertSame(0, Fournisseur::query()->count());
    }

    /**
     * Même règle que pour un client : saisi avec ses séparateurs, le n° de
     * TVA s'enregistre sans eux, en majuscules.
     */
    public function test_le_numero_de_tva_s_enregistre_sans_separateurs(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Brasserie de Namur', 'numero_tva' => 'be 0123.456.789'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fournisseurs', ['nom' => 'Brasserie de Namur', 'numero_tva' => 'BE0123456789']);
    }

    /**
     * Le SIRET est facultatif : le n° de TVA de l'entreprise du magasin,
     * saisi seul, est refusé aussi.
     */
    public function test_le_numero_de_tva_de_l_entreprise_du_magasin_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'siren' => '100000009', 'numero_tva' => 'FR88100000009'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Ma Boutique Lyon', 'numero_tva' => 'FR88100000009'])
            ->call('create')
            ->assertHasFormErrors(['numero_tva' => fn (array $reglesEnEchec, array $messages): bool => $messages === ['Ce n° de TVA est celui de votre propre entreprise : un achat entre magasins d\'une même société n\'est pas un achat.']]);

        $this->assertSame(0, Fournisseur::query()->count());
    }

    public function test_un_numero_de_tva_au_format_est_accepte(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Grossiste A', 'numero_tva' => 'FR56121212120'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fournisseurs', ['nom' => 'Grossiste A', 'numero_tva' => 'FR56121212120']);
    }

    /**
     * Acheter à un autre magasin de la même société n'est pas un achat.
     */
    public function test_le_siret_d_un_etablissement_de_l_entreprise_du_magasin_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'siren' => '100000009'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(CreateFournisseur::class)
            ->fillForm(['nom' => 'Ma Boutique Lyon', 'siret' => '10000000900025'])
            ->call('create')
            ->assertHasFormErrors(['siret' => fn (array $reglesEnEchec, array $messages): bool => $messages === [self::MESSAGE_PROPRE_ENTREPRISE]]);

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
     * non plus : ni bouton, ni effet pour une requête forgée.
     */
    public function test_un_observateur_ne_restaure_pas_un_fournisseur_archive(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $observateur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Observateur);
        $this->actingAs($observateur);
        Filament::setTenant($magasin);
        $fournisseur = Fournisseur::factory()->trashed()->create();

        Livewire::test(ListFournisseurs::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $fournisseur)
            ->call('mountTableAction', 'restore', (string) $fournisseur->getKey())
            ->call('callMountedTableAction');

        $this->assertSoftDeleted($fournisseur);
    }
}
