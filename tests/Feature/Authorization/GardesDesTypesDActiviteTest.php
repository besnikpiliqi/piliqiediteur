<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les gardes de la base sur les articles (voir GardesDesTypesDActivite) :
 * une société sans articles n'a ni produit, ni facture d'achat, ni avoir
 * fournisseur, ni inventaire d'ouverture, même écrits par DB::table(),
 * sans passer par l'application.
 */
class GardesDesTypesDActiviteTest extends TestCase
{
    use RefreshDatabase;

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
