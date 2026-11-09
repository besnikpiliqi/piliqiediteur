<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Les gardes des droits en base (voir GardesDesDroits) : écrite sans
 * passer par l'application, par DB::table() ou une requête brute, une
 * ligne de droits incohérente avec le rôle de société du compte est
 * refusée par la base elle-même, sous PostgreSQL comme sous SQLite ; les
 * transitions que font les actions, dans leur ordre, passent.
 */
class GardesDesDroitsEnBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_refuse_une_affectation_au_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $proprietaire, $magasin->id, RoleMagasin::Vendeur->value)));
        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $proprietaire, null, null)));

        $this->assertSame(0, Affectation::query()->where('user_id', $proprietaire->id)->count());
    }

    /**
     * Ni un rôle de magasin inconnu (l'ancien « comptable » compris), ni un
     * rôle de société inconnu.
     */
    public function test_la_base_refuse_un_role_de_magasin_inconnu(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        foreach (['comptable', 'administrateur', 'proprietaire', 'chef'] as $role) {
            $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $vendeur, $autre->id, $role)), $role);
            $this->refusee(fn () => DB::table('affectations')->where('id', $affectation->id)->update(['role' => $role]), $role);
        }

        $this->refusee(fn () => DB::table('users')->where('id', $vendeur->id)->update(['role_societe' => 'comptable']));

        $this->assertSame(RoleMagasin::Vendeur, $affectation->fresh()->role);
        $this->assertSame(RoleSociete::Membre, $vendeur->refresh()->role_societe);
    }

    public function test_la_base_refuse_une_affectation_globale_ou_sans_role_a_un_membre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $vendeur, null, null)));
        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $vendeur, null, RoleMagasin::Vendeur->value)));
        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $vendeur, $autre->id, null)));

        $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $vendeur->id)->pluck('magasin_id')->all());
    }

    public function test_la_base_refuse_une_affectation_avec_role_a_un_administrateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $sonAffectation = Affectation::query()->where('user_id', $administrateur->id)->sole();

        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $administrateur, $autre->id, RoleMagasin::Responsable->value)));
        $this->refusee(fn () => DB::table('affectations')->where('id', $sonAffectation->id)->update(['role' => RoleMagasin::Vendeur->value]));

        $this->assertNull($sonAffectation->fresh()->role);
    }

    /**
     * Un administrateur a accès à tous les magasins ou à une sélection,
     * jamais aux deux : ni une ligne globale à côté d'une sélection, ni une
     * ligne de sélection à côté d'une ligne globale.
     */
    public function test_la_base_refuse_qu_un_administrateur_ait_a_la_fois_tous_les_magasins_et_une_selection(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $deLaSelection = $this->administrateur($magasin, [$magasin->id]);
        $deTous = $this->administrateur($magasin);

        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $deLaSelection, null, null)));
        $this->refusee(fn () => DB::table('affectations')->insert($this->ligne($magasin, $deTous, $autre->id, null)));

        $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $deLaSelection->id)->pluck('magasin_id')->all());
        $this->assertSame([null], Affectation::query()->where('user_id', $deTous->id)->pluck('magasin_id')->all());
    }

    /**
     * Un responsable n'a aucune case : ni une case ajoutée sur son
     * affectation, ni une affectation qui devient la sienne en gardant ses
     * cases.
     */
    public function test_la_base_refuse_une_case_sur_une_affectation_de_responsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $affectationDuResponsable = Affectation::query()->where('user_id', $responsable->id)->sole();
        $affectationDuVendeur = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->refusee(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectationDuResponsable->id, 'autorisation' => PermissionRegistry::FACTURE_VIEW]));
        $this->refusee(fn () => DB::table('affectations')->where('id', $affectationDuVendeur->id)->update(['role' => RoleMagasin::Responsable->value]));

        $this->assertSame([], $affectationDuResponsable->autorisations());
        $this->assertSame(RoleMagasin::Vendeur, $affectationDuVendeur->fresh()->role);
    }

    /**
     * Écrite par le modèle (voir Affectation), une affectation incohérente
     * avec le rôle de société du compte est refusée avant d'atteindre la
     * base, avec le message qui dit la règle, comme une case cochée sur
     * l'affectation d'un responsable ; rien ne change.
     */
    public function test_le_modele_refuse_une_affectation_incoherente_avant_la_base(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsable = $this->responsable($magasin);
        $avant = Affectation::query()->orderBy('id')->get(['id', 'user_id', 'magasin_id', 'role'])->toArray();

        foreach ([
            [$this->proprietaireDe($magasin), $magasin->id, RoleMagasin::Vendeur, 'Le propriétaire et un collaborateur d\'un cabinet n\'ont aucune affectation.'],
            [$administrateur, $autre->id, RoleMagasin::Responsable, 'Un administrateur n\'a aucun rôle de magasin.'],
            [$administrateur, null, null, 'Un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux.'],
            [$vendeur, $autre->id, null, 'Un membre est affecté à un magasin, avec un rôle de magasin.'],
            [$vendeur, null, RoleMagasin::Vendeur, 'Un membre est affecté à un magasin, avec un rôle de magasin.'],
        ] as [$compte, $magasinId, $role, $message]) {
            $affectation = (new Affectation)->forceFill(['societe_id' => $magasin->societe_id, 'user_id' => $compte->id, 'magasin_id' => $magasinId, 'role' => $role]);

            $this->assertThrows(fn () => $affectation->save(), LogicException::class, $message);
        }

        $this->assertThrows(
            fn () => Affectation::query()->where('user_id', $responsable->id)->sole()->cocher([PermissionRegistry::FACTURE_VIEW]),
            LogicException::class,
            AffectationsDesMembres::MESSAGE_RESPONSABLE_SANS_CASE,
        );
        $this->assertSame($avant, Affectation::query()->orderBy('id')->get(['id', 'user_id', 'magasin_id', 'role'])->toArray());
        $this->assertSame([], Affectation::query()->where('user_id', $responsable->id)->sole()->autorisations());
    }

    public function test_la_base_refuse_une_autorisation_de_societe_a_qui_n_est_pas_administrateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        foreach ([$proprietaire, $responsable, $vendeur] as $compte) {
            $this->refusee(fn () => DB::table('autorisations_societe')->insert(['societe_id' => $magasin->societe_id, 'user_id' => $compte->id, 'autorisation' => AutorisationsSociete::RESPONSABLES_NOMMER]), $compte->name);
        }

        $this->assertSame(0, DB::table('autorisations_societe')->count());
    }

    public function test_la_base_refuse_de_retrograder_un_administrateur_qui_garde_une_affectation_globale_ou_une_autorisation_de_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $deTous = $this->administrateur($magasin);
        $autorise = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        DB::table('affectations')->where('user_id', $autorise->id)->delete();

        $this->refusee(fn () => DB::table('users')->where('id', $deTous->id)->update(['role_societe' => RoleSociete::Membre->value]));
        $this->refusee(fn () => DB::table('users')->where('id', $autorise->id)->update(['role_societe' => RoleSociete::Membre->value]));

        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Administrateur], [$deTous->refresh()->role_societe, $autorise->refresh()->role_societe]);
    }

    /**
     * Ni propriétaire, ni collaborateur hors d'un cabinet d'expertise
     * comptable : un compte qui garde une affectation ou une autorisation
     * de société ne prend aucun de ces rôles.
     */
    public function test_la_base_refuse_de_rendre_proprietaire_ou_collaborateur_hors_d_un_cabinet_un_compte_qui_garde_des_droits(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        $administrateurSansAffectation = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);
        DB::table('affectations')->where('user_id', $administrateurSansAffectation->id)->delete();

        foreach ([RoleSociete::Proprietaire, RoleSociete::Collaborateur] as $role) {
            foreach ([$vendeur, $administrateur, $administrateurSansAffectation] as $compte) {
                $this->refusee(fn () => DB::table('users')->where('id', $compte->id)->update(['role_societe' => $role->value]), "{$role->value} {$compte->name}");
            }
        }

        $this->assertSame(RoleSociete::Membre, $vendeur->refresh()->role_societe);
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
    }

    /**
     * Les transitions des actions, faites dans leur ordre (les droits qui
     * contrediraient le nouveau rôle s'effacent d'abord), passent : nommer
     * administrateur, le ramener membre dans son magasin, transmettre la
     * propriété (l'adresse confirmée et le rôle du nouveau propriétaire
     * dans la même mise à jour), promouvoir un vendeur responsable ; dans
     * un cabinet d'expertise comptable, créer, retirer et réintégrer un
     * collaborateur, qui garde son rôle une fois retiré.
     */
    public function test_les_transitions_permises_passent(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $futurAdministrateur = $this->membre($magasin, RoleMagasin::Observateur);
        $nouveauProprietaire = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Responsable);
        $this->assertSame([RoleMagasin::Responsable, []], [Affectation::query()->where('user_id', $vendeur->id)->sole()->role, Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations()]);

        app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $futurAdministrateur, null, PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [AutorisationsSociete::MAGASINS_GERER]);
        $this->assertTrue($futurAdministrateur->refresh()->estAdministrateur());
        app(ChangerLeRoleDeSocieteAction::class)->versMembre($proprietaire, $futurAdministrateur, RoleMagasin::Observateur);
        $this->assertTrue($futurAdministrateur->refresh()->estMembre());
        $this->assertSame([], $futurAdministrateur->autorisationsDeSociete());

        DB::transaction(function () use ($proprietaire, $nouveauProprietaire, $magasin): void {
            DB::table('affectations')->where('user_id', $nouveauProprietaire->id)->delete();
            DB::table('autorisations_societe')->where('user_id', $nouveauProprietaire->id)->delete();
            DB::table('users')->where('id', $proprietaire->id)->update(['role_societe' => RoleSociete::Administrateur->value]);
            DB::table('users')->where('id', $nouveauProprietaire->id)->update(['role_societe' => RoleSociete::Proprietaire->value, 'email' => 'nouveau-proprietaire@example.test', 'email_verified_at' => now()]);
            DB::table('affectations')->insert($this->ligne($magasin, $proprietaire, null, null));
        });
        $this->assertTrue($nouveauProprietaire->refresh()->estProprietaire());
        $this->assertTrue($proprietaire->refresh()->estAdministrateur());

        $cabinet = $this->ouvrirUnCabinet();
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);
        app(ChangerStatutCompteAction::class)->retirer($proprietaireDuCabinet, $collaborateur);
        $this->assertSame([true, true], [$collaborateur->refresh()->estCollaborateur(), $collaborateur->estRetire()]);
        app(ChangerStatutCompteAction::class)->reintegrerLeCollaborateur($proprietaireDuCabinet, $collaborateur);
        $this->assertSame([true, true], [$collaborateur->refresh()->estCollaborateur(), $collaborateur->estActif()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(Magasin $magasin, User $compte, ?int $magasinId, ?string $role): array
    {
        return ['societe_id' => $magasin->societe_id, 'user_id' => $compte->id, 'magasin_id' => $magasinId, 'role' => $role, 'created_at' => now(), 'updated_at' => now()];
    }

    /**
     * L'écriture est refusée par la base, dans un point de sauvegarde qui
     * laisse la transaction du test utilisable.
     */
    private function refusee(Closure $ecriture, string $cas = ''): void
    {
        try {
            $this->dansUnPointDeSauvegarde($ecriture)();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("La base aurait dû refuser cette écriture. {$cas}");
    }
}
