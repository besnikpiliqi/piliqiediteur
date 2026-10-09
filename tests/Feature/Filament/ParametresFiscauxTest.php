<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\ParametresFiscaux;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ParametresFiscauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_configure_le_regime_de_tva_du_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelNormalTrimestriel->value,
                'option_debits_tva' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $magasin->regime_tva);
        $this->assertTrue($magasin->option_debits_tva);
    }

    /**
     * Le régime de TVA et l'option pour les débits ont un impact sur la
     * conformité : chaque changement s'inscrit au journal des événements
     * du magasin (JET 270), avec son ancienne et sa nouvelle valeur.
     */
    public function test_chaque_parametre_modifie_s_inscrit_au_journal_avec_son_ancienne_et_sa_nouvelle_valeur(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::ReelSimplifie], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelNormalMensuel->value,
                'option_debits_tva' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([
            ['parametre' => 'regime_tva', 'ancienne_valeur' => 'reel_simplifie', 'nouvelle_valeur' => 'reel_normal_mensuel'],
            ['parametre' => 'option_debits_tva', 'ancienne_valeur' => false, 'nouvelle_valeur' => true],
        ], $this->parametresJournalises());
    }

    public function test_un_parametre_inchange_n_est_pas_journalise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::ReelSimplifie], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelSimplifie->value,
                'option_debits_tva' => true,
            ])
            ->call('save');

        $this->assertSame([
            ['parametre' => 'option_debits_tva', 'ancienne_valeur' => false, 'nouvelle_valeur' => true],
        ], $this->parametresJournalises());
    }

    /**
     * Fermé par défaut : sans clé de signature, le changement ne peut pas
     * s'inscrire au journal, donc ne s'enregistre pas, et l'écran dit
     * pourquoi.
     */
    public function test_sans_cle_de_signature_rien_n_est_enregistre_et_l_ecran_dit_pourquoi(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::ReelSimplifie], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelNormalMensuel->value,
                'option_debits_tva' => false,
            ])
            ->call('save')
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertSame(RegimeTva::ReelSimplifie, $magasin->fresh()->regime_tva);
        $this->assertSame([], $this->parametresJournalises());
    }

    public function test_un_vendeur_sans_la_permission_ne_peut_pas_acceder_a_la_page(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo(PermissionRegistry::FACTURE_VIEW);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ParametresFiscaux::canAccess());
    }

    /**
     * Les détails des événements 270 du journal du magasin actif, dans
     * l'ordre de la chaîne.
     *
     * @return list<array<string, mixed>>
     */
    private function parametresJournalises(): array
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '270')
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->all();
    }
}
