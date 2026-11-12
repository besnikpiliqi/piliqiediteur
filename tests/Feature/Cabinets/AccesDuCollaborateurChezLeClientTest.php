<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
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
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DestinatairesDesAlertes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un compte d'un cabinet d'expertise comptable ouvre les magasins réels
 * d'un client lié qui lui est confié comme s'il y était connecté, avec
 * exactement les autorisations que le client accorde au cabinet, en son
 * nom avec celui du cabinet ; jamais l'espace de la société du client, sa
 * démonstration, sa caisse, ses envois, son journal d'audit ni ses traces.
 */
class AccesDuCollaborateurChezLeClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_collaborateur_ouvre_les_magasins_reels_des_clients_confies_comme_s_il_y_etait_connecte(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $ticket = $this->ticketDuMagasin($magasin);

        $this->actingAs($collaborateur)->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk()->assertSeeText($ticket->numero);
        $this->actingAs($collaborateur)->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $magasin))->assertOk();
    }

    public function test_un_collaborateur_ne_voit_pas_un_client_qui_ne_lui_est_pas_confie_et_le_proprietaire_du_cabinet_les_voit_tous(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre cliente']);
        $this->lierLeCabinet($cabinet, $autre);

        $this->assertFalse($collaborateur->fresh()->peutOuvrir($autre));
        $this->actingAs($collaborateur)->get("/app/{$autre->uuid}")->assertNotFound();
        $this->assertEqualsCanonicalizing([$magasin->id, $autre->id], $this->proprietaireDuCabinet($cabinet)->magasinsAccessibles()->modelKeys());
        $this->assertSame([$magasin->id], $collaborateur->fresh()->magasinsAccessibles()->modelKeys());
    }

    public function test_un_collaborateur_telecharge_les_pdf_et_exporte_le_journal_a_son_nom_avec_celui_du_cabinet(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $ticket = $this->ticketDuMagasin($magasin);

        $this->dansLeMagasin($magasin, $collaborateur);
        $telechargement = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->callAction('telecharger_pdf')->assertFileDownloaded("ticket-{$ticket->numero}.pdf");
        Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()])->assertFileDownloaded();

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
        $export = EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->sole();
        $this->assertSame(['id' => $collaborateur->id, 'nom' => "Paul Lefèvre (cabinet {$cabinet->raison_sociale})"], $export->contenuSigne()['operateur']);
    }

    public function test_deux_collaborateurs_du_meme_cabinet_signent_chacun_leurs_consultations(): void
    {
        [$cabinet, $magasin, $premier] = $this->collaborateurDUnClient();
        $second = $this->confier($this->collaborateur($cabinet, 'Inès Garnier'), [$magasin]);

        foreach ([$premier, $second] as $collaborateur) {
            $this->dansLeMagasin($magasin, $collaborateur);
            Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);
        }

        $operateurs = EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->orderBy('sequence')->get()->map(fn (EnregistrementFiscal $export): int => $export->contenuSigne()['operateur']['id'])->all();
        $this->assertSame([$premier->id, $second->id], $operateurs);
    }

    public function test_un_collaborateur_ne_lit_ni_le_journal_d_audit_ni_les_traces_ip_et_navigateur_du_client(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $this->actingAs($this->proprietaireDe($magasin))->withHeader('User-Agent', 'Navigateur du propriétaire')->get("/app/{$magasin->uuid}")->assertSuccessful();

        $this->dansLeMagasin($magasin, $collaborateur);
        $export = Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => Calendrier::deParis()->aujourdhui(), 'au' => Calendrier::deParis()->aujourdhui()]);

        $contenu = base64_decode($export->effects['download']['content']);
        $this->assertStringNotContainsString('Navigateur du propriétaire', $contenu);
        $this->assertStringNotContainsString('127.0.0.1', $contenu);
        $this->assertFalse(collect(Filament::getPanel('app')->getResources())->contains(fn (string $ressource): bool => str_contains($ressource, 'Audit')));
    }

    public function test_un_collaborateur_n_ouvre_ni_l_espace_de_la_societe_du_client_ni_sa_demonstration_ni_sa_caisse(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'a_verifier' => true]);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe);
        $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);

        $this->actingAs($collaborateur)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        $this->assertFalse($collaborateur->canAccessTenant($magasin->societe));
        $this->assertFalse($collaborateur->canAccessTenant($demonstration));
        $this->assertSame([], $collaborateur->autorisationsDans($demonstration));
        $this->dansLeMagasin($magasin, $collaborateur);
        Livewire::test(Caisse::class)->assertForbidden();
    }

    public function test_un_collaborateur_n_envoie_aucun_e_mail_au_client_du_magasin(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $ticket = $this->ticketDuMagasin($magasin);

        $this->dansLeMagasin($magasin, $collaborateur);
        $this->assertTrue($collaborateur->can('view', $ticket));
        $this->assertFalse($collaborateur->can('envoyer', $ticket));
        foreach ([PermissionRegistry::FACTURE_ENVOYER, PermissionRegistry::AVOIR_ENVOYER, PermissionRegistry::DEVIS_ENVOYER] as $envoi) {
            $this->assertFalse($collaborateur->can($envoi));
        }
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('envoyer_par_email');
    }

    public function test_avec_les_achats_accordes_un_collaborateur_cree_et_valide_une_facture_d_achat_a_son_nom_avec_celui_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE]);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $this->dansLeMagasin($magasin, $collaborateur);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);

        $factureAchat = app(ValiderFactureAchatAction::class)->handle($collaborateur, app(CreerFactureAchatAction::class)->handle($collaborateur, Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ], ['date_facture' => '2026-01-15']));

        $this->assertSame([$collaborateur->id, "Paul Lefèvre (cabinet {$cabinet->raison_sociale})"], [(int) $factureAchat->cree_par, $factureAchat->cree_par_nom]);
        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->fresh()->statut);
    }

    public function test_un_collaborateur_suit_la_lecture_seule_du_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::FACTURE_ACHAT_CREER]);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        app(TenantContext::class)->runAsSociete($magasin->societe, fn (): bool => $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save());

        $this->dansLeMagasin($magasin->fresh(), $collaborateur);
        $this->assertTrue($collaborateur->can('viewAny', Facture::class));
        $this->assertFalse($collaborateur->can(PermissionRegistry::FACTURE_ACHAT_CREER));
        $this->actingAs($collaborateur)->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
    }

    /**
     * Attribuer en base un ticket émis à un compte du cabinet est refusé
     * par la garde des documents émis, puis, celle-ci levée comme le
     * ferait le propriétaire des tables, par la garde de la vente.
     */
    public function test_la_base_refuse_une_vente_ou_un_encaissement_au_nom_d_un_compte_de_cabinet(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $brouillon = $this->ticketDuMagasin($magasin);
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['cree_par' => $collaborateur->id])), QueryException::class, GardesDesDocumentsEmis::refusDeModification('factures'));
        $this->retirerLeDeclencheur('factures', GardesDesDocumentsEmis::nom('factures'));

        foreach ([$collaborateur, $proprietaireDuCabinet] as $compte) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['cree_par' => $compte->id])), QueryException::class, GardesDeLaVente::MESSAGE);
        }
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesDUnClient(): array
    {
        return ['commerce' => [TypeDActivite::Commerce], 'services' => [TypeDActivite::Services]];
    }

    #[DataProvider('typesDUnClient')]
    public function test_le_proprietaire_du_cabinet_n_a_aucun_droit_de_proprietaire_chez_un_client(TypeDActivite $type): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'type' => $type]);
        $this->lierLeCabinet($cabinet, $magasin);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $this->dansLeMagasin($magasin, $proprietaire);
        foreach (array_diff(PermissionRegistry::duProprietaire($type), PermissionRegistry::duCabinet($type)) as $autorisation) {
            $this->assertFalse($proprietaire->can($autorisation), $autorisation);
        }
        $this->assertFalse($proprietaire->can('viewAny', User::class));
        $this->assertFalse($proprietaire->canAccessTenant($magasin->societe));
        $this->actingAs($proprietaire)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
    }

    public function test_le_selecteur_d_un_collaborateur_ne_montre_que_les_magasins_reels_du_client_ouvert(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $second = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Seconde boutique']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre cliente']);
        $this->lierLeCabinet($cabinet, $autre);
        $this->confier($collaborateur, [$autre]);

        $this->dansLeMagasin($magasin, $collaborateur->fresh());
        $this->assertEqualsCanonicalizing([$magasin->id, $second->id], $collaborateur->fresh()->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->dansLeMagasin($autre, $collaborateur->fresh());
        $this->assertSame([$autre->id], $collaborateur->fresh()->getTenants(Filament::getPanel('app'))->modelKeys());
    }

    public function test_un_compte_de_cabinet_qui_ouvre_app_va_a_l_espace_du_cabinet(): void
    {
        [$cabinet, , $collaborateur] = $this->collaborateurDUnClient();

        $this->actingAs($collaborateur)->get('/app')->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet));
    }

    public function test_un_cabinet_qui_n_est_plus_verifie_ou_plus_actif_n_ouvre_plus_aucun_client(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $lien = LienCabinet::query()->withoutGlobalScopes()->sole();
        $cabinet->cabinet()->sole()->forceFill(['ordre_retire_le' => now()])->save();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        foreach ([$collaborateur, $proprietaire] as $compte) {
            $this->assertFalse($compte->fresh()->peutOuvrir($magasin));
            $this->assertFalse(app(TenantContext::class)->runAs($magasin, fn (): bool => $compte->fresh()->can(PermissionRegistry::FACTURE_VIEW)));
            $this->flushSession();
            $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertNotFound();
        }
        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        $this->get(ClientDuCabinetResource::getUrl('view', ['record' => $lien->uuid], panel: 'cabinet', tenant: $cabinet))->assertNotFound();
        $tableau = Livewire::test(TableauDeBordDuCabinet::class);
        $this->assertSame([], $tableau->viewData('clients'));
        $this->assertSame([$lien->id], $tableau->viewData('liensActifs')->modelKeys());
        $tableau->callAction('mettreFinAuLien', arguments: ['uuid' => $lien->uuid]);
        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);

        $actif = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $client = $this->ouvrirUneSociete(['nom' => 'Cliente du second']);
        $this->lierLeCabinet($actif, $client);
        $actif->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $this->assertFalse($this->proprietaireDuCabinet($actif)->peutOuvrir($client));
    }

    public function test_peut_ouvrir_se_lit_sans_contexte_et_dans_le_contexte_d_un_client(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();

        app(TenantContext::class)->clear();
        $this->assertTrue($collaborateur->fresh()->peutOuvrir($magasin));
        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        app(TenantContext::class)->runAsSociete($magasin->societe, function () use ($collaborateur, $magasin, $cabinet): void {
            $this->assertTrue($collaborateur->fresh()->peutOuvrir($magasin));
            $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        });
    }

    public function test_un_collaborateur_ouvre_un_magasin_du_client_par_son_url(): void
    {
        [$cabinet, $magasin, $collaborateur] = $this->collaborateurDUnClient();
        $nonConfie = $this->ouvrirUneSociete(['nom' => 'Non confiée']);
        $this->lierLeCabinet($cabinet, $nonConfie);

        $this->actingAs($collaborateur)->get("/app/{$magasin->uuid}")->assertOk();
        $this->get("/app/{$nonConfie->uuid}")->assertNotFound();
    }

    public function test_la_fin_de_session_d_un_collaborateur_s_inscrit_dans_les_magasins_du_client_qu_il_a_ouverts(): void
    {
        [, $magasin, $collaborateur] = $this->collaborateurDUnClient();

        $this->actingAs($collaborateur)->get("/app/{$magasin->uuid}")->assertOk();
        $this->post(Filament::getPanel('app')->getLogoutUrl());

        $fin = app(TenantContext::class)->runAs($magasin, fn () => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::FinDeSession->value)->get());
        $this->assertCount(1, $fin);
        $this->assertSame($collaborateur->id, $fin->sole()->contenuSigne()['operateur']['id']);
    }

    public function test_la_cloche_d_un_collaborateur_ne_recoit_jamais_d_alerte_de_stock_du_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin, [...PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::PRODUIT_CREATE]);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);

        $destinataires = app(DestinatairesDesAlertes::class)->autorisesDans($magasin, PermissionRegistry::PRODUIT_AJUSTER_STOCK)->modelKeys();

        $this->assertNotContains($collaborateur->id, $destinataires);
        $this->assertNotContains($this->proprietaireDuCabinet($cabinet)->id, $destinataires);
    }

    /**
     * Un cabinet vérifié, un client « Commerce ou atelier » lié avec les
     * autorisations par défaut, et un collaborateur à qui il est confié.
     *
     * @return array{0: Societe, 1: Magasin, 2: User}
     */
    private function collaborateurDUnClient(): array
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin);

        return [$cabinet, $magasin, $this->confier($this->collaborateur($cabinet), [$magasin])];
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
