<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Exceptions\AucunePieceDeChargeAServirException;
use App\Domain\Facturation\Services\ChoixDesCharges;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Facturation\Services\RecapitulatifDesDocuments;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Notifications\AvisDuTelechargementDesDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\TelechargerLesDocumentsController;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Charges\PrepareDesCharges;
use Tests\TestCase;
use ZipArchive;

/**
 * Les charges et leurs pièces dans « Télécharger les documents » (décision
 * de l'éditeur du 15/11/2026, lot DATEOP) : le type « Charges (pièces) »,
 * choisi par le mois de TVA, les validées seulement ou toutes, celles de
 * « Toute la société » pour qui les télécharge ; le ZIP range chaque pièce
 * telle qu'enregistrée dans le dossier « Charges », nommée « <AAAA-MM>
 * <type> <fournisseur> <n° de pièce>.<ext> », numérotée en cas de
 * doublon ; le CSV liste chaque charge, « Manquante » quand elle n'a pas
 * de pièce ; le lien relit les droits et les pièces avant de servir le
 * fichier.
 *
 * Le jeu : une société de deux magasins (Lyon, Paris) liée à un cabinet ;
 * en mai, un loyer de « Toute la société » à valider (1 440 € TTC) et un
 * loyer de Lyon validé par le cabinet (1 200 € TTC) ; en avril, une pièce
 * d'électricité de Paris sans ses chiffres ; en mars, des fournitures de
 * Lyon renvoyées au client (120 € TTC).
 */
class TelechargementDesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $paris;

    private Societe $societe;

    private User $proprietaire;

    private User $collaborateur;

    /** @var array<string, Charge> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        Storage::fake(PreparerLeTelechargementDesDocumentsJob::DISQUE);
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']);
        $this->paris = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Paris', 'code' => 'PARIS']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        $this->collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        app(TenantContext::class)->clear();

        $this->charges['loyer_societe'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-05', 'magasin' => '']));
        $this->charges['loyer_lyon'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '1000.00', champs: ['fournisseur' => 'Bailleur de Lyon', 'numero_piece' => 'L-05', 'magasin' => (string) $this->lyon->uuid]));
        $this->charges['electricite_paris'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:electricite', null, champs: ['fournisseur' => 'EDF', 'numero_piece' => 'E-04', 'date_piece' => '2026-04-03', 'magasin' => (string) $this->paris->uuid]));
        $this->charges['fournitures_lyon'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:fournitures', '100.00', champs: ['fournisseur' => 'Papeterie du centre', 'numero_piece' => 'F-1', 'date_piece' => '2026-03-10', 'magasin' => (string) $this->lyon->uuid]));
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, (string) $this->charges['loyer_lyon']->uuid);
        app(TenantContext::class)->clear();
        app(RenvoyerUneChargeAction::class)->handle($this->collaborateur, $this->societe, (string) $this->charges['fournitures_lyon']->uuid, 'Pièce illisible');
        app(TenantContext::class)->clear();
    }

    public function test_les_charges_se_choisissent_par_leur_mois_de_tva_validees_seulement_ou_toutes(): void
    {
        $documents = app(DocumentsDeLaPeriode::class);
        $charges = [TypeDeDocumentTelechargeable::Charges];

        $this->assertSame(
            [['Charge : Loyer, TVA de mai 2026, validée', 'Lyon (LYON)', 'L-05', '02/05/2026', 'Bailleur de Lyon', 120000]],
            self::lues($documents->trouver($this->proprietaire, [$this->lyon, $this->paris], '2026-05-10', '2026-05-12', $charges, OrigineDesAchats::Toutes)),
            'Par défaut, les charges validées des magasins, du mois que la période touche.',
        );
        $this->assertSame(
            [
                ['Charge : Loyer, TVA de mai 2026, à valider', 'Toute la société', 'Q-05', '02/05/2026', 'SCI des Halles', 144000],
                ['Charge : Loyer, TVA de mai 2026, validée', 'Lyon (LYON)', 'L-05', '02/05/2026', 'Bailleur de Lyon', 120000],
            ],
            self::lues($documents->trouver($this->proprietaire, [$this->lyon, $this->paris], '2026-05-01', '2026-05-31', $charges, OrigineDesAchats::Toutes, charges: new ChoixDesCharges($this->societe, true))),
            'Toutes les charges, celles de « Toute la société » d\'abord.',
        );
        $this->assertSame(
            [
                ['Charge : Fournitures, TVA de mars 2026, renvoyée au client', 'Lyon (LYON)', 'F-1', '10/03/2026', 'Papeterie du centre', 12000],
                ['Charge : Électricité, TVA d\'avril 2026, pièce sans chiffres', 'Paris (PARIS)', 'E-04', '03/04/2026', 'EDF', 0],
            ],
            self::lues($documents->trouver($this->proprietaire, [$this->lyon, $this->paris], '2026-03-31', '2026-04-01', $charges, OrigineDesAchats::Toutes, charges: new ChoixDesCharges(null, true))),
            'Une période du 31 mars au 1er avril touche les deux mois.',
        );
    }

    public function test_les_charges_ne_se_proposent_qu_a_qui_voit_les_charges_et_telecharge_les_documents(): void
    {
        $documents = fn (): DocumentsDeLaPeriode => app(DocumentsDeLaPeriode::class);
        $charges = fn (User $compte, Magasin $magasin): bool => in_array(TypeDeDocumentTelechargeable::Charges, $documents()->typesVisibles($compte, $magasin), true);

        $this->assertTrue($charges($this->proprietaire, $this->lyon));
        $this->assertTrue($documents()->telechargeLesChargesDeLaSociete($this->proprietaire, $this->societe));

        $lesDeux = [PermissionRegistry::CHARGES_AJOUTER, PermissionRegistry::DOCUMENTS_TELECHARGER];
        $administrateur = $this->administrateur($this->lyon, [$this->lyon->getKey()], autorisationsMagasin: $lesDeux);
        $this->assertTrue($charges($administrateur, $this->lyon), 'Un administrateur qui a les deux cases dans Lyon.');
        $this->assertFalse($documents()->peutTelechargerDans($administrateur, $this->paris), 'Paris n\'est pas de sa portée.');
        $this->assertTrue($documents()->telechargeLesChargesDeLaSociete($administrateur, $this->societe));
        $sansLesCharges = $this->administrateur($this->lyon, autorisationsMagasin: [PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $this->assertFalse($charges($sansLesCharges, $this->lyon), 'Sans « Charges : ajouter », aucune charge.');
        $this->assertFalse($documents()->telechargeLesChargesDeLaSociete($sansLesCharges, $this->societe));

        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, $lesDeux);
        $this->assertTrue($charges($responsable, $this->lyon));
        $this->assertFalse($documents()->telechargeLesChargesDeLaSociete($responsable, $this->societe), 'Un responsable n\'a jamais les charges de « Toute la société ».');
        $this->assertFalse($charges($this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]), $this->lyon), 'Sans l\'autorisation de télécharger, aucun type.');
        $this->assertFalse($charges($this->membre($this->lyon, RoleMagasin::Vendeur, [PermissionRegistry::DOCUMENTS_TELECHARGER]), $this->lyon), 'Un vendeur ne voit jamais les charges.');

        $this->assertFalse($charges($this->collaborateur, $this->lyon), 'Le téléchargement n\'est jamais accordé au cabinet par défaut.');
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien, [...$lien->autorisations(), PermissionRegistry::DOCUMENTS_TELECHARGER], null);
        app(TenantContext::class)->clear();
        $this->assertTrue($charges($this->collaborateur->refresh(), $this->lyon));
        $this->assertTrue($documents()->telechargeLesChargesDeLaSociete($this->collaborateur, $this->societe));
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien->refresh(), array_values(array_diff($lien->autorisations(), [PermissionRegistry::CHARGES_VALIDER])), null);
        app(TenantContext::class)->clear();
        $this->assertFalse($charges($this->collaborateur->refresh(), $this->lyon), 'Sans « Charges : voir, ajouter, saisir et valider », plus de charges.');
        $this->assertFalse($documents()->telechargeLesChargesDeLaSociete($this->collaborateur, $this->societe));

        $demonstration = Magasin::query()->where('societe_id', $this->societe->getKey())->where('est_demonstration', true)->first();
        if ($demonstration !== null) {
            $this->assertFalse($documents()->voitLesChargesDans($this->proprietaire, $demonstration->setRelation('societe', $this->societe)), 'Jamais dans le magasin de démonstration.');
        }
    }

    public function test_le_zip_range_chaque_piece_dans_le_dossier_charges_sous_son_nom_et_numerote_les_doublons(): void
    {
        $sansNumero = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '50.00', champs: ['fournisseur' => 'SCI des Halles', 'magasin' => '']));
        $memeNom = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '60.00', champs: ['fournisseur' => 'SCI des Halles', 'magasin' => '']));
        $sansPiece = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '70.00', champs: ['fournisseur' => 'Nettoyage / Vitres', 'numero_piece' => 'N:7', 'magasin' => (string) $this->lyon->uuid]), avecLaPiece: false);

        $telechargement = $this->demander($this->proprietaire, FormatDuTelechargement::Zip, '2026-05-01', '2026-05-31');

        $this->assertSame(StatutDuTelechargement::Pret, $telechargement->statut);
        $this->assertSame(5, $telechargement->nombre_documents, 'Les cinq charges de mai, la charge sans pièce comprise.');
        $fichiers = $this->lireLeZip($telechargement);
        $this->assertSame([
            'Charges/2026-05 Loyer SCI des Halles Q-05.pdf',
            'Charges/2026-05 Loyer SCI des Halles.pdf',
            'Charges/2026-05 Loyer SCI des Halles (2).pdf',
            'Charges/2026-05 Loyer Bailleur de Lyon L-05.pdf',
        ], array_keys($fichiers), 'La charge sans pièce n\'a rien dans le ZIP.');
        $piece = PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $sansNumero->getKey())->sole();
        $this->assertSame(Storage::disk(PiecesDesCharges::DISQUE)->get($piece->chemin), $fichiers['Charges/2026-05 Loyer SCI des Halles.pdf'], 'La pièce telle qu\'elle a été enregistrée.');
        $this->assertEqualsCanonicalizing(
            PieceDeCharge::query()->withoutGlobalScopes()->whereIn('charge_id', [$this->charges['loyer_societe']->getKey(), $this->charges['loyer_lyon']->getKey(), $sansNumero->getKey(), $memeNom->getKey()])->pluck('id')->all(),
            $telechargement->piecesDeChargeContenues(),
        );
        $this->assertNotNull($sansPiece->getKey());
        $entree = EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::DocumentsTelecharges->value)->sole();
        $this->assertEquals(['magasins' => ['LYON'], 'charges_de_la_societe' => true, 'toutes_les_charges' => true, 'types' => ['charges'], 'nombre_documents' => 5], array_intersect_key($entree->nouvelles_valeurs ?? [], array_flip(['magasins', 'charges_de_la_societe', 'toutes_les_charges', 'types', 'nombre_documents'])));
    }

    public function test_un_zip_qui_ne_choisit_que_des_charges_sans_piece_est_refuse_clairement_a_la_demande(): void
    {
        $sansPiece = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '70.00', champs: ['fournisseur' => 'Nettoyage du centre', 'numero_piece' => 'N-7', 'magasin' => (string) $this->lyon->uuid]), avecLaPiece: false);
        $trouvees = app(DocumentsDeLaPeriode::class)->trouver($this->proprietaire, [$this->lyon], '2026-05-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, charges: new ChoixDesCharges(null, true));
        app(TenantContext::class)->clear();
        $cle = 'charges:'.$this->lyon->getKey().':'.$sansPiece->getKey();
        $this->assertSame([['L-05', false], ['N-7', true]], array_map(fn (DocumentTrouve $document): array => [$document->numero, $document->pieceManquante], $trouvees), 'La liste propose la charge sans pièce, marquée « Pièce manquante ».');

        $this->assertThrows(
            fn () => $this->demanderCes($this->proprietaire, FormatDuTelechargement::Zip, [$cle]),
            AucunePieceDeChargeAServirException::class,
            AucunePieceDeChargeAServirException::MESSAGE,
        );
        $this->assertSame(0, TelechargementDeDocuments::query()->withoutGlobalScopes()->count(), 'Rien n\'est demandé ni préparé.');

        $this->assertSame(StatutDuTelechargement::Pret, $this->demanderCes($this->proprietaire, FormatDuTelechargement::Csv, [$cle])->statut, 'Le récapitulatif CSV la liste.');
        $avecUneAutre = $this->demanderCes($this->proprietaire, FormatDuTelechargement::Zip, [$cle, 'charges:'.$this->lyon->getKey().':'.$this->charges['loyer_lyon']->getKey()]);
        $this->assertSame(StatutDuTelechargement::Pret, $avecUneAutre->statut, 'Avec une charge qui a sa pièce, le ZIP se prépare.');
        $this->assertSame(['Charges/2026-05 Loyer Bailleur de Lyon L-05.pdf'], array_keys($this->lireLeZip($avecUneAutre)));
    }

    public function test_une_piece_bloquee_pendant_la_preparation_clot_le_zip_par_un_refus_clair_sans_nouvelle_tentative(): void
    {
        Notification::fake();
        $cle = 'charges:'.$this->lyon->getKey().':'.$this->charges['loyer_lyon']->getKey();
        $this->app->instance(Antivirus::class, new class implements Antivirus
        {
            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                return ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau');
            }

            public function description(): string
            {
                return 'antivirus des tests';
            }
        });
        $this->travelTo(self::aParis('2026-05-21 11:00:00'));

        $telechargement = $this->demanderCes($this->proprietaire, FormatDuTelechargement::Zip, [$cle]);

        $this->assertSame([StatutDuTelechargement::Echec, AucunePieceDeChargeAServirException::MESSAGE, null], [$telechargement->statut, $telechargement->motif_echec, $telechargement->chemin], 'La pièce analysée de nouveau est bloquée : la demande échoue pour ce motif, sans erreur qui la ferait reprendre.');
        $this->assertSame([], Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->allFiles());
        $this->assertTrue(PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $this->charges['loyer_lyon']->getKey())->whereNotNull('bloquee_le')->exists());
        Notification::assertSentTo($this->proprietaire, AvisDuTelechargementDesDocuments::class, fn (AvisDuTelechargementDesDocuments $avis): bool => $avis->telechargement->is($telechargement));
        $this->assertSame([], EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::DocumentsTelecharges->value)->pluck('id')->all(), 'Aucun téléchargement n\'est inscrit au journal d\'audit.');

        $this->assertThrows(
            fn () => $this->demanderCes($this->proprietaire, FormatDuTelechargement::Zip, [$cle]),
            AucunePieceDeChargeAServirException::class,
            AucunePieceDeChargeAServirException::MESSAGE,
        );
    }

    public function test_le_csv_liste_chaque_charge_par_taux_avec_ses_colonnes_et_la_piece_manquante(): void
    {
        $sansPiece = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '70.00', champs: ['fournisseur' => 'Nettoyage du centre', 'numero_piece' => 'N-7', 'magasin' => (string) $this->lyon->uuid]), avecLaPiece: false);
        $trouvees = app(DocumentsDeLaPeriode::class)->trouver($this->proprietaire, [$this->lyon, $this->paris], '2026-04-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, charges: new ChoixDesCharges($this->societe, true));

        $csv = app(RecapitulatifDesDocuments::class)->ecrire($this->proprietaire, $trouvees, '2026-04-01', '2026-05-31');
        $lignes = array_map(fn (string $ligne): string => implode(';', str_getcsv($ligne, ';', '"', '')), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));

        $this->assertSame(implode(';', RecapitulatifDesDocuments::COLONNES), $lignes[0]);
        $this->assertContains('Charge;Toute la société;Q-05;02/05/2026;SCI des Halles;;Saisie du client;;;20;1200,00;240,00;1440,00;;;2026-05;Loyer;;240,00;Non;À valider;;;', $lignes);
        $this->assertContains('Charge;Lyon (LYON);L-05;02/05/2026;Bailleur de Lyon;;Saisie du client;;;20;1000,00;200,00;1200,00;;;2026-05;Loyer;;200,00;Non;Validée;Validée par le cabinet;20/05/2026;', $lignes);
        $this->assertContains('Charge;Paris (PARIS);E-04;03/04/2026;EDF;;Saisie du client;;;;;;;;;2026-04;Électricité;;;Non;Pièce sans chiffres;;;', $lignes);
        $this->assertContains('Charge;Lyon (LYON);N-7;02/05/2026;Nettoyage du centre;;Saisie du client;Manquante;;20;70,00;14,00;84,00;;;2026-05;Loyer;;14,00;Non;À valider;;;', $lignes, 'Une charge sans pièce : « Manquante ».');
        $this->assertNotNull($sansPiece->getKey());
    }

    public function test_le_responsable_ne_recoit_que_les_charges_de_son_magasin(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER, PermissionRegistry::DOCUMENTS_TELECHARGER]);

        $trouvees = app(DocumentsDeLaPeriode::class)->trouver($responsable, [$this->lyon, $this->paris], '2026-03-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, charges: new ChoixDesCharges($this->societe, true));

        $this->assertSame(['Lyon (LYON)', 'Lyon (LYON)'], array_map(fn (DocumentTrouve $document): string => $document->magasin, $trouvees), 'Ni « Toute la société », ni Paris : seulement les deux charges de Lyon.');
        $this->assertSame(['F-1', 'L-05'], array_map(fn (DocumentTrouve $document): string => $document->numero, $trouvees));
    }

    public function test_les_charges_de_toute_la_societe_sont_refusees_a_qui_ne_les_telecharge_pas(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER, PermissionRegistry::DOCUMENTS_TELECHARGER]);
        $cle = 'charges:0:'.$this->charges['loyer_societe']->getKey();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(DemanderLeTelechargementDesDocumentsAction::REFUS_CHARGES_DE_LA_SOCIETE);

        app(DemanderLeTelechargementDesDocumentsAction::class)->handle($responsable, $this->societe, [], '2026-05-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, FormatDuTelechargement::Zip, [$cle], chargesDeLaSociete: true);
    }

    public function test_le_lien_se_ferme_quand_le_client_retire_les_charges_au_cabinet(): void
    {
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien, [...$lien->autorisations(), PermissionRegistry::DOCUMENTS_TELECHARGER], null);
        app(TenantContext::class)->clear();
        $telechargement = $this->demander($this->collaborateur->refresh(), FormatDuTelechargement::Zip, '2026-05-01', '2026-05-31');
        $this->assertSame(StatutDuTelechargement::Pret, $telechargement->statut);
        $this->telechargerEnTantQue($this->collaborateur, $telechargement->lien())->assertOk();

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien->refresh(), array_values(array_diff($lien->autorisations(), [PermissionRegistry::CHARGES_VALIDER])), null);
        app(TenantContext::class)->clear();

        $this->telechargerEnTantQue($this->collaborateur->refresh(), $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);
    }

    public function test_une_piece_de_charge_analysee_il_y_a_plus_de_24_heures_repasse_l_antivirus_qui_peut_fermer_le_lien(): void
    {
        $telechargement = $this->demander($this->proprietaire, FormatDuTelechargement::Zip, '2026-05-01', '2026-05-31');
        $this->telechargerEnTantQue($this->proprietaire, $telechargement->lien())->assertOk();
        $piece = PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $this->charges['loyer_lyon']->getKey())->sole();
        $menace = Storage::disk(PiecesDesCharges::DISQUE)->get($piece->chemin);
        $this->app->instance(Antivirus::class, new class((string) $menace) implements Antivirus
        {
            public function __construct(private readonly string $menace) {}

            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                return $contenu === $this->menace ? ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau') : ResultatDeLAntivirus::sain();
            }

            public function description(): string
            {
                return 'antivirus des tests';
            }
        });

        $this->travelTo(self::aParis('2026-05-21 11:00:00'));

        $this->telechargerEnTantQue($this->proprietaire, $telechargement->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::PIECE_BLOQUEE);
        $this->assertTrue(PieceDeCharge::query()->withoutGlobalScopes()->whereIn('id', $telechargement->piecesDeChargeContenues())->whereNotNull('bloquee_le')->exists(), 'La pièce reconnue comme une menace est bloquée.');
    }

    public function test_une_charge_d_une_autre_societe_n_est_jamais_trouvee_ni_comptee(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $autre = $this->deposer($this->proprietaireDe($ailleurs), $ailleurs->societe, $this->saisie(champs: ['fournisseur' => 'Bailleur d\'ailleurs', 'numero_piece' => 'A-1', 'magasin' => '']));
        $documents = app(DocumentsDeLaPeriode::class);

        $trouvees = $documents->trouver($this->proprietaire, [$this->lyon, $this->paris], '2026-05-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, charges: new ChoixDesCharges($this->societe, true));

        $this->assertNotContains('Bailleur d\'ailleurs', array_map(fn (DocumentTrouve $document): ?string => $document->tiers, $trouvees));
        $this->assertSame(0, $documents->nombreEncoreVisibles($this->proprietaire, [$this->lyon], ['charges:0:'.$autre->getKey(), 'charges:'.$this->lyon->getKey().':'.$autre->getKey()], $this->societe), 'Une clé forgée vers la charge d\'une autre société ne désigne rien.');
        $this->assertSame(0, $documents->nombreEncoreVisibles($this->proprietaireDe($ailleurs), [], ['charges:0:'.$this->charges['loyer_societe']->getKey()], $this->societe), 'Ni le propriétaire d\'une autre société.');
    }

    /**
     * Ce que la liste montre de chaque charge trouvée.
     *
     * @param  list<DocumentTrouve>  $trouvees
     * @return list<array{string, string, string, string, string|null, int}>
     */
    private static function lues(array $trouvees): array
    {
        return array_map(fn (DocumentTrouve $document): array => [$document->libelleDuType(), $document->magasin, $document->numero, Calendrier::afficherJour($document->jour, 'd/m/Y'), $document->tiers, $document->ttc], $trouvees);
    }

    /**
     * Les charges de ce compte sur cette période, toutes, celles de « Toute
     * la société » comprises quand il les télécharge, préparées par la
     * file.
     */
    private function demander(User $compte, FormatDuTelechargement $format, string $du, string $au): TelechargementDeDocuments
    {
        $documents = app(DocumentsDeLaPeriode::class);
        $magasins = collect([$this->lyon, $this->paris])->filter(fn (Magasin $magasin): bool => $documents->peutTelechargerDans($compte, $magasin))->values();
        $deLaSociete = $documents->telechargeLesChargesDeLaSociete($compte, $this->societe);
        $trouvees = $documents->trouver($compte, $magasins, $du, $au, [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, charges: new ChoixDesCharges($deLaSociete ? $this->societe : null, true));
        app(TenantContext::class)->clear();

        return app(DemanderLeTelechargementDesDocumentsAction::class)->handle(
            $compte,
            $this->societe,
            $magasins,
            $du,
            $au,
            [TypeDeDocumentTelechargeable::Charges],
            OrigineDesAchats::Toutes,
            $format,
            array_map(fn (DocumentTrouve $document): string => $document->cle(), $trouvees),
            chargesDeLaSociete: $deLaSociete,
            toutesLesCharges: true,
        )->refresh();
    }

    /**
     * Ces charges de Lyon, de mai, toutes, choisies par leur clé et
     * demandées par ce compte dans ce format, préparées par la file.
     *
     * @param  list<string>  $cles
     */
    private function demanderCes(User $compte, FormatDuTelechargement $format, array $cles): TelechargementDeDocuments
    {
        app(TenantContext::class)->clear();

        return app(DemanderLeTelechargementDesDocumentsAction::class)->handle($compte, $this->societe, [$this->lyon], '2026-05-01', '2026-05-31', [TypeDeDocumentTelechargeable::Charges], OrigineDesAchats::Toutes, $format, $cles, toutesLesCharges: true)->refresh();
    }

    private function telechargerEnTantQue(User $compte, string $adresse): TestResponse
    {
        $this->flushSession();
        app(TenantContext::class)->clear();

        return $this->actingAs($compte)->get($adresse);
    }

    /**
     * Les fichiers du ZIP préparé, chacun sous son chemin dans l'archive,
     * dans l'ordre de l'archive.
     *
     * @return array<string, string>
     */
    private function lireLeZip(TelechargementDeDocuments $telechargement): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->path((string) $telechargement->chemin)));
        $fichiers = [];

        for ($rang = 0; $rang < $zip->numFiles; $rang++) {
            $fichiers[(string) $zip->getNameIndex($rang)] = (string) $zip->getFromIndex($rang);
        }

        $zip->close();

        return $fichiers;
    }
}
