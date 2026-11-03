<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\FicheDuClient;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\ListClientsDuCabinet;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * L'isolation de l'espace des cabinets d'expertise comptable : un cabinet
 * ne voit que ses clients liés, un collaborateur que ses clients confiés,
 * un client que son cabinet et les noms de ses personnes qui ont accès,
 * jamais un autre client ni un autre cabinet ; chaque lecture hors du
 * client courant filtre et ne lit que ses colonnes.
 */
class IsolationDesCabinetsTest extends TestCase
{
    use RefreshDatabase;

    private Societe $c1;

    private Societe $c2;

    private User $a;

    private User $b;

    private Magasin $x;

    private Magasin $xBis;

    private Magasin $y;

    private Magasin $z;

    private LienCabinet $lienX;

    private LienCabinet $lienY;

    private LienCabinet $lienZ;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c1 = $this->ouvrirUnCabinet();
        $this->c2 = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $this->x = $this->ouvrirUneSociete(['nom' => 'X centre', 'raison_sociale' => 'CLIENTE X', 'a_verifier' => true]);
        app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($this->x), $this->x->societe);
        $this->xBis = $this->ouvrirUnAutreMagasin($this->x, ['nom' => 'X gare']);
        $this->y = $this->ouvrirUneSociete(['nom' => 'Y', 'raison_sociale' => 'CLIENTE Y']);
        $this->z = $this->ouvrirUneSociete(['nom' => 'Z', 'raison_sociale' => 'CLIENTE Z']);
        $this->lienX = $this->lierLeCabinet($this->c1, $this->x);
        $this->lienY = $this->lierLeCabinet($this->c1, $this->y);
        $this->lienZ = $this->lierLeCabinet($this->c2, $this->z);
        $this->a = $this->confier($this->collaborateur($this->c1, 'Alice Arnaud'), [$this->x]);
        $this->b = $this->confier($this->collaborateur($this->c1, 'Bruno Benoît'), [$this->y]);
    }

    public function test_un_collaborateur_ne_voit_que_les_clients_qui_lui_sont_confies(): void
    {
        $this->dansLEspaceDuCabinet($this->c1, $this->a);
        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$this->lienX])->assertCanNotSeeTableRecords([$this->lienY]);
        $this->get(ClientDuCabinetResource::getUrl('view', ['record' => $this->lienY->uuid], panel: 'cabinet', tenant: $this->c1))->assertNotFound();
        $this->get(ClientDuCabinetResource::getUrl('view', ['record' => $this->lienX->uuid], panel: 'cabinet', tenant: $this->c1))->assertOk();
        $this->flushSession();
        $this->actingAs($this->a)->get("/app/{$this->y->uuid}")->assertNotFound();

        $this->dansLEspaceDuCabinet($this->c1, $this->b);
        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$this->lienY])->assertCanNotSeeTableRecords([$this->lienX]);
        $this->flushSession();
        $this->actingAs($this->b)->get("/app/{$this->x->uuid}")->assertNotFound();
    }

    /**
     * L'adresse de la fiche d'un client que produit la table du cabinet,
     * par l'action « Ouvrir » comme par le clic sur la ligne, porte l'uuid
     * du lien, jamais son identifiant numérique : elle ouvre la fiche pour
     * le propriétaire du cabinet et pour le collaborateur à qui ce client
     * est confié ; la même adresse donne 404 au collaborateur à qui il ne
     * l'est pas et au propriétaire d'un autre cabinet, qui ne la trouve pas
     * non plus dans son propre espace ; l'adresse qui porte l'identifiant
     * numérique du lien donne 404.
     */
    public function test_l_adresse_de_la_fiche_produite_par_la_table_porte_l_uuid_du_lien_et_ne_s_ouvre_qu_a_qui_le_client_est_ouvert(): void
    {
        $adresse = url("/cabinet/{$this->c1->uuid}/clients/{$this->lienX->uuid}");

        foreach (['propriétaire' => $this->proprietaireDuCabinet($this->c1), 'collaborateur confié' => $this->a] as $qui => $compte) {
            $this->dansLEspaceDuCabinet($this->c1, $compte);
            $liste = Livewire::test(ListClientsDuCabinet::class)->assertTableActionHasUrl('view', $adresse, $this->lienX);

            $this->assertSame($adresse, $liste->instance()->getTable()->getRecordUrl($this->lienX), $qui);
            $this->get($adresse)->assertOk()->assertSeeText('CLIENTE X');
        }

        $this->dansLEspaceDuCabinet($this->c1, $this->b);
        $this->get($adresse)->assertNotFound();
        $this->dansLEspaceDuCabinet($this->c2, $this->proprietaireDuCabinet($this->c2));
        $this->get($adresse)->assertNotFound();
        $this->get(url("/cabinet/{$this->c2->uuid}/clients/{$this->lienX->uuid}"))->assertNotFound();
        $this->dansLEspaceDuCabinet($this->c1, $this->proprietaireDuCabinet($this->c1));
        $this->get(url("/cabinet/{$this->c1->uuid}/clients/{$this->lienX->getKey()}"))->assertNotFound();
    }

    /**
     * La liste des clients se cherche par dénomination, sans casse ni
     * accents, ou par SIREN, parmi les seuls clients que le cabinet ouvre
     * au compte : jamais le client d'un autre cabinet, ni, pour un
     * collaborateur, un client qui ne lui est pas confié.
     */
    public function test_la_liste_des_clients_se_cherche_par_denomination_ou_siren_parmi_les_seuls_clients_ouverts_au_compte(): void
    {
        $this->dansLEspaceDuCabinet($this->c1, $this->proprietaireDuCabinet($this->c1));
        Livewire::test(ListClientsDuCabinet::class)
            ->searchTable('clienté y')->assertCanSeeTableRecords([$this->lienY])->assertCanNotSeeTableRecords([$this->lienX, $this->lienZ])
            ->searchTable((string) $this->x->societe->siren)->assertCanSeeTableRecords([$this->lienX])->assertCanNotSeeTableRecords([$this->lienY, $this->lienZ])
            ->searchTable('CLIENTE Z')->assertCountTableRecords(0);

        $this->dansLEspaceDuCabinet($this->c1, $this->a);
        Livewire::test(ListClientsDuCabinet::class)->searchTable('cliente')->assertCanSeeTableRecords([$this->lienX])->assertCanNotSeeTableRecords([$this->lienY, $this->lienZ]);
    }

    public function test_le_proprietaire_du_cabinet_voit_tous_ses_clients_et_jamais_ceux_d_un_autre_cabinet(): void
    {
        $this->dansLEspaceDuCabinet($this->c1, $this->proprietaireDuCabinet($this->c1));
        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$this->lienX, $this->lienY])->assertCanNotSeeTableRecords([$this->lienZ]);
        $this->get(ClientDuCabinetResource::getUrl('view', ['record' => $this->lienZ->uuid], panel: 'cabinet', tenant: $this->c1))->assertNotFound();

        $this->dansLEspaceDuCabinet($this->c2, $this->proprietaireDuCabinet($this->c2));
        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$this->lienZ])->assertCanNotSeeTableRecords([$this->lienX, $this->lienY]);
    }

    public function test_aucune_donnee_d_un_client_n_est_visible_depuis_un_autre_client(): void
    {
        app(TenantContext::class)->set($this->y);
        Client::factory()->create(['nom' => 'Client de Y']);
        Produit::factory()->create(['nom' => 'Produit de Y']);
        $proprietaire = $this->proprietaireDuCabinet($this->c1);

        $this->dansLeMagasin($this->x, $proprietaire);
        $this->assertSame(0, Client::query()->where('nom', 'Client de Y')->count());
        $this->assertSame(0, Produit::query()->where('nom', 'Produit de Y')->count());
        $this->assertEqualsCanonicalizing([$this->x->id, $this->xBis->id], $proprietaire->fresh()->getTenants(Filament::getPanel('app'))->modelKeys());
    }

    public function test_un_client_ne_voit_que_son_cabinet_et_les_noms_de_ses_personnes_qui_ont_acces(): void
    {
        $this->a->forceFill(['email' => 'alice@cabinet.test'])->save();
        $this->dansLEspaceDeLaSociete($this->x->societe, $this->proprietaireDe($this->x));

        Livewire::test(CabinetComptable::class)
            ->assertSeeText(['Claire Martin', 'Alice Arnaud', (string) $this->c1->raison_sociale])
            ->assertDontSeeText(['Bruno Benoît', (string) $this->c2->raison_sociale, 'alice@cabinet.test', (string) $this->a->identifiantDeConnexion()]);
    }

    public function test_les_lectures_hors_du_client_courant_filtrent_par_cabinet_et_ne_lisent_que_leurs_colonnes(): void
    {
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $liens = app(AccesDesCabinets::class)->liensOuverts($this->a)->pluck('liens_cabinet.id')->all();
        $page = new CabinetComptable;
        $personnes = app(TenantContext::class)->runAsSociete($this->x->societe, fn (): array => $page->personnesQuiOntAcces($this->lienX));

        $this->assertSame([$this->lienX->id], array_map(intval(...), $liens));
        $this->assertSame(['Alice Arnaud', 'Claire Martin'], $personnes);
        $this->assertTrue(collect($requetes)->contains(fn (string $sql): bool => str_contains($sql, '"liens_cabinet"."cabinet_id" = ?') && str_contains($sql, 'from "clients_confies"')));
        $this->assertTrue(collect($requetes)->contains(fn (string $sql): bool => str_starts_with($sql, 'select "name" from "users"') || str_starts_with($sql, 'select "users"."name" from "users"')));
    }

    public function test_chaque_modele_d_un_cabinet_ou_d_un_lien_est_isole_par_sa_societe(): void
    {
        $this->inviterUneEntreprise($this->c1, SocieteFactory::sirenFictif(), 'a@b.test');
        $this->inviterUneEntreprise($this->c2, SocieteFactory::sirenFictif(), 'c@d.test');

        $this->assertSame([$this->lienX->id], app(TenantContext::class)->runAsSociete($this->x->societe, fn (): array => LienCabinet::query()->pluck('id')->map(intval(...))->all()));
        $this->assertSame([$this->c1->id], app(TenantContext::class)->runAsSociete($this->c1, fn (): array => InvitationCabinet::query()->pluck('societe_id')->map(intval(...))->unique()->values()->all()));
        $this->assertSame([$this->c1->id], app(TenantContext::class)->runAsSociete($this->c1, fn (): array => ClientConfie::query()->pluck('societe_id')->map(intval(...))->unique()->values()->all()));
        $this->assertSame([], app(TenantContext::class)->runAsSociete($this->c2, fn (): array => ClientConfie::query()->pluck('id')->all()));
    }

    public function test_une_action_forgee_du_cabinet_sur_un_client_non_lie_est_refusee(): void
    {
        $this->assertThrows(fn () => app(ConfierDesClientsAction::class)->handle($this->proprietaireDuCabinet($this->c1), $this->a, [$this->lienZ->id]), LogicException::class);
        $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDuCabinet($this->c1), $this->lienX, []), AuthorizationException::class);
        $this->assertThrows(fn () => app(MettreFinAuLienAction::class)->handle($this->proprietaireDuCabinet($this->c2), $this->lienX), AuthorizationException::class);
        $this->assertSame('actif', DB::table('liens_cabinet')->where('id', $this->lienX->id)->value('etat'));
    }

    /**
     * La fiche d'un client ne donne que l'adresse de ses magasins réels :
     * une requête Livewire forgée qui lui passe l'identifiant d'un magasin
     * d'une autre société, d'un autre client ou de la démonstration ne rend
     * l'adresse d'aucun magasin.
     */
    public function test_une_requete_livewire_forgee_de_la_fiche_d_un_client_ne_rend_l_adresse_d_aucun_autre_magasin(): void
    {
        $demonstration = Magasin::query()->where('societe_id', $this->x->societe_id)->where('est_demonstration', true)->sole();
        $this->dansLEspaceDuCabinet($this->c1, $this->a);
        $fiche = Livewire::test(FicheDuClient::class, ['record' => $this->lienX->uuid]);

        foreach ([$this->z, $this->y, $demonstration] as $magasin) {
            $this->assertThrows(fn () => $fiche->call('urlDuMagasin', $magasin->id), MethodNotFoundException::class);
            $fiche->assertDontSee($magasin->uuid);
        }
        $this->assertSame(
            [Dashboard::getUrl(panel: 'app', tenant: $this->x), Dashboard::getUrl(panel: 'app', tenant: $this->xBis)],
            array_column($fiche->instance()->magasins(), 'url'),
        );
    }

    public function test_un_collaborateur_ne_voit_ni_les_invitations_ni_les_demandes_du_cabinet(): void
    {
        $this->inviterUneEntreprise($this->c1, SocieteFactory::sirenFictif(), 'a@b.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->c1->id)->sole();

        foreach ([$this->a, $this->b] as $collaborateur) {
            $this->dansLEspaceDuCabinet($this->c1, $collaborateur);
            $this->get(InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $this->c1))->assertForbidden();
            $this->assertFalse($collaborateur->can('viewAny', InvitationCabinet::class));
            $this->assertFalse($collaborateur->can('view', $invitation));
        }
    }
}
