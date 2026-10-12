<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire, et lui seul, transmet la propriété de sa société, en
 * redonnant son mot de passe, à un administrateur actif dont la qualité de
 * représentant est prouvée à son nom ; il en devient administrateur de
 * tous les magasins, avec toutes les autorisations, et la société garde
 * toujours un seul propriétaire.
 */
class TransfererProprieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le mot de passe que la fabrique donne au propriétaire.
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    public function test_le_proprietaire_transmet_la_propriete_a_un_administrateur_dont_la_qualite_est_prouvee(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->assertActionVisible('transmettreLaPropriete')
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertHasNoActionErrors()
            ->assertRedirect(MembreResource::getUrl('index'));

        $this->assertSame(RoleSociete::Proprietaire, $administrateur->refresh()->role_societe);
        $this->assertSame(RoleSociete::Administrateur, $proprietaire->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
        foreach ([$magasin, $autre] as $chacun) {
            $siens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $administrateur->id)->last();
            $this->assertSame(RoleSociete::Proprietaire->value, $siens['apres']['role_societe'], $chacun->nom);
            $anciens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $proprietaire->id)->last();
            $this->assertSame(RoleSociete::Administrateur->value, $anciens['apres']['role_societe'], $chacun->nom);
        }
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee);
        $this->assertSame(RoleSociete::Proprietaire->value, $entree->nouvelles_valeurs['droits'][$administrateur->id]['role_societe']);
        $this->assertSame(RoleSociete::Administrateur->value, $entree->nouvelles_valeurs['droits'][$proprietaire->id]['role_societe']);
        Notification::assertSentTo($administrateur, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Vous êtes propriétaire');
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Propriété transmise');
    }

    public function test_le_transfert_a_un_administrateur_sans_qualite_prouvee_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, TransfererProprieteAction::MESSAGE_QUALITE);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    /**
     * Le mot de passe du propriétaire se redonne à chaque transfert : sans
     * lui, ou faux, rien ne change.
     */
    public function test_le_mot_de_passe_est_redemande(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => ''])
            ->assertHasActionErrors(['mot_de_passe' => 'required']);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => 'devine-au-hasard'])
            ->assertNotified(TransfererProprieteAction::MESSAGE_MOT_DE_PASSE);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
    }

    /**
     * Après un transfert réussi comme après chaque transfert refusé, la
     * société a exactement un propriétaire actif ; la base refuse d'en
     * poser un second.
     */
    public function test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = tap($this->proprietaireDe($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();
        $premier = $this->administrateurQualifie($magasin);
        $sansQualite = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $unSeulProprietaireActif = fn (): bool => User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->where('statut', 'actif')->count() === 1;

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class);
        $this->assertTrue($unSeulProprietaireActif());
        app(TransfererProprieteAction::class)->handle($proprietaire, $premier, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire->refresh(), $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        app(TransfererProprieteAction::class)->handle($premier->refresh(), $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire->refresh(), $retire);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $retire->id)->update(['role_societe' => RoleSociete::Proprietaire->value])), UniqueConstraintViolationException::class);
        $this->assertTrue($unSeulProprietaireActif());
    }

    public function test_un_administrateur_ne_transmet_pas_la_propriete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);
        $autre = $this->administrateurQualifie($magasin);

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($administrateur, $autre, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($administrateur, $administrateur, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Administrateur], [$administrateur->refresh()->role_societe, $autre->refresh()->role_societe]);
    }

    /**
     * La propriété ne va qu'à un administrateur actif de la société : ni à
     * l'administrateur d'une autre société, ni à un administrateur
     * suspendu, ni à un membre, même responsable, ni au propriétaire
     * lui-même.
     */
    public function test_la_propriete_ne_va_ni_a_un_compte_d_une_autre_societe_ni_a_un_compte_suspendu_ni_a_un_simple_membre(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $administrateurAilleurs = $this->administrateurQualifie($ailleurs);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->administrateurQualifie($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        $responsable = tap($this->responsable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        foreach ([$administrateurAilleurs, $suspendu->refresh(), $responsable, $proprietaire] as $cible) {
            $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $cible, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_ADMINISTRATEUR);
        }

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateurAilleurs->refresh()->role_societe);
        $this->assertSame(RoleSociete::Membre, $responsable->refresh()->role_societe);
    }

    /**
     * Le nouveau propriétaire perd sa sélection, ses cases et ses
     * autorisations de société ; l'ancien reçoit tous les magasins,
     * présents et futurs, toutes les cases et toutes les autorisations de
     * société.
     */
    public function test_le_nouveau_proprietaire_perd_ses_affectations_et_l_ancien_devient_administrateur_de_tous_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = tap($this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER], [PermissionRegistry::FACTURE_VIEW])->forceFill(['qualite_verifiee_le' => now()]))->save();

        app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
        $globale = Affectation::query()->where('user_id', $proprietaire->id)->sole();
        $this->assertNull($globale->magasin_id);
        $this->assertNull($globale->role);
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $globale->autorisations());
        $proprietaire->refresh();
        $this->assertEqualsCanonicalizing(AutorisationsSociete::toutes(), $proprietaire->autorisationsDeSociete());
        $this->assertTrue($proprietaire->administreTousLesMagasins());

        $futur = app(TenantContext::class)->runAs($magasin, fn (): Magasin => $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Magasin futur']));
        $this->assertTrue($proprietaire->refresh()->peutOuvrir($futur));
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $proprietaire->autorisationsDans($futur));
    }

    /**
     * Une requête Livewire forgée par un administrateur, sur la fiche d'un
     * autre administrateur qu'il voit, ne transmet rien : l'action lui est
     * masquée, et l'action du domaine le refuse.
     */
    public function test_un_transfert_force_par_une_requete_livewire_d_un_administrateur_ne_change_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);
        $responsable = tap($this->responsable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $responsable->getRouteKey()])->assertActionHidden('transmettreLaPropriete'), 'transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_CHOISI]);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    public function test_la_propriete_ne_va_jamais_a_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = tap($this->expertComptable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->assertActionHidden('transmettreLaPropriete');
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $expert, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_ADMINISTRATEUR);

        $this->assertSame(RoleSociete::ExpertComptable, $expert->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * Un administrateur de tous les magasins, avec toutes les autorisations
     * de société, dont la qualité de représentant est prouvée à son nom.
     */
    private function administrateurQualifie(Magasin $magasin): User
    {
        return tap($this->administrateur($magasin, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
    }
}
