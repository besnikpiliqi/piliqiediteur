<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Middleware\MenerUnCompteSansMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Qui ouvre quel panneau (voir User::canAccessPanel(), getTenants() et
 * canAccessTenant()) : le panneau des magasins s'ouvre à tout compte
 * actif, et mène celui qui n'a encore aucun magasin à l'espace de sa
 * société, et un compte d'un cabinet d'expertise comptable à l'espace de
 * son cabinet, jamais à une erreur (voir MenerUnCompteSansMagasin) ;
 * celui de l'administration de la société à son propriétaire et à ses
 * administrateurs, et aucun autre ; un magasin ne s'ouvre qu'au
 * propriétaire de sa société, à ses membres actifs et aux comptes du
 * cabinet lié à qui elle est confiée.
 */
class AccesAuxPanneauxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trois panneaux et aucun autre : celui des magasins, l'espace
     * d'administration d'une société et l'espace d'un cabinet d'expertise
     * comptable. Le propriétaire d'une société qui a des magasins ouvre
     * les deux premiers, jamais celui d'un cabinet ni un panneau inconnu.
     */
    public function test_il_n_existe_que_les_panneaux_app_societe_et_cabinet(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->assertEqualsCanonicalizing(['app', 'societe', 'cabinet'], array_keys(Filament::getPanels()));
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('app')));
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('societe')));
        $this->assertFalse($proprietaire->canAccessPanel(Filament::getPanel('cabinet')));
        $this->assertSame([], $proprietaire->getTenants(Filament::getPanel('cabinet'))->modelKeys());
        $this->assertFalse($proprietaire->canAccessPanel(Panel::make()->id('editeur')));
        $this->assertSame([$magasin->societe_id], $proprietaire->getTenants(Filament::getPanel('societe'))->modelKeys());
        $this->assertSame([], $proprietaire->getTenants(Panel::make()->id('editeur'))->modelKeys());
    }

    /**
     * Un magasin suspendu reste consultable : son propriétaire l'ouvre
     * toujours, en lecture seule (voir ControleDesDroits).
     */
    public function test_le_proprietaire_d_un_magasin_suspendu_l_ouvre_toujours(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $magasin->forceFill(['suspendu_le' => now()])->save();

        $reponse = $this->actingAs($proprietaire)->get("/app/{$magasin->uuid}");

        $reponse->assertOk();
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('app')));
        $this->assertTrue($proprietaire->canAccessTenant($magasin));
    }

    /**
     * Suspendu, un membre n'a plus de magasin à ouvrir : le sien ne figure
     * plus parmi ceux que le panneau lui propose, et ne s'ouvre plus.
     */
    public function test_un_membre_suspendu_n_ouvre_pas_ce_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membreSuspendu($magasin);

        $magasins = $membre->getTenants(Filament::getPanel('app'));

        $this->assertSame([], $magasins->modelKeys());
        $this->assertFalse($membre->canAccessTenant($magasin));
    }

    /**
     * Suspendu, un compte n'ouvre plus rien, dès la requête suivante :
     * ni le propriétaire, ni un membre, ni aucun de leurs magasins, ni
     * l'administration de la société.
     */
    public function test_un_compte_suspendu_n_ouvre_aucun_magasin(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur);

        foreach ([$proprietaire, $membre] as $compte) {
            $compte->forceFill(['statut' => StatutCompte::Suspendu])->save();

            $this->assertSame([], $compte->getTenants(Filament::getPanel('app'))->modelKeys());
            $this->assertSame([], $compte->getTenants(Filament::getPanel('societe'))->modelKeys());
            $this->assertSame([false, false, false, false], [
                $compte->canAccessPanel(Filament::getPanel('app')),
                $compte->canAccessPanel(Filament::getPanel('societe')),
                $compte->canAccessTenant($magasin),
                $compte->canAccessTenant($magasin->societe),
            ]);
            $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertForbidden();
        }
        $this->assertFalse($proprietaire->canAccessTenant($autre));
    }

    public function test_sans_aucun_magasin_actif_le_panneau_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membreSuspendu($magasin);

        $reponse = $this->actingAs($membre)->get("/app/{$magasin->uuid}");

        $reponse->assertForbidden();
    }

    /**
     * Après une connexion ou un mot de passe choisi, chaque compte va à
     * l'accueil du panneau où il se trouve : celui de l'espace de sa
     * société quand il s'y connecte.
     */
    public function test_apres_la_connexion_chacun_va_a_l_accueil_de_son_panneau(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $this->actingAs($this->proprietaireDe($magasin));

        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        $this->assertSame(Filament::getPanel('societe')->getUrl($societe), MenerUnCompteSansMagasin::accueil());
    }

    /**
     * Le propriétaire d'une société qui n'a encore aucun magasin se connecte
     * au panneau des magasins et en ouvre l'accueil sans erreur : il va à
     * l'espace de sa société, où se créent les magasins.
     */
    public function test_un_proprietaire_sans_magasin_ouvre_app_et_va_a_l_espace_de_sa_societe(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(Connexion::class)
            ->fillForm(['login' => $proprietaire->email, 'password' => 'mot-de-passe-sur'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.app.tenant'));

        $this->assertAuthenticatedAs($proprietaire);
        $this->get('/app')->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe));
    }

    /**
     * Un administrateur d'une société qui n'a encore aucun magasin, dont
     * l'identifiant n'ouvre rien faute de poste reconnu d'un magasin, choisit
     * son mot de passe devant le propriétaire, se connecte avec lui et ouvre
     * l'accueil du panneau des magasins sans erreur : il va à l'espace de
     * sa société.
     */
    public function test_un_administrateur_sans_magasin_ouvre_app_et_va_a_l_espace_de_sa_societe(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $administrateur = app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alice Martin'], null, PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [AutorisationsSociete::MAGASINS_GERER]);
        $identifiant = (string) $administrateur->identifiantDeConnexion();
        app(TenantContext::class)->clear();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(Connexion::class)
            ->fillForm(['login' => $identifiant, 'password' => $identifiant])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);
        $this->assertGuest();

        app(RedonnerLAccesAction::class)->handle($proprietaire, $administrateur, true, self::MOT_DE_PASSE_CHOISI, (string) Str::uuid());
        Livewire::test(Connexion::class)
            ->fillForm(['login' => $identifiant, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.app.tenant'));

        $this->assertAuthenticatedAs($administrateur);
        $this->get('/app')->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe));
    }

    /**
     * Une adresse dont le magasin n'est pas un uuid ne désigne aucun
     * magasin : 404, jamais l'erreur de la colonne uuid, de type uuid.
     */
    public function test_une_adresse_qui_ne_porte_pas_un_uuid_de_magasin_est_introuvable(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $reponse = $this->actingAs($proprietaire)->get('/app/pas-un-uuid');

        $reponse->assertNotFound();
    }

    /**
     * De même, une adresse de l'espace de la société qui ne porte pas un
     * uuid de société est introuvable.
     */
    public function test_une_adresse_qui_ne_porte_pas_un_uuid_de_societe_est_introuvable(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $this->actingAs($proprietaire)->get('/societe/pas-un-uuid')->assertNotFound();
    }

    public function test_un_responsable_ouvre_ses_magasins_et_pas_l_espace_societe(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $responsable = $this->responsable($lyon);

        $this->assertTrue($responsable->canAccessPanel(Filament::getPanel('app')));
        $this->assertSame([true, false], [$responsable->canAccessTenant($lyon), $responsable->canAccessTenant($villeurbanne)]);
        $this->actingAs($responsable)->get("/app/{$lyon->uuid}")->assertOk();
        $this->get('/app')->assertRedirect("/app/{$lyon->uuid}");
        $this->assertFalse($responsable->canAccessPanel(Filament::getPanel('societe')));
        $this->assertSame([], $responsable->getTenants(Filament::getPanel('societe'))->modelKeys());
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))->assertForbidden();
    }

    /**
     * Un collaborateur d'un cabinet lié ouvre les magasins réels du client
     * qui lui est confié, jamais ceux d'un client qui ne l'est pas, et
     * jamais l'espace d'administration d'une société.
     */
    public function test_un_collaborateur_ouvre_les_magasins_reels_des_clients_confies_et_jamais_l_espace_societe(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $nonConfie = $this->ouvrirUneSociete(['nom' => 'Non confié']);
        $collaborateur = $this->collaborateurDuCabinetLie($lyon);
        $this->lierLeCabinet($collaborateur->societe, $nonConfie);
        $collaborateur->refresh();

        $this->assertTrue($collaborateur->canAccessPanel(Filament::getPanel('app')));
        $this->assertSame([true, true, false], [$collaborateur->canAccessTenant($lyon), $collaborateur->canAccessTenant($villeurbanne), $collaborateur->canAccessTenant($nonConfie)]);
        $this->actingAs($collaborateur)->get("/app/{$villeurbanne->uuid}")->assertOk();
        $this->get("/app/{$nonConfie->uuid}")->assertNotFound();
        $this->assertFalse($collaborateur->canAccessPanel(Filament::getPanel('societe')));
        $this->assertSame([], $collaborateur->getTenants(Filament::getPanel('societe'))->modelKeys());
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))->assertForbidden();
    }

    private function membreSuspendu(Magasin $magasin): User
    {
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $membre);

        return $membre->refresh();
    }
}
