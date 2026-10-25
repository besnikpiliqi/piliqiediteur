<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * L'archivage d'un produit ou d'un fournisseur pendant la validation ou la
 * correction d'une facture d'achat qui les porte, chacun dans son
 * processus, sur une vraie base PostgreSQL. L'archivage verrouille la
 * fiche (voir ArchiverUneFicheAction) ; la validation et la correction
 * relisent sous verrou ce qu'elles contrôlent : le fournisseur sous un
 * verrou partagé, après la facture d'achat, puis ses produits, avant de
 * les dire au catalogue. Un archivage validé avant ces verrous fait
 * refuser le geste, avec un message ; un archivage qui arrive ensuite
 * attend la fin du geste : aucune réception n'entre dans le stock d'un
 * produit archivé, aucun produit ne passe à un fournisseur archivé, et
 * aucune correction ne finit sur une page introuvable.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FactureAchatArchivageConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_un_produit_archive_juste_avant_le_verrou_de_ses_produits_fait_refuser_la_validation(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $fournisseur, $produit] = $this->leMagasinSonFournisseurEtSonProduit();
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']]);
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $archivage = null;
        DB::connection()->beforeExecuting(function (string $requete) use (&$archivage, $magasinId, $produitId): void {
            if ($archivage === null && str_contains($requete, 'from "produits"') && str_ends_with($requete, 'for update')) {
                $archivage = $this->demarrerUnProcessusConcurrent(static fn () => self::archiver(Produit::class, $magasinId, $produitId))->wait();
            }
        });

        $this->assertThrows(
            fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $brouillon),
            LogicException::class,
            'Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture, avant de la valider.',
        );

        $this->assertNotNull($archivage);
        $this->assertTrue($archivage->successful(), $archivage->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([StatutFactureAchat::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame([true, 0, null], [Produit::withTrashed()->findOrFail($produitId)->trashed(), Produit::withTrashed()->findOrFail($produitId)->stock_quantite, Produit::withTrashed()->findOrFail($produitId)->fournisseur_id]);
        $this->assertSame(0, MouvementStock::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gestesQuiRattachentLeFournisseur(): array
    {
        return [
            'la validation du brouillon' => ['valider'],
            'la correction de la facture validée vers un autre fournisseur' => ['corriger'],
        ];
    }

    /**
     * L'archivage du fournisseur lancé dès que le geste l'a relu : il
     * attend la fin du geste, qui rattache le produit à ce fournisseur
     * encore actif, puis l'archive.
     */
    #[DataProvider('gestesQuiRattachentLeFournisseur')]
    #[Group('concurrence')]
    public function test_l_archivage_du_fournisseur_attend_la_fin_du_geste_qui_lui_rattache_ses_produits(string $geste): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $fournisseur, $produit] = $this->leMagasinSonFournisseurEtSonProduit();
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']]);
        $rattache = $fournisseur;

        if ($geste === 'corriger') {
            $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);
            $rattache = Fournisseur::factory()->create(['nom' => 'Nouveau grossiste']);
        }

        $magasinId = $magasin->id;
        $rattacheId = $rattache->id;
        $archivage = null;
        $enAttente = null;
        DB::listen(function (QueryExecuted $requete) use (&$archivage, &$enAttente, $magasinId, $rattacheId): void {
            if ($archivage === null && str_starts_with($requete->sql, 'select * from "fournisseurs"') && in_array($rattacheId, $requete->bindings, true)) {
                $archivage = $this->demarrerUnProcessusConcurrent(static fn () => self::archiver(Fournisseur::class, $magasinId, $rattacheId));
                $this->attendreQueLeProcessusAttendeUnVerrou($archivage);
                $enAttente = $archivage->running();
            }
        });

        $geste === 'valider'
            ? app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat)
            : app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $rattache, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']]);

        $this->assertInstanceOf(InvokedProcess::class, $archivage);
        $this->assertTrue($enAttente, 'L\'archivage du fournisseur n\'a pas attendu la fin du geste.');
        $resultat = $archivage->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertTrue(Fournisseur::withTrashed()->findOrFail($rattacheId)->trashed());
        $this->assertSame([$rattacheId, $rattacheId, 5], [$factureAchat->fresh()->fournisseur_id, $produit->fresh()->fournisseur_id, $produit->fresh()->stock_quantite]);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function correctionsDuProduit(): array
    {
        return [
            'le produit retiré par la correction' => [true],
            'le produit gardé par la correction' => [false],
        ];
    }

    /**
     * Le produit d'une réception validée archivé pendant sa correction,
     * une fois ses lignes d'avant effacées : il attend la fin de la
     * correction, qui corrige sa réception, puis s'archive ; la correction
     * ne finit jamais sur un produit introuvable.
     */
    #[DataProvider('correctionsDuProduit')]
    #[Group('concurrence')]
    public function test_un_produit_de_la_reception_ne_s_archive_qu_apres_sa_correction(bool $retire): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $fournisseur, $produit] = $this->leMagasinSonFournisseurEtSonProduit();
        $coque = Produit::factory()->create(['nom' => 'Coque', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']]));
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $archivage = null;
        $enAttente = null;
        DB::listen(function (QueryExecuted $requete) use (&$archivage, &$enAttente, $magasinId, $produitId): void {
            if ($archivage === null && str_starts_with($requete->sql, 'delete from "lignes_achat"')) {
                $archivage = $this->demarrerUnProcessusConcurrent(static fn () => self::archiver(Produit::class, $magasinId, $produitId));
                $this->attendreQueLeProcessusAttendeUnVerrou($archivage);
                $enAttente = $archivage->running();
            }
        });

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $fournisseur, $retire
            ? [['produit_id' => $coque->id, 'quantite' => 1, 'prix_unitaire_ht' => '3.00']]
            : [['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => '2.00']]);

        $this->assertInstanceOf(InvokedProcess::class, $archivage);
        $this->assertTrue($enAttente, 'L\'archivage du produit n\'a pas attendu la fin de la correction.');
        $resultat = $archivage->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $archive = Produit::withTrashed()->findOrFail($produitId);
        $this->assertSame([true, $retire ? 0 : 8], [$archive->trashed(), $archive->stock_quantite]);
        $this->assertSame([$retire ? 1 : 0], [$coque->fresh()->stock_quantite]);
        $this->assertSame([$retire ? $coque->id : $produitId], $factureAchat->fresh()->lignes->pluck('produit_id')->all());
    }

    /**
     * La boutique, son fournisseur « Grossiste » et son produit « Câble
     * USB-C », suivi en stock ; le responsable de test, auteur des gestes
     * du test, obtenu avant tout processus concurrent.
     *
     * @return array{0: Magasin, 1: Fournisseur, 2: Produit}
     */
    private function leMagasinSonFournisseurEtSonProduit(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        self::responsableDeTest($magasin);

        return [
            $magasin,
            Fournisseur::factory()->create(['nom' => 'Grossiste']),
            Produit::factory()->create(['nom' => 'Câble USB-C', 'reference' => 'CABLE-C', 'prix_vente_ttc' => 9, 'taux_tva' => 20, 'suivi_stock' => true]),
        ];
    }

    /**
     * Le propriétaire archive la fiche, comme le bouton « Archiver ».
     *
     * @param  class-string<Produit|Fournisseur>  $classe
     */
    private static function archiver(string $classe, int $magasinId, int $ficheId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        app(ArchiverUneFicheAction::class)->archiver(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $classe::query()->findOrFail($ficheId));
    }
}
