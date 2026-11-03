<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La validation d'un brouillon de facture d'achat et sa suppression au
 * même instant, chacune dans son processus, sur une vraie base
 * PostgreSQL : toutes deux verrouillent la facture d'achat, et la
 * seconde attend la première. Une suppression qui attendait la validation
 * est refusée, et la facture d'achat reste validée, avec ses lignes, son
 * numéro, son nouveau produit et sa réception en stock ; une validation
 * qui attendait la suppression ne trouve plus la facture d'achat et le
 * dit, et rien ne naît.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FactureAchatConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiers(): array
    {
        return [
            'la validation d\'abord' => [true],
            'la suppression d\'abord' => [false],
        ];
    }

    #[DataProvider('premiers')]
    #[Group('concurrence')]
    public function test_une_suppression_simultanee_passe_avant_ou_apres_la_validation_d_une_facture_d_achat(bool $laValidationDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Coque', 'reference' => 'COQUE', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 2, 'prix_unitaire_ht' => 2],
        ]);
        $magasinId = $magasin->id;
        $factureAchatId = $factureAchat->id;
        $valider = static function () use ($magasinId, $factureAchatId): void {
            self::valider($magasinId, $factureAchatId);
        };
        $supprimer = static function () use ($magasinId, $factureAchatId): void {
            self::supprimer($magasinId, $factureAchatId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('factures_achat', 'for update', $laValidationDAbord ? $supprimer : $valider, $concurrente);

        $laValidationDAbord ? $valider() : $supprimer();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed(), $resultat->output());
        app(TenantContext::class)->set($magasin);

        if ($laValidationDAbord) {
            $this->assertStringContainsString(FactureAchat::REFUS_VALIDEE, $resultat->errorOutput());
            $validee = FactureAchat::query()->findOrFail($factureAchatId);
            $this->assertSame(StatutFactureAchat::Validee, $validee->statut);
            $this->assertNotNull($validee->numero);
            $this->assertSame(1, LigneAchat::query()->where('facture_achat_id', $factureAchatId)->count());
            $this->assertSame([2, 1], [Produit::query()->where('reference', 'COQUE')->sole()->stock_quantite, MouvementStock::query()->count()]);
        } else {
            $this->assertStringContainsString(FactureAchat::REFUS_INTROUVABLE, $resultat->errorOutput());
            $this->assertSame(
                [0, 0, 0, 0],
                [FactureAchat::query()->count(), LigneAchat::query()->count(), Produit::withTrashed()->count(), MouvementStock::query()->count()],
            );
        }
    }

    /**
     * Le responsable de test du magasin, obtenu par le test avant de lancer
     * l'autre processus (voir auteur()), valide la facture d'achat.
     */
    private static function valider(int $magasinId, int $factureAchatId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(ValiderFactureAchatAction::class)->handle(self::responsableDeTest($magasin), FactureAchat::query()->findOrFail($factureAchatId));
    }

    /**
     * Le responsable de test du magasin supprime le brouillon de la facture
     * d'achat.
     */
    private static function supprimer(int $magasinId, int $factureAchatId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(SupprimerFactureAchatAction::class)->handle(self::responsableDeTest($magasin), FactureAchat::query()->findOrFail($factureAchatId));
    }
}
