<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Facturation\Support\GardesDesDocumentsEmis;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\DestinatairesDesAlertes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\AucunMagasin;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\CoordonneesMagasin;
use App\Filament\App\Pages\LaSociete;
use App\Filament\App\Pages\ParametresFactures;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Filament\App\Pages\ParametresRelances;
use App\Filament\App\Pages\TelechargerLesDocuments;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\App\Resources\EReportingResource;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\PaiementResource;
use App\Filament\App\Resources\PosteReconnuResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ServiceResource;
use App\Filament\App\Widgets\FacturesAEncaisserApercu;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ce que voit et fait l'expert-comptable de la société (décision de
 * l'éditeur du 01/11/2026, § 3) : dans chaque magasin réel de sa société
 * que sa déclaration lui ouvre, présent ou futur, exactement les
 * autorisations de sa déclaration, un refus net pour toute autre, comme un
 * compte d'un cabinet lié ; jamais l'espace de la société, la
 * démonstration, la caisse ni un magasin d'une autre société ; la base
 * refuse toute vente ou tout encaissement à son nom.
 */
class AccesDeLExpertComptableTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_expert_comptable_ouvre_les_magasins_reels_presents_et_futurs_avec_les_autorisations_declarees(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $expert = $this->expertComptable($lyon1, autorisations: [PermissionRegistry::FACTURE_VIEW]);

        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();
        $expert = $expert->fresh();

        $this->assertSame(['Lyon 1', 'Lyon 2', 'Villeurbanne'], $expert->magasinsAccessibles()->pluck('nom')->all());

        foreach ([$lyon1, $lyon2, $villeurbanne] as $magasin) {
            $this->assertTrue($expert->peutOuvrir($magasin), $magasin->nom);
            $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], $expert->autorisationsDans($magasin), $magasin->nom);
        }

        $this->assertNotNull(collect($this->modificationsDesDroits($villeurbanne))->firstWhere('membre.id', $expert->id));
        $ticket = $this->ticketDuMagasin($lyon1);
        $this->actingAs($expert)->get(FactureResource::getUrl('index', tenant: $lyon1))->assertOk()->assertSeeText($ticket->numero);
        $this->actingAs($expert)->get(ClientResource::getUrl('index', tenant: $lyon1))->assertForbidden();
    }

    public function test_l_expert_comptable_n_ouvre_ni_l_espace_de_la_societe_ni_la_demonstration_ni_la_caisse_ni_l_espace_d_un_cabinet(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe);
        app(TenantContext::class)->clear();
        $this->saisirLeCodeDuCourrier($societe)->forceFill(['password' => 'password'])->save();
        $magasin = $this->creerLeMagasinDuSiege($societe->fresh(), 'Lyon 1');
        app(TenantContext::class)->clear();
        $expert = $this->expertComptable($magasin)->fresh();

        $this->assertFalse($expert->canAccessPanel(Filament::getPanel('societe')));
        $this->assertFalse($expert->canAccessPanel(Filament::getPanel('cabinet')));
        $this->assertFalse($expert->canAccessTenant($magasin->societe));
        $this->assertFalse($expert->peutOuvrir($demonstration));
        $this->assertSame([], $expert->autorisationsDans($demonstration));
        $this->actingAs($expert)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        $this->dansLeMagasin($magasin, $expert);
        Livewire::test(Caisse::class)->assertForbidden();
    }

    public function test_un_expert_comptable_n_a_aucune_autorisation_de_societe_ni_rien_dans_l_espace_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->expertComptable($magasin);

        app(TenantContext::class)->runAsSociete($magasin->societe, function () use ($expert, $magasin): void {
            foreach (AutorisationsSociete::toutes() as $autorisation) {
                $this->assertFalse($expert->fresh()->can($autorisation), $autorisation);
            }

            $this->assertFalse($expert->fresh()->can('gererSonCabinet', $magasin->societe));
        });

        $this->assertFalse($expert->fresh()->peutAdministrer());
    }

    public function test_la_base_refuse_une_vente_ou_un_encaissement_au_nom_d_un_expert_comptable_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->expertComptable($magasin);
        $ticket = $this->ticketDuMagasin($magasin);
        $this->retirerLeDeclencheur('factures', GardesDesDocumentsEmis::nom('factures'));

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $ticket->id)->update(['cree_par' => $expert->id])), QueryException::class, GardesDeLaVente::MESSAGE);
    }

    public function test_l_expert_comptable_n_ouvre_aucun_magasin_d_une_autre_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'siren' => SocieteFactory::sirenFictif()]);
        $expert = $this->expertComptable($magasin)->fresh();

        $this->assertFalse($expert->peutOuvrir($autre));
        $this->assertSame([], $expert->autorisationsDans($autre));
        $this->assertNull($expert->droitsDans($autre));
        $this->actingAs($expert)->get("/app/{$autre->uuid}")->assertNotFound();
    }

    public function test_le_tableau_de_bord_dit_expert_comptable_declare_par_la_societe_et_le_nom_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $expert = $this->expertComptable($magasin)->fresh();

        $this->assertSame('Expert-comptable déclaré par la société · cabinet CABINET PETIT ET ASSOCIES · Lyon 1', $expert->libelleDuRoleDans($magasin));
    }

    public function test_entrer_par_les_autorisations_d_un_cabinet_vaut_pour_un_compte_de_cabinet_et_pour_un_expert_comptable(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935');
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->expertComptable($magasin);
        $autre = $this->ouvrirUneSociete(['siren' => SocieteFactory::sirenFictif()]);
        $this->lierLeCabinet($cabinet, $autre);

        $this->assertTrue($expert->fresh()->entreParLesAutorisationsDUnCabinet());
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->fresh()->entreParLesAutorisationsDUnCabinet());
        $this->assertTrue($this->collaborateur($cabinet)->entreParLesAutorisationsDUnCabinet());
        $this->assertFalse($this->proprietaireDe($magasin)->entreParLesAutorisationsDUnCabinet());
        $this->assertFalse($this->responsable($magasin)->entreParLesAutorisationsDUnCabinet());
        $this->assertSame(RoleSociete::ExpertComptable, $expert->role_societe);
    }

    public function test_l_expert_comptable_d_une_societe_sans_magasin_reel_arrive_sur_une_page_qui_le_lui_dit(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe)->forceFill(['password' => 'password'])->save();
        $marc = $this->expertComptable($societe->fresh());
        $this->actingAs($marc);

        $this->get(route('filament.app.tenant'))->assertRedirect(route('filament.app.aucun-magasin'));
        $this->get(route('filament.app.aucun-magasin'))->assertOk()->assertSee(AucunMagasin::MESSAGE_EXPERT_COMPTABLE)->assertSee('Me déconnecter');

        $magasin = app(CreerEtablissementAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe->fresh(), ['nom' => 'Lyon 1', 'code' => null, 'email' => null, 'nic' => MagasinFactory::nicLibre($societe)]);
        app(TenantContext::class)->clear();
        $this->get(route('filament.app.aucun-magasin'))->assertRedirect(route('filament.app.tenant'));
        $this->assertTrue($marc->fresh()->peutOuvrir($magasin));
    }

    public function test_l_expert_comptable_voit_l_identite_et_les_reglages_fiscaux_de_la_societe_en_lecture_seule(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);

        $this->actingAs($marc);
        $this->get(LaSociete::getUrl(tenant: $magasin))->assertOk()
            ->assertSee((string) $magasin->societe->siren)
            ->assertSee($magasin->societe->type_activite->getLabel())
            ->assertSee('Mois de fin d')
            ->assertSee('seul le propriétaire de la société les change');
        $this->get(LaSociete::getUrl(tenant: $autre))->assertNotFound();

        foreach ([$vendeur, $this->proprietaireDe($magasin)] as $autreCompte) {
            $this->dansLeMagasin($magasin, $autreCompte);
            $this->assertFalse(LaSociete::canAccess());
        }

        $this->dansLeMagasin($magasin, $marc->fresh());
        $this->assertTrue(LaSociete::canAccess());
    }

    /**
     * Ce qu'un expert-comptable de la société, à qui la déclaration accorde
     * toutes les autorisations d'un cabinet, ouvre dans un magasin réel :
     * exactement ce qu'ouvre un compte d'un cabinet lié aux mêmes
     * autorisations (voir PanneauCabinetTest), plus « La société ».
     */
    public function test_chaque_ressource_et_page_du_panneau_app_a_une_reponse_attendue_pour_un_expert_comptable(): void
    {
        $attendu = [
            AvoirFournisseurResource::class => ['consulter' => true, 'creer' => true],
            AvoirResource::class => ['consulter' => true, 'creer' => false],
            ClientResource::class => ['consulter' => true, 'creer' => false],
            ClotureCaissePersonnelleResource::class => ['consulter' => true, 'creer' => false],
            ClotureCaisseResource::class => ['consulter' => true, 'creer' => false],
            DevisResource::class => ['consulter' => true, 'creer' => false],
            EReportingResource::class => ['consulter' => true, 'creer' => false],
            EnregistrementFiscalResource::class => ['consulter' => true, 'creer' => false],
            EquipeDuMagasinResource::class => ['consulter' => false, 'creer' => false],
            FactureAchatResource::class => ['consulter' => true, 'creer' => true],
            FactureResource::class => ['consulter' => true, 'creer' => false],
            FournisseurResource::class => ['consulter' => true, 'creer' => true],
            InventaireOuvertureResource::class => ['consulter' => true, 'creer' => true],
            PaiementResource::class => ['consulter' => true, 'creer' => false],
            PosteReconnuResource::class => ['consulter' => false, 'creer' => false],
            ProduitResource::class => ['consulter' => true, 'creer' => false],
            ServiceResource::class => ['consulter' => true, 'creer' => false],
        ];
        $pagesAttendues = [
            Caisse::class => false,
            CoordonneesMagasin::class => false,
            LaSociete::class => true,
            ParametresFactures::class => false,
            ParametresPrixDeVente::class => false,
            ParametresRelances::class => false,
            TelechargerLesDocuments::class => true,
            Dashboard::class => true,
        ];
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->expertComptable($magasin, autorisations: PermissionRegistry::casesDuCabinet(PermissionRegistry::duCabinet(TypeDActivite::Commerce)));
        $this->dansLeMagasin($magasin, $marc->fresh());
        $panneau = Filament::getPanel('app');

        $this->assertEqualsCanonicalizing(array_keys($attendu), array_values($panneau->getResources()));
        $this->assertEqualsCanonicalizing(array_keys($pagesAttendues), array_values($panneau->getPages()));
        foreach ($attendu as $ressource => $reponse) {
            $this->assertSame($reponse, ['consulter' => $ressource::canViewAny(), 'creer' => $ressource::canCreate()], $ressource);
        }
        foreach ($pagesAttendues as $page => $reponse) {
            $this->assertSame($reponse, $page::canAccess(), $page);
        }
    }

    public function test_l_expert_comptable_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $this->actingAs($this->proprietaireDe($magasin))->withHeader('User-Agent', 'Navigateur du propriétaire')->get("/app/{$magasin->uuid}")->assertSuccessful();

        $this->dansLeMagasin($magasin, $marc->fresh());
        $export = Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);

        $contenu = base64_decode($export->effects['download']['content']);
        $this->assertStringNotContainsString('Navigateur du propriétaire', $contenu);
        $this->assertStringNotContainsString('127.0.0.1', $contenu);
        $this->assertFalse(collect(Filament::getPanel('app')->getResources())->contains(fn (string $ressource): bool => str_contains($ressource, 'Audit')));
        $this->assertFalse($marc->fresh()->canAccessPanel(Filament::getPanel('societe')));
    }

    public function test_l_expert_comptable_telecharge_les_pdf_et_exporte_le_journal_des_evenements_a_son_nom_avec_son_cabinet(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $marc = $this->expertComptable($magasin);
        $ticket = $this->ticketDuMagasin($magasin);

        $this->dansLeMagasin($magasin, $marc->fresh());
        $telechargement = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->callAction('telecharger_pdf')->assertFileDownloaded("ticket-{$ticket->numero}.pdf");
        Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()])->assertFileDownloaded();

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
        $export = EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->sole();
        $this->assertSame(['id' => $marc->id, 'nom' => 'Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)'], $export->contenuSigne()['operateur']);
    }

    public function test_l_expert_comptable_n_envoie_aucun_e_mail_a_un_client_et_ne_recoit_aucune_alerte_de_stock(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::PRODUIT_CREATE, PermissionRegistry::FACTURE_ACHAT_CREER]);
        $ticket = $this->ticketDuMagasin($magasin);

        $this->dansLeMagasin($magasin, $marc->fresh());
        $this->assertTrue($marc->fresh()->can('view', $ticket));
        $this->assertFalse($marc->fresh()->can('envoyer', $ticket));
        foreach ([PermissionRegistry::FACTURE_ENVOYER, PermissionRegistry::AVOIR_ENVOYER, PermissionRegistry::DEVIS_ENVOYER] as $envoi) {
            $this->assertFalse($marc->fresh()->can($envoi), $envoi);
        }
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('envoyer_par_email');
        app(TenantContext::class)->clear();

        foreach ([PermissionRegistry::PRODUIT_AJUSTER_STOCK, PermissionRegistry::PRODUIT_CREATE, PermissionRegistry::FACTURE_ACHAT_CREER] as $autorisation) {
            $this->assertNotContains($marc->id, app(DestinatairesDesAlertes::class)->autorisesDans($magasin, $autorisation)->modelKeys(), $autorisation);
        }
    }

    public function test_avec_les_achats_declares_l_expert_comptable_cree_et_valide_une_facture_d_achat_a_son_nom(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $marc = $this->expertComptable($magasin, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE])->fresh();
        $this->dansLeMagasin($magasin, $marc);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);

        $factureAchat = app(ValiderFactureAchatAction::class)->handle($marc, app(CreerFactureAchatAction::class)->handle($marc, Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ], ['date_facture' => '2026-01-15']));

        $this->assertSame([$marc->id, 'Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)'], [(int) $factureAchat->cree_par, $factureAchat->cree_par_nom]);
        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->fresh()->statut);
    }

    public function test_l_expert_comptable_suit_la_lecture_seule_de_la_societe_et_de_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Lyon 2']);
        $marc = $this->expertComptable($magasin, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::FACTURE_ACHAT_CREER]);

        $this->dansLeMagasin($lyon2, $marc->fresh());
        $this->assertTrue($marc->fresh()->can(PermissionRegistry::FACTURE_ACHAT_CREER));
        app(TenantContext::class)->clear();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $lyon2, MotifFermeture::Temporaire, 'password');
        app(TenantContext::class)->clear();

        $this->dansLeMagasin($lyon2->fresh(), $marc->fresh());
        $this->assertTrue($marc->fresh()->can('viewAny', Facture::class));
        $this->assertFalse($marc->fresh()->can(PermissionRegistry::FACTURE_ACHAT_CREER));
        app(TenantContext::class)->clear();

        app(TenantContext::class)->runAsSociete($magasin->societe, fn (): bool => $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save());
        $this->dansLeMagasin($magasin->fresh(), $marc->fresh());
        $this->assertTrue($marc->fresh()->can('viewAny', Facture::class));
        $this->assertFalse($marc->fresh()->can(PermissionRegistry::FACTURE_ACHAT_CREER));
        $this->actingAs($marc->fresh())->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
    }

    public function test_un_expert_comptable_n_a_que_les_autorisations_declarees_quelles_que_soient_les_lignes_en_base(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->sole();
        DB::unprepared('DROP TRIGGER IF EXISTS '.GardesDesCabinets::AUTORISATIONS.' ON autorisations_cabinet');
        DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $declaration->id, 'autorisation' => PermissionRegistry::FACTURE_CREATE]);

        $marc = $marc->fresh();
        $this->dansLeMagasin($magasin, $marc);

        $this->assertNotContains(PermissionRegistry::FACTURE_CREATE, $marc->autorisationsDans($magasin));
        $this->assertFalse($marc->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertFalse($marc->can('create', Facture::class));
        $this->assertFalse($marc->can(AutorisationsSociete::MAGASINS_GERER));
    }

    public function test_l_expert_comptable_a_exactement_les_droits_d_un_compte_de_cabinet_aux_memes_autorisations(): void
    {
        $autorisations = [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::PRODUIT_CREATE];
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin, autorisations: $autorisations)->fresh();
        $cabinet = $this->ouvrirUnCabinet('000000935');
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'siren' => SocieteFactory::sirenFictif()]);
        $this->lierLeCabinet($cabinet, $autre, $autorisations);
        $paul = $this->confier($this->collaborateur($cabinet), [$autre]);

        $this->assertSame($paul->autorisationsDans($autre), $marc->autorisationsDans($magasin));
        $attendues = [...$autorisations, PermissionRegistry::FACTURE_VIEW_TOUTES];
        sort($attendues);
        $this->assertSame($attendues, $marc->autorisationsDans($magasin));
        foreach ([[$magasin, $marc], [$autre, $paul]] as [$chez, $compte]) {
            $this->dansLeMagasin($chez, $compte);
            $reponses[] = array_map(fn (string $autorisation): bool => $compte->can($autorisation), [...PermissionRegistry::toutes(), ...AutorisationsSociete::toutes()]);
            app(TenantContext::class)->clear();
        }

        $this->assertSame($reponses[1], $reponses[0]);
    }

    public function test_deux_experts_comptables_de_la_meme_societe_signent_chacun_leurs_consultations(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($this->jetonDuDernierLienDExpertComptable(), self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);
        app(TenantContext::class)->clear();

        foreach ([$marc, $ines] as $expert) {
            $this->dansLeMagasin($magasin, $expert->fresh());
            Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);
            app(TenantContext::class)->clear();
        }

        $operateurs = app(TenantContext::class)->runAs($magasin, fn () => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->orderBy('sequence')->get()->map(fn (EnregistrementFiscal $export): int => $export->contenuSigne()['operateur']['id'])->all());
        $this->assertSame([$marc->id, $ines->id], $operateurs);
    }

    public function test_un_nouveau_magasin_inscrit_les_droits_implicites_des_experts_comptables_ouverts(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $marc = $this->expertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $ines);
        app(TenantContext::class)->clear();

        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert après la déclaration']);

        $siens = array_values(array_filter($this->modificationsDesDroits($nouveau), fn (array $details): bool => $details['membre']['id'] === $marc->id));
        $this->assertCount(1, $siens);
        $this->assertNull($siens[0]['avant']);
        $this->assertSame(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), $siens[0]['apres']['permissions']);
        $this->assertSame(['siren' => FauxRepertoireSirene::SIREN_CABINET, 'raison_sociale' => 'CABINET PETIT ET ASSOCIES'], $siens[0]['apres']['cabinet_declare']);
        $this->assertSame('Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)', $siens[0]['membre']['nom']);
        $this->assertSame([], array_values(array_filter($this->modificationsDesDroits($nouveau), fn (array $details): bool => $details['membre']['id'] === $ines->id && $details['apres'] !== null && $details['apres']['statut'] === 'actif')));
    }

    public function test_un_compte_d_expert_comptable_retire_garde_son_role_et_son_identifiant_sans_aucun_droit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $identifiant = $marc->identifiantDeConnexion();

        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $marc->fresh());
        app(TenantContext::class)->clear();
        $marc = $marc->fresh();

        $this->assertNotNull($identifiant);
        $this->assertSame([RoleSociete::ExpertComptable, $identifiant], [$marc->role_societe, $marc->identifiantDeConnexion()]);
        $this->assertFalse($marc->peutOuvrir($magasin));
        $this->assertSame([], $marc->autorisationsDans($magasin));
        $this->assertSame([], $marc->magasinsAccessibles()->all());
        $this->actingAs($marc)->get("/app/{$magasin->uuid}")->assertForbidden();
        $this->assertTrue($ines->fresh()->peutOuvrir($magasin));
    }

    public function test_l_alerte_des_factures_a_encaisser_suit_l_autorisation_de_voir_celles_de_toute_l_equipe(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin);
        $deLEquipe = $this->factureAUnProfessionnel($vendeur, 50);
        $duResponsable = $this->factureAUnProfessionnel(self::responsableDeTest($magasin), 120);
        app(TenantContext::class)->clear();
        $marc = $this->expertComptable($magasin);
        $this->dansLeMagasin($magasin, $marc->fresh());

        $this->assertTrue(FacturesAEncaisserApercu::canView());
        Livewire::test(FacturesAEncaisserApercu::class)->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $deLEquipe->numero, $duResponsable->numero]);
        $this->assertSame([false, false], [$marc->fresh()->can(PermissionRegistry::PAIEMENT_CONTRE_PASSER), $marc->fresh()->can(PermissionRegistry::PAIEMENT_CREER)]);
        app(TenantContext::class)->clear();

        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $declaration, array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), [PermissionRegistry::FACTURE_VIEW])));
        $this->dansLeMagasin($magasin, $marc->fresh());

        $this->assertFalse(FacturesAEncaisserApercu::canView());
    }

    public function test_l_url_d_un_document_d_un_autre_magasin_de_la_societe_repond_404_a_l_expert_comptable(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Villeurbanne']);
        $ticket = $this->ticketDuMagasin($lyon2);
        $marc = $this->expertComptable($lyon1)->fresh();
        $this->actingAs($marc);

        $this->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $lyon2))->assertOk();
        $this->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $lyon1))->assertNotFound();

        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon1->societe_id)->where('etat', EtatDuLien::Declare->value)->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($lyon1), $declaration, $declaration->autorisationsRetenues(), [$lyon1->id, $villeurbanne->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($marc->fresh())->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $lyon2))->assertNotFound();
        $this->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $villeurbanne))->assertNotFound();
    }

    private function factureAUnProfessionnel(User $auteur, int $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    private function ticketDuMagasin(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);
        $vendeur = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        app(TenantContext::class)->clear();

        return $ticket;
    }
}
