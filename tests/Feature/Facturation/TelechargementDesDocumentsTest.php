<?php

namespace Tests\Feature\Facturation;

use App\Console\Commands\EffacerTelechargementsDeDocuments;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\FicheDeSaisiePdfGenerator;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ArchiveDesDocuments;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Facturation\Services\RecapitulatifDesDocuments;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Notifications\AvisDuTelechargementDesDocuments;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;
use ZipArchive;

/**
 * Le téléchargement des documents par période (décision de l'éditeur du
 * 03/11/2026, lot EX, E1 à E6, F2 = oui) : les documents d'une période,
 * type par type et magasin par magasin, ceux que la personne peut voir,
 * dans le calendrier de leur magasin ; l'autorisation « télécharger les
 * documents par période », que le propriétaire a toujours et coche compte
 * par compte ; le fichier ZIP de leurs PDF, rangé par type puis par mois,
 * ou leur récapitulatif CSV, lu tel qu'enregistré ; la préparation en file
 * d'attente, son lien de sept jours réservé à son auteur, son effacement,
 * la limite de dix demandes par heure et l'entrée au journal d'audit.
 */
class TelechargementDesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";

    private Magasin $magasin;

    private User $responsable;

    private User $vendeur;

    /**
     * Les documents du magasin, par nom : les factures d'achat (avec pièce,
     * sans pièce, reçue par l'API, de février, en brouillon), l'avoir
     * fournisseur, le ticket du vendeur, la facture de l'hôtel, l'avoir du
     * ticket, la clôture.
     *
     * @var array<string, mixed>
     */
    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PiecesDuFournisseur::DISQUE);
        Storage::fake(PreparerLeTelechargementDesDocumentsJob::DISQUE);
        $this->travelTo(self::aParis('2026-03-09 09:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']));
        $this->responsable = $this->membre($this->magasin, RoleMagasin::Responsable, identite: ['name' => 'Rémi Responsable']);
        $this->vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, identite: ['name' => 'Véra Vendeuse']);
        $this->remplir();
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
    }

    public function test_le_proprietaire_trouve_chaque_type_de_la_periode_dans_le_calendrier_du_magasin(): void
    {
        $trouves = $this->trouver($this->proprietaireDe($this->magasin));

        $this->assertSame([
            ['ventes', $this->documents['ticket']->numero, '2026-03-09', 'Client comptoir', 1800],
            ['ventes', $this->documents['facture']->numero, '2026-03-09', 'Hôtel des Pins', 4233],
            ['avoirs', $this->documents['avoir']->numero, '2026-03-09', 'Client comptoir', 1800],
            ['achats', $this->documents['achatAvecPiece']->numero, '2026-03-02', 'Grossiste du Nord', 15165],
            ['achats', $this->documents['achatSansPiece']->numero, '2026-03-05', 'Grossiste du Nord', 3000],
            ['achats', $this->documents['achatApi']->numero, '2026-03-09', 'Grossiste du Nord', 1200],
            ['avoirs-fournisseur', $this->documents['avoirFournisseur']->numero, '2026-03-09', 'Grossiste du Nord', 600],
            ['clotures', $this->documents['cloture']->numero, '2026-03-09', null, 0],
            ['encaissements', 'Encaissements 2026-03', '2026-03-31', null, 3800],
        ], array_map(fn (DocumentTrouve $document): array => [$document->type->value, $document->numero, $document->jour, $document->tiers, $document->ttc], $trouves));
        $this->assertSame([SourceFactureAchat::Manuelle->getLabel(), SourceFactureAchat::Manuelle->getLabel(), SourceFactureAchat::Api->getLabel()], array_values(array_map(fn (DocumentTrouve $document): ?string => $document->origine, array_filter($trouves, fn (DocumentTrouve $document): bool => $document->type === TypeDeDocumentTelechargeable::FacturesAchat))));
    }

    public function test_l_origine_des_achats_et_la_periode_choisissent_les_documents(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $achats = [TypeDeDocumentTelechargeable::FacturesAchat, TypeDeDocumentTelechargeable::AvoirsFournisseur];
        $numeros = fn (array $trouves): array => array_map(fn (DocumentTrouve $document): string => $document->numero, $trouves);

        $this->assertSame(
            [$this->documents['achatAvecPiece']->numero, $this->documents['achatSansPiece']->numero, $this->documents['avoirFournisseur']->numero],
            $numeros($this->trouver($proprietaire, $achats, OrigineDesAchats::SaisieManuelle)),
        );
        $this->assertSame([$this->documents['achatApi']->numero], $numeros($this->trouver($proprietaire, $achats, OrigineDesAchats::ReceptionAutomatique)));
        $this->assertSame(
            [$this->documents['achatFevrier']->numero],
            $numeros(app(DocumentsDeLaPeriode::class)->trouver($proprietaire, [$this->magasin], '2026-02-01', '2026-02-28', $achats, OrigineDesAchats::Toutes)),
        );
        $this->assertSame(
            [$this->documents['achatSansPiece']->numero],
            $numeros(app(DocumentsDeLaPeriode::class)->trouver($proprietaire, [$this->magasin], '2026-03-03', '2026-03-05', $achats, OrigineDesAchats::Toutes)),
        );
    }

    public function test_un_vendeur_autorise_ne_recoit_que_les_types_qu_il_voit_et_ses_propres_documents(): void
    {
        $vendeur = $this->autoriser($this->vendeur);
        $documents = app(DocumentsDeLaPeriode::class);

        $this->assertSame([TypeDeDocumentTelechargeable::Ventes, TypeDeDocumentTelechargeable::Encaissements], $documents->typesVisibles($vendeur, $this->magasin));
        $this->assertSame(
            [['ventes', $this->documents['ticket']->numero, 1800], ['encaissements', 'Encaissements 2026-03', 1800]],
            array_map(fn (DocumentTrouve $document): array => [$document->type->value, $document->numero, $document->ttc], $this->trouver($vendeur)),
        );
    }

    public function test_seul_le_proprietaire_a_l_autorisation_d_office_et_la_coche_compte_par_compte(): void
    {
        $documents = app(DocumentsDeLaPeriode::class);
        $proprietaire = $this->proprietaireDe($this->magasin);
        $administrateur = $this->administrateur($this->magasin);

        $this->assertSame([true, false, false, false], [
            $documents->peutTelechargerDans($proprietaire, $this->magasin),
            $documents->peutTelechargerDans($this->responsable, $this->magasin),
            $documents->peutTelechargerDans($this->vendeur, $this->magasin),
            $documents->peutTelechargerDans($administrateur, $this->magasin),
        ]);
        $this->assertNotContains(PermissionRegistry::DOCUMENTS_TELECHARGER, RoleMagasin::Vendeur->permissionsParDefaut($this->magasin->societe->type_activite));
        $this->assertNotContains(PermissionRegistry::DOCUMENTS_TELECHARGER, PermissionRegistry::modeleDeLAdministrateur($this->magasin->societe->type_activite));

        $this->assertThrows(
            fn () => app(ModifierMembreAction::class)->handle($this->responsable, $this->vendeur, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut($this->magasin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]),
            LogicException::class,
            PorteeDesDroits::REFUS_AU_DELA,
        );

        app(ModifierMembreAction::class)->handle($proprietaire, $this->responsable, RoleMagasin::Responsable, [PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $vendeur = $this->autoriser($this->vendeur);
        $documents = app(DocumentsDeLaPeriode::class);
        $this->assertTrue($documents->peutTelechargerDans($this->responsable->refresh(), $this->magasin));
        $this->assertTrue($documents->peutTelechargerDans($vendeur, $this->magasin));

        app(ModifierMembreAction::class)->handle($this->responsable, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $this->assertEqualsCanonicalizing([PermissionRegistry::DOCUMENTS_TELECHARGER, PermissionRegistry::FACTURE_VIEW], $vendeur->refresh()->autorisationsDans($this->magasin));

        $this->assertThrows(
            fn () => app(ModifierMembreAction::class)->handle($proprietaire, $this->responsable, RoleMagasin::Responsable, [PermissionRegistry::FACTURE_VIEW]),
            LogicException::class,
            AffectationsDesMembres::MESSAGE_RESPONSABLE_SANS_CASE,
        );
        app(ModifierMembreAction::class)->handle($proprietaire, $this->responsable, RoleMagasin::Responsable, []);
        $this->assertFalse(app(DocumentsDeLaPeriode::class)->peutTelechargerDans($this->responsable->refresh(), $this->magasin));
    }

    public function test_un_magasin_en_lecture_seule_se_telecharge_et_une_societe_non_validee_n_a_aucun_document_reel(): void
    {
        $documents = app(DocumentsDeLaPeriode::class);
        $proprietaire = $this->proprietaireDe($this->magasin);
        $avant = array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->trouver($proprietaire));
        app(FermerMagasinAction::class)->handle($proprietaire, $this->magasin, MotifFermeture::Temporaire, 'password');

        $this->assertTrue($documents->peutTelechargerDans($proprietaire, $this->magasin->refresh()));
        $apres = $this->trouver($proprietaire);
        $this->assertSame($avant, array_values(array_intersect(array_map(fn (DocumentTrouve $document): string => $document->cle(), $apres), $avant)));
        $this->assertSame([['clotures', '2026-03-10']], array_values(array_map(fn (DocumentTrouve $document): array => [$document->type->value, $document->jour], array_filter($apres, fn (DocumentTrouve $document): bool => ! in_array($document->cle(), $avant, true)))));

        $inscrite = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($inscrite);
        $this->assertFalse($documents->peutTelechargerDans($inscrite->proprietaire, $reel));
        $this->assertSame([], $documents->magasinsDeLaSociete($inscrite->proprietaire, $inscrite)->all());
    }

    public function test_le_recapitulatif_csv_reprend_chaque_taux_tel_qu_enregistre_et_ce_qui_reste_du_a_la_fin_de_la_periode(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $csv = app(RecapitulatifDesDocuments::class)->ecrire($proprietaire, $this->trouver($proprietaire), '2026-03-01', '2026-03-31');
        $lignes = array_map(fn (string $ligne): string => implode(';', str_getcsv($ligne, ';', '"', '')), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(implode(';', RecapitulatifDesDocuments::COLONNES), $lignes[0]);
        $facture = $this->documents['facture'];
        $this->assertContains("Vente;Boutique du Port (PORT);{$facture->numero};09/03/2026;Hôtel des Pins;{$facture->client_siret};;;;20;30,00;6,00;36,00;20,00;22,33", $lignes);
        $this->assertContains("Vente;Boutique du Port (PORT);{$facture->numero};09/03/2026;Hôtel des Pins;{$facture->client_siret};;;;5,5;6,00;0,33;6,33;;", $lignes);
        $achat = $this->documents['achatAvecPiece'];
        $this->assertContains("Facture d'achat;Boutique du Port (PORT);{$achat->numero};02/03/2026;Grossiste du Nord;;Saisie manuelle;;;20;100,00;20,00;120,00;;", $lignes);
        $this->assertContains("Facture d'achat;Boutique du Port (PORT);{$achat->numero};02/03/2026;Grossiste du Nord;;Saisie manuelle;;;5,5;30,00;1,65;31,65;;", $lignes);
        $sansPiece = $this->documents['achatSansPiece'];
        $this->assertContains("Facture d'achat;Boutique du Port (PORT);{$sansPiece->numero};05/03/2026;Grossiste du Nord;;Saisie manuelle;Manquante;;20;25,00;5,00;30,00;;", $lignes);
        $cloture = $this->documents['cloture']->numero;
        $this->assertContains("Clôture du jour;Boutique du Port (PORT);{$cloture};09/03/2026;;;;;;20;0,00;0,00;0,00;;", $lignes);
        $this->assertContains('Relevé des encaissements;Boutique du Port (PORT);Encaissements 2026-03;31/03/2026;;;;;Espèces;;;;18,00;;', $lignes);
        $this->assertContains('Relevé des encaissements;Boutique du Port (PORT);Encaissements 2026-03;31/03/2026;;;;;Virement;;;;20,00;;', $lignes);
    }

    public function test_un_nom_qui_commence_comme_une_formule_s_ecrit_desamorce_dans_le_csv(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $facture = app(TenantContext::class)->runAs($this->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle(
            $this->responsable,
            app(CreerFactureAction::class)->handle($this->responsable, Client::factory()->professionnel()->create(['nom' => '=HYPERLIEN("x")']), [['produit_id' => Produit::query()->where('reference', 'THE')->value('id'), 'quantite' => 1]]),
        ));

        $csv = app(RecapitulatifDesDocuments::class)->ecrire($proprietaire, $this->trouver($proprietaire, [TypeDeDocumentTelechargeable::Ventes]), '2026-03-01', '2026-03-31');
        $lignes = array_values(array_filter(array_map(fn (string $ligne): array => str_getcsv($ligne, ';', '"', ''), explode("\r\n", rtrim(substr($csv, 3), "\r\n"))), fn (array $ligne): bool => $ligne[2] === $facture->numero));

        $this->assertSame(["'=HYPERLIEN(\"x\")"], array_unique(array_column($lignes, 4)));
    }

    public function test_le_zip_range_chaque_document_par_type_et_par_mois_avec_les_pieces_telles_que_deposees(): void
    {
        $telechargement = $this->demander($this->proprietaireDe($this->magasin), FormatDuTelechargement::Zip);

        $this->assertSame(StatutDuTelechargement::Pret, $telechargement->statut);
        $fichiers = $this->lireLeZip($telechargement);
        $achat = $this->documents['achatAvecPiece']->numero;
        $sansPiece = $this->documents['achatSansPiece']->numero;
        $this->assertEqualsCanonicalizing([
            "ventes/2026-03/{$this->documents['ticket']->numero}.pdf",
            "ventes/2026-03/{$this->documents['facture']->numero}.pdf",
            "avoirs/2026-03/{$this->documents['avoir']->numero}.pdf",
            "achats/2026-03/{$achat} - Facture F-812.pdf",
            "achats/2026-03/{$sansPiece} - saisie PILIQI.pdf",
            "achats/2026-03/{$this->documents['achatApi']->numero} - saisie PILIQI.pdf",
            "avoirs-fournisseur/2026-03/{$this->documents['avoirFournisseur']->numero} - saisie PILIQI.pdf",
            "clotures/2026-03/{$this->documents['cloture']->numero}.pdf",
            'encaissements/2026-03/encaissements-PORT-2026-03.pdf',
        ], array_keys($fichiers));
        $this->assertSame(self::PDF, $fichiers["achats/2026-03/{$achat} - Facture F-812.pdf"]);
        foreach ($fichiers as $nom => $contenu) {
            $this->assertStringStartsWith('%PDF-', $contenu, $nom);
        }
        $textes = fn (string $pdf): string => implode(' | ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));
        $cloture = $textes($fichiers["clotures/2026-03/{$this->documents['cloture']->numero}.pdf"]);
        $releve = $textes($fichiers['encaissements/2026-03/encaissements-PORT-2026-03.pdf']);
        $fiche = $textes($fichiers["achats/2026-03/{$sansPiece} - saisie PILIQI.pdf"]);
        $this->assertStringContainsString('Clôture du jour (ticket Z) | '.$this->documents['cloture']->numero.' | Journée du 09/03/2026', $cloture);
        $this->assertStringContainsString('Net des ventes aux particuliers (base de l\'e-reporting) | 0,00 € | 0,00 € | 0,00 €', $cloture);
        $this->assertStringContainsString('dont TVA à 5,5 % | 6,00 € | 0,33 € | 6,33 €', $cloture);
        $this->assertStringContainsString('Relevé des encaissements | Mars 2026 | Du 01/03/2026 au 31/03/2026', $releve);
        $this->assertStringContainsString('Espèces | 1 | 0 | 18,00 € | Virement | 1 | 0 | 20,00 € | Total encaissé | 38,00 €', $releve);
        $this->assertStringStartsWith(FicheDeSaisiePdfGenerator::MARQUE_FACTURE.' | Boutique du Port', $fiche);
        $this->assertStringContainsString('Facture du 05/03/2026 | Saisie manuelle | FOURNISSEUR | Grossiste du Nord', $fiche);
        $this->assertStringContainsString('Total TTC | 30,00 €', $fiche);
    }

    public function test_la_preparation_previent_la_personne_et_s_inscrit_au_journal_d_audit_de_la_societe(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaireDe($this->magasin);

        $telechargement = $this->demander($proprietaire, FormatDuTelechargement::Csv);

        $this->assertSame([StatutDuTelechargement::Pret, 9], [$telechargement->statut, $telechargement->nombre_documents]);
        Notification::assertSentTo($proprietaire, AvisDuTelechargementDesDocuments::class, fn (AvisDuTelechargementDesDocuments $avis): bool => $avis->telechargement->is($telechargement));
        $this->assertSame([[
            'au' => '2026-03-31',
            'du' => '2026-03-01',
            'format' => 'csv',
            'magasins' => ['PORT'],
            'nombre_documents' => 9,
            'origine' => 'toutes',
            'piece_manquante_seulement' => false,
            'types' => ['ventes', 'avoirs', 'achats', 'avoirs-fournisseur', 'clotures', 'encaissements'],
        ]], array_map(fn ($entree): array => $entree->nouvelles_valeurs, $this->entreesDAudit($this->magasin->societe, ActionAuditee::DocumentsTelecharges)));
    }

    public function test_le_lien_ne_sert_que_son_auteur_pendant_sept_jours_puis_s_efface_avec_son_fichier(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $telechargement = $this->demander($proprietaire, FormatDuTelechargement::Csv);
        $chemin = $telechargement->chemin;

        $this->assertTrue(TelechargementDeDocuments::duLienPour($proprietaire, $telechargement->uuid)->exists());
        $this->assertFalse(TelechargementDeDocuments::duLienPour($this->responsable, $telechargement->uuid)->exists());
        $this->assertFalse(TelechargementDeDocuments::duLienPour($proprietaire, 'pas-un-uuid')->exists());
        $this->assertTrue(Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->exists($chemin));

        $this->travelTo(self::aParis('2026-03-17 09:59:00'));
        $this->artisan(EffacerTelechargementsDeDocuments::class)->assertSuccessful();
        $this->assertFalse($telechargement->refresh()->estExpire());

        $this->travelTo(self::aParis('2026-03-17 10:00:00'));
        $this->assertTrue($telechargement->estExpire());
        $this->artisan(EffacerTelechargementsDeDocuments::class)->assertSuccessful();
        $this->assertFalse(TelechargementDeDocuments::query()->withoutGlobalScopes()->whereKey($telechargement->id)->exists());
        $this->assertFalse(Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->exists($chemin));
    }

    public function test_un_compte_demande_au_plus_dix_telechargements_par_heure(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);

        for ($demande = 1; $demande <= TelechargementDeDocuments::PAR_HEURE_AU_PLUS; $demande++) {
            $this->demander($proprietaire, FormatDuTelechargement::Csv);
        }

        $this->assertThrows(fn () => $this->demander($proprietaire, FormatDuTelechargement::Csv), LogicException::class, DemanderLeTelechargementDesDocumentsAction::REFUS_PAR_HEURE);
        $this->travelTo(self::aParis('2026-03-10 11:00:01'));
        $this->assertSame(StatutDuTelechargement::Pret, $this->demander($proprietaire, FormatDuTelechargement::Csv)->statut);
    }

    public function test_une_demande_hors_des_droits_est_refusee_et_une_cle_inconnue_n_apporte_rien(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre société']);

        $this->assertThrows(
            fn () => app(DemanderLeTelechargementDesDocumentsAction::class)->handle($this->responsable, $this->magasin->societe, [$this->magasin], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes], OrigineDesAchats::Toutes, FormatDuTelechargement::Csv, ['ventes:'.$this->magasin->id.':'.$this->documents['ticket']->id]),
            AuthorizationException::class,
            DemanderLeTelechargementDesDocumentsAction::REFUS_MAGASINS,
        );
        $this->assertThrows(
            fn () => app(DemanderLeTelechargementDesDocumentsAction::class)->handle($proprietaire, $this->magasin->societe, [$autre], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes], OrigineDesAchats::Toutes, FormatDuTelechargement::Csv, ['ventes:'.$autre->id.':1']),
            AuthorizationException::class,
            DemanderLeTelechargementDesDocumentsAction::REFUS_MAGASINS,
        );

        $telechargement = app(DemanderLeTelechargementDesDocumentsAction::class)->handle($proprietaire, $this->magasin->societe, [$this->magasin], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes], OrigineDesAchats::Toutes, FormatDuTelechargement::Csv, ['ventes:'.$this->magasin->id.':999999']);

        $this->assertSame([StatutDuTelechargement::Echec, ArchiveDesDocuments::AUCUN_DOCUMENT], [$telechargement->refresh()->statut, $telechargement->motif_echec]);
        $this->assertSame([], $this->entreesDAudit($this->magasin->societe, ActionAuditee::DocumentsTelecharges));
    }

    public function test_une_autre_societe_ne_trouve_ni_ne_lit_rien_de_ce_magasin(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $telechargement = $this->demander($proprietaire, FormatDuTelechargement::Csv);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre société']);
        $etranger = $this->proprietaireDe($autre);
        $documents = app(DocumentsDeLaPeriode::class);

        $this->assertFalse($documents->peutTelechargerDans($etranger, $this->magasin));
        $this->assertSame([], $documents->trouver($etranger, [$this->magasin], '2026-03-01', '2026-03-31', TypeDeDocumentTelechargeable::cases(), OrigineDesAchats::Toutes));
        $this->assertSame([], $documents->magasinsDeLaSociete($etranger, $this->magasin->societe)->all());
        $this->assertSame([], $documents->magasinsDeLaSociete($proprietaire, $autre->societe)->all());
        $this->assertSame(0, app(TenantContext::class)->runAsSociete($autre->societe, fn (): int => TelechargementDeDocuments::query()->count()));
        $this->assertSame([], TelechargementDeDocuments::recentsDe($etranger, $this->magasin->societe)->get()->all());
        $this->assertFalse(TelechargementDeDocuments::duLienPour($etranger, $telechargement->uuid)->exists());
        $this->assertSame([$telechargement->id], TelechargementDeDocuments::recentsDe($proprietaire, $this->magasin->societe)->pluck('id')->all());
    }

    public function test_la_seule_marque_piece_manquante_ne_garde_que_les_achats_saisis_sans_leur_piece(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $trouves = app(DocumentsDeLaPeriode::class)->trouver($proprietaire, [$this->magasin], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes, TypeDeDocumentTelechargeable::FacturesAchat, TypeDeDocumentTelechargeable::AvoirsFournisseur], OrigineDesAchats::Toutes, true);

        $this->assertSame([
            ['ventes', $this->documents['ticket']->numero, false],
            ['ventes', $this->documents['facture']->numero, false],
            ['achats', $this->documents['achatSansPiece']->numero, true],
            ['avoirs-fournisseur', $this->documents['avoirFournisseur']->numero, true],
        ], array_map(fn (DocumentTrouve $document): array => [$document->type->value, $document->numero, $document->pieceManquante], $trouves));
        $this->assertSame(
            [false, true, false],
            array_values(array_map(fn (DocumentTrouve $document): bool => $document->pieceManquante, array_filter($this->trouver($proprietaire), fn (DocumentTrouve $document): bool => $document->type === TypeDeDocumentTelechargeable::FacturesAchat))),
        );
    }

    public function test_la_demande_retient_la_seule_marque_piece_manquante_et_l_inscrit_au_journal_d_audit(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);

        $telechargement = app(DemanderLeTelechargementDesDocumentsAction::class)->handle(
            $proprietaire,
            $this->magasin->societe,
            [$this->magasin],
            '2026-03-01',
            '2026-03-31',
            [TypeDeDocumentTelechargeable::FacturesAchat, TypeDeDocumentTelechargeable::AvoirsFournisseur],
            OrigineDesAchats::Toutes,
            FormatDuTelechargement::Csv,
            array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->trouver($proprietaire)),
            true,
        )->refresh();

        $this->assertSame([true, StatutDuTelechargement::Pret, 2], [$telechargement->piece_manquante_seulement, $telechargement->statut, $telechargement->nombre_documents]);
        $this->assertSame(['achats:'.$this->magasin->id.':'.$this->documents['achatSansPiece']->id, 'avoirs-fournisseur:'.$this->magasin->id.':'.$this->documents['avoirFournisseur']->id], $telechargement->selection);
        $this->assertSame([true], array_map(fn ($entree): bool => $entree->nouvelles_valeurs['piece_manquante_seulement'], $this->entreesDAudit($this->magasin->societe, ActionAuditee::DocumentsTelecharges)));
        $lignes = explode("\r\n", rtrim(substr((string) Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->get($telechargement->chemin), 3), "\r\n"));
        $this->assertSame(['Manquante'], array_values(array_unique(array_map(fn (string $ligne): string => str_getcsv($ligne, ';', '"', '')[7], array_slice($lignes, 1)))));
    }

    public function test_le_fichier_ne_compte_que_les_documents_que_le_compte_voit_encore(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        app(ModifierMembreAction::class)->handle($proprietaire, $this->vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::PAIEMENT_VIEW, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $vendeur = $this->vendeur->refresh();
        $cles = array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->trouver($vendeur));

        $this->assertSame([
            'ventes:'.$this->magasin->id.':'.$this->documents['ticket']->id,
            'ventes:'.$this->magasin->id.':'.$this->documents['facture']->id,
            'encaissements:'.$this->magasin->id.':2026-03:'.DocumentTrouve::RELEVE_DE_L_EQUIPE,
        ], $cles);
        $this->assertSame(3, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($vendeur, [$this->magasin], [...$cles, 'ventes:'.$this->magasin->id.':pas-un-nombre', 'inconnu:1:1']));

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PAIEMENT_VIEW, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->assertSame(1, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($vendeur->refresh(), [$this->magasin], $cles));
        $this->assertSame(
            ['ventes:'.$this->magasin->id.':'.$this->documents['ticket']->id, 'encaissements:'.$this->magasin->id.':2026-03:'.DocumentTrouve::RELEVE_DE_LA_PERSONNE],
            array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->trouver($vendeur)),
        );

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->assertSame(0, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($vendeur->refresh(), [$this->magasin], ['encaissements:'.$this->magasin->id.':2026-03:'.DocumentTrouve::RELEVE_DE_LA_PERSONNE]));
        $this->assertSame(1, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($proprietaire, [$this->magasin], ['encaissements:'.$this->magasin->id.':2026-03:'.DocumentTrouve::RELEVE_DE_L_EQUIPE]));
    }

    /**
     * Un avoir client désigné par le fichier ne compte, au moment de le
     * servir, que pour qui le voit encore : son auteur, ou qui voit ceux de
     * toute l'équipe.
     */
    public function test_un_avoir_client_ne_compte_que_pour_qui_le_voit_encore(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $cle = 'avoirs:'.$this->magasin->id.':'.$this->documents['avoir']->id;
        app(ModifierMembreAction::class)->handle($proprietaire, $this->vendeur, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::DOCUMENTS_TELECHARGER]);

        $this->assertSame(1, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($proprietaire, [$this->magasin], [$cle]));
        $this->assertSame(1, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($this->vendeur->refresh(), [$this->magasin], [$cle]));

        app(ModifierMembreAction::class)->handle($proprietaire, $this->vendeur, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_VIEW, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->assertSame(0, app(DocumentsDeLaPeriode::class)->nombreEncoreVisibles($this->vendeur->refresh(), [$this->magasin], [$cle]));
    }

    /**
     * Le relevé des encaissements du mois ajoute, après les modes, le
     * crédit d'avoir imputé dans le mois sur les factures que la personne
     * voit : il ne fait entrer aucun argent et ne se mêle à aucun mode.
     */
    public function test_le_releve_des_encaissements_ajoute_le_credit_d_avoir_utilise_dans_le_mois(): void
    {
        $facture = $this->documents['facture'];
        app(TenantContext::class)->runAs($this->magasin, fn (): Avoir => app(EnregistrerAvoirAction::class)->handle(
            $this->responsable,
            Client::query()->whereKey($facture->client_id)->sole(),
            [['ligne_facture_id' => $facture->lignes()->where('quantite', 1)->sole()->id, 'quantite' => 1]],
            'Thé abîmé',
        ));
        $proprietaire = $this->proprietaireDe($this->magasin);

        $csv = app(RecapitulatifDesDocuments::class)->ecrire($proprietaire, $this->trouver($proprietaire, [TypeDeDocumentTelechargeable::Encaissements]), '2026-03-01', '2026-03-31');
        $lignes = array_map(fn (string $ligne): string => implode(';', str_getcsv($ligne, ';', '"', '')), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));

        $this->assertSame([
            implode(';', RecapitulatifDesDocuments::COLONNES),
            'Relevé des encaissements;Boutique du Port (PORT);Encaissements 2026-03;31/03/2026;;;;;Espèces;;;;18,00;;',
            'Relevé des encaissements;Boutique du Port (PORT);Encaissements 2026-03;31/03/2026;;;;;Virement;;;;20,00;;',
            'Relevé des encaissements;Boutique du Port (PORT);Encaissements 2026-03;31/03/2026;;;;;Avoir (crédit utilisé);;;;6,33;;',
        ], $lignes);
    }

    public function test_le_recapitulatif_lit_les_documents_d_un_magasin_ensemble_quel_que_soit_leur_nombre(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $requetes = function () use ($proprietaire): int {
            $documents = $this->trouver($proprietaire);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(RecapitulatifDesDocuments::class)->ecrire($proprietaire, $documents, '2026-03-01', '2026-03-31');
            $nombre = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $nombre;
        };
        $avant = $requetes();

        app(TenantContext::class)->runAs($this->magasin, function (): void {
            $carnet = Produit::factory()->create(['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => 3, 'taux_tva' => 20, 'suivi_stock' => false]);

            foreach (range(1, 12) as $rang) {
                self::vendreAuComptant($this->vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $carnet->id, 'quantite' => 1]], $rang % 2 === 0 ? ModePaiement::Especes : ModePaiement::CarteBancaire);
            }
        });

        $this->assertSame($avant, $requetes());
        $this->assertLessThanOrEqual(20, $avant, 'Le magasin et sa société, puis, type par type, ses documents avec leurs lignes, leurs taux, leurs tiers et leurs règlements.');
    }

    public function test_la_preparation_passe_par_sa_file_dont_le_delai_de_reprise_depasse_sa_duree(): void
    {
        Queue::fake();
        $proprietaire = $this->proprietaireDe($this->magasin);

        app(DemanderLeTelechargementDesDocumentsAction::class)->handle($proprietaire, $this->magasin->societe, [$this->magasin], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes], OrigineDesAchats::Toutes, FormatDuTelechargement::Csv, ['ventes:'.$this->magasin->id.':'.$this->documents['ticket']->id]);

        Queue::assertPushed(PreparerLeTelechargementDesDocumentsJob::class, fn (PreparerLeTelechargementDesDocumentsJob $travail): bool => $travail->connection === PreparerLeTelechargementDesDocumentsJob::CONNEXION);
        $connexion = config('queue.connections.'.PreparerLeTelechargementDesDocumentsJob::CONNEXION);
        $this->assertSame('telechargements', $connexion['queue']);
        $this->assertGreaterThan((new PreparerLeTelechargementDesDocumentsJob(1))->timeout, $connexion['retry_after']);
        $this->assertNotSame(config('queue.connections.database.queue'), $connexion['queue']);
    }

    public function test_l_effacement_d_un_telechargement_expire_emporte_l_avis_qui_portait_son_lien(): void
    {
        $proprietaire = $this->proprietaireDe($this->magasin);
        $expire = $this->demander($proprietaire, FormatDuTelechargement::Csv);
        $this->travelTo(self::aParis('2026-03-12 10:00:00'));
        $recent = $this->demander($proprietaire, FormatDuTelechargement::Csv);
        $this->assertSame([$expire->id, $recent->id], $proprietaire->notifications()->get()->map(fn ($avis): int => $avis->data['telechargement_id'])->sort()->values()->all());

        $this->travelTo(self::aParis('2026-03-17 11:00:00'));
        $this->artisan(EffacerTelechargementsDeDocuments::class)->assertSuccessful();

        $this->assertSame([$recent->id], $proprietaire->notifications()->get()->map(fn ($avis): int => $avis->data['telechargement_id'])->all());
    }

    public function test_le_cabinet_lie_ne_telecharge_que_si_le_client_le_lui_coche(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        $documents = app(DocumentsDeLaPeriode::class);

        $this->assertFalse($documents->peutTelechargerDans($collaborateur, $this->magasin));
        $this->assertNotContains(PermissionRegistry::DOCUMENTS_TELECHARGER, PermissionRegistry::parDefautDuCabinet($this->magasin->societe->type_activite));

        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->magasin->societe_id)->where('etat', EtatDuLien::Actif->value)->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($this->magasin), $lien, [...PermissionRegistry::parDefautDuCabinet($this->magasin->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->assertTrue(app(DocumentsDeLaPeriode::class)->peutTelechargerDans($collaborateur->refresh(), $this->magasin));
        $this->assertSame(9, count($this->trouver($collaborateur)));
    }

    /**
     * Les documents de mars du magasin, de ces types, que ce compte trouve.
     *
     * @param  list<TypeDeDocumentTelechargeable>|null  $types
     * @return list<DocumentTrouve>
     */
    private function trouver(User $compte, ?array $types = null, OrigineDesAchats $origine = OrigineDesAchats::Toutes): array
    {
        return app(DocumentsDeLaPeriode::class)->trouver($compte, [$this->magasin], '2026-03-01', '2026-03-31', $types ?? TypeDeDocumentTelechargeable::cases(), $origine);
    }

    /**
     * La demande de tous les documents de mars que ce compte trouve, dans
     * ce format, préparée aussitôt (file d'attente synchrone des tests).
     */
    private function demander(User $compte, FormatDuTelechargement $format): TelechargementDeDocuments
    {
        return app(DemanderLeTelechargementDesDocumentsAction::class)->handle(
            $compte,
            $this->magasin->societe,
            [$this->magasin],
            '2026-03-01',
            '2026-03-31',
            TypeDeDocumentTelechargeable::cases(),
            OrigineDesAchats::Toutes,
            $format,
            array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->trouver($compte)),
        )->refresh();
    }

    /**
     * Ce compte de membre, à qui le propriétaire coche aussi le
     * téléchargement des documents par période.
     */
    private function autoriser(User $compte): User
    {
        $autorisations = app(TenantContext::class)->runAs($this->magasin, fn (): array => $compte->affectationDeMembre()->autorisations());
        app(ModifierMembreAction::class)->handle($this->proprietaireDe($this->magasin), $compte, RoleMagasin::Vendeur, [...$autorisations, PermissionRegistry::DOCUMENTS_TELECHARGER]);

        return $compte->refresh();
    }

    /**
     * Les fichiers du ZIP préparé, chacun sous son chemin dans l'archive.
     *
     * @return array<string, string>
     */
    private function lireLeZip(TelechargementDeDocuments $telechargement): array
    {
        $chemin = Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->path($telechargement->chemin);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($chemin));
        $fichiers = [];

        for ($rang = 0; $rang < $zip->numFiles; $rang++) {
            $nom = (string) $zip->getNameIndex($rang);
            $fichiers[$nom] = (string) $zip->getFromIndex($rang);
        }

        $zip->close();

        return $fichiers;
    }

    /**
     * Les documents du magasin, du 27 février au 9 mars 2026 : quatre
     * factures d'achat validées (une avec sa pièce, une sans, une reçue
     * par l'API sans date, une de février) et une en brouillon, un avoir
     * fournisseur, le ticket du vendeur payé en espèces, la facture de
     * l'hôtel réglée en partie par virement, l'avoir d'un article du
     * ticket, et la clôture de la journée du 9 mars.
     */
    private function remplir(): void
    {
        app(TenantContext::class)->runAs($this->magasin, function (): void {
            $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste du Nord', 'siret' => null, 'numero_tva' => null]);
            $hotel = Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']);
            $bougie = Produit::factory()->create(['nom' => 'Bougie', 'reference' => 'BOUGIE', 'prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => true]);
            $the = Produit::factory()->create(['nom' => 'Thé vert', 'reference' => 'THE', 'prix_vente_ttc' => '6.33', 'taux_tva' => '5.5', 'suivi_stock' => true]);
            $achat = fn (array $lignes, array $attributs, array $pieces = []): FactureAchat => app(CreerFactureAchatAction::class)->handle($this->responsable, $fournisseur, $lignes, $attributs, null, $pieces);
            $valider = fn (FactureAchat $facture): FactureAchat => app(ValiderFactureAchatAction::class)->handle($this->responsable, $facture);

            $this->documents['achatFevrier'] = $valider($achat([['produit_id' => $bougie->id, 'quantite' => 1, 'prix_unitaire_ht' => 5]], ['date_facture' => '2026-02-27']));
            $this->documents['achatAvecPiece'] = $valider($achat(
                [['produit_id' => $bougie->id, 'quantite' => 20, 'prix_unitaire_ht' => 5], ['produit_id' => $the->id, 'quantite' => 10, 'prix_unitaire_ht' => 3]],
                ['date_facture' => '2026-03-02', 'numero_fournisseur' => 'F-812'],
                [new PieceDeposee('Facture F-812.pdf', self::PDF)],
            ));
            $this->documents['achatSansPiece'] = $valider($achat([['produit_id' => $bougie->id, 'quantite' => 5, 'prix_unitaire_ht' => 5]], ['date_facture' => '2026-03-05']));
            $this->documents['achatApi'] = $valider($achat([['produit_id' => $bougie->id, 'quantite' => 2, 'prix_unitaire_ht' => 5]], ['source' => SourceFactureAchat::Api, 'date_facture' => null]));
            $this->documents['brouillon'] = $achat([['produit_id' => $bougie->id, 'quantite' => 3, 'prix_unitaire_ht' => 5]], ['date_facture' => '2026-03-06']);
            $this->documents['avoirFournisseur'] = app(EnregistrerAvoirFournisseurAction::class)->handle($this->responsable, $this->documents['achatAvecPiece'], [['ligne_achat_id' => $this->documents['achatAvecPiece']->lignes->first()->id, 'quantite' => 1]], 'Bougie cassée', 'AV-NORD-1');

            $this->travelTo(self::aParis('2026-03-09 11:00:00'));
            $this->documents['ticket'] = self::vendreAuComptant($this->vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $bougie->id, 'quantite' => 1]]);
            $this->documents['facture'] = app(EmettreFactureAction::class)->handle($this->responsable, app(CreerFactureAction::class)->handle($this->responsable, $hotel, [['produit_id' => $bougie->id, 'quantite' => 2], ['produit_id' => $the->id, 'quantite' => 1]]));
            app(EnregistrerPaiementAction::class)->handle($this->responsable, $hotel, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => '2026-03-09'], [['facture_id' => $this->documents['facture']->id, 'montant' => '20.00']]);
            $this->documents['avoir'] = app(EnregistrerAvoirAction::class)->handle($this->responsable, Client::query()->where('est_comptoir', true)->sole(), [['ligne_facture_id' => $this->documents['ticket']->lignes->sole()->id, 'quantite' => 1]], 'Retour');
            $this->travelTo(self::aParis('2026-03-09 20:00:00'));
            $this->documents['cloture'] = app(CloturerCaisseAction::class)->handle($this->responsable, $this->magasin, '2026-03-09');
        });

        $this->assertInstanceOf(AvoirFournisseur::class, $this->documents['avoirFournisseur']);
        $this->assertInstanceOf(Facture::class, $this->documents['ticket']);
        $this->assertInstanceOf(Avoir::class, $this->documents['avoir']);
        $this->assertInstanceOf(ClotureCaisse::class, $this->documents['cloture']);
    }
}
