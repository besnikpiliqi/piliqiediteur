<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\GardesDesTypesDActivite;
use Closure;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les gardes de la base sur le type d'activité (voir
 * GardesDesTypesDActivite), même écrit par DB::table(), sans passer par
 * l'application, chacune par son refus exact : un type connu et qui ne
 * change jamais ; aucun magasin dans un cabinet d'expertise comptable ;
 * dans une société sans articles, ni case de la famille des articles, ni
 * produit, ni facture d'achat, ni avoir fournisseur, ni inventaire
 * d'ouverture.
 */
class GardesDesTypesDActiviteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque test repose les gardes par le code de leurs migrations
     * (GardesDesTypesDActivite::retirer() puis poser()), dans sa
     * transaction : la couverture relie ainsi ce code aux tests qui
     * vérifient ce que les gardes refusent, et les tests de mutation
     * l'éprouvent par eux (voir le README).
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([GardesDesTypesDActivite::TYPE_FIXE, GardesDesTypesDActivite::MAGASINS, GardesDesTypesDActivite::CASES_D_ARTICLES, GardesDesTypesDActivite::PRODUITS, GardesDesTypesDActivite::FACTURES_ACHAT, GardesDesTypesDActivite::AVOIRS_FOURNISSEUR, GardesDesTypesDActivite::INVENTAIRES_OUVERTURE] as $garde) {
            GardesDesTypesDActivite::retirer($garde);
            GardesDesTypesDActivite::poser($garde);
        }
    }

    /**
     * @return array<string, array{0: TypeDActivite, 1: TypeDActivite}>
     */
    public static function passagesDUnTypeAUnAutre(): array
    {
        $passages = [];

        foreach (TypeDActivite::cases() as $de) {
            foreach (TypeDActivite::cases() as $vers) {
                if ($de !== $vers) {
                    $passages["{$de->value} vers {$vers->value}"] = [$de, $vers];
                }
            }
        }

        return $passages;
    }

    /**
     * La base elle-même refuse un type inconnu et tout passage d'un type à
     * un autre, même par une requête qui ne passe pas par le modèle.
     */
    #[DataProvider('passagesDUnTypeAUnAutre')]
    public function test_la_base_refuse_un_type_inconnu_et_toute_modification_du_type(TypeDActivite $de, TypeDActivite $vers): void
    {
        $societe = Societe::factory()->create(['type_activite' => $de]);
        $inconnu = GardesDesTypesDActivite::TYPE_FIXE.' : type d\'activité inconnu';

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $societe->id)->update(['type_activite' => $vers->value])), QueryException::class, GardesDesTypesDActivite::TYPE_FIXE.' : le type d\'activité d\'une société ne change jamais');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $societe->id)->update(['type_activite' => 'boulangerie'])), QueryException::class, $inconnu);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->insert(['uuid' => (string) Str::uuid(), 'siren' => SocieteFactory::sirenFictif(), 'type_activite' => 'boulangerie'])), QueryException::class, $inconnu);

        $this->assertSame($de->value, DB::table('societes')->where('id', $societe->id)->value('type_activite'));
        $this->assertSame(1, DB::table('societes')->count());
    }

    /**
     * Un cabinet d'expertise comptable n'a aucun magasin : ni une ligne
     * écrite pour lui, ni le magasin d'une autre société qui passerait à
     * lui.
     */
    public function test_la_base_refuse_un_magasin_dans_un_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $copie = array_diff_key((array) DB::table('magasins')->where('id', $ailleurs->id)->first(), ['id' => true]);
        $refus = GardesDesTypesDActivite::MAGASINS.' : un cabinet d\'expertise comptable n\'a aucun magasin';

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins')->insert([...$copie, 'uuid' => (string) Str::uuid(), 'code' => 'CAB', 'societe_id' => $cabinet->id])), QueryException::class, $refus);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins')->where('id', $ailleurs->id)->update(['societe_id' => $cabinet->id])), QueryException::class, $refus);

        $this->assertSame(0, DB::table('magasins')->where('societe_id', $cabinet->id)->count());
    }

    /**
     * Dans une société sans articles, aucune case de la famille des
     * articles, ni cochée ni obtenue en changeant une case commune ; une
     * case commune passe.
     */
    public function test_la_base_refuse_une_case_d_articles_dans_une_societe_sans_articles(): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services, 'nom' => 'Conseil']);
        $affectation = Affectation::query()->where('user_id', $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW])->id)->sole();
        $refus = GardesDesTypesDActivite::CASES_D_ARTICLES.' : une autorisation de la famille des articles n\'existe pas dans une société sans articles';

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => PermissionRegistry::PRODUIT_VIEW])), QueryException::class, $refus);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->where('affectation_id', $affectation->id)->where('autorisation', PermissionRegistry::FACTURE_VIEW)->update(['autorisation' => PermissionRegistry::PRODUIT_CREATE])), QueryException::class, $refus);
        DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => PermissionRegistry::AVOIR_VIEW]);

        $this->assertEqualsCanonicalizing([PermissionRegistry::AVOIR_VIEW, PermissionRegistry::FACTURE_VIEW], $affectation->autorisations());
    }

    /**
     * @return array<string, array{0: string, 1: Closure(): Model, 2: string}>
     */
    public static function lignesDArticles(): array
    {
        return [
            'produit' => ['produits', static fn (): Model => Produit::factory()->create(), 'une société sans articles n\'a pas de produit'],
            'facture d\'achat' => ['factures_achat', static fn (): Model => FactureAchat::factory()->create(), 'une société sans articles n\'a pas de facture d\'achat'],
            'avoir fournisseur' => ['avoirs_fournisseur', static fn (): Model => AvoirFournisseur::factory()->create(), 'une société sans articles n\'a pas d\'avoir fournisseur'],
            'inventaire d\'ouverture' => ['inventaires_ouverture', static fn (): Model => InventaireOuverture::factory()->create(), 'une société sans articles n\'a pas d\'inventaire d\'ouverture'],
        ];
    }

    /**
     * La ligne, écrite dans un magasin d'une société « Commerce ou
     * atelier », ne se copie pas dans un magasin d'une société de services
     * et ne s'y déplace pas ; la base n'y en garde aucune.
     *
     * @param  Closure(): Model  $creer
     */
    #[DataProvider('lignesDArticles')]
    public function test_la_base_refuse_produit_facture_d_achat_avoir_fournisseur_et_inventaire_sans_articles(string $table, Closure $creer, string $refus): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services, 'nom' => 'Conseil']);
        $commerce = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        app(TenantContext::class)->set($commerce);
        $ligne = $creer();
        $copie = array_diff_key((array) DB::table($table)->where('id', $ligne->getKey())->first(), ['id' => true]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->insert([...$copie, 'magasin_id' => $services->id])), QueryException::class, $refus);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->where('id', $ligne->getKey())->update(['magasin_id' => $services->id])), QueryException::class, $refus);

        $this->assertSame(0, DB::table($table)->where('magasin_id', $services->id)->count());
        $this->assertSame((int) $commerce->id, (int) DB::table($table)->where('id', $ligne->getKey())->value('magasin_id'));
    }
}
