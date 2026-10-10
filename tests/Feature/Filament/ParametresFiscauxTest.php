<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\ParametresFiscaux;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ParametresFiscaux::canAccess());
    }

    /**
     * Aucun modèle de rôle ne coche « Paramètres du magasin » : même un
     * administrateur n'entre pas, tant que le propriétaire ne la lui coche
     * pas.
     */
    public function test_un_administrateur_n_accede_pas_aux_parametres_fiscaux(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur);

        $this->actingAs($administrateur)
            ->get(ParametresFiscaux::getUrl(tenant: $magasin))
            ->assertForbidden();
    }

    public function test_un_administrateur_a_qui_le_proprietaire_coche_les_parametres_y_accede(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur, [
            ...RoleMagasin::Administrateur->permissionsParDefaut(),
            PermissionRegistry::PARAMETRES_GERER,
        ]);

        $this->actingAs($administrateur)
            ->get(ParametresFiscaux::getUrl(tenant: $magasin))
            ->assertSee('Régime de TVA');
    }

    /**
     * Le régime de TVA et l'option pour les débits sont communs aux
     * magasins d'une même société (J2, K5) : un autre magasin que le
     * principal n'enregistre que ses valeurs, et chaque champ dit celle à
     * prendre. Rien n'est enregistré ni inscrit au journal.
     */
    public function test_un_autre_magasin_de_la_societe_n_enregistre_que_les_parametres_du_principal(): void
    {
        $principal = $this->principalDeLaSociete();
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm([
                'regime_tva' => RegimeTva::ReelNormalTrimestriel->value,
                'option_debits_tva' => true,
            ])
            ->call('save')
            ->assertHasFormErrors(['regime_tva', 'option_debits_tva'])
            ->assertSee('Réglage commun à tous les magasins de votre société (SIREN 732829320), fixé par son magasin principal : prenez « Réel normal (déclaration mensuelle) ».')
            ->assertSee('Réglage commun à tous les magasins de votre société (SIREN 732829320), fixé par son magasin principal : prenez « désactivée ».');

        $this->assertSame([RegimeTva::ReelNormalMensuel, false], [$magasin->fresh()->regime_tva, $magasin->fresh()->option_debits_tva]);
        $this->assertSame(0, EnregistrementFiscal::query()->withoutGlobalScopes()->where('magasin_id', $magasin->id)->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->count());
    }

    public function test_un_autre_magasin_attend_le_regime_que_le_principal_n_a_pas_encore_choisi(): void
    {
        $principal = $this->principalDeLaSociete(['regime_tva' => null]);
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm(['regime_tva' => RegimeTva::FranchiseEnBase->value, 'option_debits_tva' => false])
            ->call('save')
            ->assertHasFormErrors(['regime_tva'])
            ->assertSee('Réglage commun à tous les magasins de votre société (SIREN 732829320), fixé par son magasin principal, qui ne l\'a pas encore choisi : il le choisit en premier.');

        $this->assertNull($magasin->fresh()->regime_tva);
    }

    /**
     * Le principal change seul le régime de toute la société ; la page de
     * l'autre magasin dit alors ce qu'il doit aligner.
     */
    public function test_le_principal_change_le_regime_et_l_autre_magasin_voit_ce_qu_il_doit_aligner(): void
    {
        $principal = $this->principalDeLaSociete();
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);
        $this->connecter($principal);

        Livewire::test(ParametresFiscaux::class)
            ->fillForm(['regime_tva' => RegimeTva::ReelNormalTrimestriel->value, 'option_debits_tva' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->connecter($magasin);

        Livewire::test(ParametresFiscaux::class)
            ->assertSee('Réglages à aligner sur le magasin principal de votre société')
            ->assertSee('Ce magasin n\'émet ni facture ni ticket tant que ses réglages communs ne sont pas ceux du magasin principal de votre société (SIREN 732829320) ; à prendre : régime de TVA « Réel normal (déclaration trimestrielle) » (Paramètres fiscaux).');
        $this->assertSame(RegimeTva::ReelNormalTrimestriel, $principal->fresh()->regime_tva);
    }

    /**
     * Le magasin principal de la société SIREN 732829320, réel normal
     * mensuel sans option pour les débits, avec son propriétaire.
     *
     * @param  array<string, mixed>  $reglages
     */
    private function principalDeLaSociete(array $reglages = []): Magasin
    {
        return app(CreerMagasinAction::class)->handle([
            'nom' => 'Bellecour',
            'siren' => '732829320',
            'siret' => '73282932000074',
            'raison_sociale' => 'Dupont SARL',
            'numero_tva' => 'FR44732829320',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
            ...$reglages,
        ], User::factory()->create());
    }

    /**
     * Un autre magasin de la société du principal, avec ses réglages
     * communs, dont le propriétaire est connecté.
     */
    private function connecterUnMagasinDeLaSociete(Magasin $principal): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle([
            'nom' => 'Ma Boutique',
            'siren' => '732829320',
            'siret' => '73282932000082',
            ...$principal->only(['regime_tva', 'option_debits_tva', 'numero_tva', 'raison_sociale', 'forme_juridique', 'capital_social', 'immatriculation']),
        ], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Le propriétaire du magasin, connecté à son magasin.
     */
    private function connecter(Magasin $magasin): void
    {
        app(TenantContext::class)->set($magasin);
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);
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
