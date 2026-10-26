<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les experts-comptables de la société (un compte par collaborateur du
 * cabinet) : chacun lit tous les magasins réels, présents et futurs, et
 * n'écrit rien ; aucun n'ouvre l'espace de la société, la démonstration ni
 * la caisse ; tous sont créés, suspendus, réactivés, retirés et réintégrés
 * par le propriétaire seul ; chacun signe ses propres consultations.
 */
class ExpertComptableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un administrateur, même de tous les magasins et autorisé à tout, un
     * responsable et un vendeur sont refusés par l'écran, par une requête
     * forgée et par l'action ; le propriétaire fait chaque geste.
     */
    public function test_seul_le_proprietaire_cree_suspend_reactive_retire_et_reintegre_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = $this->expertComptable($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $refuses = [$administrateur, $this->responsable($magasin), $this->membre($magasin, RoleMagasin::Vendeur)];

        foreach ($refuses as $compte) {
            $this->assertThrows(fn () => app(CreerExpertComptableAction::class)->handle($compte, $societe, ['name' => 'Cabinet']), AuthorizationException::class, CreerExpertComptableAction::MESSAGE_PROPRIETAIRE_SEUL);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($compte, $expert), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($compte, $expert), AuthorizationException::class);
        }

        $this->dansLEspaceDeLaSociete($societe, $administrateur);
        $this->forgerLAction(Livewire::test(ListMembres::class)->assertActionHidden('creerUnExpertComptable'), 'creerUnExpertComptable', ['name' => 'Cabinet']);
        $this->get(MembreResource::getUrl('view', ['record' => $expert, 'tenant' => $societe]))->assertNotFound();
        foreach (array_slice($refuses, 1) as $compte) {
            $this->flushSession();
            $this->actingAs($compte)->get(MembreResource::getUrl('index', ['tenant' => $societe]))->assertForbidden();
        }
        $this->assertFalse(User::query()->where('name', 'Cabinet')->exists());
        $this->assertTrue($expert->refresh()->estActif());

        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->callAction('creerUnExpertComptable', ['name' => 'Cabinet'])
            ->assertHasNoActionErrors();
        $this->assertTrue(User::query()->where('name', 'Cabinet')->sole()->estExpertComptable());
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->callAction('suspendre');
        $this->assertTrue($expert->refresh()->estSuspendu());
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->callAction('reactiver');
        $this->assertTrue($expert->refresh()->estActif());
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->callAction('retirerDeLaSociete');
        $this->assertTrue($expert->refresh()->estRetire());
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->callAction('reintegrer');
        $this->assertSame([StatutCompte::Actif, RoleSociete::ExpertComptable], [$expert->refresh()->statut, $expert->role_societe]);

        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $expert);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrerLExpertComptable($administrateur, $expert), AuthorizationException::class);
        $this->assertTrue($expert->refresh()->estRetire());
    }

    public function test_l_expert_comptable_lit_tous_les_magasins_reels_y_compris_un_magasin_cree_apres_lui(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $expert = $this->expertComptable($magasin);
        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après lui']);

        $this->assertEqualsCanonicalizing([$magasin->id, $nouveau->id], $expert->getTenants(Filament::getPanel('app'))->modelKeys());
        foreach ([$magasin, $nouveau] as $chacun) {
            /** @var Magasin $chacun */
            app(TenantContext::class)->set($chacun);
            $this->assertTrue($expert->canAccessTenant($chacun), $chacun->nom);
            $this->assertEqualsCanonicalizing(PermissionRegistry::consultations(), $expert->autorisationsDans($chacun), $chacun->nom);
            $this->assertTrue($expert->can('viewAny', Facture::class), $chacun->nom);
            $this->assertFalse($expert->can('create', Facture::class), $chacun->nom);
        }
        $this->assertSame(0, Affectation::query()->where('user_id', $expert->id)->count());
    }

    public function test_l_expert_comptable_n_ouvre_ni_l_espace_societe_ni_la_demonstration_ni_la_caisse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $societe = $magasin->societe;
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($magasin), $societe);
        $expert = $this->expertComptable($magasin);

        $this->assertFalse($expert->canAccessPanel(Filament::getPanel('societe')));
        $this->actingAs($expert)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertForbidden();
        $this->assertFalse($expert->canAccessTenant($demonstration));
        $this->assertNotContains($demonstration->id, $expert->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertSame([], $expert->autorisationsDans($demonstration));

        $this->dansLeMagasin($magasin, $expert);
        Livewire::test(Caisse::class)->assertForbidden();
    }

    /**
     * La trace technique (adresse IP et navigateur) d'un événement ne se
     * lit que par le propriétaire : ni dans la fiche, ni dans l'export de
     * l'expert-comptable. Le journal d'audit de la société, consultable
     * par le propriétaire seul, n'a aucun écran dans le panneau d'un
     * magasin.
     */
    public function test_l_expert_comptable_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = $this->expertComptable($magasin);
        $this->actingAs($proprietaire)->withHeader('User-Agent', 'Navigateur du propriétaire')->get("/app/{$magasin->uuid}")->assertSuccessful();

        $this->dansLeMagasin($magasin, $expert);
        $export = Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);

        $contenu = base64_decode($export->effects['download']['content']);
        $this->assertStringNotContainsString('Navigateur du propriétaire', $contenu);
        $this->assertStringNotContainsString('127.0.0.1', $contenu);
        $this->assertFalse($expert->canAccessPanel(Filament::getPanel('societe')));
        $this->assertFalse(collect(Filament::getPanel('app')->getResources())->contains(fn (string $ressource): bool => str_contains($ressource, 'Audit')));
    }

    /**
     * Il télécharge le PDF d'une vente et exporte le journal des événements
     * du magasin, l'export s'inscrivant au journal à son nom.
     */
    public function test_l_expert_comptable_telecharge_les_pdf_et_exporte_le_journal_des_evenements_a_son_nom(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ticket = $this->ticketDuMagasin($magasin);
        $expert = $this->expertComptable($magasin);

        $this->dansLeMagasin($magasin, $expert);
        $telechargement = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->callAction('telecharger_pdf')
            ->assertFileDownloaded("ticket-{$ticket->numero}.pdf");
        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()])
            ->assertFileDownloaded();

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
        $export = EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->sole();
        $this->assertSame(['id' => $expert->id, 'nom' => $expert->name], $export->contenuSigne()['operateur']);
    }

    /**
     * Il n'envoie aucun e-mail : ni facture, ni avoir, ni devis, ni
     * relance ; le bouton n'apparaît pas et la policy refuse.
     */
    public function test_l_expert_comptable_n_envoie_aucun_e_mail(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ticket = $this->ticketDuMagasin($magasin);
        $expert = $this->expertComptable($magasin);

        $this->dansLeMagasin($magasin, $expert);
        $this->assertTrue($expert->can('view', $ticket));
        $this->assertFalse($expert->can('envoyer', $ticket));
        $this->assertFalse($expert->can(PermissionRegistry::FACTURE_ENVOYER));
        $this->assertFalse($expert->can(PermissionRegistry::AVOIR_ENVOYER));
        $this->assertFalse($expert->can(PermissionRegistry::DEVIS_ENVOYER));
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('envoyer_par_email');
        $this->get(FactureResource::getUrl('view', ['record' => $ticket, 'tenant' => $magasin]))->assertOk()->assertDontSee('Envoyer par e-mail');
    }

    /**
     * Une société a plusieurs experts-comptables, un compte par
     * collaborateur du cabinet : chacun lit tous les magasins réels, et
     * chaque consultation qui s'inscrit au journal porte son propre nom.
     */
    public function test_deux_experts_comptables_dans_la_meme_societe_chacun_ses_traces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->expertComptable($magasin);
        $second = $this->expertComptable($magasin);

        foreach ([$premier, $second] as $expert) {
            $this->assertTrue($expert->refresh()->estExpertComptable());
            $this->dansLeMagasin($magasin, $expert);
            Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);
        }

        $operateurs = EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->orderBy('sequence')->get()->map(fn (EnregistrementFiscal $export): array => $export->contenuSigne()['operateur'])->all();
        $this->assertSame([['id' => $premier->id, 'nom' => $premier->name], ['id' => $second->id, 'nom' => $second->name]], $operateurs);
        $this->assertSame(2, User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::ExpertComptable->value)->count());
    }

    public function test_creer_suspendre_ou_retirer_l_expert_comptable_inscrit_le_jet_130_dans_chaque_magasin_reel(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = $this->expertComptable($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $expert);
        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $expert);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $expert);

        foreach ([$magasin, $autre] as $chacun) {
            $siens = array_values(array_filter($this->modificationsDesDroits($chacun), fn (array $details): bool => $details['membre']['id'] === $expert->id));
            $this->assertSame([
                [null, 'actif'],
                ['actif', 'suspendu'],
                ['suspendu', 'actif'],
                ['actif', null],
            ], array_map(fn (array $details): array => [$details['avant']['statut'] ?? null, $details['apres']['statut'] ?? null], $siens), $chacun->nom);
            $this->assertSame(['role_societe' => 'expert_comptable', 'role_magasin' => null, 'statut' => 'actif', 'permissions' => $this->trier(PermissionRegistry::consultations())], $siens[0]['apres']);
        }
    }

    public function test_un_nouveau_magasin_inscrit_les_droits_implicites_de_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $expert = $this->expertComptable($magasin);

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après lui']);

        $siens = array_values(array_filter($this->modificationsDesDroits($nouveau), fn (array $details): bool => $details['membre']['id'] === $expert->id));
        $this->assertCount(1, $siens);
        $this->assertNull($siens[0]['avant']);
        $this->assertSame(['role_societe' => 'expert_comptable', 'role_magasin' => null, 'statut' => 'actif', 'permissions' => $this->trier(PermissionRegistry::consultations())], $siens[0]['apres']);
    }

    /**
     * Retiré de la société, l'expert-comptable garde son rôle, sans plus
     * aucun droit dans aucun magasin ; il se réintègre comme
     * expert-comptable, sans passer par un autre rôle.
     */
    public function test_retirer_l_expert_comptable_garde_son_role_sans_aucun_droit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $expert = $this->expertComptable($magasin);

        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $expert);

        $expert->refresh();
        $this->assertSame([RoleSociete::ExpertComptable, StatutCompte::Retire], [$expert->role_societe, $expert->statut]);
        $this->assertNull($expert->droitsDans($magasin));
        $this->assertSame([], $expert->autorisationsDans($magasin));
        $this->assertSame([], $expert->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertFalse($expert->canAccessTenant($magasin));
        app(TenantContext::class)->set($magasin);
        $this->assertFalse($expert->can('viewAny', Facture::class));

        app(ChangerStatutCompteAction::class)->reintegrerLExpertComptable($this->proprietaireDe($magasin), $expert);
        $this->assertSame([RoleSociete::ExpertComptable, StatutCompte::Actif], [$expert->refresh()->role_societe, $expert->statut]);
        $this->assertTrue($expert->can('viewAny', Facture::class));
    }

    /**
     * Un ticket de 12,00 € TTC émis dans le magasin par son responsable de
     * test, connecté : le propriétaire ne vend jamais.
     */
    private function ticketDuMagasin(Magasin $magasin): Facture
    {
        $this->actingAs($this->responsableDeTest($magasin));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * @param  list<string>  $autorisations
     * @return list<string>
     */
    private function trier(array $autorisations): array
    {
        sort($autorisations);

        return $autorisations;
    }
}
