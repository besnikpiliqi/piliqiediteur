<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Actions\ChangerFinExerciceAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\TelechargerLesDocuments;
use App\Filament\Cabinet\Pages\TelechargerLesDocuments as TelechargerLesDocumentsDuCabinet;
use App\Filament\Societe\Pages\TelechargerLesDocuments as TelechargerLesDocumentsDeLaSociete;
use App\Http\Controllers\TelechargerLesDocumentsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les pages « Télécharger les documents » d'un magasin, de l'espace de la
 * société et de l'espace du cabinet (décision de l'éditeur du 03/11/2026,
 * E5) : qui les ouvre, la période et ses raccourcis, « Tout sélectionner »
 * qui prend tous les documents trouvés, au-delà de la page affichée, la
 * demande qui ne retient que ce que la page propose ; puis le lien du
 * fichier préparé, servi à son seul auteur, pendant sept jours, tant qu'il
 * télécharge encore ces documents.
 */
class TelechargerLesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PreparerLeTelechargementDesDocumentsJob::DISQUE);
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']);
    }

    public function test_la_page_du_magasin_s_ouvre_au_proprietaire_et_a_qui_il_coche_l_autorisation(): void
    {
        $responsable = $this->responsable($this->magasin);
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur);
        $administrateur = $this->administrateur($this->magasin);
        $avecLaCase = $this->administrateur($this->magasin, null, [], [...PermissionRegistry::modeleDeLAdministrateur($this->magasin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);

        foreach ([[$this->proprietaireDe($this->magasin), true], [$responsable, false], [$vendeur, false], [$administrateur, false], [$avecLaCase, true]] as [$compte, $ouvre]) {
            $this->dansLeMagasin($this->magasin, $compte);
            $this->assertSame($ouvre, TelechargerLesDocuments::canAccess(), $compte->name);
        }

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($this->magasin), $responsable, RoleMagasin::Responsable, [PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->dansLeMagasin($this->magasin, $responsable->refresh());
        $this->assertTrue(TelechargerLesDocuments::canAccess());
        $this->get(TelechargerLesDocuments::getUrl(panel: 'app', tenant: $this->magasin))->assertOk()->assertSeeText(['Période et documents', 'Tout sélectionner', 'PDF (ZIP)', 'Récapitulatif (CSV)']);
    }

    public function test_les_raccourcis_posent_la_periode_dans_le_calendrier_du_magasin_et_de_l_exercice(): void
    {
        $this->dansLeMagasin($this->magasin, $this->proprietaireDe($this->magasin));
        $page = Livewire::test(TelechargerLesDocuments::class)->assertSet('du', '2026-03-01')->assertSet('au', '2026-03-31');

        foreach ([
            'mois_dernier' => ['2026-02-01', '2026-02-28'],
            'trimestre' => ['2026-01-01', '2026-03-31'],
            'trimestre_dernier' => ['2025-10-01', '2025-12-31'],
            'exercice' => ['2026-01-01', '2026-12-31'],
            'exercice_precedent' => ['2025-01-01', '2025-12-31'],
            'ce_mois' => ['2026-03-01', '2026-03-31'],
            'inconnu' => ['2026-03-01', '2026-03-31'],
        ] as $raccourci => [$du, $au]) {
            $page->call('raccourci', $raccourci)->assertSet('du', $du)->assertSet('au', $au);
        }
    }

    public function test_dans_l_espace_du_cabinet_les_raccourcis_suivent_l_exercice_du_client_et_ses_changements_legaux(): void
    {
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $this->accorderAuCabinet();
        $cabinet = Societe::query()->findOrFail($collaborateur->societe_id);
        $juin = $this->ouvrirUneSociete(['nom' => 'Boutique de Juin']);
        $juin->societe->forceFill(['mois_fin_exercice' => 6])->save();
        $this->lierLeCabinet($cabinet, $juin, [...PermissionRegistry::parDefautDuCabinet($juin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->confier($collaborateur, [$this->magasin, $juin]);
        app(ChangerFinExerciceAction::class)->handle($this->magasin->societe->fresh(), 6, '2026-06-30', '2026-03-05', 'Claire Martin, Cabinet Martin et associés', new InterventionDeLEditeur('Procès-verbal de l\'assemblée générale reçu', 'PV-AGE-2026-03-05', ['alice']));
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur->refresh());

        $page = Livewire::test(TelechargerLesDocumentsDuCabinet::class)->set('client', $juin->societe->uuid);
        $page->call('raccourci', 'exercice')->assertSet('du', '2025-07-01')->assertSet('au', '2026-06-30');
        $page->call('raccourci', 'exercice_precedent')->assertSet('du', '2024-07-01')->assertSet('au', '2025-06-30');

        $page->set('client', $this->magasin->societe->uuid);
        $page->call('raccourci', 'exercice')->assertSet('du', '2026-01-01')->assertSet('au', '2026-06-30');
        $page->call('raccourci', 'exercice_precedent')->assertSet('du', '2025-01-01')->assertSet('au', '2025-12-31');
    }

    public function test_la_seule_marque_piece_manquante_choisit_les_achats_et_passe_a_la_demande(): void
    {
        $fournisseur = app(TenantContext::class)->runAs($this->magasin, fn (): Fournisseur => Fournisseur::factory()->create(['nom' => 'Grossiste du Nord']));
        $sansPiece = $this->acheter($fournisseur, []);
        $avecPiece = $this->acheter($fournisseur, [new PieceDeposee('facture.pdf', FichiersDeTest::pdf())]);
        $this->dansLeMagasin($this->magasin, $this->proprietaireDe($this->magasin));

        $page = Livewire::test(TelechargerLesDocuments::class)
            ->set('types', [TypeDeDocumentTelechargeable::FacturesAchat->value])
            ->assertSeeText(['Seulement ceux dont la pièce du fournisseur manque', $sansPiece->numero, $avecPiece->numero, 'Pièce du fournisseur manquante'])
            ->set('pieceManquanteSeulement', true)
            ->assertSeeText(['Documents trouvés : 1', $sansPiece->numero])
            ->assertDontSeeText($avecPiece->numero)
            ->set('format', FormatDuTelechargement::Csv->value)
            ->call('toutSelectionner')
            ->call('telecharger')
            ->assertNotified('Votre fichier est en préparation.');

        $telechargement = TelechargementDeDocuments::query()->withoutGlobalScopes()->sole();
        $this->assertSame([true, 1, ['achats:'.$this->magasin->id.':'.$sansPiece->id]], [$telechargement->piece_manquante_seulement, $telechargement->nombre_documents, $telechargement->selection]);
        $page->set('pieceManquanteSeulement', false)->assertSeeText('Documents trouvés : 2');
    }

    public function test_un_clic_dans_l_espace_de_la_societe_ne_lit_chaque_magasin_qu_une_fois(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $requetes = [];

        foreach ([2, 3, 4] as $nombre) {
            while ($this->magasin->societe->magasins()->count() < $nombre) {
                $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique '.$nombre, 'code' => 'B'.$nombre]);
            }

            $this->dansLEspaceDeLaSociete($this->magasin->societe, $proprietaire);
            $requetes[$nombre] = $this->requetesDuClic(Livewire::test(TelechargerLesDocumentsDeLaSociete::class));
        }

        $this->assertSame($requetes[3] - $requetes[2], $requetes[4] - $requetes[3]);
        $this->assertLessThanOrEqual(count(TypeDeDocumentTelechargeable::cases()), $requetes[4] - $requetes[3], 'Un magasin de plus : une lecture par type, rien d\'autre.');
        $this->assertLessThanOrEqual(12 + 2 * count(TypeDeDocumentTelechargeable::cases()), $requetes[2]);
    }

    public function test_un_clic_dans_l_espace_du_cabinet_ne_relit_pas_les_autres_clients(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $this->accorderAuCabinet();
        $cabinet = Societe::query()->findOrFail($collaborateur->societe_id);
        $clients = [$this->magasin];
        $requetes = [];

        foreach ([1, 2, 3] as $nombre) {
            while (count($clients) < $nombre) {
                $client = $this->ouvrirUneSociete(['nom' => 'Client '.count($clients)]);
                $this->lierLeCabinet($cabinet, $client, [...PermissionRegistry::parDefautDuCabinet($client->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);
                $clients[] = $client;
            }

            $this->confier($collaborateur, $clients);
            $this->dansLEspaceDuCabinet($cabinet, $collaborateur->refresh());
            $requetes[$nombre] = $this->requetesDuClic(Livewire::test(TelechargerLesDocumentsDuCabinet::class)->set('client', $this->magasin->societe->uuid));
        }

        $this->assertSame($requetes[2] - $requetes[1], $requetes[3] - $requetes[2]);
        $this->assertLessThanOrEqual(6, $requetes[3] - $requetes[2], 'Un client de plus : seulement la question du téléchargement dans son magasin.');
        $this->assertLessThanOrEqual(80, $requetes[1]);
    }

    public function test_tout_selectionner_prend_tous_les_documents_trouves_au_dela_de_la_page_affichee(): void
    {
        $tickets = $this->vendreDesTickets(TelechargerLesDocuments::DOCUMENTS_PAR_PAGE + 2);
        $this->dansLeMagasin($this->magasin, $this->proprietaireDe($this->magasin));

        $page = Livewire::test(TelechargerLesDocuments::class)
            ->set('types', [TypeDeDocumentTelechargeable::Ventes->value])
            ->assertSeeText('Documents trouvés : '.count($tickets))
            ->assertSeeText('Page 1 sur 2')
            ->call('toutSelectionner')
            ->assertSeeText('Sélection : '.count($tickets).' documents');

        $this->assertCount(count($tickets), $page->get('selection'));
        $page->call('pageSuivante')->assertSeeText('Page 2 sur 2')->call('toutDeselectionner')->assertSet('selection', []);
    }

    public function test_telecharger_ne_retient_que_les_documents_et_les_magasins_que_la_page_propose(): void
    {
        $tickets = $this->vendreDesTickets(2);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre société']);
        $proprietaire = $this->proprietaireDe($this->magasin);
        $this->dansLEspaceDeLaSociete($this->magasin->societe, $proprietaire);

        Livewire::test(TelechargerLesDocumentsDeLaSociete::class)
            ->assertSet('magasinsCoches', [$this->magasin->id])
            ->set('magasinsCoches', [$this->magasin->id, $autre->id, [$autre->id]])
            ->set('types', [TypeDeDocumentTelechargeable::Ventes->value])
            ->set('format', FormatDuTelechargement::Csv->value)
            ->set('selection', ['ventes:'.$this->magasin->id.':'.$tickets[0]->id, 'ventes:'.$autre->id.':'.$tickets[1]->id, 'ventes:'.$this->magasin->id.':999999', ['ventes:'.$autre->id.':'.$tickets[1]->id]])
            ->call('telecharger')
            ->assertNotified('Votre fichier est en préparation.');

        $telechargement = TelechargementDeDocuments::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$this->magasin->societe_id, [$this->magasin->id], ['ventes:'.$this->magasin->id.':'.$tickets[0]->id], StatutDuTelechargement::Pret, 1], [
            (int) $telechargement->societe_id, $telechargement->magasins, $telechargement->selection, $telechargement->statut, $telechargement->nombre_documents,
        ]);
    }

    public function test_l_espace_de_la_societe_propose_les_magasins_de_la_portee_ou_la_personne_telecharge(): void
    {
        $gare = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        $administrateur = $this->administrateur($this->magasin, [$this->magasin->id, $gare->id], [], [
            $this->magasin->id => [...PermissionRegistry::modeleDeLAdministrateur($this->magasin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER],
            $gare->id => PermissionRegistry::modeleDeLAdministrateur($this->magasin->societe->type_activite),
        ]);

        $this->dansLEspaceDeLaSociete($this->magasin->societe, $this->proprietaireDe($this->magasin));
        Livewire::test(TelechargerLesDocumentsDeLaSociete::class)->assertSet('magasinsCoches', [$gare->id, $this->magasin->id])->assertSeeText(['Boutique de la Gare (GARE)', 'Boutique du Port (PORT)']);

        $this->dansLEspaceDeLaSociete($this->magasin->societe, $administrateur);
        Livewire::test(TelechargerLesDocumentsDeLaSociete::class)->assertSet('magasinsCoches', [$this->magasin->id])->assertDontSeeText('Boutique de la Gare (GARE)');

        $this->dansLEspaceDeLaSociete($this->magasin->societe, $this->administrateur($this->magasin));
        $this->assertFalse(TelechargerLesDocumentsDeLaSociete::canAccess());
    }

    public function test_l_espace_du_cabinet_propose_les_seuls_clients_qui_lui_accordent_le_telechargement(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $cabinet = Societe::query()->findOrFail($collaborateur->societe_id);
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        $this->assertFalse(TelechargerLesDocumentsDuCabinet::canAccess());

        $this->accorderAuCabinet();
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur->refresh());
        $this->assertTrue(TelechargerLesDocumentsDuCabinet::canAccess());
        Livewire::test(TelechargerLesDocumentsDuCabinet::class)
            ->assertSet('client', $this->magasin->societe->uuid)
            ->assertSet('magasinsCoches', [$this->magasin->id])
            ->set('client', $this->ouvrirUneSociete(['nom' => 'Société non liée'])->societe->uuid)
            ->assertSet('magasinsCoches', [])
            ->assertSeeText('Documents trouvés : 0');
    }

    public function test_le_lien_sert_le_fichier_a_son_auteur_et_le_refuse_a_tout_autre_compte(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $telechargement = $this->telechargementPret($proprietaire);

        $reponse = $this->telechargerEnTantQue($proprietaire, $telechargement->lien());
        $reponse->assertOk()->assertDownload('documents-2026-03-01-au-2026-03-31.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $reponse->streamedContent());
        $this->assertSame([
            'text/csv; charset=UTF-8',
            'attachment; filename=documents-2026-03-01-au-2026-03-31.csv',
            'nosniff',
            "sandbox; default-src 'none'",
            'no-store, private',
            'same-origin',
        ], array_map(fn (string $entete): ?string => $reponse->headers->get($entete), ['Content-Type', 'Content-Disposition', 'X-Content-Type-Options', 'Content-Security-Policy', 'Cache-Control', 'Cross-Origin-Resource-Policy']), 'Le fichier, qui peut contenir des pièces reçues, part comme elles : en pièce jointe, dans un bac à sable, jamais gardé en cache.');
        $this->assertStringContainsString('/societe/telechargements/', $telechargement->lien());

        $this->telechargerEnTantQue($this->responsable($this->magasin), route('filament.app.telechargements-de-documents.telecharger', ['telechargement' => $telechargement->uuid]))->assertNotFound()->assertSeeText(TelechargerLesDocumentsController::INTROUVABLE);
        $this->telechargerEnTantQue($this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre société'])), $telechargement->lien())->assertNotFound();
        $this->telechargerEnTantQue($proprietaire, route('filament.societe.telechargements-de-documents.telecharger', ['telechargement' => 'pas-un-uuid']))->assertNotFound();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get($telechargement->lien())->assertRedirect();
    }

    public function test_le_lien_est_refuse_expire_en_preparation_ou_quand_les_droits_sont_retires(): void
    {
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $telechargement = $this->telechargementPret($vendeur);
        $this->assertStringContainsString('/app/telechargements/', $telechargement->lien());
        $this->telechargerEnTantQue($vendeur, $telechargement->lien())->assertOk();

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($this->magasin), $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $this->telechargerEnTantQue($vendeur->refresh(), $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);

        $proprietaire = $this->proprietaireDe($this->magasin);
        $enPreparation = $this->telechargementPret($proprietaire);
        $enPreparation->forceFill(['statut' => StatutDuTelechargement::EnPreparation])->save();
        $this->telechargerEnTantQue($proprietaire, $enPreparation->lien())->assertNotFound()->assertSeeText(TelechargerLesDocumentsController::EN_PREPARATION);

        $this->travelTo(self::aParis('2026-03-17 10:00:00'));
        $this->telechargerEnTantQue($proprietaire, $this->telechargementDe($proprietaire)->lien())->assertStatus(410)->assertSeeText(TelechargerLesDocumentsController::EXPIRE);
    }

    public function test_le_lien_est_refuse_a_qui_ne_voit_plus_les_documents_du_fichier(): void
    {
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $telechargement = $this->telechargementPret($vendeur);
        $proprietaire = $this->proprietaireDe($this->magasin);
        $this->telechargerEnTantQue($vendeur, $telechargement->lien())->assertOk();

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->telechargerEnTantQue($vendeur->refresh(), $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->telechargerEnTantQue($vendeur->refresh(), $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->telechargerEnTantQue($vendeur->refresh(), $telechargement->lien())->assertOk();
    }

    public function test_le_lien_d_un_compte_du_cabinet_se_ferme_quand_le_client_lui_retire_une_consultation(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $lien = $this->accorderAuCabinet();
        $telechargement = $this->telechargementPret($collaborateur->refresh());
        $this->telechargerEnTantQue($collaborateur, $telechargement->lien())->assertOk();

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($this->magasin), $lien, [
            ...array_values(array_diff(PermissionRegistry::parDefautDuCabinet($this->magasin->societe->type_activite), [PermissionRegistry::FACTURE_VIEW])),
            PermissionRegistry::DOCUMENTS_TELECHARGER,
        ]);
        $this->telechargerEnTantQue($collaborateur->refresh(), $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);
    }

    public function test_le_lien_d_un_compte_du_cabinet_se_ferme_avec_le_lien_du_client(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $lien = $this->accorderAuCabinet();
        $telechargement = $this->telechargementPret($collaborateur->refresh());

        $this->assertStringContainsString('/cabinet/telechargements/', $telechargement->lien());
        $this->telechargerEnTantQue($collaborateur, $telechargement->lien())->assertOk();

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($this->magasin), $lien);
        $this->telechargerEnTantQue($collaborateur->refresh(), $telechargement->lien())->assertForbidden();
    }

    /**
     * Le nombre de requêtes d'un clic sur un raccourci de la page, sa
     * réponse comprise.
     */
    private function requetesDuClic(Testable $page): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('raccourci', 'mois_dernier');
        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }

    /**
     * Une facture d'achat du magasin saisie à la main le 2 mars, avec ces
     * pièces du fournisseur, validée.
     *
     * @param  list<PieceDeposee>  $pieces
     */
    private function acheter(Fournisseur $fournisseur, array $pieces): FactureAchat
    {
        $responsable = $this->responsable($this->magasin);

        return app(TenantContext::class)->runAs($this->magasin, function () use ($fournisseur, $pieces, $responsable): FactureAchat {
            $carnet = Produit::query()->where('reference', 'CARNET')->first() ?? Produit::factory()->create(['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => 3, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, $fournisseur, [['produit_id' => $carnet->id, 'quantite' => 2, 'prix_unitaire_ht' => 1]], ['date_facture' => '2026-03-02'], null, $pieces));
        });
    }

    /**
     * Ce nombre de tickets du magasin, vendus en espèces par un nouveau
     * vendeur, à l'instant du test.
     *
     * @return list<Facture>
     */
    private function vendreDesTickets(int $nombre): array
    {
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur);

        return app(TenantContext::class)->runAs($this->magasin, function () use ($vendeur, $nombre): array {
            $carnet = Produit::query()->where('reference', 'CARNET')->first() ?? Produit::factory()->create(['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => 3, 'taux_tva' => 20, 'suivi_stock' => false]);
            $comptoir = Client::query()->where('est_comptoir', true)->sole();

            return array_map(fn (): Facture => self::vendreAuComptant($vendeur, $comptoir, [['produit_id' => $carnet->id, 'quantite' => 1]]), range(1, $nombre));
        });
    }

    /**
     * Les autorisations par défaut du cabinet lié, et le téléchargement par
     * période, que le propriétaire du client lui coche.
     */
    private function accorderAuCabinet(): LienCabinet
    {
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->magasin->societe_id)->where('etat', EtatDuLien::Actif->value)->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($this->magasin), $lien, [...PermissionRegistry::parDefautDuCabinet($this->magasin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);

        return $lien;
    }

    /**
     * Un téléchargement des ventes de mars du magasin demandé par ce compte
     * depuis la page du magasin, préparé aussitôt (file d'attente
     * synchrone des tests).
     */
    private function telechargementPret(User $compte): TelechargementDeDocuments
    {
        $this->vendreDesTickets(1);
        $this->dansLeMagasin($this->magasin, $compte);
        Livewire::test(TelechargerLesDocuments::class)
            ->set('types', [TypeDeDocumentTelechargeable::Ventes->value])
            ->set('format', FormatDuTelechargement::Csv->value)
            ->call('toutSelectionner')
            ->call('telecharger');

        return $this->telechargementDe($compte);
    }

    private function telechargementDe(User $compte): TelechargementDeDocuments
    {
        return TelechargementDeDocuments::query()->withoutGlobalScopes()->where('demande_par', $compte->id)->latest('id')->firstOrFail();
    }

    /**
     * Une requête de ce compte, dans une session neuve, comme depuis son
     * propre navigateur.
     */
    private function telechargerEnTantQue(User $compte, string $adresse): TestResponse
    {
        $this->flushSession();
        app(TenantContext::class)->clear();

        return $this->actingAs($compte)->get($adresse);
    }
}
