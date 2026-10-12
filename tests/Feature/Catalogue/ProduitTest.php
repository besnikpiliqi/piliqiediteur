<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La référence produit est libre à la saisie (manuelle ou future
 * intégration API) : voir Produit::booted(). Si elle n'est pas fournie,
 * elle doit être générée automatiquement, par magasin, à la manière d'une
 * séquence de numéro de facture.
 */
class ProduitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_une_reference_vide_est_generee_automatiquement(): void
    {
        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertNotSame('', $produit->reference);
        $this->assertStringStartsWith('PRD-', $produit->reference);
    }

    public function test_une_reference_fournie_explicitement_est_conservee(): void
    {
        $produit = Produit::factory()->create(['reference' => 'MA-REF-PERSO']);

        $this->assertSame('MA-REF-PERSO', $produit->reference);
    }

    /**
     * Archivés compris, comme l'index unique de la table : l'erreur est
     * dite clairement plutôt que de casser sur la base.
     */
    public function test_une_reference_deja_prise_est_refusee(): void
    {
        Produit::factory()->create(['reference' => 'MA-REF-PERSO'])->delete();

        $this->expectExceptionObject(new InvalidArgumentException('La référence « MA-REF-PERSO » est déjà celle d\'un autre produit.'));

        Produit::factory()->create(['reference' => 'MA-REF-PERSO']);
    }

    /**
     * « ABC » et « abc » sont la même référence : le modèle le dit avant
     * que l'index unique (magasin_id, reference_normalisee) ne refuse.
     */
    public function test_une_reference_deja_prise_sous_une_autre_casse_est_refusee(): void
    {
        Produit::factory()->create(['reference' => 'MA-REF-PERSO']);

        $this->expectExceptionObject(new InvalidArgumentException('La référence « ma-ref-perso » est déjà celle d\'un autre produit.'));

        Produit::factory()->create(['reference' => 'ma-ref-perso']);
    }

    /**
     * La casse d'une lettre accentuée compte comme celle d'une autre
     * lettre, sur les deux moteurs : la clé de la référence se calcule en
     * PHP, pas par le lower() de la base, qui sous SQLite ne met en
     * minuscules que les lettres ASCII.
     */
    public function test_une_reference_accentuee_deja_prise_sous_une_autre_casse_est_refusee(): void
    {
        Produit::factory()->create(['reference' => 'ÉCRAN-1']);

        $this->assertTrue(Produit::referenceDejaPrise('écran-1'));
        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => Produit::factory()->create(['reference' => 'écran-1'])),
            InvalidArgumentException::class,
            'La référence « écran-1 » est déjà celle d\'un autre produit.',
        );
        $this->assertSame(['ÉCRAN-1'], Produit::query()->pluck('reference')->all());
    }

    public function test_une_reference_accentuee_prise_dans_un_autre_magasin_reste_libre(): void
    {
        Produit::factory()->create(['reference' => 'ÉCRAN-1']);
        $autreMagasin = Magasin::factory()->create();
        app(TenantContext::class)->set($autreMagasin);

        $produit = Produit::factory()->create(['reference' => 'écran-1']);

        $this->assertSame(['écran-1', $autreMagasin->id], [$produit->reference, $produit->magasin_id]);
    }

    /**
     * La base elle-même refuse une seconde référence de même clé, même par
     * une requête qui ne passe pas par le modèle ; la référence reste
     * enregistrée telle que saisie.
     */
    public function test_la_base_refuse_une_reference_deja_prise_sous_une_autre_casse(): void
    {
        $produit = Produit::factory()->create(['reference' => 'ABC']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('produits')->insert([
            'magasin_id' => $produit->magasin_id,
            'reference' => 'abc',
            'reference_normalisee' => Produit::normaliserReference('abc'),
            'nom' => 'Doublon',
            'taux_tva' => 20,
        ])), UniqueConstraintViolationException::class);

        $this->assertSame(['ABC'], Produit::query()->pluck('reference')->all());
    }

    /**
     * Un autre membre du magasin enregistre « ABC » juste après la
     * vérification de la référence « abc », avant son écriture (simulé par
     * un enregistrement direct en base, dès la fin de la requête de
     * vérification) : la référence est refusée comme si elle avait été
     * prise avant, au lieu d'une erreur de l'index unique, et la
     * transaction en cours reste utilisable (point de sauvegarde).
     */
    public function test_une_reference_prise_entre_temps_par_un_autre_membre_est_refusee_sans_erreur_de_la_base(): void
    {
        $magasinId = app(TenantContext::class)->id();
        $autreMembreAEnregistre = false;
        DB::listen(static function (QueryExecuted $requete) use (&$autreMembreAEnregistre, $magasinId): void {
            if (! $autreMembreAEnregistre && str_starts_with($requete->sql, 'select') && str_contains($requete->sql, 'reference_normalisee')) {
                $autreMembreAEnregistre = true;
                DB::table('produits')->insert(['magasin_id' => $magasinId, 'reference' => 'ABC', 'reference_normalisee' => 'abc', 'nom' => 'Écran', 'taux_tva' => 20]);
            }
        });

        $this->assertThrows(
            fn () => Produit::factory()->create(['reference' => 'abc']),
            InvalidArgumentException::class,
            'La référence « abc » est déjà celle d\'un autre produit.',
        );
        $this->assertSame(['ABC'], Produit::query()->pluck('reference')->all());
    }

    public function test_deux_produits_sans_reference_dans_le_meme_magasin_recoivent_des_numeros_distincts(): void
    {
        $premier = Produit::factory()->create(['reference' => '']);
        $second = Produit::factory()->create(['reference' => '']);

        $this->assertNotSame($premier->reference, $second->reference);
        $this->assertSame('PRD-000001', $premier->reference);
        $this->assertSame('PRD-000002', $second->reference);
    }

    public function test_une_edition_qui_vide_la_reference_en_genere_une_nouvelle(): void
    {
        $produit = Produit::factory()->create(['reference' => 'MA-REF-PERSO']);

        $produit->reference = '';
        $produit->save();

        $this->assertNotSame('', $produit->reference);
        $this->assertStringStartsWith('PRD-', $produit->reference);
    }

    /**
     * Une référence saisie à la main peut déjà porter le format automatique
     * : le produit suivant créé sans référence prend le numéro d'après.
     */
    public function test_la_reference_automatique_saute_un_numero_deja_saisi_a_la_main(): void
    {
        Produit::factory()->create(['reference' => 'PRD-000001']);

        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertSame('PRD-000002', $produit->reference);
    }

    public function test_la_reference_automatique_saute_le_numero_d_un_produit_archive(): void
    {
        Produit::factory()->create(['reference' => 'PRD-000001'])->delete();
        Produit::factory()->create(['reference' => 'PRD-000002']);

        $produit = Produit::factory()->create(['reference' => '']);

        $this->assertSame('PRD-000003', $produit->reference);
    }

    /**
     * @return array<string, array{array<string, float>, string}>
     */
    public static function prixNegatifs(): array
    {
        return [
            'prix de vente TTC' => [['prix_vente_ttc' => -12.0], 'Le prix de vente d\'un produit ne peut pas être négatif.'],
            'prix de vente HT' => [['prix_vente_ht' => -10.0], 'Le prix de vente d\'un produit ne peut pas être négatif.'],
            'coût d\'achat' => [['cmup_ht' => -5.0], 'Le coût d\'achat d\'un produit ne peut pas être négatif.'],
        ];
    }

    /**
     * Quel que soit le chemin d'enregistrement (formulaire, facture
     * d'achat, API), un prix négatif n'est jamais enregistré.
     *
     * @param  array<string, float>  $prix
     */
    #[DataProvider('prixNegatifs')]
    public function test_un_prix_negatif_est_refuse(array $prix, string $message): void
    {
        $this->assertThrows(
            fn () => Produit::factory()->create(['reference' => 'ECR-1', 'taux_tva' => 20, ...$prix]),
            InvalidArgumentException::class,
            $message,
        );

        $this->assertSame(0, Produit::withTrashed()->count());
    }

    /**
     * Les documents et le grand livre qui gardent un produit, archivé
     * compris.
     *
     * @return array<string, array{0: Closure(Produit): void}>
     */
    public static function usagesDUnProduit(): array
    {
        return [
            'grand livre du stock' => [static fn (Produit $produit) => app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::AjustementEntree, 3)],
            'ligne d\'achat' => [static fn (Produit $produit) => app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => 2]])],
            'ligne de devis' => [static fn (Produit $produit) => app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]])],
        ];
    }

    /**
     * Une fiche utilisée ne s'efface jamais : même archivé, un produit
     * reste dans la base tant qu'un document ou le grand livre le porte.
     *
     * @param  Closure(Produit): void  $utiliser
     */
    #[DataProvider('usagesDUnProduit')]
    public function test_un_produit_utilise_ne_s_efface_jamais_de_la_base(Closure $utiliser): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $utiliser($produit);
        $produit->delete();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $produit->forceDelete()), QueryException::class);

        $this->assertSoftDeleted($produit);
    }

    /**
     * Le fournisseur d'un produit, archivé compris, reste dans la base.
     */
    public function test_le_fournisseur_d_un_produit_ne_s_efface_jamais_de_la_base(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        Produit::factory()->create()->rattacherAuFournisseur($fournisseur->id);
        $fournisseur->delete();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $fournisseur->forceDelete()), QueryException::class);

        $this->assertSoftDeleted($fournisseur);
    }
}
