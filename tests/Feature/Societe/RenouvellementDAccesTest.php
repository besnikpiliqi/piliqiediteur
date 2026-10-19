<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un accès perdu se redonne (voir RedonnerLAccesAction et
 * PorteeDesDroits::peutRedonnerLAcces()) : par le propriétaire pour tout
 * compte ; par un responsable de son magasin pour un vendeur ou un
 * observateur ; par un administrateur autorisé à nommer les responsables
 * pour un responsable d'un magasin réel de sa portée ; par un administrateur
 * autorisé à redonner l'accès pour un vendeur ou un observateur d'un magasin
 * réel de sa portée ; personne ne redonne celui du propriétaire. La
 * personne présente tape elle-même son nouveau mot de passe ; absente, il
 * redevient son identifiant. Chaque renouvellement ferme les sessions,
 * s'inscrit au journal d'audit, s'affiche à la personne et, fait par un
 * autre, est signalé au propriétaire.
 */
class RenouvellementDAccesTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAU_MOT_DE_PASSE = 'un-mot-de-passe-solide';

    public function test_le_responsable_redonne_l_acces_d_un_vendeur_de_son_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->callTableAction('redonner_l_acces', $saLigne, ['mode' => 'absent'])
            ->assertHasNoTableActionErrors()
            ->assertNotified("Accès redonné. Son identifiant, qui est de nouveau son mot de passe : {$vendeur->identifiantDeConnexion()}. Remettez-le-lui en main propre.");

        $vendeur->refresh();
        $this->assertTrue(Hash::check((string) $vendeur->identifiantDeConnexion(), $vendeur->password));
        $this->assertSame([true, false, (int) $responsable->id], [$vendeur->mot_de_passe_est_l_identifiant, $vendeur->acces_redonne_en_presence, (int) $vendeur->acces_redonne_par]);
    }

    public function test_un_responsable_ne_redonne_l_acces_ni_d_un_autre_responsable_ni_d_un_compte_d_un_autre_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $collegue = $this->responsable($magasin);
        $vendeurDAilleurs = $this->membre($autre, RoleMagasin::Vendeur);

        foreach ([$collegue, $vendeurDAilleurs] as $cible) {
            $this->assertFalse($responsable->can('redonnerLAcces', $cible), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($responsable, $cible, false), AuthorizationException::class);
            $this->assertNull($cible->refresh()->acces_redonne_le);
        }
    }

    public function test_un_administrateur_autorise_a_nommer_les_responsables_redonne_l_acces_d_un_responsable_de_sa_portee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $responsableHorsPortee = $this->responsable($horsPortee);

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($administrateur, $responsable, true, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()));

        $this->assertTrue(Hash::check(self::NOUVEAU_MOT_DE_PASSE, $responsable->refresh()->password));
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($administrateur, $responsableHorsPortee, false), AuthorizationException::class);
        $this->assertNull($responsableHorsPortee->refresh()->acces_redonne_le);
    }

    /**
     * Autorisé par le propriétaire à redonner l'accès, un administrateur le
     * fait pour un vendeur d'un magasin de sa portée, et rien d'autre sur ce
     * compte : ni ses cases, ni son rôle, ni sa suspension, ni son retrait ;
     * rien pour un vendeur d'un magasin hors de sa portée.
     */
    public function test_un_administrateur_autorise_par_le_proprietaire_redonne_l_acces_d_un_vendeur_de_sa_portee_et_ne_fait_rien_d_autre_sur_ce_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER], PermissionRegistry::toutes());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurHorsPortee = $this->membre($horsPortee, RoleMagasin::Vendeur);

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($administrateur, $vendeur, false));
        $this->assertTrue($vendeur->refresh()->mot_de_passe_est_l_identifiant);

        foreach (['update', 'suspendreOuReactiver', 'retirerDeLaSociete', 'changerLeRoleDeSociete'] as $geste) {
            $this->assertFalse($administrateur->can($geste, $vendeur), $geste);
        }
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeur, RoleMagasin::Observateur), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($administrateur, $vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $vendeur), AuthorizationException::class);
        $this->assertSame([RoleMagasin::Vendeur, true], [Affectation::query()->where('user_id', $vendeur->id)->sole()->role, $vendeur->refresh()->estActif()]);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($administrateur, $vendeurHorsPortee, false), AuthorizationException::class);
    }

    /**
     * Dans l'espace de la société, un administrateur qui n'a que « redonner
     * l'accès » voit les vendeurs et les observateurs des magasins réels de
     * sa portée, et aucun autre compte ; sur leur fiche, seuls « Redonner
     * l'accès » et « Afficher l'identifiant » ; ni identifiant ni badge en
     * liste.
     */
    public function test_un_administrateur_qui_n_a_que_redonner_l_acces_voit_les_vendeurs_de_sa_portee_et_rien_d_autre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);
        $invisibles = [
            $this->responsable($magasin),
            $this->membre($horsPortee, RoleMagasin::Vendeur),
            $this->administrateur($magasin),
            $this->expertComptable($magasin),
            $this->proprietaireDe($magasin),
        ];

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(ListMembres::class)
            ->assertCanSeeTableRecords([$vendeur, $observateur])
            ->assertCanNotSeeTableRecords($invisibles)
            ->assertTableColumnHidden('identifiant')
            ->assertTableColumnHidden('signaux')
            ->assertActionHidden('creerUnMembre')
            ->assertActionHidden('nommerUnResponsable')
            ->assertActionHidden('creerUnAdministrateur');

        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]);
        $fiche->assertActionVisible('redonnerLAcces')->assertActionVisible('afficherLIdentifiant')->assertDontSee((string) $vendeur->identifiantDeConnexion());
        foreach (['modifierSonRole', 'changerLeRoleDeSociete', 'suspendre', 'reactiver', 'retirerDeLaSociete', 'reintegrer', 'annulerLaNomination'] as $geste) {
            $fiche->assertActionHidden($geste);
        }
    }

    /**
     * Le même administrateur ne crée ni ne modifie aucun compte, même par
     * des requêtes Livewire forgées : ni création, ni cases, ni suspension,
     * ni retrait.
     */
    public function test_il_ne_cree_aucun_compte_meme_par_une_action_livewire_forgee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER], PermissionRegistry::toutes());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $comptes = User::query()->count();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'creerUnMembre', ['jeton' => (string) Str::uuid(), 'magasin_id' => $magasin->id, 'name' => 'Intrus', 'role' => RoleMagasin::Vendeur->value]);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'nommerUnResponsable', ['magasin_id' => $magasin->id, 'compte' => 'nouveau', 'name' => 'Intrus']);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'modifierSonRole', ['role' => RoleMagasin::Vendeur->value, 'autorisations' => PermissionRegistry::toutes()]);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'suspendre');
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'retirerDeLaSociete');

        $this->assertSame($comptes, User::query()->count());
        $this->assertFalse(User::query()->where('name', 'Intrus')->exists());
        $this->assertTrue($vendeur->refresh()->estActif());
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $vendeur->autorisationsDans($magasin));
    }

    /**
     * Un administrateur ne voit l'identifiant d'un vendeur que par
     * « Afficher l'identifiant » : le geste s'inscrit au journal d'audit, le
     * compte sans sa valeur, et le propriétaire en est prévenu, dans sa
     * cloche et par e-mail, quand le mot de passe est encore l'identifiant ;
     * il ne l'est pas pour un compte dont la personne a choisi le sien.
     */
    public function test_un_administrateur_ne_voit_l_identifiant_que_par_une_action_tracee_et_le_proprietaire_en_est_prevenu(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        [$vendeur, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $prudent = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])
            ->assertDontSee($identifiant)
            ->callAction('afficherLIdentifiant')
            ->assertNotified("Identifiant de {$vendeur->name} : {$identifiant}");

        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::IdentifiantConsulte);
        $this->assertCount(1, $entrees);
        $this->assertSame((int) $vendeur->id, (int) $entrees[0]->nouvelles_valeurs['compte']['id']);
        $this->assertStringNotContainsString($identifiant, json_encode($entrees[0]->toArray(), JSON_THROW_ON_ERROR));
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Identifiant affiché à un administrateur' && $avis->via($proprietaire) === ['database', 'mail'] && ! str_contains($avis->texte, $identifiant));

        Livewire::test(FicheDuMembre::class, ['record' => $prudent->getRouteKey()])->callAction('afficherLIdentifiant');
        Notification::assertSentToTimes($proprietaire, AvisDeLEquipe::class, 1);
        $this->assertCount(2, $this->entreesDAudit($magasin->societe, ActionAuditee::IdentifiantConsulte));
    }

    public function test_un_administrateur_ne_nomme_aucun_responsable_dans_la_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($reel, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        $entrainement = app(TenantContext::class)->runAs($demonstration, fn (): User => app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Vendeur d\'essai'], RoleMagasin::Vendeur));
        $portee = app(PorteeDesDroits::class);

        $this->assertFalse($portee->peutNommerUnResponsable($administrateur->refresh(), $demonstration));
        $this->assertSame([], $portee->candidatsResponsables($administrateur, $demonstration)->pluck('id')->all());
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $entrainement, RoleMagasin::Responsable), AuthorizationException::class);
        $this->assertThrows(fn () => app(TenantContext::class)->runAs($demonstration, fn () => app(CreerMembreAction::class)->handle($administrateur, $demonstration, ['name' => 'Chef d\'essai'], RoleMagasin::Responsable)), AuthorizationException::class);
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $entrainement->id)->sole()->role);
    }

    /**
     * Même autorisé à tout, un administrateur ne redonne l'accès d'aucun
     * compte d'entraînement : seuls le propriétaire et un responsable du
     * magasin de démonstration le font.
     */
    public function test_un_administrateur_ne_redonne_pas_l_acces_d_un_compte_d_entrainement(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($reel, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        [$chef, $vendeur] = app(TenantContext::class)->runAs($demonstration, fn (): array => [
            $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Chef d\'essai'], RoleMagasin::Responsable)),
            $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Vendeur d\'essai'], RoleMagasin::Vendeur)),
        ]);

        foreach ([$chef, $vendeur] as $cible) {
            $this->assertFalse($administrateur->can('redonnerLAcces', $cible), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($administrateur, $cible, false), AuthorizationException::class);
        }
        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($chef, $vendeur, false));
        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($societe->proprietaire, $chef, false));
    }

    public function test_un_administrateur_sans_autorisation_ne_redonne_aucun_acces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::PARAMETRES_GERER], PermissionRegistry::toutes());

        foreach ([$this->membre($magasin, RoleMagasin::Vendeur), $this->membre($magasin, RoleMagasin::Observateur), $this->responsable($magasin)] as $cible) {
            $this->assertFalse($administrateur->can('redonnerLAcces', $cible), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($administrateur, $cible, false), AuthorizationException::class);
        }
    }

    public function test_seul_le_proprietaire_redonne_l_acces_d_un_administrateur_ou_d_un_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreAdministrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $responsable = $this->responsable($magasin);

        foreach ([$this->administrateur($magasin), $this->expertComptable($magasin)] as $cible) {
            foreach ([$autreAdministrateur, $responsable] as $auteur) {
                $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($auteur, $cible, false), AuthorizationException::class);
            }
            $this->assertTrue(app(RedonnerLAccesAction::class)->handle($this->proprietaireDe($magasin), $cible, false));
            $this->assertTrue($cible->refresh()->mot_de_passe_est_l_identifiant);
        }
    }

    public function test_personne_ne_redonne_l_acces_du_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $motDePasse = $proprietaire->password;

        foreach ([$proprietaire, $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes()), $this->responsable($magasin)] as $auteur) {
            $this->assertFalse($auteur->can('redonnerLAcces', $proprietaire), $auteur->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($auteur, $proprietaire, true, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()), AuthorizationException::class);
        }

        $this->assertSame([$motDePasse, null], [$proprietaire->refresh()->password, $proprietaire->acces_redonne_le]);
    }

    /**
     * La personne présente tape son nouveau mot de passe, qui suit la règle
     * du mot de passe choisi : ni trop court, ni son identifiant, ni une
     * chaîne qui le contient, ni un seul caractère répété, ni une suite de
     * chiffres.
     */
    public function test_en_presence_la_personne_choisit_son_mot_de_passe_et_il_suit_la_regle(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $refuses = [
            'court7c' => RegleDuMotDePasse::MESSAGE_TROP_COURT,
            $identifiant => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            "mon-{$identifiant}!" => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            'aaaaaaaaaa' => RegleDuMotDePasse::MESSAGE_REPETE,
            '23456789' => RegleDuMotDePasse::MESSAGE_SUITE,
        ];

        foreach ($refuses as $motDePasse => $message) {
            try {
                app(RedonnerLAccesAction::class)->handle($this->proprietaireDe($magasin), $vendeur, true, (string) $motDePasse, (string) Str::uuid());
                $this->fail("Le mot de passe « {$motDePasse} » est accepté.");
            } catch (ValidationException $refus) {
                $this->assertContains($message, $refus->errors()['mot_de_passe'], (string) $motDePasse);
            }
        }
        $this->assertNull($vendeur->refresh()->acces_redonne_le);

        app(RedonnerLAccesAction::class)->handle($this->proprietaireDe($magasin), $vendeur, true, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid());

        $vendeur->refresh();
        $this->assertTrue(Hash::check(self::NOUVEAU_MOT_DE_PASSE, $vendeur->password));
        $this->assertSame([false, true], [$vendeur->mot_de_passe_est_l_identifiant, $vendeur->acces_redonne_en_presence]);
    }

    /**
     * La même demande, au même jeton de fenêtre, répétée après une réponse
     * perdue, ne fait rien de plus : une entrée d'audit, un avis au
     * propriétaire, les sessions fermées une seule fois.
     */
    public function test_un_acces_redonne_en_presence_repete_ne_s_inscrit_qu_une_fois(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $jeton = (string) Str::uuid();

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($administrateur, $vendeur, true, self::NOUVEAU_MOT_DE_PASSE, $jeton));
        $session = $this->ouvrirUneSessionAilleurs($vendeur->refresh());
        $this->assertFalse(app(RedonnerLAccesAction::class)->handle($administrateur, $vendeur, true, self::NOUVEAU_MOT_DE_PASSE, $jeton));

        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::AccesRedonne));
        Notification::assertSentToTimes($this->proprietaireDe($magasin), AvisDeLEquipe::class, 1);
        $this->assertTrue(DB::table('sessions')->where('id', $session)->exists());
        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($administrateur, $vendeur->refresh(), true, 'un-autre-mot-de-passe', (string) Str::uuid()));
        $this->assertSessionsFermees($vendeur);
    }

    public function test_en_absence_le_mot_de_passe_redevient_l_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($this->proprietaireDe($magasin), $vendeur, false));

        $vendeur->refresh();
        $this->assertTrue(Hash::check((string) $vendeur->identifiantDeConnexion(), $vendeur->password));
        $this->assertFalse(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
        $this->assertSame([true, false, null], [$vendeur->mot_de_passe_est_l_identifiant, $vendeur->acces_redonne_en_presence, $vendeur->jeton_acces_redonne]);
    }

    public function test_en_absence_un_mot_de_passe_deja_egal_a_l_identifiant_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$vendeur] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($proprietaire, $vendeur, false), LogicException::class, RedonnerLAccesAction::MESSAGE_DEJA_L_IDENTIFIANT);
        $this->assertNull($vendeur->refresh()->acces_redonne_le);

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($proprietaire, $vendeur, true, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()));
        $this->assertFalse($vendeur->refresh()->mot_de_passe_est_l_identifiant);
    }

    public function test_l_acces_d_un_compte_suspendu_ne_se_redonne_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        foreach ([$suspendu, $retire] as $cible) {
            $this->assertFalse($proprietaire->can('redonnerLAcces', $cible->refresh()), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($proprietaire, $cible, false), LogicException::class, RedonnerLAccesAction::MESSAGE_INACTIF);
            $this->assertNull($cible->refresh()->acces_redonne_le);
        }
    }

    public function test_redonner_l_acces_ferme_les_sessions_coupe_le_rappel_et_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $this->ouvrirUneSessionAilleurs($vendeur);

        app(RedonnerLAccesAction::class)->handle($responsable, $vendeur, false);

        $this->assertSessionsFermees($vendeur);
        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::AccesRedonne);
        $this->assertCount(1, $entrees);
        $this->assertSame((int) $responsable->id, (int) $entrees[0]->auteur_id);
        $this->assertSame(['compte' => ['id' => $vendeur->id, 'nom' => 'Paul Martin'], 'en_presence' => false], $entrees[0]->nouvelles_valeurs);
        $this->assertStringNotContainsString((string) $vendeur->identifiantDeConnexion(), json_encode($entrees[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Un accès redonné par un autre que le propriétaire lui est signalé,
     * dans sa cloche et par e-mail ; puis le premier mot de passe que la
     * personne choisit ensuite, une seule fois. Un accès qu'il redonne
     * lui-même ne lui est pas signalé.
     */
    public function test_le_proprietaire_est_prevenu_d_un_acces_redonne_par_un_autre_puis_du_premier_mot_de_passe_choisi_ensuite(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);

        app(RedonnerLAccesAction::class)->handle($responsable, $vendeur, false);

        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Accès redonné'
            && str_contains($avis->texte, "{$responsable->name} a redonné l'accès de Paul Martin, en son absence")
            && $avis->via($proprietaire) === ['database', 'mail']);
        $this->assertTrue($vendeur->refresh()->signaler_le_prochain_mot_de_passe);

        app(ChangerSonMotDePasseAction::class)->handle($vendeur, self::NOUVEAU_MOT_DE_PASSE);
        app(ChangerSonMotDePasseAction::class)->handle($vendeur->refresh(), 'encore-un-autre-mot-de-passe');

        Notification::assertSentToTimes($proprietaire, AvisDeLEquipe::class, 2);
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Mot de passe choisi' && str_contains($avis->texte, 'Paul Martin'));
        $this->assertFalse($vendeur->refresh()->signaler_le_prochain_mot_de_passe);

        app(RedonnerLAccesAction::class)->handle($proprietaire, $vendeur, false);
        app(ChangerSonMotDePasseAction::class)->handle($vendeur->refresh(), self::NOUVEAU_MOT_DE_PASSE);
        Notification::assertSentToTimes($proprietaire, AvisDeLEquipe::class, 2);
    }

    /**
     * À sa connexion suivante, la personne voit sa connexion précédente,
     * puis que son accès a été redonné, quand, par qui et en sa présence ;
     * à la connexion d'après, plus rien de l'accès redonné.
     */
    public function test_la_personne_voit_l_acces_redonne_et_sa_derniere_connexion_a_sa_connexion_suivante(): void
    {
        $this->travelTo('2026-10-12 09:15:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->membre($magasin, RoleMagasin::Responsable, null, ['name' => 'Julie Morel']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();

        $this->travelTo('2026-10-13 18:20:00');
        app(RedonnerLAccesAction::class)->handle($responsable, $vendeur->refresh(), true, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid());
        $this->travelTo('2026-10-14 08:05:00');

        $this->seConnecter($identifiant, self::NOUVEAU_MOT_DE_PASSE)->assertHasNoErrors();
        $this->assertSame(['Votre connexion précédente : le 12/10/2026 à 09:15', 'Votre accès a été redonné le 13/10/2026 à 18:20 par Julie Morel, en votre présence.'], $this->titresDesAvisALaPersonne());

        $this->travelTo('2026-10-15 08:00:00');
        $this->seConnecter($identifiant, self::NOUVEAU_MOT_DE_PASSE)->assertHasNoErrors();
        $this->assertSame(['Votre connexion précédente : le 14/10/2026 à 08:05'], $this->titresDesAvisALaPersonne());
    }

    /**
     * Les titres des avis que la connexion vient de montrer à la personne,
     * dans l'ordre, lus dans sa session sans les consommer.
     *
     * @return list<string>
     */
    private function titresDesAvisALaPersonne(): array
    {
        return collect(session('filament.notifications', []))->pluck('title')->values()->all();
    }

    /**
     * Une connexion au panneau du magasin, sans session ouverte ni témoin.
     */
    private function seConnecter(string $saisie, string $motDePasse): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => ''])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse])
            ->call('authenticate');
    }
}
