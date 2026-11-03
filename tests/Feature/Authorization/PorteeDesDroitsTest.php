<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\CreerCollaborateurAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\AfficherLIdentifiantAction;
use App\Domain\Tenancy\Actions\AnnulerUneNominationAction;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
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
 * instance périmée ; et chaque case des matrices de la conception (avenant
 * A4, 2.3, compléments compris, puis avenant A4c : un compte par magasin,
 * accès redonné, changement de rôle de société, transmission par un lien)
 * a sa donnée dans le test de référence.
 */
class PorteeDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $m;

    private Magasin $n;

    private Magasin $o;

    private ?Societe $cabinet = null;

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
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur), AuthorizationException::class);
        $this->avantLesVerrous($retrograder);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, RoleMagasin::Vendeur, PermissionRegistry::toutes()), AuthorizationException::class);

        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
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

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeur, RoleMagasin::Responsable), AuthorizationException::class);

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
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($instanceDeLaRequete, $vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($instanceDeLaRequete, $vendeur, false), AuthorizationException::class);

        $this->assertSame(StatutCompte::Actif, $vendeur->refresh()->statut);
        $this->assertSame(1, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Un vendeur et un responsable suspendus par le propriétaire : ni le
     * responsable du magasin, ni l'administrateur autorisé à nommer les
     * responsables ne les retirent de la société (de leur magasin, ce qui
     * revient au même), ni n'annulent la nomination qui les y a mis.
     * Retirés, ils seraient réintégrés par eux et leur suspension serait
     * levée sans le propriétaire.
     */
    public function test_un_compte_suspendu_par_le_proprietaire_ne_se_retire_pas_par_un_autre(): void
    {
        Notification::fake();
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsableDuMagasin = $this->responsable($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $nomme = $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle($administrateur, $magasin, ['name' => 'Nina'], RoleMagasin::Responsable));
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $nomme);
        $nomination = Affectation::query()->where('user_id', $nomme->id)->sole();

        $gestes = [
            fn () => app(ChangerStatutCompteAction::class)->retirer($responsableDuMagasin, $vendeur),
            fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $nomme),
            fn () => app(AnnulerUneNominationAction::class)->handle($administrateur, $nomination),
        ];
        foreach ($gestes as $geste) {
            $this->assertThrows($geste, AuthorizationException::class, PorteeDesDroits::REFUS);
        }

        foreach ([$vendeur, $nomme] as $compte) {
            $this->assertSame(StatutCompte::Suspendu, $compte->refresh()->statut);
            $this->assertSame(1, Affectation::query()->where('user_id', $compte->id)->count());
        }
        Mail::assertNothingOutgoing();

        app(AnnulerUneNominationAction::class)->handle($proprietaire, $nomination);
        $this->assertSame(StatutCompte::Retire, $nomme->refresh()->statut);
    }

    /**
     * Le propriétaire suspend un vendeur, en retire un autre, puis
     * transmet la propriété à un administrateur, qui suit le lien : ses
     * décisions tiennent. Ni le responsable du magasin ni l'ancien
     * propriétaire ne les lèvent ; le nouveau propriétaire, si.
     */
    public function test_les_decisions_du_proprietaire_tiennent_apres_la_transmission_de_la_propriete(): void
    {
        Notification::fake();
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $successeur = $this->administrateurPretAReprendre($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        $jeton = $this->demanderLaTransmission($proprietaire, $successeur);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($successeur, $jeton, self::MOT_DE_PASSE_CHOISI);

        $gestes = [
            fn () => app(ChangerStatutCompteAction::class)->reactiver($responsable, $suspendu->refresh()),
            fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $suspendu->refresh()),
            fn () => app(ChangerStatutCompteAction::class)->reintegrer($responsable, $retire->refresh(), RoleMagasin::Vendeur),
            fn () => app(ChangerStatutCompteAction::class)->reactiver($proprietaire->refresh(), $suspendu->refresh()),
        ];
        foreach ($gestes as $geste) {
            $this->assertThrows($geste, AuthorizationException::class, PorteeDesDroits::REFUS);
        }
        $this->assertSame([StatutCompte::Suspendu, StatutCompte::Retire], [$suspendu->refresh()->statut, $retire->refresh()->statut]);

        app(ChangerStatutCompteAction::class)->reactiver($successeur->refresh(), $suspendu);
        app(ChangerStatutCompteAction::class)->reintegrer($successeur, $retire, RoleMagasin::Vendeur);

        $this->assertSame([StatutCompte::Actif, StatutCompte::Actif], [$suspendu->refresh()->statut, $retire->refresh()->statut]);
    }

    /**
     * Chaque case des matrices A à J (avenant A4, 2.3), compléments de
     * l'éditeur compris, et telles que l'avenant A4c les change : un
     * compte de membre ne vaut que pour son magasin (plus d'ajout ni de
     * changement de magasin, plus de compte de deux magasins) ; l'accès se
     * redonne au lieu du provisoire et du lien ; le rôle de société change
     * par le propriétaire seul ; la propriété se transmet par un lien ;
     * plus de refus d'un compte ni de rattachement. A= est un
     * administrateur à qui le propriétaire n'a coché que « redonner
     * l'accès aux vendeurs et aux observateurs ». C est un collaborateur du
     * cabinet d'expertise comptable lié, à qui la société de M est confiée,
     * qui n'a aucun geste sur les comptes de la société. La matrice I est
     * celle du cabinet : son propriétaire (PC), seul, crée, suspend,
     * réactive, retire et réintègre ses collaborateurs, leur redonne
     * l'accès, leur confie des clients et leur transmet la propriété
     * (Q-EC3) ; ni un collaborateur (C, C2), ni le propriétaire de la
     * société cliente (P) ne le font.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: bool}>
     */
    public static function matrice(): array
    {
        $tous = static fn (bool $p, bool $ap, bool $am, bool $rm, bool $v, bool $c): array => ['P' => $p, 'A+' => $ap, 'A-' => $am, 'R(M)' => $rm, 'V' => $v, 'C' => $c];
        $lignes = [
            ['A', 'créer un administrateur', null, [...$tous(true, false, false, false, false, false), 'A=' => false]],
            ['A', 'changer le rôle vers administrateur', 'vendeur de M', [...$tous(true, false, false, false, false, false), 'A=' => false]],
            ['A', 'modifier l\'administrateur', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'changer le rôle vers membre', 'administrateur', [...$tous(true, false, false, false, false, false), 'A=' => false]],
            ['A', 'suspendre', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'réactiver', 'administrateur suspendu', $tous(true, false, false, false, false, false)],
            ['A', 'retirer de la société', 'administrateur', $tous(true, false, false, false, false, false)],
            ['A', 'redonner l\'accès', 'administrateur', [...$tous(true, false, false, false, false, false), 'A=' => false]],
            ['A', 'afficher l\'identifiant', 'administrateur', [...$tous(false, false, false, false, false, false), 'A=' => false]],
            ['A', 'demander la transmission de la propriété', 'administrateur prêt à reprendre', [...$tous(true, false, false, false, false, false), 'A=' => false]],

            ['B', 'créer un responsable de M', null, ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'nommer responsable de M', 'vendeur de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'rétrograder en vendeur dans M', 'responsable de M', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'cocher des cases dans M', 'responsable de M', ['P' => false]],
            ['B', 'suspendre', 'responsable de M', ['P' => true, 'A+' => false, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'réactiver', 'responsable de M suspendu', ['P' => true, 'A+' => false, 'A-' => false, 'R(M)' => false, 'V' => false, 'C' => false]],
            ['B', 'retirer de la société', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'retirer de la société', 'responsable de M suspendu', ['P' => true, 'A+' => false]],
            ['B', 'redonner l\'accès', 'responsable de M', ['P' => true, 'A+' => true, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['B', 'afficher l\'identifiant', 'responsable de M', ['P' => false, 'A+' => true, 'A+ hors de M' => false, 'A=' => false, 'A-' => false, 'R(M)' => false]],

            ['C', 'créer un vendeur de M', null, ['P' => true, 'A+' => false, 'A=' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'cocher des cases dans M', 'vendeur de M', ['P' => true, 'A+' => false, 'A=' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'nommer responsable de M', 'vendeur de M', ['P' => true, 'A+' => true, 'A=' => false, 'A-' => false, 'R(M)' => false, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'suspendre', 'vendeur de M', ['P' => true, 'A+' => false, 'A=' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'réactiver', 'vendeur de M suspendu par R(M)', ['P' => true, 'A+' => false, 'A=' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'réactiver', 'vendeur de M suspendu par le propriétaire', ['P' => true, 'R(M)' => false]],
            ['C', 'retirer de la société', 'vendeur de M', ['P' => true, 'A+' => false, 'A=' => false, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'retirer de la société', 'vendeur de M suspendu par R(M)', ['P' => true, 'R(M)' => true]],
            ['C', 'retirer de la société', 'vendeur de M suspendu par le propriétaire', ['P' => true, 'R(M)' => false]],
            ['C', 'redonner l\'accès', 'vendeur de M', ['P' => true, 'A+' => false, 'A=' => true, 'A-' => false, 'R(M)' => true, 'R(N)' => false, 'V' => false, 'C' => false]],
            ['C', 'redonner l\'accès', 'vendeur de N', ['P' => true, 'A=' => true, 'R(M)' => false, 'R(N)' => true]],
            ['C', 'redonner l\'accès', 'vendeur de O', ['P' => true, 'A=' => false, 'A+ hors de M' => false]],
            ['C', 'afficher l\'identifiant', 'vendeur de M', ['P' => false, 'A+' => false, 'A=' => true, 'A-' => false, 'R(M)' => false, 'V' => false]],
            ['C', 'afficher l\'identifiant', 'vendeur de O', ['A=' => false]],

            ['F', 'suspendre', 'propriétaire', [...$tous(false, false, false, false, false, false), 'A=' => false]],
            ['F', 'retirer de la société', 'propriétaire', [...$tous(false, false, false, false, false, false), 'A=' => false]],
            ['F', 'rétrograder en vendeur dans M', 'propriétaire', $tous(false, false, false, false, false, false)],
            ['F', 'redonner l\'accès', 'propriétaire', [...$tous(false, false, false, false, false, false), 'A=' => false]],
            ['F', 'changer le rôle vers administrateur', 'propriétaire', ['P' => false]],

            ['G', 'cocher des cases dans M', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'suspendre', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'retirer de la société', 'soi-même', $tous(false, false, false, false, false, false)],
            ['G', 'redonner l\'accès', 'soi-même', [...$tous(false, false, false, false, false, false), 'A=' => false]],

            ['H', 'réintégrer comme vendeur de M', 'ancien vendeur retiré par R(M)', ['P' => true, 'A+' => false, 'A=' => false, 'R(M)' => true]],
            ['H', 'réintégrer comme responsable de M', 'ancien responsable retiré par A+', ['P' => true, 'A+' => true, 'R(M)' => false]],
            ['H', 'réintégrer comme vendeur de M', 'ancien vendeur retiré par le propriétaire', ['P' => true, 'R(M)' => false]],
            ['H', 'réintégrer comme responsable de M', 'ancien vendeur retiré par le propriétaire', ['P' => true, 'A+' => false]],
            ['H', 'réintégrer comme collaborateur', 'ancien vendeur retiré par R(M)', ['P' => false, 'A+' => false, 'R(M)' => false]],
            ['H', 'changer le rôle vers administrateur', 'ancien vendeur retiré par R(M)', ['P' => false, 'A+' => false, 'R(M)' => false]],

            ['I', 'créer un collaborateur', null, ['PC' => true, 'C' => false, 'C2' => false, 'P' => false]],
            ['I', 'créer un second collaborateur', null, ['PC' => true, 'C2' => false]],
            ['I', 'suspendre', 'collaborateur', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false, 'A+' => false]],
            ['I', 'réactiver', 'collaborateur suspendu', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false]],
            ['I', 'retirer de la société', 'collaborateur', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false, 'A+' => false]],
            ['I', 'réintégrer comme collaborateur', 'collaborateur retiré', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false]],
            ['I', 'redonner l\'accès', 'collaborateur', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false, 'A=' => false]],
            ['I', 'afficher l\'identifiant', 'collaborateur', ['PC' => false, 'C' => false, 'C2' => false, 'P' => false, 'A=' => false]],
            ['I', 'confier un client', 'collaborateur', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false]],
            ['I', 'nommer responsable de M', 'collaborateur', ['P' => false, 'A+' => false]],
            ['I', 'demander la transmission de la propriété', 'collaborateur prêt à reprendre', ['PC' => true, 'C' => false, 'C2' => false, 'P' => false]],

            ['J', 'voir la liste des comptes de la société', null, [...$tous(true, true, false, false, false, false), 'A=' => true]],
            ['J', 'voir ce compte dans l\'espace société', 'collaborateur', [...$tous(false, false, false, false, false, false), 'A=' => false]],
            ['J', 'voir ce compte dans l\'espace société', 'responsable de M', [...$tous(true, true, false, false, false, false), 'A=' => false]],
            ['J', 'voir ce compte dans l\'espace société', 'vendeur de M', [...$tous(true, false, false, false, false, false), 'A=' => true]],
            ['J', 'voir ce compte dans l\'espace société', 'vendeur de O', ['A=' => false]],
            ['J', 'gérer ce compte dans l\'espace société', 'vendeur de M', ['P' => true, 'A+' => false, 'A=' => false]],
            ['J', 'voir l\'équipe du magasin M', null, [...$tous(true, false, false, true, false, false), 'A=' => false]],
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

        if (in_array($geste, ['créer un collaborateur', 'créer un second collaborateur'], true)) {
            $this->poserLeContexteDuGeste($this->acteur('PC'));
        }

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
            'A=' => $this->administrateur($this->m, [$this->m->id, $this->n->id], [AutorisationsSociete::ACCES_REDONNER]),
            'A-' => $this->administrateur($this->m, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::PARAMETRES_GERER], PermissionRegistry::toutes()),
            'R(M)' => $this->responsable($this->m),
            'R(N)' => $this->responsable($this->n),
            'V' => $this->membre($this->m, RoleMagasin::Vendeur, PermissionRegistry::toutes()),
            'PC' => $this->proprietaireDuCabinet($this->cabinet()),
            'C' => $this->collaborateurQuiSuitM('Paul Lefèvre'),
            'C2' => $this->collaborateurQuiSuitM('Marc Girard'),
        };
    }

    /**
     * Le cabinet d'expertise comptable lié à la société de M, ouvert et lié
     * au premier besoin. Le contexte d'avant est rétabli ensuite.
     */
    private function cabinet(): Societe
    {
        return $this->cabinet ??= app(TenantContext::class)->runAs($this->m, function (): Societe {
            $cabinet = $this->ouvrirUnCabinet();
            $this->lierLeCabinet($cabinet, $this->m);

            return $cabinet;
        });
    }

    /**
     * Un collaborateur du cabinet lié, à qui la société de M est confiée.
     * Le contexte d'avant est rétabli ensuite.
     */
    private function collaborateurQuiSuitM(string $nom): User
    {
        $cabinet = $this->cabinet();

        return app(TenantContext::class)->runAs($this->m, fn (): User => $this->confier($this->collaborateur($cabinet, $nom), [$this->m]));
    }

    private function cible(string $nom): User
    {
        $proprietaire = $this->acteur('P');

        $compte = match ($nom) {
            'propriétaire' => $proprietaire,
            'administrateur', 'administrateur suspendu' => $this->administrateur($this->m, [$this->m->id]),
            'administrateur prêt à reprendre' => $this->administrateurPretAReprendre($this->m),
            'collaborateur prêt à reprendre' => $this->collaborateurPretAReprendre(),
            'responsable de M', 'responsable de M suspendu', 'ancien responsable retiré par A+' => $this->responsable($this->m),
            'vendeur de M', 'vendeur de M suspendu par R(M)', 'vendeur de M suspendu par le propriétaire', 'ancien vendeur retiré par R(M)', 'ancien vendeur retiré par le propriétaire' => $this->membre($this->m, RoleMagasin::Vendeur),
            'vendeur de N' => $this->membre($this->n, RoleMagasin::Vendeur),
            'vendeur de O' => $this->membre($this->o, RoleMagasin::Vendeur),
            'collaborateur', 'collaborateur suspendu', 'collaborateur retiré' => $this->collaborateurQuiSuitM('Julie Moreau'),
        };

        if ($compte->estDUnCabinet()) {
            app(TenantContext::class)->clear();
        }

        match ($nom) {
            'administrateur suspendu', 'responsable de M suspendu', 'vendeur de M suspendu par le propriétaire' => app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $compte),
            'collaborateur suspendu' => app(ChangerStatutCompteAction::class)->suspendre($this->acteur('PC'), $compte),
            'collaborateur retiré' => app(ChangerStatutCompteAction::class)->retirer($this->acteur('PC'), $compte),
            'vendeur de M suspendu par R(M)' => app(ChangerStatutCompteAction::class)->suspendre($this->acteur('R(M)'), $compte),
            'ancien vendeur retiré par R(M)' => app(ChangerStatutCompteAction::class)->retirer($this->acteur('R(M)'), $compte),
            'ancien vendeur retiré par le propriétaire' => app(ChangerStatutCompteAction::class)->retirer($proprietaire, $compte),
            'ancien responsable retiré par A+' => app(ChangerStatutCompteAction::class)->retirer($this->acteur('A+'), $compte),
            default => null,
        };

        $this->poserLeContexteDuGeste($compte);

        return $compte->refresh();
    }

    /**
     * Le contexte où se fait un geste sur ce compte : l'espace du cabinet
     * pour un compte d'un cabinet d'expertise comptable, où son
     * propriétaire gère ses collaborateurs ; le magasin M sinon.
     */
    private function poserLeContexteDuGeste(User $compte): void
    {
        if (! $compte->estDUnCabinet()) {
            app(TenantContext::class)->set($this->m);

            return;
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($this->cabinet());
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
        $nouveau = ['name' => fake()->name()];

        match ($geste) {
            'créer un administrateur' => app(CreerAdministrateurAction::class)->handle($auteur, $societe, $nouveau, [$m], [], []),
            'changer le rôle vers administrateur' => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($auteur, $compte, [$m], [], []),
            'changer le rôle vers membre' => app(ChangerLeRoleDeSocieteAction::class)->versMembre($auteur, $compte, RoleMagasin::Vendeur, null, $m),
            'modifier l\'administrateur' => app(ModifierAdministrateurAction::class)->modifier($auteur, $compte, [$m], [PermissionRegistry::FACTURE_VIEW], []),
            'suspendre' => app(ChangerStatutCompteAction::class)->suspendre($auteur, $compte),
            'réactiver' => app(ChangerStatutCompteAction::class)->reactiver($auteur, $compte),
            'retirer de la société' => app(ChangerStatutCompteAction::class)->retirer($auteur, $compte),
            'redonner l\'accès' => app(RedonnerLAccesAction::class)->handle($auteur, $compte, true, 'un-mot-de-passe-solide'),
            'afficher l\'identifiant' => app(AfficherLIdentifiantAction::class)->handle($auteur, $compte),
            'demander la transmission de la propriété' => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($auteur, $compte, $auteur->estDUnCabinet() ? self::MOT_DE_PASSE_CHOISI : 'password'),
            'créer un responsable de M' => app(CreerMembreAction::class)->handle($auteur, $this->m, $nouveau, RoleMagasin::Responsable),
            'créer un vendeur de M' => app(CreerMembreAction::class)->handle($auteur, $this->m, $nouveau, RoleMagasin::Vendeur),
            'nommer responsable de M' => app(ModifierMembreAction::class)->handle($auteur, $compte, RoleMagasin::Responsable),
            'rétrograder en vendeur dans M' => app(ModifierMembreAction::class)->handle($auteur, $compte, RoleMagasin::Vendeur),
            'cocher des cases dans M' => $this->cocher($auteur, $compte),
            'réintégrer comme vendeur de M' => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $compte, RoleMagasin::Vendeur),
            'réintégrer comme responsable de M' => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $compte, RoleMagasin::Responsable),
            'réintégrer comme collaborateur' => app(ChangerStatutCompteAction::class)->reintegrerLeCollaborateur($auteur, $compte),
            'créer un collaborateur' => app(CreerCollaborateurAction::class)->handle($auteur, $this->cabinet(), $nouveau),
            'créer un second collaborateur' => $this->secondCollaborateur($auteur),
            'confier un client' => app(ConfierDesClientsAction::class)->handle($auteur, $compte, [(int) LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $this->cabinet()->id)->value('id')]),
            'voir la liste des comptes de la société' => $this->exiger($auteur->can('viewAny', User::class)),
            'voir ce compte dans l\'espace société' => $this->exiger($auteur->can('view', $compte)),
            'gérer ce compte dans l\'espace société' => $this->exiger($auteur->can('update', $compte)),
            'voir l\'équipe du magasin M' => $this->exiger(app(TenantContext::class)->runAs($this->m, fn (): bool => $auteur->can('viewAny', Affectation::class))),
        };
    }

    /**
     * Les cases d'un vendeur de son magasin, en gardant son rôle ; pour un
     * responsable, des cases demandées sur son affectation, qu'aucune
     * action n'accepte.
     */
    private function cocher(User $auteur, User $compte): void
    {
        $role = $compte->affectationDeMembre()?->role ?? RoleMagasin::Vendeur;

        if ($role === RoleMagasin::Responsable) {
            try {
                app(ModifierMembreAction::class)->handle($auteur, $compte, RoleMagasin::Responsable, [PermissionRegistry::FACTURE_VIEW]);
            } catch (LogicException) {
                throw new AuthorizationException('Un responsable n\'a aucune case.');
            }

            return;
        }

        app(ModifierMembreAction::class)->handle($auteur, $compte, $role, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);
    }

    /**
     * Plusieurs collaborateurs par cabinet : un second se crée comme le
     * premier.
     */
    private function secondCollaborateur(User $auteur): void
    {
        $this->collaborateur($this->cabinet(), 'Premier collaborateur');
        $this->poserLeContexteDuGeste($this->acteur('PC'));
        app(CreerCollaborateurAction::class)->handle($auteur, $this->cabinet(), ['name' => 'Second collaborateur']);
    }

    /**
     * Un collaborateur qui remplit les conditions de la transmission de la
     * propriété d'un cabinet, les mêmes que pour un administrateur (Q-EC3) :
     * son mot de passe choisi, son adresse renseignée par lui.
     */
    private function collaborateurPretAReprendre(): User
    {
        return $this->renseignerSonAdresse($this->collaborateurQuiSuitM('Julie Moreau'), 'collaborateur@example.test');
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
