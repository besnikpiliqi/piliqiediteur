<?php

namespace Tests\Feature\Stock;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un geste sur un inventaire d'ouverture (sa validation, sa saisie ou sa
 * modification) et un autre geste au même instant, chacun dans son
 * processus, sur une vraie base PostgreSQL.
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
 * ne trouve plus l'inventaire et le dit, et rien ne naît.
 *
 * La saisie ou la modification d'un brouillon et la première vente :
 * toutes deux verrouillent d'abord le registre fiscal du magasin, et
 * la saisie relit sous lui la première vente ; un brouillon saisi ou
 * modifié avant la vente reste, et une saisie qui attendait la vente est
 * refusée.
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
        $this->concurrencerDesQueLaLigneEstVerrouillee('inventaires_ouverture', 'for update', $laValidationDAbord ? $supprimer : $valider, $concurrente);

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
            $this->assertStringContainsString(InventaireOuverture::REFUS_INTROUVABLE, $resultat->errorOutput());
            $this->assertSame(
                [0, 0, 0, 0],
                [InventaireOuverture::query()->count(), LigneInventaireOuverture::query()->count(), Produit::query()->count(), MouvementStock::query()->count()],
            );
        }
    }

    /**
     * La saisie ou la modification du brouillon, et qui passe le premier.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function saisiesEtOrdres(): array
    {
        return [
            'la saisie avant la première vente' => ['saisir', true],
            'la saisie après la première vente' => ['saisir', false],
            'la modification avant la première vente' => ['modifier', true],
            'la modification après la première vente' => ['modifier', false],
        ];
    }

    #[DataProvider('saisiesEtOrdres')]
    #[Group('concurrence')]
    public function test_un_brouillon_saisi_pendant_la_premiere_vente_passe_avant_elle_ou_est_refuse_apres(string $geste, bool $laSaisieDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $inventaireId = $geste === 'modifier' ? app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [self::carnets(10)])->id : null;
        $magasinId = $magasin->id;
        $venduId = $vendu->id;
        $saisir = static function () use ($magasinId, $inventaireId): void {
            self::saisir($magasinId, $inventaireId);
        };
        $vendre = static function () use ($magasinId, $venduId): void {
            self::vendre($magasinId, $venduId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($laSaisieDAbord ? $vendre : $saisir, $concurrente);

        $laSaisieDAbord ? $saisir() : $vendre();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame(! $laSaisieDAbord, $resultat->failed(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, Facture::query()->count());
        $quantites = LigneInventaireOuverture::query()->orderBy('id')->pluck('quantite')->all();

        if ($laSaisieDAbord) {
            $this->assertSame([12], $quantites);
        } else {
            $this->assertStringContainsString(InventaireOuverture::REFUS_APRES_LA_PREMIERE_VENTE, $resultat->errorOutput());
            $this->assertSame($geste === 'modifier' ? [10] : [], $quantites);
        }
    }

    /**
     * Le responsable de test du magasin saisit un brouillon de douze
     * carnets, ou y modifie la quantité du brouillon déjà saisi.
     */
    private static function saisir(int $magasinId, ?int $inventaireId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $auteur = self::responsableDeTest($magasin);

        $inventaireId === null
            ? app(CreerInventaireOuvertureAction::class)->handle($auteur, [self::carnets(12)])
            : app(ModifierInventaireOuvertureAction::class)->handle($auteur, InventaireOuverture::query()->findOrFail($inventaireId), [self::carnets(12)]);
    }

    /**
     * Une ligne d'inventaire d'ouverture de carnets, en cette quantité.
     *
     * @return array<string, mixed>
     */
    private static function carnets(int $quantite): array
    {
        return ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => $quantite, 'cout_unitaire_ht' => '2.00'];
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
     * Le responsable de test du magasin, obtenu par le test avant de lancer
     * l'autre processus (voir auteur()), vend un ticket de 12,00 € au
     * comptoir : la première vente du magasin, que le propriétaire ne fait
     * jamais.
     */
    private static function vendre(int $magasinId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        self::vendreAuComptant(
            self::responsableDeTest($magasin),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }
}
