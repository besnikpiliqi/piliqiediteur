<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le téléchargement des documents par période sous la concurrence, chaque
 * geste dans son processus, sur une vraie base PostgreSQL :
 * - deux demandes simultanées d'un même compte, à une demande de sa
 *   limite de dix par heure : les demandes de l'heure se comptent sous le
 *   verrou de la ligne du compte (voir
 *   DemanderLeTelechargementDesDocumentsAction), si bien que la seconde
 *   voit la première et est refusée ;
 * - un export pendant l'émission d'une facture : il ne lit que ce qui est
 *   validé, la facture encore en brouillon pendant l'émission, jamais à
 *   moitié émise ; une fois l'émission faite, il la trouve, numérotée.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé. Les fichiers préparés vont dans un dossier de
 * test, à lui seul, vidé avant et après.
 */
class TelechargementDesDocumentsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const DOSSIER = 'framework/testing/telechargements-concurrence';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::DOSSIER));

        parent::tearDown();
    }

    /**
     * Le disque des téléchargements, dans le dossier de ce test, pour ce
     * processus.
     */
    private static function disqueDuTest(): void
    {
        config(['filesystems.disks.'.PreparerLeTelechargementDesDocumentsJob::DISQUE.'.root' => storage_path(self::DOSSIER)]);
        Storage::forgetDisk(PreparerLeTelechargementDesDocumentsJob::DISQUE);
    }

    #[Group('concurrence')]
    public function test_deux_demandes_simultanees_ne_depassent_jamais_dix_par_heure(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        File::deleteDirectory(storage_path(self::DOSSIER));
        self::disqueDuTest();
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']);
        $ticket = $this->vendreUnTicket($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->runAsSociete($magasin->societe, function () use ($proprietaire, $magasin): void {
            foreach (range(1, TelechargementDeDocuments::PAR_HEURE_AU_PLUS - 1) as $demande) {
                (new TelechargementDeDocuments)->forceFill([
                    'demande_par' => $proprietaire->id,
                    'magasins' => [$magasin->id],
                    'du' => '2026-03-01',
                    'au' => '2026-03-31',
                    'types' => [TypeDeDocumentTelechargeable::Ventes->value],
                    'origine' => OrigineDesAchats::Toutes,
                    'format' => FormatDuTelechargement::Csv,
                    'selection' => [],
                    'statut' => StatutDuTelechargement::Echec,
                    'expire_le' => self::aParis('2026-03-17 10:00:00'),
                ])->save();
            }
        });
        [$magasinId, $proprietaireId, $cle] = [$magasin->id, $proprietaire->id, 'ventes:'.$magasin->id.':'.$ticket->id];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', 'for no key update', static function () use ($magasinId, $proprietaireId, $cle): void {
            self::demander($magasinId, $proprietaireId, $cle);
        }, $concurrente);

        self::demander($magasinId, $proprietaireId, $cle);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed(), $resultat->output());
        $this->assertStringContainsString(DemanderLeTelechargementDesDocumentsAction::REFUS_PAR_HEURE, $resultat->errorOutput());
        $this->assertSame(
            [TelechargementDeDocuments::PAR_HEURE_AU_PLUS, 1],
            [TelechargementDeDocuments::demandesDepuis($proprietaire, self::aParis('2026-03-10 09:00:00'))->count(), TelechargementDeDocuments::demandesDepuis($proprietaire, self::aParis('2026-03-10 09:00:00'))->where('statut', StatutDuTelechargement::Pret->value)->count()],
        );
    }

    #[Group('concurrence')]
    public function test_un_export_pendant_une_emission_ne_voit_la_facture_qu_une_fois_emise(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        self::disqueDuTest();
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']));
        $ticket = $this->vendreUnTicket($magasin);
        $responsable = $this->membre($magasin, RoleMagasin::Responsable);
        $brouillon = app(TenantContext::class)->runAs($magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
            $responsable,
            Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']),
            [['produit_id' => Produit::query()->where('reference', 'CARNET')->value('id'), 'quantite' => 2]],
        ));
        [$magasinId, $proprietaireId] = [$magasin->id, $this->proprietaireDe($magasin)->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $proprietaireId): void {
            echo json_encode(self::numerosDesVentes($magasinId, $proprietaireId));
        }, $concurrente);

        $facture = app(TenantContext::class)->runAs($magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($responsable, $brouillon));

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame([$ticket->numero], json_decode(trim($resultat->output()), true));
        $this->assertSame([$ticket->numero, $facture->numero], self::numerosDesVentes($magasinId, $proprietaireId));
    }

    /**
     * Un ticket du magasin, vendu en espèces par un vendeur.
     */
    private function vendreUnTicket(Magasin $magasin): Facture
    {
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant(
            $vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::factory()->create(['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => 3, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
        ));
    }

    /**
     * La demande du ticket de ce magasin par ce compte, au format CSV,
     * préparée aussitôt (file d'attente synchrone des tests).
     */
    private static function demander(int $magasinId, int $proprietaireId, string $cle): void
    {
        self::disqueDuTest();
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(DemanderLeTelechargementDesDocumentsAction::class)->handle(
            User::query()->findOrFail($proprietaireId),
            $magasin->societe,
            [$magasin],
            '2026-03-01',
            '2026-03-31',
            [TypeDeDocumentTelechargeable::Ventes],
            OrigineDesAchats::Toutes,
            FormatDuTelechargement::Csv,
            [$cle],
        );
    }

    /**
     * Les numéros des ventes de mars que ce compte trouve dans ce magasin.
     *
     * @return list<string>
     */
    private static function numerosDesVentes(int $magasinId, int $proprietaireId): array
    {
        return array_map(
            fn (DocumentTrouve $document): string => $document->numero,
            app(DocumentsDeLaPeriode::class)->trouver(User::query()->findOrFail($proprietaireId), [Magasin::query()->findOrFail($magasinId)], '2026-03-01', '2026-03-31', [TypeDeDocumentTelechargeable::Ventes], OrigineDesAchats::Toutes),
        );
    }
}
