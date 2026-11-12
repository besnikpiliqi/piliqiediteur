<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
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
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /**
     * Les liens actifs d'un cabinet, que lit son propriétaire pour y mettre
     * fin quand le cabinet n'est plus vérifié, ne lisent de chaque client
     * que son identifiant, son SIREN et sa dénomination, d'un coup pour
     * tous ses clients.
     */
    public function test_les_liens_actifs_d_un_cabinet_ne_lisent_du_client_que_son_siren_et_sa_denomination(): void
    {
        $liens = app(AccesDesCabinets::class)->liensActifsDuCabinet($this->c1);

        $this->assertSame(
            [[['id', 'siren', 'raison_sociale'], 'CLIENTE X'], [['id', 'siren', 'raison_sociale'], 'CLIENTE Y']],
            $liens->map(fn (LienCabinet $lien): array => [array_keys($lien->client->getAttributes()), $lien->client->raison_sociale])->all(),
        );
    }

    /**
     * Un compte retiré d'un cabinet n'a aucun lien ni aucun droit chez ses
     * clients, même si un client lui restait confié (le retrait efface
     * d'ordinaire ses clients confiés, voir
     * ChangerStatutCompteAction::retirerLesDroits()) : rien de ses droits
     * ne s'inscrit au journal des événements du client.
     */
    public function test_un_compte_retire_d_un_cabinet_n_a_aucun_lien_ni_droit_meme_avec_un_client_encore_confie(): void
    {
        $this->a->forceFill(['statut' => StatutCompte::Retire])->save();
        $retire = $this->a->refresh();

        $this->assertSame(0, app(AccesDesCabinets::class)->liensDuCompte($retire)->count());
        $this->assertSame([], app(AccesDesCabinets::class)->autorisationsParClient($retire));
        $this->assertNull($retire->droitsDans($this->x));
    }

    public function test_chaque_modele_d_un_cabinet_ou_d_un_lien_est_isole_par_sa_societe(): void
    {
        $this->inviterUneEntreprise($this->c1, SocieteFactory::sirenFictif(), 'a@b.test');
        $this->inviterUneEntreprise($this->c2, SocieteFactory::sirenFictif(), 'c@d.test');

        $this->assertSame([$this->lienX->id], app(TenantContext::class)->runAsSociete($this->x->societe, fn (): array => LienCabinet::query()->pluck('id')->map(intval(...))->all()));
        $this->assertSame([$this->c1->id], app(TenantContext::class)->runAsSociete($this->c1, fn (): array => InvitationCabinet::query()->pluck('societe_id')->map(intval(...))->unique()->values()->all()));
        $this->assertSame([$this->c1->id], app(TenantContext::class)->runAsSociete($this->c1, fn (): array => ClientConfie::query()->pluck('societe_id')->map(intval(...))->unique()->values()->all()));
        $this->assertSame([], app(TenantContext::class)->runAsSociete($this->c2, fn (): array => ClientConfie::query()->pluck('id')->all()));

        $w = $this->ouvrirUneSociete(['nom' => 'W', 'siren' => SocieteFactory::sirenFictif()]);
        $expert = $this->declarerSonExpertComptable($w, siren: '000000927');
        $this->assertSame([$expert->id], app(TenantContext::class)->runAsSociete($w->societe, fn (): array => CompteExpertComptable::query()->pluck('user_id')->map(intval(...))->all()));
        $this->assertSame([], app(TenantContext::class)->runAsSociete($this->x->societe, fn (): array => CompteExpertComptable::query()->pluck('id')->all()));
    }

    /**
     * Les déclarations en attente d'un SIREN, lues hors de toute société
     * quand le cabinet de ce SIREN devient vérifié (lecture L2 du lot
     * EC2), filtrent par ce SIREN et cet état et ne lisent de chacune que
     * son identifiant et sa société.
     */
    public function test_les_lectures_des_declarations_d_un_siren_ne_lisent_que_leur_identifiant_et_leur_societe(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'siren' => SocieteFactory::sirenFictif()]);
        $v = $this->ouvrirUneSociete(['nom' => 'V', 'siren' => SocieteFactory::sirenFictif()]);
        $autre = $this->ouvrirUneSociete(['nom' => 'U', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($w, siren: '000000927');
        $this->declarerSonExpertComptable($v, siren: '000000927', email: 'luc.moreau@petit-associes.test');
        $this->declarerSonExpertComptable($autre, siren: '000000935', email: 'eve.morel@morel.test');
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $declarations = LienCabinet::declaresDuSiren('000000927');

        $this->assertSame([[['id', 'societe_id'], $w->societe_id], [['id', 'societe_id'], $v->societe_id]], $declarations->map(fn (LienCabinet $lien): array => [array_keys($lien->getAttributes()), $lien->societe_id])->all());
        $this->assertSame(['select "id", "societe_id" from "liens_cabinet" where "siren_cabinet" = ? and "etat" = ? order by "id" asc'], $requetes);
    }

    /**
     * Tant que le cabinet d'un SIREN déclaré n'est pas entièrement
     * vérifié, il n'apprend rien des sociétés qui l'ont déclaré : ni lien,
     * ni client, ni avis, ni entrée de son journal d'audit.
     */
    public function test_le_cabinet_n_apprend_rien_des_societes_qui_ont_declare_son_siren_avant_le_rattachement(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'raison_sociale' => 'CLIENTE W', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($w, siren: '000000927');
        $cabinet = $this->inscrireUneSociete('000000927', 'cabinet@petit-associes.test', TypeDActivite::Cabinet);
        $proprietaire = $cabinet->proprietaire()->firstOrFail();

        $this->assertSame([], app(AccesDesCabinets::class)->liensActifsDuCabinet($cabinet)->all());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $proprietaire->id)->count());
        $this->assertSame(0, DB::table('journal_audit')->where('societe_id', $cabinet->id)->where('nouvelles_valeurs', 'like', '%CLIENTE W%')->count());
        Mail::assertNotSent(fn (Mailable $mail): bool => $mail->hasTo('cabinet@petit-associes.test') && str_contains((string) $mail->render(), 'CLIENTE W'));
    }

    /**
     * Deux sociétés qui déclarent le même SIREN l'ignorent l'une de
     * l'autre : la seconde déclaration se fait sans refus ni message, et
     * rien de la première ne se lit dans la seconde société.
     */
    public function test_une_societe_n_apprend_pas_qu_une_autre_a_declare_le_meme_siren(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'raison_sociale' => 'CLIENTE W', 'siren' => SocieteFactory::sirenFictif()]);
        $v = $this->ouvrirUneSociete(['nom' => 'V', 'raison_sociale' => 'CLIENTE V', 'siren' => SocieteFactory::sirenFictif()]);
        $marc = $this->declarerSonExpertComptable($w, siren: '000000927');
        $luc = $this->declarerSonExpertComptable($v, siren: '000000927', nom: 'Luc Moreau', email: 'luc.moreau@petit-associes.test');

        $this->assertSame([$luc->id], app(TenantContext::class)->runAsSociete($v->societe, fn (): array => CompteExpertComptable::query()->pluck('user_id')->map(intval(...))->all()));
        $this->assertSame(1, app(TenantContext::class)->runAsSociete($v->societe, fn (): int => LienCabinet::query()->count()));
        $this->dansLEspaceDeLaSociete($v->societe, $this->proprietaireDe($v));
        Livewire::test(CabinetComptable::class)->assertSee('Luc Moreau')->assertDontSee('Marc Petit')->assertDontSee('CLIENTE W');
        $this->assertSame(0, DB::table('journal_audit')->where('societe_id', $v->societe_id)->where('nouvelles_valeurs', 'like', '%CLIENTE W%')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->proprietaireDe($v)->id)->where('data', 'like', '%CLIENTE W%')->count());
        $this->assertTrue($marc->fresh()->estActif());
    }

    /**
     * La page du lien du mot de passe, avant qu'aucune société ne soit
     * ouverte (lecture L3 du lot EC2), ne lit que l'attachement, le compte,
     * sa société et sa déclaration, chacun par ses colonnes : jamais le
     * type d'activité de la société, ni qui l'a déclaré, ni l'adresse ou
     * le mot de passe du compte.
     */
    public function test_la_page_du_lien_ne_lit_que_le_compte_sa_societe_et_sa_declaration(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'raison_sociale' => 'CLIENTE W', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($w, siren: '000000927');
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertOk()->assertSee('CLIENTE W');

        $lectures = collect($requetes)->filter(fn (string $sql): bool => str_starts_with($sql, 'select'));
        $this->assertTrue($lectures->contains('select "id", "societe_id", "lien_id", "user_id", "jeton_expire_le" from "comptes_expert_comptable" where "jeton_hache" = ? limit 1'));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "societe_id", "name", "role_societe", "statut" from "users" where "societe_id" = ?')));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "raison_sociale", "siren", "close_le", "statut" from "societes"')));
        $this->assertFalse($lectures->contains(fn (string $sql): bool => str_contains($sql, 'type_activite') || str_contains($sql, 'demande_par') || str_contains($sql, '"email"') || str_contains($sql, '"password"') || str_contains($sql, 'from "identifiants"') || str_contains($sql, 'select *')));
        $this->assertTrue($lectures->filter(fn (string $sql): bool => str_contains($sql, 'from "liens_cabinet"'))->every(fn (string $sql): bool => str_contains($sql, '"liens_cabinet"."societe_id" = ?')));
    }

    /**
     * Les listes, le tableau de bord et les liens d'un cabinet ne lisent
     * que ses liens : une déclaration, qui n'a pas de cabinet, n'y paraît
     * jamais.
     */
    public function test_les_listes_et_le_tableau_de_bord_du_cabinet_ignorent_les_declarations(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'raison_sociale' => 'CLIENTE W', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($w, siren: '000000927');
        $proprietaire = $this->proprietaireDuCabinet($this->c1);

        $this->assertSame([$this->lienX->id, $this->lienY->id], app(AccesDesCabinets::class)->liensOuverts($proprietaire)->pluck('liens_cabinet.id')->map(intval(...))->sort()->values()->all());
        $this->assertSame(['CLIENTE X', 'CLIENTE Y'], collect(app(TableauDeBordDesClients::class)->pour($proprietaire))->map(fn (array $ligne): ?string => $ligne['client']->raison_sociale)->sort()->values()->all());
        $this->assertSame([$this->lienX->id, $this->lienY->id], app(AccesDesCabinets::class)->liensActifsDuCabinet($this->c1)->pluck('id')->map(intval(...))->sort()->values()->all());
        $this->dansLEspaceDuCabinet($this->c1, $proprietaire);
        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$this->lienX, $this->lienY])->assertDontSee('CLIENTE W');
    }

    /**
     * Le nom figé d'un expert-comptable de la société et sa déclaration,
     * lus sans contexte, à la connexion ou à l'ouverture d'un magasin
     * (lecture L4 du lot EC2), ne lisent que l'attachement et la
     * déclaration de sa société.
     */
    public function test_la_connexion_et_le_nom_fige_d_un_expert_comptable_ne_lisent_que_la_declaration_de_sa_societe(): void
    {
        $w = $this->ouvrirUneSociete(['nom' => 'W', 'siren' => SocieteFactory::sirenFictif()]);
        $expert = $this->declarerSonExpertComptable($w, siren: '000000927');
        $relu = User::query()->findOrFail($expert->id);
        app(TenantContext::class)->clear();
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->assertStringContainsString('(expert-comptable déclaré par la société, cabinet', $relu->nomPourLHistorique());

        $this->assertNotEmpty($requetes);
        $this->assertTrue(collect($requetes)->every(fn (string $sql): bool => ! str_contains($sql, 'from "liens_cabinet"') || (str_contains($sql, '"liens_cabinet"."societe_id" = ?') && str_contains($sql, 'from "comptes_expert_comptable" where "societe_id" = ?'))));
        $this->assertFalse(collect($requetes)->contains(fn (string $sql): bool => str_contains($sql, 'select *')));
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
            $this->assertFalse($collaborateur->can('retirer', $invitation));
        }
    }

    /**
     * Une invitation ouverte se voit et se retire par le propriétaire de son
     * cabinet, jamais par celui d'un autre cabinet.
     */
    public function test_seul_le_proprietaire_du_cabinet_voit_et_retire_ses_invitations(): void
    {
        $this->inviterUneEntreprise($this->c1, SocieteFactory::sirenFictif(), 'a@b.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->c1->id)->sole();
        $proprietaire = $this->proprietaireDuCabinet($this->c1);
        $autreProprietaire = $this->proprietaireDuCabinet($this->c2);

        $this->dansLEspaceDuCabinet($this->c1, $proprietaire);
        $this->assertSame([true, true], [$proprietaire->can('view', $invitation), $proprietaire->can('retirer', $invitation)]);

        $this->dansLEspaceDuCabinet($this->c2, $autreProprietaire);
        $this->assertSame([false, false], [$autreProprietaire->can('view', $invitation), $autreProprietaire->can('retirer', $invitation)]);
    }

    /**
     * Les liens et les demandes d'un cabinet (voir, accepter, retirer,
     * mettre fin) restent aux gestes de son propriétaire actif : jamais le
     * propriétaire d'un autre cabinet, ni le sien une fois suspendu.
     */
    public function test_un_autre_cabinet_ou_un_proprietaire_suspendu_n_agit_sur_aucun_lien_du_cabinet(): void
    {
        $designant = $this->ouvrirUneSociete(['nom' => 'W', 'raison_sociale' => 'CLIENTE W']);
        $invitee = $this->ouvrirUneSociete(['nom' => 'V', 'raison_sociale' => 'CLIENTE V']);
        app(TenantContext::class)->clear();
        Mail::fake();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($designant), $designant->societe, $this->c1);
        $this->demanderLeRattachement($this->c1, $invitee->societe, 'v@b.test');
        $demandeDuCabinet = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $this->c1->id)->where('societe_id', $invitee->societe_id)->sole();
        $proprietaire = $this->proprietaireDuCabinet($this->c1);
        $autreProprietaire = $this->proprietaireDuCabinet($this->c2);
        $gestes = fn (User $compte): array => array_map(
            fn (LienCabinet $lien): array => [$compte->can('view', $lien), $compte->can('accepter', $lien), $compte->can('retirer', $lien), $compte->can('mettreFin', $lien)],
            ['désignation du client' => $designation, 'demande du cabinet' => $demandeDuCabinet, 'lien actif' => $this->lienY],
        );
        $aucun = ['désignation du client' => [false, false, false, false], 'demande du cabinet' => [false, false, false, false], 'lien actif' => [false, false, false, false]];

        $this->dansLEspaceDuCabinet($this->c1, $proprietaire);
        $this->assertSame([
            'désignation du client' => [true, true, false, false],
            'demande du cabinet' => [true, false, true, false],
            'lien actif' => [true, false, false, true],
        ], $gestes($proprietaire));

        $this->dansLEspaceDuCabinet($this->c2, $autreProprietaire);
        $this->assertSame($aucun, $gestes($autreProprietaire));

        $this->dansLEspaceDuCabinet($this->c1, $proprietaire);
        $proprietaire->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $this->assertSame($aucun, $gestes($proprietaire->fresh()));
    }

    /**
     * Le propriétaire d'un cabinet n'invite qu'au nom de son cabinet,
     * jamais au nom d'un autre : rien ne part et aucune invitation ne naît.
     */
    public function test_le_proprietaire_d_un_cabinet_n_invite_jamais_au_nom_d_un_autre_cabinet(): void
    {
        Mail::fake();
        $autreProprietaire = $this->proprietaireDuCabinet($this->c2);
        $this->dansLEspaceDuCabinet($this->c2, $autreProprietaire);

        $this->assertSame([true, false], [$autreProprietaire->can('create', [InvitationCabinet::class, $this->c2]), $autreProprietaire->can('create', [InvitationCabinet::class, $this->c1])]);
        $this->assertThrows(
            fn () => app(InviterUneEntrepriseAction::class)->handle($autreProprietaire, $this->c1, SocieteFactory::sirenFictif(), 'a@b.test', self::MOT_DE_PASSE_CHOISI),
            AuthorizationException::class,
            InviterUneEntrepriseAction::MESSAGE_PROPRIETAIRE_SEUL,
        );
        $this->assertSame(0, InvitationCabinet::query()->withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }
}
