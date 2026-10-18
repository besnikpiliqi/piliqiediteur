<?php

namespace Tests\Feature\Stock;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La première vente du magasin et la validation d'un inventaire
 * d'ouverture au même instant, chacune dans son processus, sur une vraie
 * base PostgreSQL : toutes deux verrouillent d'abord le registre fiscal du
 * magasin, et la seconde attend la première. Une validation qui passe
 * avant la vente fait entrer son stock, et la vente suit ; une validation
 * qui attendait la première vente est refusée, sans rien créer.
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
