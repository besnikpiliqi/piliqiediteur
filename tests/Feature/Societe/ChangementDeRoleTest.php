<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un changement de rôle garde le compte, son identifiant et son historique
 * (voir ModifierMembreAction et ChangerLeRoleDeSocieteAction) : un vendeur
 * devient responsable dans son magasin, administrateur ou expert-comptable
 * de la société, et un administrateur ne redevient membre que dans le
 * magasin de son identifiant, écrit une seule fois. Chaque changement
 * s'inscrit au journal d'audit ; seul le propriétaire change le rôle de
 * société, jamais celui d'un compte d'entraînement.
 */
class ChangementDeRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_devient_responsable_de_son_magasin_avec_le_meme_compte_et_le_meme_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $identifiant = $vendeur->identifiantDeConnexion();
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, RoleMagasin::Responsable);

        $this->assertSame([$identifiant, true], [$vendeur->refresh()->identifiantDeConnexion(), $vendeur->estResponsableDe($magasin)]);
        $this->assertSame([(int) $affectation->id, RoleMagasin::Responsable], [(int) Affectation::query()->where('user_id', $vendeur->id)->sole()->id, $affectation->fresh()->role]);
        $this->assertSame(1, User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Membre->value)->where('id', $vendeur->id)->count());
    }

    /**
     * Nommé administrateur depuis sa fiche, un vendeur garde son compte et
     * son identifiant ; la fenêtre ne précoche que le modèle de
     * l'administrateur, aucune autorisation de vente.
     */
    public function test_un_vendeur_devient_administrateur_avec_le_meme_compte_sans_aucune_autorisation_de_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
        $identifiant = $vendeur->identifiantDeConnexion();

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $page = Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])->mountAction('changerLeRoleDeSociete');
        $this->assertSame([], $page->get('mountedActionsData.0.autorisations_magasin_vente'));
        $page->setActionData(['role_societe' => RoleSociete::Administrateur->value, 'portee' => 'tous'])->callMountedAction()->assertHasNoActionErrors();

        $vendeur->refresh();
        $this->assertSame([RoleSociete::Administrateur, $identifiant, (int) $magasin->id], [$vendeur->role_societe, $vendeur->identifiantDeConnexion(), (int) $vendeur->magasinDeSonIdentifiant()?->id]);
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $vendeur->autorisationsDans($magasin))));
        $this->assertFalse($vendeur->tientUneCaisseDans($magasin));
        $this->assertSame(0, Affectation::query()->where('user_id', $vendeur->id)->whereNotNull('role')->count());
    }

    /**
     * Un administrateur, ancien vendeur, ne redevient membre que dans le
     * magasin de son identifiant : un autre magasin choisi ne compte pas.
     */
    public function test_un_administrateur_ne_redevient_membre_que_dans_le_magasin_de_son_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $vendeur, null, [], []);

        app(ChangerLeRoleDeSocieteAction::class)->versMembre($proprietaire, $vendeur, RoleMagasin::Observateur, null, $autre->id);

        $this->assertSame([[(int) $magasin->id, RoleMagasin::Observateur]], Affectation::query()->where('user_id', $vendeur->id)->get()->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());
        $this->assertFalse($vendeur->refresh()->peutOuvrir($autre));
    }

    /**
     * Un compte né administrateur, sans magasin, reçoit à sa première
     * rétrogradation le magasin réel ouvert que le propriétaire lui
     * choisit, une fois pour toutes : sans choix, ou avec un magasin fermé,
     * rien ne change ; ensuite, un autre choix ne compte plus.
     */
    public function test_un_compte_ne_sans_magasin_recoit_son_magasin_une_seule_fois_a_sa_premiere_retrogradation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $ferme = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique fermée']);
        $proprietaire = $this->proprietaireDe($magasin);
        app(FermerMagasinAction::class)->handle($proprietaire, $ferme->fresh(), MotifFermeture::Temporaire, 'password');
        $administrateur = $this->administrateur($magasin);
        $action = app(ChangerLeRoleDeSocieteAction::class);

        $this->assertThrows(fn () => $action->versMembre($proprietaire, $administrateur, RoleMagasin::Vendeur), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_MAGASIN_A_CHOISIR);
        $this->assertThrows(fn () => $action->versMembre($proprietaire, $administrateur, RoleMagasin::Vendeur, null, $ferme->id), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_MAGASIN_A_CHOISIR);
        $this->assertSame([RoleSociete::Administrateur, null], [$administrateur->refresh()->role_societe, $administrateur->magasinDeSonIdentifiant()]);

        $action->versMembre($proprietaire, $administrateur, RoleMagasin::Vendeur, null, $magasin->id);
        $action->versAdministrateur($proprietaire, $administrateur->refresh(), null, [], []);
        $action->versMembre($proprietaire, $administrateur->refresh(), RoleMagasin::Vendeur, null, $autre->id);

        $this->assertSame((int) $magasin->id, (int) $administrateur->refresh()->magasinDeSonIdentifiant()?->id);
        $this->assertSame([(int) $magasin->id], Affectation::query()->where('user_id', $administrateur->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Un changement de rôle de société s'inscrit au journal d'audit, au
     * nom de son auteur, à sa date, avec l'ancien et le nouveau rôle.
     */
    public function test_un_changement_de_role_s_inscrit_au_journal_d_audit_avec_l_ancien_et_le_nouveau_role_la_date_et_l_auteur(): void
    {
        $this->travelTo('2026-10-12 09:30:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);

        app(ChangerLeRoleDeSocieteAction::class)->versExpertComptable($proprietaire, $vendeur);

        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::RoleDeSocieteChange);
        $this->assertSame([RoleSociete::Membre->value, RoleSociete::ExpertComptable->value], [$entree->anciennes_valeurs['droits'][$vendeur->id]['role_societe'], $entree->nouvelles_valeurs['droits'][$vendeur->id]['role_societe']]);
        $this->assertSame(['Paul', (int) $proprietaire->id, true], [$entree->nouvelles_valeurs['droits'][$vendeur->id]['nom'], (int) $entree->auteur_id, $entree->created_at->equalTo(now())]);
        $dernier = collect($this->modificationsDesDroits($magasin))->last();
        $this->assertSame([$vendeur->id, 'membre', 'expert_comptable'], [$dernier['membre']['id'], $dernier['avant']['role_societe'], $dernier['apres']['role_societe']]);
    }

    public function test_les_ventes_passees_restent_au_nom_du_compte_apres_un_changement_de_role(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes(), ['name' => 'Paul Vendeur']);
        $vente = self::vendreAuComptant($vendeur, Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

        app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($this->proprietaireDe($magasin), $vendeur, null, [], []);
        $vendeur->forceFill(['name' => 'Paul Administrateur'])->save();

        $vente = Facture::query()->findOrFail($vente->id);
        $this->assertSame([(int) $vendeur->id, 'Paul Vendeur'], [(int) $vente->cree_par, $vente->cree_par_nom]);
        $this->assertSame((int) $vendeur->id, (int) $vente->enregistrementDeVente()->firstOrFail()->operateur_id);
    }

    /**
     * Seul le propriétaire change le rôle de société d'un compte : ni un
     * administrateur autorisé à tout, ni un responsable ; l'action leur est
     * masquée et refusée.
     */
    public function test_seul_le_proprietaire_change_le_role_de_societe_d_un_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsableNomme = $this->responsable($magasin);

        foreach ([$administrateur, $responsable] as $auteur) {
            $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($auteur, $vendeur, null, [], []), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versExpertComptable($auteur, $vendeur), AuthorizationException::class);
        }
        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $responsableNomme->getRouteKey()])->assertActionHidden('changerLeRoleDeSociete');
        $this->forgerLAction($fiche, 'changerLeRoleDeSociete', ['role_societe' => RoleSociete::ExpertComptable->value]);

        $this->assertSame([RoleSociete::Membre, RoleSociete::Membre], [$vendeur->refresh()->role_societe, $responsableNomme->refresh()->role_societe]);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::RoleDeSocieteChange));
    }

    /**
     * Retiré, un compte garde son rôle de société, et se réintègre dans ce
     * rôle : un membre dans son magasin, un administrateur avec la portée
     * choisie, un expert-comptable comme tel ; le rôle d'un compte retiré
     * ne se change pas.
     */
    public function test_un_compte_retire_garde_son_role_et_se_reintegre_dans_ce_role(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $administrateur = $this->administrateur($magasin);
        $expert = $this->expertComptable($magasin);
        $statuts = app(ChangerStatutCompteAction::class);
        foreach ([$vendeur, $administrateur, $expert] as $compte) {
            $statuts->retirer($proprietaire, $compte);
        }

        $this->assertSame([RoleSociete::Membre, RoleSociete::Administrateur, RoleSociete::ExpertComptable], [$vendeur->refresh()->role_societe, $administrateur->refresh()->role_societe, $expert->refresh()->role_societe]);
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $vendeur, null, [], []), AuthorizationException::class);

        $statuts->reintegrer($proprietaire, $vendeur, RoleMagasin::Observateur);
        $statuts->reintegrerLAdministrateur($proprietaire, $administrateur, [$magasin->id], [], []);
        $statuts->reintegrerLExpertComptable($proprietaire, $expert);

        $this->assertSame([StatutCompte::Actif, RoleMagasin::Observateur, (int) $magasin->id], [$vendeur->refresh()->statut, Affectation::query()->where('user_id', $vendeur->id)->sole()->role, (int) Affectation::query()->where('user_id', $vendeur->id)->sole()->magasin_id]);
        $this->assertSame([StatutCompte::Actif, RoleSociete::Administrateur, true], [$administrateur->refresh()->statut, $administrateur->role_societe, $administrateur->administreLeMagasin($magasin)]);
        $this->assertSame([StatutCompte::Actif, RoleSociete::ExpertComptable], [$expert->refresh()->statut, $expert->role_societe]);
    }

    /**
     * Promouvoir un compte dont le mot de passe est encore son identifiant
     * avertit le propriétaire, sans bloquer : le compte change de rôle, et
     * la personne présente peut choisir son mot de passe dans la même
     * fenêtre.
     */
    public function test_promouvoir_un_compte_dont_le_mot_de_passe_est_l_identifiant_avertit_sans_bloquer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$absent] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        [$present] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(FicheDuMembre::class, ['record' => $absent->getRouteKey()])
            ->mountAction('changerLeRoleDeSociete')
            ->assertSee(ListMembres::AVERTISSEMENT_IDENTIFIANT)
            ->setActionData(['role_societe' => RoleSociete::Administrateur->value, 'portee' => 'tous'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        Livewire::test(FicheDuMembre::class, ['record' => $present->getRouteKey()])
            ->mountAction('changerLeRoleDeSociete')
            ->setActionData(['role_societe' => RoleSociete::ExpertComptable->value, 'mode' => 'present', 'mot_de_passe' => 'mon-propre-mot', 'mot_de_passe_confirmation' => 'mon-propre-mot'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([RoleSociete::Administrateur, true], [$absent->refresh()->role_societe, $absent->mot_de_passe_est_l_identifiant]);
        $this->assertSame([RoleSociete::ExpertComptable, false], [$present->refresh()->role_societe, $present->mot_de_passe_est_l_identifiant]);
    }

    /**
     * Un compte d'entraînement de la démonstration ne devient jamais
     * administrateur ni expert-comptable : l'action le refuse avec son
     * message, la fiche ne le propose pas, et la base le refuse.
     */
    public function test_un_compte_d_entrainement_ne_devient_ni_administrateur_ni_expert_comptable(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $entrainement = app(TenantContext::class)->runAs($demonstration, fn (): User => app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Essai'], RoleMagasin::Vendeur));

        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($societe->proprietaire, $entrainement, null, [], []), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_ENTRAINEMENT);
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versExpertComptable($societe->proprietaire, $entrainement), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_ENTRAINEMENT);
        $this->dansLEspaceDeLaSociete($societe, $societe->proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $entrainement->getRouteKey()])->assertActionHidden('changerLeRoleDeSociete');
        DB::table('affectations')->where('user_id', $entrainement->id)->delete();
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $entrainement->id)->update(['role_societe' => RoleSociete::ExpertComptable->value])), QueryException::class);

        $this->assertSame(RoleSociete::Membre, $entrainement->refresh()->role_societe);
    }

    /**
     * Un administrateur n'est jamais rétrogradé dans la démonstration, ni
     * dans un magasin fermé ou cédé : la fenêtre ne propose que les
     * magasins réels ouverts, l'action appelée directement les refuse, et
     * la base refuse de rattacher après coup un compte à la démonstration.
     */
    public function test_un_administrateur_n_est_jamais_retrograde_dans_la_demonstration_ni_dans_un_magasin_ferme_ou_cede(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire;
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());
        $this->saisirLeCodeDuCourrier($societe);
        $ferme = $this->ouvrirUnAutreMagasin($reel->fresh(), ['nom' => 'Boutique fermée']);
        $cede = $this->ouvrirUnAutreMagasin($reel->fresh(), ['nom' => 'Boutique cédée']);
        app(FermerMagasinAction::class)->handle($proprietaire, $ferme->fresh(), MotifFermeture::Temporaire, 'mot-de-passe-sur');
        app(FermerMagasinAction::class)->handle($proprietaire, $cede->fresh(), MotifFermeture::Cession, 'mot-de-passe-sur', 'Boutique cédée');
        $administrateur = $this->administrateur($reel->fresh());
        $action = app(ChangerLeRoleDeSocieteAction::class);

        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->mountAction('changerLeRoleDeSociete')
            ->setActionData(['role_societe' => RoleSociete::Membre->value])
            ->assertFormFieldExists('magasin_id', 'mountedActionForm', fn (Select $choix): bool => array_keys($choix->getOptions()) === [(int) $reel->id]);
        foreach ([$demonstration, $ferme, $cede] as $magasin) {
            $this->assertThrows(fn () => $action->versMembre($proprietaire, $administrateur, RoleMagasin::Vendeur, null, $magasin->id), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_MAGASIN_A_CHOISIR);
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('identifiants')->where('user_id', $administrateur->id)->update(['magasin_id' => $demonstration->id])), QueryException::class);

        $ancienVendeur = $this->membre($ferme->fresh(), RoleMagasin::Vendeur);
        $action->versAdministrateur($proprietaire, $ancienVendeur, null, [], []);
        $this->assertThrows(fn () => $action->versMembre($proprietaire, $ancienVendeur->refresh(), RoleMagasin::Vendeur), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_MAGASIN_FERME);
        $this->assertSame([RoleSociete::Administrateur, null], [$administrateur->refresh()->role_societe, $administrateur->magasinDeSonIdentifiant()]);
        $this->assertInstanceOf(Magasin::class, $demonstration);
    }
}
