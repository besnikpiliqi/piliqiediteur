<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Resources\JournalAuditDuCabinetResource;
use App\Filament\Societe\Resources\JournalAuditResource;
use App\Filament\Societe\Resources\JournalAuditResource\Pages\ListEntreesAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le journal d'audit consultable (voir JournalAuditResource et
 * EntreeAuditPolicy) : le propriétaire seul le lit, entrées et traces
 * techniques comprises, dans l'espace de sa société, ou de son cabinet
 * pour un cabinet d'expertise comptable ; jamais un administrateur, même
 * avec toutes les autorisations, ni un membre, ni un compte d'un cabinet
 * lié, ni une autre société. Les filtres ne proposent que la société et
 * ses magasins ; l'auteur s'affiche par le nom figé au moment de
 * l'opération.
 */
class JournalAuditResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire lit la liste et la fiche d'une entrée, avec l'adresse
     * IP et le navigateur de sa trace ; un administrateur de tous les
     * magasins à qui le propriétaire a coché toutes les autorisations de la
     * société et de magasin n'y accède pas (403, et la Gate le lui refuse),
     * ni un responsable, ni un compte du cabinet lié, son propriétaire ou un
     * collaborateur, qui n'ouvrent pas l'espace de la société.
     */
    public function test_seul_le_proprietaire_consulte_le_journal_et_les_traces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $entree = $this->inscrireParUneRequete($societe, $proprietaire);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::pourLeType($societe->type_activite));
        $responsable = $this->responsable($magasin);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($collaborateur->societe);
        $liste = JournalAuditResource::getUrl('index', panel: 'societe', tenant: $societe);
        $fiche = JournalAuditResource::getUrl('view', ['record' => $entree], panel: 'societe', tenant: $societe);

        $this->actingAs($proprietaire)->get($liste)->assertOk()->assertSee('Identité de la société modifiée');
        $this->actingAs($proprietaire)->get($fiche)->assertOk()->assertSee('203.0.113.7')->assertSee('Navigateur de test');

        foreach ([$administrateur, $responsable, $collaborateur, $proprietaireDuCabinet] as $compte) {
            $this->flushSession();
            $this->actingAs($compte)->get($liste)->assertForbidden();
            $this->actingAs($compte)->get($fiche)->assertForbidden();
        }

        $this->dansLEspaceDeLaSociete($societe, $administrateur);
        $this->assertFalse(Gate::forUser($administrateur)->allows('viewAny', EntreeAudit::class));
        $this->assertFalse(Gate::forUser($administrateur)->allows('view', $entree));
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        $this->assertTrue(Gate::forUser($proprietaire)->allows('viewAny', EntreeAudit::class));
        $this->assertTrue(Gate::forUser($proprietaire)->allows('view', $entree));
        $this->assertFalse(Gate::forUser($proprietaire)->allows('update', $entree));
        $this->assertFalse(Gate::forUser($proprietaire)->allows('delete', $entree));
    }

    /**
     * Le propriétaire d'une autre société n'ouvre pas ce journal, et
     * l'entrée d'une autre société ne se lit pas depuis le sien : elle
     * n'existe pas pour lui (404).
     */
    public function test_le_journal_d_une_autre_societe_ne_se_lit_jamais(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $entreeDAilleurs = $this->inscrire($ailleurs->societe, $ailleurs, ActionAuditee::MagasinModifie, ['nom' => 'Ailleurs']);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->actingAs($this->proprietaireDe($ailleurs))->get(JournalAuditResource::getUrl('index', panel: 'societe', tenant: $magasin->societe))->assertNotFound();
        $this->flushSession();
        $this->actingAs($proprietaire)->get(JournalAuditResource::getUrl('view', ['record' => $entreeDAilleurs], panel: 'societe', tenant: $magasin->societe))->assertNotFound();
    }

    /**
     * Le filtre des magasins ne propose que la société et ses magasins ;
     * un magasin d'une autre société, forcé dans le filtre, ne rend rien ;
     * aucune entrée d'une autre société ne paraît dans la liste.
     */
    public function test_les_filtres_ne_proposent_que_la_societe(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre', 'code' => 'LYON']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'code' => 'VILL']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'code' => 'AILL']);
        $deLyon = $this->inscrire($lyon->societe, $lyon, ActionAuditee::MagasinModifie, ['nom' => 'Lyon Centre']);
        $deLaSociete = $this->inscrire($lyon->societe, null, ActionAuditee::IdentiteModifiee, ['raison_sociale' => 'Martin et Fils']);
        $dAilleurs = $this->inscrire($ailleurs->societe, $ailleurs, ActionAuditee::MagasinModifie, ['nom' => 'Ailleurs']);

        $this->dansLEspaceDeLaSociete($lyon->societe, $this->proprietaireDe($lyon));
        $page = Livewire::test(ListEntreesAudit::class);

        $this->assertSame(
            ['societe' => 'La société (hors magasin)', $lyon->id => 'Lyon Centre (LYON)', $villeurbanne->id => 'Villeurbanne (VILL)'],
            $page->instance()->getTable()->getFilter('portee')->getOptions(),
        );
        $page->assertCanSeeTableRecords([$deLyon, $deLaSociete])
            ->assertCanNotSeeTableRecords([$dAilleurs])
            ->filterTable('portee', $ailleurs->id)
            ->assertCountTableRecords(0)
            ->filterTable('portee', $lyon->id)
            ->assertCanSeeTableRecords([$deLyon])
            ->assertCanNotSeeTableRecords([$deLaSociete])
            ->filterTable('portee', 'societe')
            ->assertCanSeeTableRecords([$deLaSociete])
            ->assertCanNotSeeTableRecords([$deLyon]);
    }

    /**
     * Le filtre des actions propose celles des achats (avoir fournisseur,
     * inventaire d'ouverture) et de la transmission de la propriété par un
     * lien (demandée, annulée, acceptée), et ne garde que l'action choisie.
     */
    public function test_le_filtre_des_actions_retient_les_achats_et_la_transmission_de_la_propriete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $avoir = $this->inscrire($magasin->societe, $magasin, ActionAuditee::AvoirFournisseurEmis, null);
        $transmission = $this->inscrire($magasin->societe, null, ActionAuditee::TransmissionDemandee, null);

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $page = Livewire::test(ListEntreesAudit::class);
        $options = $page->instance()->getTable()->getFilter('action')->getOptions();

        foreach ([ActionAuditee::AvoirFournisseurEmis, ActionAuditee::InventaireOuvertureValide, ActionAuditee::TransmissionDemandee, ActionAuditee::TransmissionAnnulee, ActionAuditee::ProprieteTransferee] as $action) {
            $this->assertSame($action->getLabel(), $options[$action->value]);
        }

        $page->filterTable('action', ActionAuditee::TransmissionDemandee->value)
            ->assertCanSeeTableRecords([$transmission])
            ->assertCanNotSeeTableRecords([$avoir]);
    }

    /**
     * L'auteur d'une entrée s'affiche par le nom que le journal a figé au
     * moment de l'opération, jamais par le nom actuel de son compte ; une
     * entrée sans auteur est celle du système.
     */
    public function test_l_auteur_s_affiche_par_son_nom_au_moment_de_l_operation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['name' => 'Jeanne Martin'])->save();
        $parLuiMeme = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): EntreeAudit => DB::transaction(fn (): EntreeAudit => app(JournalAudit::class)->inscrire(ActionAuditee::IdentiteModifiee, $proprietaire, null, null, ['raison_sociale' => 'Martin et Fils'])));
        $parLeSysteme = $this->inscrire($magasin->societe, null, ActionAuditee::IdentiteModifiee, ['raison_sociale' => 'Martin et Filles']);
        $proprietaire->forceFill(['name' => 'Jeanne Durand'])->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire->fresh());
        Livewire::test(ListEntreesAudit::class)
            ->assertTableColumnStateSet('auteur', 'Jeanne Martin', $parLuiMeme)
            ->assertTableColumnStateSet('auteur', 'Le système', $parLeSysteme)
            ->assertDontSee('Jeanne Durand');
    }

    /**
     * Pendant l'heure qui se répète au passage à l'heure d'hiver, la date
     * d'une entrée dit si c'était l'heure d'été ou l'heure d'hiver, à
     * Paris.
     */
    public function test_l_heure_repetee_dit_l_heure_d_ete_ou_l_heure_d_hiver(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $avant = $this->inscrire($magasin->societe, null, ActionAuditee::IdentiteModifiee, ['raison_sociale' => 'Martin et Fils']);
        $this->travelTo(self::enUtc('2026-10-25 01:30:00'));
        $apres = $this->inscrire($magasin->societe, null, ActionAuditee::IdentiteModifiee, ['raison_sociale' => 'Martin et Filles']);

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(ListEntreesAudit::class)
            ->assertTableColumnFormattedStateSet('created_at', '25/10/2026 02:30:00 (heure d\'été)', $avant)
            ->assertTableColumnFormattedStateSet('created_at', '25/10/2026 02:30:00 (heure d\'hiver)', $apres);
    }

    /**
     * Le propriétaire d'un cabinet d'expertise comptable lit le journal
     * de son cabinet dans l'espace du cabinet ; un collaborateur, non.
     */
    public function test_le_proprietaire_d_un_cabinet_consulte_le_journal_de_son_cabinet_et_pas_un_collaborateur(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet, 'Paul Lefèvre');
        $liste = JournalAuditDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet);

        $this->actingAs($this->proprietaireDuCabinet($cabinet))->get($liste)->assertOk()->assertSee('Collaborateur du cabinet créé');
        $this->flushSession();
        $this->actingAs($collaborateur)->get($liste)->assertForbidden();
    }

    /**
     * D'une procédure de l'éditeur, la fiche ne dit que l'éditeur et la
     * nature de la procédure : ni sa justification, qui peut nommer un
     * tiers, ni sa pièce, ni ses opérateurs, ni l'utilisateur et la machine
     * d'où elle a été lancée, que l'entrée garde pour l'éditeur.
     */
    public function test_la_fiche_d_une_procedure_de_l_editeur_ne_montre_ni_sa_justification_ni_ses_operateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $societe = $magasin->societe;
        $justification = 'Le vrai titulaire, Jean Valjean, a présenté son Kbis et sa pièce d\'identité';
        $entree = app(TenantContext::class)->runAsSociete($societe, fn (): EntreeAudit => DB::transaction(fn (): EntreeAudit => app(JournalAudit::class)->inscrire(ActionAuditee::IdentiteModifiee, null, null, null, ['raison_sociale' => 'Martin et Fils'], null, new InterventionDeLEditeur($justification, 'DOSSIER-2026-0077', ['alice', 'bruno']))));

        $this->actingAs($this->proprietaireDe($magasin))->get(JournalAuditResource::getUrl('view', ['record' => $entree], panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSee('Une procédure exceptionnelle de l\'éditeur, qui en garde la justification et les pièces')
            ->assertDontSee('Jean Valjean')
            ->assertDontSee('DOSSIER-2026-0077')
            ->assertDontSee('Opérateurs')
            ->assertDontSee('Utilisateur du système')
            ->assertDontSee('Machine');
        $this->assertSame([$justification, ['alice', 'bruno']], [$entree->justification, $entree->operateur['operateurs']]);
    }

    /**
     * La propriétaire d'une société inscrite refuse la demande de
     * rattachement d'un cabinet, qui ne connaît d'elle que le SIREN qu'il a
     * saisi : au journal du cabinet, la liste et la fiche disent que le
     * refus vient de l'autre société, sans son nom ni l'identifiant de la
     * société.
     */
    public function test_le_journal_du_cabinet_ne_nomme_pas_la_personne_d_une_societe_qui_refuse_sa_demande(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique discrète']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['name' => 'Jeanne Discrète-Dupont'])->save();
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'jeanne@discrete.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->sole();
        app(TenantContext::class)->clear();

        app(RefuserLaDemandeDeLienAction::class)->handle($proprietaire->fresh(), $demande);

        [$refus] = $this->entreesDAudit($cabinet, ActionAuditee::LienRefuse);
        $this->assertSame(['autre_societe', null, null, ['siren' => (string) $magasin->societe->siren]], [$refus->origine, $refus->auteur_id, $refus->auteur_libelle, $refus->nouvelles_valeurs['client']]);
        $this->actingAs($this->proprietaireDuCabinet($cabinet))->get(JournalAuditDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSee('L&#039;autre société', false)
            ->assertDontSee('Jeanne Discrète-Dupont');
        $this->get(JournalAuditDuCabinetResource::getUrl('view', ['record' => $refus], panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertDontSee('Jeanne Discrète-Dupont');
        [$auJournalDuClient] = $this->entreesDAudit($magasin->societe, ActionAuditee::LienRefuse);
        $this->assertSame('Jeanne Discrète-Dupont', $auJournalDuClient->auteur_libelle);
    }

    /**
     * Une entrée de la vérification de la société se garde jusqu'à la
     * durée réglée après la fin de la relation, tant que cette fin n'est pas
     * venue ; ensuite, jusqu'à la date que la fin de la relation a fixée à
     * la société.
     */
    public function test_la_fin_de_conservation_d_une_entree_de_la_verification_suit_le_reglage_puis_la_date_de_la_societe(): void
    {
        config(['conservation.verification_apres_la_fin_de_la_relation_en_annees' => 6]);
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $societe = $magasin->societe;
        $verification = $this->inscrire($societe, null, ActionAuditee::VerificationEnregistree, ['type' => 'courrier']);
        $fiche = JournalAuditResource::getUrl('view', ['record' => $verification], panel: 'societe', tenant: $societe);

        $this->actingAs($this->proprietaireDe($magasin))->get($fiche)->assertSee('6 ans après la fin de la relation avec la société');

        DB::table('societes')->where('id', $societe->id)->update(['statut' => 'revoquee', 'verification_conservee_jusqu_au' => self::aParis('2032-11-02 10:00:00')->utc()->format('Y-m-d H:i:s')]);
        $this->get($fiche)->assertSee('02/11/2032')->assertDontSee('après la fin de la relation');
    }

    /**
     * Une entrée de l'action, dans la chaîne du magasin ou de la société,
     * inscrite hors de toute requête, au nom du système.
     *
     * @param  array<string, mixed>|null  $nouvellesValeurs
     */
    private function inscrire(Societe $societe, ?Magasin $magasin, ActionAuditee $action, ?array $nouvellesValeurs): EntreeAudit
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): EntreeAudit => DB::transaction(fn (): EntreeAudit => app(JournalAudit::class)->inscrire($action, null, $magasin, null, $nouvellesValeurs)));
    }

    /**
     * Une entrée de la société inscrite par une requête HTTP de cet
     * auteur, depuis l'adresse 203.0.113.7 avec le navigateur « Navigateur
     * de test ».
     */
    private function inscrireParUneRequete(Societe $societe, User $auteur): EntreeAudit
    {
        Route::post("/test/auditer/{$societe->id}", fn (): int => app(TenantContext::class)->runAsSociete(
            $societe,
            fn (): int => DB::transaction(fn (): int => app(JournalAudit::class)->inscrire(ActionAuditee::IdentiteModifiee, $auteur, null, null, ['raison_sociale' => 'Martin et Fils'])->id),
        ));

        $identifiant = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'Navigateur de test')
            ->post("/test/auditer/{$societe->id}")
            ->getContent();

        return EntreeAudit::withoutGlobalScopes()->findOrFail($identifiant);
    }

    /**
     * L'expert-comptable de la société n'ouvre jamais le journal d'audit,
     * ni sa liste ni une fiche (décision de l'éditeur du 01/11/2026).
     */
    public function test_l_expert_comptable_de_la_societe_n_ouvre_jamais_le_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $societe = $magasin->societe;
        $expert = $this->expertComptable($magasin);
        $entree = $this->inscrireParUneRequete($societe, $this->proprietaireDe($magasin));

        $this->flushSession();
        $this->actingAs($expert)->get(JournalAuditResource::getUrl('index', panel: 'societe', tenant: $societe))->assertForbidden();
        $this->actingAs($expert)->get(JournalAuditResource::getUrl('view', ['record' => $entree], panel: 'societe', tenant: $societe))->assertForbidden();
        $this->dansLeMagasin($magasin, $expert);
        $this->assertFalse(Gate::forUser($expert)->allows('viewAny', EntreeAudit::class));
        $this->assertFalse(Gate::forUser($expert)->allows('view', $entree));
    }

    /**
     * Les entrées de la déclaration, de l'ajout d'une personne et du
     * réglage des autorisations de l'expert-comptable de la société
     * nomment leur objet (voir AfficheLeJournalDAudit::OBJETS), jamais une
     * « Fiche » sans nature.
     */
    public function test_les_entrees_de_l_expert_comptable_de_la_societe_nomment_leur_objet(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $societe = $magasin->societe;
        $this->declarerSonExpertComptable($magasin);
        $this->ajouterUnExpertComptable($societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $actions = [ActionAuditee::ExpertComptableDeclare, ActionAuditee::ExpertComptableCree];
        $entrees = app(TenantContext::class)->runAsSociete($societe, fn () => EntreeAudit::query()->whereIn('action', array_map(fn (ActionAuditee $action): string => $action->value, $actions))->whereNotNull('cible_type')->get());
        $this->assertNotEmpty($entrees);

        foreach ($entrees as $entree) {
            $page = html_entity_decode((string) $this->actingAs($this->proprietaireDe($magasin))->get(JournalAuditResource::getUrl('view', ['record' => $entree], panel: 'societe', tenant: $societe))->assertOk()->getContent(), ENT_QUOTES);
            $this->assertStringNotContainsString('Fiche (identifiant', $page);
            $this->assertStringContainsString("(identifiant {$entree->cible_id})", $page);
        }
    }
}
