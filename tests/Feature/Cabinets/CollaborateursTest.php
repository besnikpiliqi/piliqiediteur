<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\CreerCollaborateurAction;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\Cabinet\Resources\CollaborateurResource;
use App\Filament\Cabinet\Resources\CollaborateurResource\Pages\FicheDuCollaborateur;
use App\Filament\Cabinet\Resources\CollaborateurResource\Pages\ListCollaborateurs;
use App\Filament\Societe\Pages\AccepterLaTransmission;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les collaborateurs d'un cabinet d'expertise comptable (voir
 * CreerCollaborateurAction et CollaborateurResource) : son propriétaire
 * seul les crée, les suspend, les réactive, les retire, les réintègre et
 * leur redonne l'accès, en leur présence seulement, selon les règles des
 * comptes de PILIQI ; un collaborateur n'ouvre jamais son compte avec son
 * identifiant, n'ouvre jamais l'espace d'une société et ne change jamais
 * de rôle ; la base le refuse hors d'un cabinet ; la propriété d'un
 * cabinet se transmet à un collaborateur.
 */
class CollaborateursTest extends TestCase
{
    use RefreshDatabase;

    public function test_seul_le_proprietaire_du_cabinet_cree_suspend_reactive_retire_et_reintegre_un_collaborateur(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $autre = $this->collaborateur($cabinet, 'Anne Morel');
        $cible = $this->collaborateur($cabinet, 'Bruno Petit');

        $this->dansLEspaceDuCabinet($cabinet, $autre);
        $this->get(CollaborateurResource::getUrl('index', panel: 'cabinet', tenant: $cabinet))->assertForbidden();
        $this->get(CollaborateurResource::getUrl('view', ['record' => $cible], panel: 'cabinet', tenant: $cabinet))->assertNotFound();
        $this->assertThrows(fn () => app(CreerCollaborateurAction::class)->handle($autre, $cabinet, ['name' => 'Intrus']), AuthorizationException::class, CreerCollaborateurAction::MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL);
        foreach (['suspendre', 'retirer'] as $geste) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->{$geste}($autre, $cible), AuthorizationException::class);
        }
        $this->assertSame([false, false, false, false], [$autre->can('suspendreOuReactiver', $cible), $autre->can('retirerDeLaSociete', $cible), $autre->can('redonnerLAcces', $cible), $autre->can('creerUnCollaborateur', User::class)]);
        $this->assertSame(StatutCompte::Actif, $cible->fresh()->statut);

        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        Livewire::test(ListCollaborateurs::class)->assertCanSeeTableRecords([$autre, $cible])->assertActionVisible('creerUnCollaborateur');
        $fiche = Livewire::test(FicheDuCollaborateur::class, ['record' => $cible->getRouteKey()]);
        $fiche->callAction('suspendre');
        $this->assertSame(StatutCompte::Suspendu, $cible->fresh()->statut);
        $fiche->callAction('reactiver');
        $this->assertSame(StatutCompte::Actif, $cible->fresh()->statut);
        $fiche->callAction('retirerDeLaSociete');
        $this->assertSame(StatutCompte::Retire, $cible->fresh()->statut);
        $fiche->callAction('reintegrer');
        $this->assertSame([StatutCompte::Actif, RoleSociete::Collaborateur], [$cible->fresh()->statut, $cible->fresh()->role_societe]);
        $statuts = [];
        foreach ([ActionAuditee::CollaborateurCree, ActionAuditee::CompteSuspendu, ActionAuditee::CompteReactive, ActionAuditee::CompteRetire, ActionAuditee::CompteReintegre] as $action) {
            foreach ($this->entreesDAudit($cabinet, $action) as $entree) {
                if (isset($entree->nouvelles_valeurs['droits'][$cible->id])) {
                    $statuts[] = $entree->nouvelles_valeurs['droits'][$cible->id]['statut'];
                }
            }
        }
        $this->assertSame(['actif', 'suspendu', 'actif', 'retire', 'actif'], $statuts);
    }

    public function test_un_collaborateur_recoit_un_identifiant_de_huit_chiffres_pour_premier_mot_de_passe_sans_adresse(): void
    {
        $cabinet = $this->ouvrirUnCabinet();

        $compte = app(CreerCollaborateurAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, ['name' => 'Paul Lefèvre', 'email' => 'paul@cabinet.test']);

        $this->assertMatchesRegularExpression('/^\d{8}$/', (string) $compte->identifiantDeConnexion());
        $this->assertSame([RoleSociete::Collaborateur, true, null, (int) $cabinet->id], [$compte->role_societe, $compte->mot_de_passe_est_l_identifiant, $compte->email, (int) $compte->societe_id]);
        $this->assertTrue(password_verify((string) $compte->identifiantDeConnexion(), $compte->password));
        $this->assertSame([], $compte->autorisationsDeSociete());
        $this->assertSame(0, DB::table('affectations')->where('user_id', $compte->id)->count());
        $this->assertSame('Collaborateur du cabinet '.$cabinet->raison_sociale, $compte->libelleDuRoleDeSociete());
        $this->assertFalse($compte->doitChoisirSonMotDePasse());
    }

    /**
     * Après la création d'un collaborateur, la fenêtre « Compte créé »
     * montre son identifiant et propose d'office que la personne présente
     * choisisse son mot de passe, sans offrir de lui remettre son
     * identifiant, qui n'ouvre pas son compte.
     */
    public function test_la_fenetre_du_compte_cree_propose_d_office_au_collaborateur_de_choisir_son_mot_de_passe(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        $page = Livewire::test(ListCollaborateurs::class)->callAction('creerUnCollaborateur', ['name' => 'Paul Lefèvre']);
        $compte = User::query()->where('role_societe', RoleSociete::Collaborateur->value)->sole();

        $page->assertActionMounted('compteCree')
            ->assertActionDataSet(['mode' => 'present'])
            ->assertSee((string) $compte->identifiantDeConnexion())
            ->assertSeeText('Un cabinet n\'a pas de poste reconnu')
            ->assertDontSeeText('Je remets l\'identifiant à la personne')
            ->setActionData(['mode' => 'present', 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI, 'mot_de_passe_confirmation' => self::MOT_DE_PASSE_CHOISI])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertFalse($compte->fresh()->mot_de_passe_est_l_identifiant);
        $this->assertTrue(password_verify(self::MOT_DE_PASSE_CHOISI, $compte->fresh()->password));
    }

    public function test_un_collaborateur_ne_peut_pas_se_connecter_avec_son_identifiant_faute_de_poste_reconnu(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $compte = app(CreerCollaborateurAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, ['name' => 'Paul Lefèvre']);
        $identifiant = (string) $compte->identifiantDeConnexion();
        app(TenantContext::class)->clear();

        $this->assertSame([Connexion::MESSAGE_ECHEC.' '.Connexion::MESSAGE_POSTE], $this->seConnecter($identifiant, $identifiant)->errors()->get('data.login'));
        $this->assertGuest();
        $this->assertFalse(app(PostesReconnus::class)->ouvreLeCompte($compte, request()));

        app(RedonnerLAccesAction::class)->handle($this->proprietaireDuCabinet($cabinet), $compte, true, self::MOT_DE_PASSE_CHOISI, (string) Str::uuid());
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($compte);
    }

    /**
     * Seul le propriétaire du cabinet redonne l'accès à un collaborateur,
     * et seulement en sa présence : l'action refuse son absence, que la
     * fenêtre ne propose pas ; ses sessions se ferment, l'accès s'inscrit
     * au journal d'audit et s'affiche à la personne à sa connexion
     * suivante.
     */
    public function test_seul_le_proprietaire_du_cabinet_redonne_l_acces_a_un_collaborateur_et_seulement_en_sa_presence(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $autre = $this->collaborateur($cabinet, 'Anne Morel');
        $cible = $this->collaborateur($cabinet, 'Bruno Petit');
        $session = $this->ouvrirUneSessionAilleurs($cible);

        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($autre, $cible, true, 'un-autre-mot-de-passe', (string) Str::uuid()), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($proprietaire, $cible, false), LogicException::class, RedonnerLAccesAction::MESSAGE_COLLABORATEUR_PRESENT);
        $this->assertFalse($cible->fresh()->mot_de_passe_est_l_identifiant);
        $this->assertTrue(DB::table('sessions')->where('id', $session)->exists());

        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        Livewire::test(FicheDuCollaborateur::class, ['record' => $cible->getRouteKey()])
            ->mountAction('redonnerLAcces')
            ->assertActionDataSet(['mode' => 'present'])
            ->assertDontSeeText('Elle est absente')
            ->setActionData(['mode' => 'present', 'mot_de_passe' => 'nouveau-mot-de-passe', 'mot_de_passe_confirmation' => 'nouveau-mot-de-passe'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertTrue(password_verify('nouveau-mot-de-passe', $cible->fresh()->password));
        $this->assertSessionsFermees($cible);
        $acces = array_values(array_filter($this->entreesDAudit($cabinet, ActionAuditee::AccesRedonne), fn ($entree): bool => (int) $entree->nouvelles_valeurs['compte']['id'] === (int) $cible->id));
        $this->assertSame([[true, (int) $proprietaire->id], [true, (int) $proprietaire->id]], array_map(fn ($entree): array => [$entree->nouvelles_valeurs['en_presence'], (int) $entree->auteur_id], $acces));
        $this->assertNotNull($cible->fresh()->acces_redonne_le);
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesQuiOntDesMagasins(): array
    {
        return [
            'commerce ou atelier' => [TypeDActivite::Commerce],
            'services sans vente d\'articles' => [TypeDActivite::Services],
        ];
    }

    /**
     * La base refuse un collaborateur dans une société qui n'est pas un
     * cabinet, et un administrateur ou un membre dans un cabinet, même
     * écrits par une requête brute.
     */
    #[DataProvider('typesQuiOntDesMagasins')]
    public function test_la_base_refuse_un_collaborateur_hors_d_un_cabinet_et_un_administrateur_ou_un_membre_dans_un_cabinet(TypeDActivite $type): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => $type]);
        $responsable = $this->responsable($magasin);
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);
        $nouveau = fn (Societe $societe, RoleSociete $role): array => ['uuid' => (string) Str::uuid(), 'societe_id' => $societe->id, 'name' => 'Intrus', 'password' => 'x', 'role_societe' => $role->value, 'statut' => 'actif', 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->insert($nouveau($magasin->societe, RoleSociete::Collaborateur))), QueryException::class, 'un collaborateur n\'existe que dans un cabinet d\'expertise comptable');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $responsable->id)->update(['role_societe' => RoleSociete::Collaborateur->value])), QueryException::class);
        foreach ([RoleSociete::Administrateur, RoleSociete::Membre] as $role) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->insert($nouveau($cabinet, $role))), QueryException::class, 'un cabinet d\'expertise comptable n\'a ni administrateur ni membre');
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $collaborateur->id)->update(['role_societe' => $role->value])), QueryException::class, 'un cabinet d\'expertise comptable n\'a ni administrateur ni membre');
        }

        $this->assertSame([RoleSociete::Collaborateur, $type], [$collaborateur->fresh()->role_societe, $magasin->societe->fresh()->type_activite]);
    }

    public function test_la_base_refuse_une_affectation_ou_une_autorisation_de_societe_a_un_collaborateur(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert(['societe_id' => $cabinet->id, 'user_id' => $collaborateur->id, 'magasin_id' => null, 'role' => null, 'created_at' => now(), 'updated_at' => now()])), QueryException::class, 'un collaborateur d\'un cabinet n\'ont aucune affectation');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_societe')->insert(['societe_id' => $cabinet->id, 'user_id' => $collaborateur->id, 'autorisation' => 'societe.parametres.gerer'])), QueryException::class, 'seul un administrateur a des autorisations de société');

        $this->assertSame([[], 0], [$collaborateur->fresh()->autorisationsDeSociete(), DB::table('affectations')->where('user_id', $collaborateur->id)->count()]);
    }

    /**
     * Un collaborateur n'ouvre jamais l'espace d'administration d'une
     * société, pas même de son cabinet, et ne change jamais de rôle : le
     * propriétaire du cabinet n'a pas ce geste, et un cabinet n'a ni
     * administrateur ni membre.
     */
    public function test_un_collaborateur_n_ouvre_jamais_l_espace_societe_et_ne_change_jamais_de_role(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);

        $this->assertSame([false, true], [$collaborateur->canAccessPanel(Filament::getPanel('societe')), $collaborateur->canAccessPanel(Filament::getPanel('cabinet'))]);
        $this->actingAs($collaborateur)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $cabinet))->assertForbidden();
        $this->assertFalse(app(PorteeDesDroits::class)->peutChangerLeRoleDeSociete($proprietaire, $collaborateur));
        $this->assertFalse($proprietaire->can('changerLeRoleDeSociete', $collaborateur));
        $this->assertSame([false, false, false], [$proprietaire->can('creerUnAdministrateur', User::class), $proprietaire->can('create', User::class), $collaborateur->can('creerUnCollaborateur', User::class)]);
        $this->assertSame([RoleSociete::Proprietaire->value, RoleSociete::Collaborateur->value], User::query()->where('societe_id', $cabinet->id)->orderBy('id')->pluck('role_societe')->map(fn (RoleSociete $role): string => $role->value)->all());
    }

    public function test_la_creation_repetee_d_un_collaborateur_ne_cree_jamais_un_second_compte(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $jeton = (string) Str::uuid();

        $premier = app(CreerCollaborateurAction::class)->handle($proprietaire, $cabinet, ['name' => 'Paul Lefèvre'], $jeton);
        $repete = app(CreerCollaborateurAction::class)->handle($proprietaire, $cabinet, ['name' => 'Paul Lefèvre'], $jeton);

        $this->assertTrue($premier->is($repete));
        $this->assertThrows(fn () => app(CreerCollaborateurAction::class)->handle($proprietaire, $cabinet, ['name' => 'Autre Personne'], $jeton), LogicException::class, CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE);
        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::Collaborateur->value)->count());
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::CollaborateurCree));
    }

    /**
     * Un collaborateur retiré garde son rôle et son identifiant, sans plus
     * aucun accès : ni à l'espace du cabinet, ni à la connexion ; le
     * propriétaire le réintègre, toujours collaborateur.
     */
    public function test_retirer_un_collaborateur_garde_son_role_sans_aucun_acces_et_le_reintegre_sans_client_confie(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);
        $identifiant = $collaborateur->identifiantDeConnexion();

        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $collaborateur);

        $retire = $collaborateur->fresh();
        $this->assertSame([StatutCompte::Retire, RoleSociete::Collaborateur, $identifiant], [$retire->statut, $retire->role_societe, $retire->identifiantDeConnexion()]);
        $this->assertSame([false, false], [$retire->canAccessPanel(Filament::getPanel('cabinet')), $retire->canAccessTenant($cabinet)]);
        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter((string) $identifiant, self::MOT_DE_PASSE_CHOISI)->errors()->get('data.login'));
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrerLeCollaborateur($this->collaborateur($cabinet, 'Anne Morel'), $retire), AuthorizationException::class);

        app(ChangerStatutCompteAction::class)->reintegrerLeCollaborateur($proprietaire, $retire);

        $this->assertSame([StatutCompte::Actif, RoleSociete::Collaborateur, $identifiant], [$retire->fresh()->statut, $retire->fresh()->role_societe, $retire->fresh()->identifiantDeConnexion()]);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::CompteReintegre));
    }

    public function test_le_proprietaire_du_cabinet_est_prevenu_de_la_premiere_connexion_d_un_collaborateur(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);
        Notification::fake();

        $this->seConnecter((string) $collaborateur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, 'cabinet')->assertHasNoErrors();
        $this->seConnecter((string) $collaborateur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, 'cabinet')->assertHasNoErrors();

        $avis = Notification::sent($proprietaire, AvisDeLEquipe::class)->filter(fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Première connexion');
        $this->assertCount(1, $avis);
        $this->assertSame('Paul Lefèvre s\'est connecté à son compte pour la première fois.', $avis->first()->texte);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::PremiereConnexion));
    }

    /**
     * La propriété d'un cabinet se transmet comme celle d'une société, à un
     * collaborateur actif, qui a choisi son mot de passe et renseigné
     * lui-même son adresse : le lien part à cette adresse et mène à
     * l'espace du cabinet ; la propriété ne passe que quand il le suit,
     * connecté à son compte.
     */
    public function test_la_propriete_d_un_cabinet_se_transmet_a_un_collaborateur_qui_suit_le_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->renseignerSonAdresse($this->collaborateur($cabinet), 'paul@cabinet.test');

        $jeton = $this->demanderLaTransmission($proprietaire, $collaborateur, self::MOT_DE_PASSE_CHOISI);

        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/cabinet/transmission/', (string) Mail::sent(TransmissionDeLaProprieteMail::class)->last()->lien);
        $this->assertSame(RoleSociete::Collaborateur, $collaborateur->fresh()->role_societe);

        $this->actingAs($collaborateur->fresh());
        Filament::setCurrentPanel(Filament::getPanel('cabinet'));
        Livewire::test(AccepterLaTransmission::class, ['jeton' => $jeton])
            ->assertSeeText('En confirmant, vous devenez le propriétaire du cabinet')
            ->fillForm(['mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
            ->call('accepter')
            ->assertRedirect();

        $this->assertSame([RoleSociete::Proprietaire, 'paul@cabinet.test'], [$collaborateur->fresh()->role_societe, $collaborateur->fresh()->email]);
        $this->assertNotNull($collaborateur->fresh()->email_verified_at);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::ProprieteTransferee));
    }

    public function test_l_ancien_proprietaire_du_cabinet_devient_collaborateur_avec_tous_les_clients_confies(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->renseignerSonAdresse($this->collaborateur($cabinet), 'paul@cabinet.test');
        $jeton = $this->demanderLaTransmission($proprietaire, $collaborateur, self::MOT_DE_PASSE_CHOISI);
        app(TenantContext::class)->clear();

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($collaborateur, $jeton, self::MOT_DE_PASSE_CHOISI);

        $ancien = $proprietaire->fresh();
        $this->assertSame([RoleSociete::Collaborateur, null, []], [$ancien->role_societe, $ancien->email_verified_at, $ancien->autorisationsDeSociete()]);
        $this->assertSame(0, DB::table('affectations')->where('user_id', $ancien->id)->count());
        $this->assertTrue($ancien->canAccessPanel(Filament::getPanel('cabinet')));
        $this->assertFalse($ancien->canAccessPanel(Filament::getPanel('societe')));
        $this->assertContains('Propriété transmise', $ancien->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(1, User::query()->where('societe_id', $cabinet->id)->where('role_societe', RoleSociete::Proprietaire->value)->count());
    }

    /**
     * Une autre personne que celle qui a inscrit le cabinet saisit le code
     * de son courrier : elle en devient le propriétaire, et le compte de la
     * personne inscrite, devenu collaborateur (un cabinet n'a pas
     * d'administrateur), est retiré avec ses collaborateurs.
     */
    public function test_la_saisie_du_code_par_une_autre_personne_retire_les_comptes_d_un_cabinet_sans_administrateur(): void
    {
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $inscrit = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);

        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($cabinet, 'reprise@cabinet.test');

        $this->assertSame([RoleSociete::Proprietaire, true], [$nouveau->role_societe, $cabinet->fresh()->estValidee()]);
        $this->assertSame([[RoleSociete::Collaborateur, StatutCompte::Retire], [RoleSociete::Collaborateur, StatutCompte::Retire]], [
            [$inscrit->fresh()->role_societe, $inscrit->fresh()->statut],
            [$collaborateur->fresh()->role_societe, $collaborateur->fresh()->statut],
        ]);
    }

    /**
     * La page de connexion envoyée avec cette saisie, sans poste reconnu.
     */
    private function seConnecter(string $saisie, string $motDePasse, string $panneau = 'app'): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel($panneau));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => ''])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse])
            ->call('authenticate');
    }
}
