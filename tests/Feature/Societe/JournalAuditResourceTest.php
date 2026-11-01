<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
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
}
