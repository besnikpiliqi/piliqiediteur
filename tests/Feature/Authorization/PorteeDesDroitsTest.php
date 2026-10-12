<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\DonnerUnMotDePasseProvisoireAction;
use App\Domain\Tenancy\Actions\EnvoyerUnLienDeReinitialisationAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\NommerAdministrateurAction;
use App\Domain\Tenancy\Actions\ProposerUnRattachementAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\DemandeRattachement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qui gère quel compte (voir PorteeDesDroits) : les règles se lisent sur
 * l'auteur et le compte relus sous les verrous de l'action, jamais sur une
 * instance périmée ; un compte au mot de passe provisoire ne reçoit de
 * nouveaux droits que de son auteur ; et chaque case des matrices de la
 * conception (avenant A4, 2.3, compléments compris) a sa donnée dans le
 * test de référence.
 */
class PorteeDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $m;

    private Magasin $n;

    private Magasin $o;

    /**
     * @var array<string, User>
     */
    private array $comptes = [];

    /**
     * Le formulaire d'un responsable reste ouvert pendant que le
     * propriétaire le rétrograde : la création qu'il envoie ensuite relit
     * ses droits sous les verrous, et ne crée ni ne coche plus rien.
     * L'ordre est rendu déterministe : l'écriture de l'autre transaction
     * arrive dès que l'action verrouille le registre, avant qu'elle relise
     * son auteur (voir DroitsConcurrenceTest pour deux vrais processus).
     */
    public function test_un_responsable_retire_du_magasin_pendant_que_son_formulaire_est_ouvert_ne_cree_ni_ne_coche_plus_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $retrograder = fn () => DB::table('affectations')->where('user_id', $responsable->id)->update(['role' => RoleMagasin::Vendeur->value]);

        $this->avantLesVerrous($retrograder);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->avantLesVerrous($retrograder);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => PermissionRegistry::toutes()]]), AuthorizationException::class);

        $this->assertFalse(User::query()->where('email', 'paul@example.test')->exists());
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations());
    }

    /**
     * Le formulaire d'un administrateur reste ouvert pendant que le
     * propriétaire lui retire « nommer et retirer les responsables » : la
     * nomination qu'il envoie ensuite est refusée sous les verrous (ordre
     * déterministe, comme ci-dessus).
     */
    public function test_un_administrateur_prive_de_nommer_les_responsables_pendant_que_son_formulaire_est_ouvert_n_en_nomme_plus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->avantLesVerrous(fn () => DB::table('autorisations_societe')->where('user_id', $administrateur->id)->delete());

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);

        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
        $this->assertNull(Affectation::query()->where('user_id', $vendeur->id)->sole()->nomme_par);
    }

    /**
     * L'instance de l'auteur garde son ancien statut, mais l'action relit
     * le compte : suspendu entre-temps, il ne modifie plus aucun compte.
     */
    public function test_un_auteur_suspendu_pendant_sa_requete_ne_modifie_aucun_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $instanceDeLaRequete = User::query()->findOrFail($responsable->id);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $responsable);

        $this->assertTrue($instanceDeLaRequete->estActif());
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($instanceDeLaRequete, $vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($instanceDeLaRequete, $vendeur, [$magasin->id => null]), AuthorizationException::class);

        $this->assertSame(StatutCompte::Actif, $vendeur->refresh()->statut);
        $this->assertSame(1, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Tant que le mot de passe d'un compte est le provisoire, seule la
     * personne qui l'a créé lui ajoute un magasin, le nomme responsable ou
     * le réintègre ; une fois son mot de passe choisi, le propriétaire et
     * les autres le peuvent selon leurs droits.
     */
    public function test_un_compte_au_mot_de_passe_provisoire_ne_recoit_de_nouveaux_droits_que_de_son_auteur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $parLeResponsable = app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($proprietaire, $parLeResponsable, [$autre->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class, PorteeDesDroits::REFUS);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($proprietaire, $parLeResponsable, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $parLeResponsable, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        app(ModifierMembreAction::class)->handle($responsable, $parLeResponsable, [$magasin->id => ['role' => RoleMagasin::Observateur]]);
        $this->assertSame([[(int) $magasin->id, RoleMagasin::Observateur]], Affectation::query()->where('user_id', $parLeResponsable->id)->get()->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());

        $this->choisirSonMotDePasse($parLeResponsable);
        app(ModifierMembreAction::class)->handle($proprietaire, $parLeResponsable, [$autre->id => ['role' => RoleMagasin::Vendeur]]);
        $this->assertSame(2, Affectation::query()->where('user_id', $parLeResponsable->id)->count());
    }

    /**
     * Chaque case des matrices A à J (avenant A4, 2.3), compléments de
     * l'éditeur compris : plusieurs experts-comptables par société, seul
     * le propriétaire affecte une même personne à plusieurs magasins, un
     * responsable ne gère que les membres dont tous les magasins sont les
     * siens.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: bool}>
     */
    public static function matrice(): array
    {
        $tous = static fn (bool $p, bool $ap, bool $am, bool $rm, bool $v, bool $e): array => ['P' => $p, 'A+' => $ap, 'A-' => $am, 'R(M)' => $rm, 'V' => $v, 'E' => $e];
        $lignes = [
            ['A', 'créer un administrateur', null, $tous(true, false, false, false, false, false)],
            ['A', 'nommer administrateur', 'vendeur de M', $tous(true, false, false, false, false, false)],
            ['A', 'nommer administrateur', 'expert-comptable', ['P' => false]],
            ['A', 'modifier l\'administrateur', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'rétrograder l\'administrateur', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'suspendre', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'réactiver', 'administrateur suspendu', $tous(true, false, false, false, false, false)],
            ['A', 'retirer de la société', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'renvoyer un lien', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'redonner un provisoire', 'administrateur au provisoire', $tous(true, false, false, false, false, false)],
            ['A', 'transmettre la propriété', 'administrateur à la qualité prouvée', $tous(true, false, false, false, false, false)],

            ['B', 'créer un responsable de M', null, ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'nommer responsable de M', 'vendeur de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'ajouter N comme responsable', 'responsable de M', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'retirer M', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'déplacer de M vers N', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false]],
            ['B', 'rétrograder en vendeur dans M', 'responsable de M', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'cocher des cases dans M', 'responsable de M', ['P' => false]],
            ['B', 'suspendre', 'responsable de M', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'réactiver', 'responsable de M suspendu', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false, 'E' => false]],
            ['B', 'retirer de la société', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'renvoyer un lien', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['B', 'redonner un provisoire', 'responsable de M au provisoire', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],

            ['C', 'créer un vendeur de M', null, ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'cocher des cases dans M', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'ajouter N comme vendeur', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'R(M) et R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'nommer responsable de M', 'vendeur de M', ['P' => true, 'A+' => true, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'retirer M', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'suspendre', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'réactiver', 'vendeur de M suspendu par R(M)', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'réactiver', 'vendeur de M suspendu par le propriétaire', ['P' => true, 'R(M)' => false]],
            ['C', 'retirer de la société', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'renvoyer un lien', 'vendeur de M', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['C', 'redonner un provisoire', 'vendeur de M au provisoire', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],

            ['D', 'cocher des cases dans M', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'cocher des cases dans N', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'retirer M', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'suspendre', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'retirer de la société', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'renvoyer un lien', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'redonner un provisoire', 'vendeur de M et N au provisoire', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => true, 'A+' => false, 'A-' => false, 'V' => false, 'E' => false]],
            ['D', 'nommer responsable de M', 'vendeur de M et N', ['P' => true, 'R(M)' => false, 'R(M) et R(N)' => false, 'A+' => true, 'A-' => false, 'V' => false, 'E' => false]],

            ['E', 'cocher des cases dans M', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => false]],
            ['E', 'retirer M', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => false]],
            ['E', 'retirer N', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => true]],
            ['E', 'rétrograder en vendeur dans N', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => false]],
            ['E', 'suspendre', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => false]],
            ['E', 'retirer de la société', 'compte mixte', ['P' => true, 'R(M)' => false, 'R(N)' => false, 'A+' => false]],

            ['F', 'suspendre', 'propriétaire', $tous(false, false, false, false, false, false)],
            ['F', 'retirer de la société', 'propriétaire', $tous(false, false, false, false, false, false)],
            ['F', 'rétrograder en vendeur dans M', 'propriétaire', $tous(false, false, false, false, false, false)],
            ['F', 'renvoyer un lien', 'propriétaire', $tous(false, false, false, false, false, false)],
            ['F', 'refuser son compte', 'propriétaire', ['P' => false]],

            ['G', 'cocher des cases dans M', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'suspendre', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'retirer de la société', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'refuser son compte', 'soi-même', ['P' => false, 'A+' => true, 'R(M)' => true, 'V' => true, 'E' => true]],

            ['H', 'réintégrer comme vendeur de M', 'ancien vendeur retiré par R(M)', ['P' => true, 'A+' => false, 'R(M)' => true]],
            ['H', 'réintégrer comme responsable de M', 'ancien responsable retiré par A+', ['P' => true, 'A+' => true, 'R(M)' => false]],
            ['H', 'réintégrer comme expert-comptable', 'ancien vendeur retiré par R(M)', ['P' => true, 'A+' => false, 'R(M)' => false]],
            ['H', 'nommer administrateur', 'ancien vendeur retiré par R(M)', ['P' => true, 'A+' => false, 'R(M)' => false]],
            ['H', 'réintégrer comme vendeur de M', 'ancien vendeur retiré par le propriétaire', ['P' => true, 'R(M)' => false]],
            ['H', 'réintégrer comme responsable de M', 'ancien vendeur retiré par le propriétaire', ['P' => true, 'A+' => false]],
            ['H', 'réintégrer comme vendeur de M', 'compte refusé', ['P' => false, 'R(M)' => false]],
            ['H', 'réintégrer comme responsable de M', 'compte refusé', ['P' => false, 'A+' => false]],
            ['H', 'proposer un rattachement comme vendeur de M', 'compte refusé', ['P' => true, 'A+' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['H', 'proposer un rattachement comme vendeur de M', 'compte retiré d\'une autre société', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'E' => false]],
            ['H', 'proposer un rattachement comme responsable de M', 'compte retiré d\'une autre société', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A-' => false, 'R(M)' => false, 'V' => false, 'E' => false]],

            ['I', 'créer un expert-comptable', null, ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'créer un second expert-comptable', null, ['P' => true, 'A+' => false]],
            ['I', 'suspendre', 'expert-comptable', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'réactiver', 'expert-comptable suspendu', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'retirer de la société', 'expert-comptable', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'réintégrer comme expert-comptable', 'expert-comptable retiré', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'renvoyer un lien', 'expert-comptable', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'redonner un provisoire', 'expert-comptable au provisoire', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['I', 'nommer responsable de M', 'expert-comptable', ['P' => false, 'A+' => false]],
            ['I', 'ajouter N comme vendeur', 'expert-comptable', ['P' => false, 'R(N)' => false]],
            ['I', 'nommer administrateur', 'expert-comptable', ['P' => false, 'A+' => false, 'A-' => false]],
            ['I', 'proposer un rattachement comme expert-comptable', 'compte retiré d\'une autre société', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false, 'E' => false]],
            ['I', 'transmettre la propriété', 'expert-comptable à la qualité prouvée', $tous(false, false, false, false, false, false)],

            ['J', 'voir la liste des comptes de la société', null, $tous(true, true, false, false, false, false)],
            ['J', 'voir ce compte dans l\'espace société', 'expert-comptable', $tous(true, false, false, false, false, false)],
            ['J', 'voir ce compte dans l\'espace société', 'responsable de M', $tous(true, true, false, false, false, false)],
            ['J', 'voir ce compte dans l\'espace société', 'vendeur de M', $tous(true, false, false, false, false, false)],
            ['J', 'voir l\'équipe du magasin M', null, $tous(true, false, false, true, false, false)],
        ];

        $donnees = [];

        foreach ($lignes as [$matrice, $geste, $cible, $attendus]) {
            foreach ($attendus as $acteur => $attendu) {
                $donnees["{$matrice} · {$geste} · ".($cible ?? 'nouveau compte')." · par {$acteur}"] = [$geste, $cible, $acteur, $attendu];
            }
        }

        return $donnees;
    }

    #[DataProvider('matrice')]
    public function test_les_regles_de_la_matrice_sont_respectees_geste_par_geste(string $geste, ?string $cible, string $acteur, bool $attendu): void
    {
        Notification::fake();
        Mail::fake();
        $this->m = $this->ouvrirUneSociete(['nom' => 'M']);
        $this->n = $this->ouvrirUnAutreMagasin($this->m, ['nom' => 'N']);
        $this->o = $this->ouvrirUnAutreMagasin($this->m, ['nom' => 'O']);
        $auteur = $this->acteur($acteur);
        $compte = match ($cible) {
            null => null,
            'soi-même' => $auteur,
            default => $this->cible($cible),
        };

        try {
            $this->faire($geste, $auteur, $compte);
            $reussi = true;
        } catch (AuthorizationException) {
            $reussi = false;
        }

        $this->assertSame($attendu, $reussi);
    }

    private function acteur(string $nom): User
    {
        return $this->comptes[$nom] ??= match ($nom) {
            'P' => $this->proprietaireDe($this->m),
            'A+' => $this->administrateur($this->m, [$this->m->id, $this->n->id], [AutorisationsSociete::RESPONSABLES_NOMMER]),
            'A+ hors de M' => $this->administrateur($this->m, [$this->o->id], [AutorisationsSociete::RESPONSABLES_NOMMER]),
            'A-' => $this->administrateur($this->m, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::PARAMETRES_GERER], PermissionRegistry::toutes()),
            'R(M)' => $this->responsable($this->m),
            'R(N)' => $this->responsable($this->n),
            'R(M) et R(N)' => $this->responsable($this->m, $this->n),
            'V' => $this->membre($this->m, RoleMagasin::Vendeur, PermissionRegistry::toutes()),
            'E' => $this->expertComptable($this->m),
        };
    }

    private function cible(string $nom): User
    {
        $proprietaire = $this->acteur('P');
        $societe = $this->m->societe;
        $auProvisoire = fn (array $affectations): User => app(CreerMembreAction::class)->handle($proprietaire, $societe, ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail()], self::MOT_DE_PASSE_PROVISOIRE, $affectations);

        $compte = match ($nom) {
            'propriétaire' => $proprietaire,
            'administrateur', 'administrateur suspendu' => $this->administrateur($this->m, [$this->m->id]),
            'administrateur à la qualité prouvée' => tap($this->administrateur($this->m, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save(),
            'expert-comptable à la qualité prouvée' => tap($this->expertComptable($this->m)->forceFill(['qualite_verifiee_le' => now()]))->save(),
            'compte retiré d\'une autre société' => $this->compteRetireDUneAutreSociete(),
            'administrateur au provisoire' => app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$this->m->id], [], []),
            'responsable de M', 'responsable de M suspendu' => $this->responsable($this->m),
            'responsable de M au provisoire' => $auProvisoire([$this->m->id => ['role' => RoleMagasin::Responsable]]),
            'vendeur de M', 'vendeur de M suspendu par R(M)', 'vendeur de M suspendu par le propriétaire', 'ancien vendeur retiré par R(M)', 'ancien vendeur retiré par le propriétaire', 'compte refusé' => $this->membre($this->m, RoleMagasin::Vendeur),
            'vendeur de M au provisoire' => $auProvisoire([$this->m->id => ['role' => RoleMagasin::Vendeur]]),
            'vendeur de M et N' => $this->membreDeCesMagasins([$this->m->id => ['role' => RoleMagasin::Vendeur], $this->n->id => ['role' => RoleMagasin::Vendeur]], $this->m),
            'vendeur de M et N au provisoire' => $auProvisoire([$this->m->id => ['role' => RoleMagasin::Vendeur], $this->n->id => ['role' => RoleMagasin::Vendeur]]),
            'compte mixte' => $this->membreDeCesMagasins([$this->m->id => ['role' => RoleMagasin::Vendeur], $this->n->id => ['role' => RoleMagasin::Responsable]], $this->m),
            'ancien responsable retiré par A+' => $this->responsable($this->m),
            'expert-comptable', 'expert-comptable suspendu', 'expert-comptable retiré' => $this->expertComptable($this->m),
            'expert-comptable au provisoire' => app(CreerExpertComptableAction::class)->handle($proprietaire, $societe, ['name' => 'Cabinet', 'email' => 'cabinet@example.test'], self::MOT_DE_PASSE_PROVISOIRE),
        };

        match ($nom) {
            'administrateur suspendu', 'responsable de M suspendu', 'vendeur de M suspendu par le propriétaire', 'expert-comptable suspendu' => app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $compte),
            'vendeur de M suspendu par R(M)' => app(ChangerStatutCompteAction::class)->suspendre($this->acteur('R(M)'), $compte),
            'ancien vendeur retiré par R(M)' => app(ChangerStatutCompteAction::class)->retirer($this->acteur('R(M)'), $compte),
            'ancien vendeur retiré par le propriétaire', 'expert-comptable retiré' => app(ChangerStatutCompteAction::class)->retirer($proprietaire, $compte),
            'ancien responsable retiré par A+' => app(ChangerStatutCompteAction::class)->retirer($this->acteur('A+'), $compte),
            'compte refusé' => app(RefuserSonCompteAction::class)->handle($compte),
            default => null,
        };

        return $compte->refresh();
    }

    /**
     * Le geste, appelé directement sur l'action du domaine ; un refus
     * d'autorisation lève AuthorizationException, toute autre exception
     * fait échouer le test.
     */
    private function faire(string $geste, User $auteur, ?User $compte): void
    {
        $societe = $this->m->societe;
        $m = $this->m->id;
        $n = $this->n->id;
        $nouveau = ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail()];

        match ($geste) {
            'créer un administrateur' => app(CreerAdministrateurAction::class)->handle($auteur, $societe, $nouveau, self::MOT_DE_PASSE_PROVISOIRE, [$m], [], []),
            'nommer administrateur' => app(NommerAdministrateurAction::class)->handle($auteur, $compte, [$m], [], []),
            'modifier l\'administrateur' => app(ModifierAdministrateurAction::class)->modifier($auteur, $compte, [$m], [PermissionRegistry::FACTURE_VIEW], []),
            'rétrograder l\'administrateur' => app(ModifierAdministrateurAction::class)->retrograder($auteur, $compte, [$m => ['role' => RoleMagasin::Vendeur]]),
            'suspendre' => app(ChangerStatutCompteAction::class)->suspendre($auteur, $compte),
            'réactiver' => app(ChangerStatutCompteAction::class)->reactiver($auteur, $compte),
            'retirer de la société' => app(ChangerStatutCompteAction::class)->retirer($auteur, $compte),
            'renvoyer un lien' => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($auteur, $compte),
            'redonner un provisoire' => app(DonnerUnMotDePasseProvisoireAction::class)->handle($auteur, $compte, 'nouveau-provisoire-1'),
            'créer un responsable de M' => app(CreerMembreAction::class)->handle($auteur, $societe, $nouveau, self::MOT_DE_PASSE_PROVISOIRE, [$m => ['role' => RoleMagasin::Responsable]]),
            'créer un vendeur de M' => app(CreerMembreAction::class)->handle($auteur, $societe, $nouveau, self::MOT_DE_PASSE_PROVISOIRE, [$m => ['role' => RoleMagasin::Vendeur]]),
            'nommer responsable de M' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$m => ['role' => RoleMagasin::Responsable]]),
            'ajouter N comme responsable' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$n => ['role' => RoleMagasin::Responsable]]),
            'ajouter N comme vendeur' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$n => ['role' => RoleMagasin::Vendeur]]),
            'retirer M' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$m => null]),
            'retirer N' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$n => null]),
            'déplacer de M vers N' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$m => null, $n => ['role' => RoleMagasin::Responsable]]),
            'rétrograder en vendeur dans M' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$m => ['role' => RoleMagasin::Vendeur]]),
            'rétrograder en vendeur dans N' => app(ModifierMembreAction::class)->handle($auteur, $compte, [$n => ['role' => RoleMagasin::Vendeur]]),
            'cocher des cases dans M' => $this->cocher($auteur, $compte, $m),
            'cocher des cases dans N' => $this->cocher($auteur, $compte, $n),
            'refuser son compte' => app(RefuserSonCompteAction::class)->handle($compte),
            'réintégrer comme vendeur de M' => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $compte, [$m => ['role' => RoleMagasin::Vendeur]]),
            'réintégrer comme responsable de M' => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $compte, [$m => ['role' => RoleMagasin::Responsable]]),
            'réintégrer comme expert-comptable' => app(ChangerStatutCompteAction::class)->reintegrerCommeExpertComptable($auteur, $compte),
            'créer un expert-comptable' => app(CreerExpertComptableAction::class)->handle($auteur, $societe, $nouveau, self::MOT_DE_PASSE_PROVISOIRE),
            'créer un second expert-comptable' => $this->secondExpertComptable($auteur),
            'transmettre la propriété' => app(TransfererProprieteAction::class)->handle($auteur, $compte, 'password'),
            'proposer un rattachement comme vendeur de M' => $this->proposer($auteur, $compte, RoleSociete::Membre, [$m => ['role' => RoleMagasin::Vendeur]]),
            'proposer un rattachement comme responsable de M' => $this->proposer($auteur, $compte, RoleSociete::Membre, [$m => ['role' => RoleMagasin::Responsable]]),
            'proposer un rattachement comme expert-comptable' => $this->proposer($auteur, $compte, RoleSociete::ExpertComptable, []),
            'voir la liste des comptes de la société' => $this->exiger($auteur->can('viewAny', User::class)),
            'voir ce compte dans l\'espace société' => $this->exiger($auteur->can('view', $compte)),
            'voir l\'équipe du magasin M' => $this->exiger(app(TenantContext::class)->runAs($this->m, fn (): bool => $auteur->can('viewAny', Affectation::class))),
        };
    }

    /**
     * Les cases d'un vendeur dans ce magasin, en gardant son rôle ; pour un
     * responsable, des cases demandées sur son affectation, qu'aucune
     * action n'accepte.
     */
    private function cocher(User $auteur, User $compte, int $magasinId): void
    {
        $role = Affectation::query()->where('user_id', $compte->id)->where('magasin_id', $magasinId)->first()?->role ?? RoleMagasin::Vendeur;

        if ($role === RoleMagasin::Responsable) {
            try {
                app(ModifierMembreAction::class)->handle($auteur, $compte, [$magasinId => ['role' => RoleMagasin::Responsable, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]]]);
            } catch (LogicException) {
                throw new AuthorizationException('Un responsable n\'a aucune case.');
            }

            return;
        }

        app(ModifierMembreAction::class)->handle($auteur, $compte, [$magasinId => ['role' => $role, 'autorisations' => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]]]);
    }

    /**
     * Plusieurs experts-comptables par société : un second se crée comme
     * le premier.
     */
    private function secondExpertComptable(User $auteur): void
    {
        $this->expertComptable($this->m);
        app(CreerExpertComptableAction::class)->handle($auteur, $this->m->societe, ['name' => 'Second', 'email' => 'second@example.test'], self::MOT_DE_PASSE_PROVISOIRE);
    }

    /**
     * Une proposition de rattachement : faite, elle doit avoir créé la
     * demande, que rien ne dit à l'auteur.
     *
     * @param  array<int, array{role: RoleMagasin}>  $affectations
     */
    private function proposer(User $auteur, User $compte, RoleSociete $role, array $affectations): void
    {
        app(ProposerUnRattachementAction::class)->handle($auteur, $this->m->societe, $compte->email, $role, $affectations);
        $this->exiger(DemandeRattachement::withoutGlobalScopes()->where('user_id', $compte->id)->exists());
    }

    /**
     * Un vendeur d'une autre société, retiré par elle ; le magasin M reste
     * le magasin actif.
     */
    private function compteRetireDUneAutreSociete(): User
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $compte = $this->membre($ailleurs, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($ailleurs), $compte);
        app(TenantContext::class)->set($this->m);

        return $compte;
    }

    private function exiger(bool $permis): void
    {
        if (! $permis) {
            throw new AuthorizationException;
        }
    }

    /**
     * Exécute le changement concurrent dès que l'action du test lit le
     * registre de son premier magasin, avant de relire l'auteur sous les
     * verrous (voir JournalDesDroits) : l'ordre est le même sous les deux
     * moteurs.
     */
    private function avantLesVerrous(Closure $changement): void
    {
        $fait = false;
        DB::listen(function (QueryExecuted $requete) use (&$fait, $changement): void {
            if (! $fait && str_contains($requete->sql, 'from "tetes_registre_fiscal"')) {
                $fait = true;
                $changement();
            }
        });
    }
}
