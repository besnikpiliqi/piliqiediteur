<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\JoindreDesPiecesAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux dépôts simultanés de pièces du fournisseur sur un même document,
 * chacun dans son processus, sur une vraie base PostgreSQL : les pièces se
 * comptent sous le verrou de la ligne du document (voir
 * PiecesDuFournisseur::joindre()), si bien que le second voit le premier
 * et qu'un document ne porte jamais plus de dix pièces.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé. Les deux processus rangent leurs fichiers dans
 * le même dossier de test, à lui seul, vidé avant et après.
 */
class PiecesDuFournisseurConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const DOSSIER = 'framework/testing/pieces-fournisseur-concurrence';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::DOSSIER));

        parent::tearDown();
    }

    /**
     * Le disque des pièces, dans le dossier de ce test, pour ce processus.
     */
    private static function disqueDuTest(): void
    {
        config(['filesystems.disks.'.PiecesDuFournisseur::DISQUE.'.root' => storage_path(self::DOSSIER)]);
        Storage::forgetDisk(PiecesDuFournisseur::DISQUE);
    }

    #[Group('concurrence')]
    public function test_deux_depots_simultanes_ne_depassent_jamais_dix_pieces(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        File::deleteDirectory(storage_path(self::DOSSIER));
        self::disqueDuTest();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => '2.00'],
        ], ['date_facture' => '2026-01-15'], null, array_map(fn (int $page): PieceDeposee => new PieceDeposee("page-{$page}.pdf", "%PDF-1.4 page {$page}"), range(1, 9)));
        $magasinId = $magasin->id;
        $factureId = $factureAchat->id;
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('factures_achat', 'for update', static function () use ($magasinId, $factureId): void {
            self::joindre($magasinId, $factureId, 'concurrente.pdf');
        }, $concurrente);

        self::joindre($magasinId, $factureId, 'premiere.pdf');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed(), $resultat->output());
        $this->assertStringContainsString(PiecesDuFournisseur::REFUS_TROP_DE_PIECES, $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(10, PieceDuFournisseur::query()->where('facture_achat_id', $factureId)->count());
        $this->assertSame(['premiere.pdf'], PieceDuFournisseur::query()->where('nom_origine', 'like', '%e.pdf')->pluck('nom_origine')->all());
        $this->assertCount(10, Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());
    }

    private static function joindre(int $magasinId, int $factureId, string $nom): void
    {
        self::disqueDuTest();
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(JoindreDesPiecesAction::class)->handle(
            User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
            FactureAchat::query()->findOrFail($factureId),
            [new PieceDeposee($nom, "%PDF-1.4 {$nom}")],
        );
    }
}
