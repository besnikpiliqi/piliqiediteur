<?php

namespace Tests\Feature\Stock;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La validation d'un inventaire d'ouverture et un autre geste au même
 * instant, chacun dans son processus, sur une vraie base PostgreSQL.
 *
 * La première vente du magasin : toutes deux verrouillent d'abord le
 * registre fiscal du magasin, et la seconde attend la première. Une
 * validation qui passe avant la vente fait entrer son stock, et la vente
 * suit ; une validation qui attendait la première vente est refusée, sans
 * rien créer.
 *
 * La suppression du brouillon : toutes deux verrouillent l'inventaire, et
 * la seconde attend la première. Une suppression qui attendait la
 * validation est refusée, et l'inventaire reste validé, avec ses lignes,
 * son produit et son stock ; une validation qui attendait la suppression
 * ne trouve plus l'inventaire, et rien ne naît.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class InventaireOuvertureConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiers(): array
    {
        return [
            'la validation d\'abord' => [true],
            'la vente d\'abord' => [false],
        ];
    }

    #[DataProvider('premiers')]
    #[Group('concurrence')]
    public function test_une_premiere_vente_simultanee_passe_avant_ou_apres_la_validation_d_un_inventaire(bool $laValidationDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $magasinId = $magasin->id;
        $inventaireId = $inventaire->id;
        $venduId = $vendu->id;
        $valider = static function () use ($magasinId, $inventaireId): void {
            self::valider($magasinId, $inventaireId);
        };
        $vendre = static function () use ($magasinId, $venduId): void {
            self::vendre($magasinId, $venduId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($laValidationDAbord ? $vendre : $valider, $concurrente);

        $laValidationDAbord ? $valider() : $vendre();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame(! $laValidationDAbord, $resultat->failed(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, Facture::query()->count());

        if ($laValidationDAbord) {
            $this->assertSame(StatutInventaireOuverture::Valide, $inventaire->fresh()->statut);
            $this->assertSame(10, Produit::query()->where('reference', 'CARNET-A5')->sole()->stock_quantite);
        } else {
            $this->assertStringContainsString('Ce magasin a déjà vendu : un inventaire d\'ouverture ne se saisit que jusqu\'à sa première vente.', $resultat->errorOutput());
            $this->assertSame(StatutInventaireOuverture::Brouillon, $inventaire->fresh()->statut);
            $this->assertSame(0, Produit::query()->where('reference', 'CARNET-A5')->count());
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiersEntreLaValidationEtLaSuppression(): array
    {
        return [
            'la validation d\'abord' => [true],
            'la suppression d\'abord' => [false],
        ];
    }

    #[DataProvider('premiersEntreLaValidationEtLaSuppression')]
    #[Group('concurrence')]
    public function test_une_suppression_simultanee_passe_avant_ou_apres_la_validation_d_un_inventaire(bool $laValidationDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $magasinId = $magasin->id;
        $inventaireId = $inventaire->id;
        $valider = static function () use ($magasinId, $inventaireId): void {
            self::valider($magasinId, $inventaireId);
        };
        $supprimer = static function () use ($magasinId, $inventaireId): void {
            self::supprimer($magasinId, $inventaireId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLInventaireEstVerrouille($laValidationDAbord ? $supprimer : $valider, $concurrente);

        $laValidationDAbord ? $valider() : $supprimer();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed(), $resultat->output());
        app(TenantContext::class)->set($magasin);

        if ($laValidationDAbord) {
            $this->assertStringContainsString(InventaireOuverture::REFUS_VALIDE, $resultat->errorOutput());
            $this->assertSame([StatutInventaireOuverture::Valide, 1], [$inventaire->fresh()->statut, $inventaire->lignes()->count()]);
            $this->assertSame([10, 1], [Produit::query()->where('reference', 'CARNET-A5')->sole()->stock_quantite, MouvementStock::query()->count()]);
        } else {
            $this->assertStringContainsString(ModelNotFoundException::class, $resultat->errorOutput());
            $this->assertSame(
                [0, 0, 0, 0],
                [InventaireOuverture::query()->count(), LigneInventaireOuverture::query()->count(), Produit::query()->count(), MouvementStock::query()->count()],
            );
        }
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient le
     * verrou de l'inventaire, et attend qu'elle attende ce verrou : la
     * seconde passe après la première. $concurrente reçoit le processus
     * démarré.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLInventaireEstVerrouille(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            if (str_contains($requete->sql, '"inventaires_ouverture"') && str_contains($requete->sql, 'for update')) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Le propriétaire valide l'inventaire d'ouverture.
     */
    private static function valider(int $magasinId, int $inventaireId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(ValiderInventaireOuvertureAction::class)->handle(
            User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
            InventaireOuverture::query()->findOrFail($inventaireId),
        );
    }

    /**
     * Le propriétaire supprime le brouillon de l'inventaire d'ouverture.
     */
    private static function supprimer(int $magasinId, int $inventaireId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(SupprimerInventaireOuvertureAction::class)->handle(
            User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
            InventaireOuverture::query()->findOrFail($inventaireId),
        );
    }

    /**
     * Le propriétaire vend un ticket de 12,00 € au comptoir : la première
     * vente du magasin.
     */
    private static function vendre(int $magasinId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        self::vendreAuComptant(
            User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }
}
