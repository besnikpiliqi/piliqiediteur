<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un achat part du PU HT du fournisseur (décision du 12/10/2026) et, comme
 * une vente (point 15), n'arrondit qu'une fois par taux : la TVA d'un taux
 * est le total HT de ses lignes × le taux, arrondie une fois au centime et
 * répartie sur ses lignes par cumul, dans leur ordre. Le HT des lignes, leur
 * coût unitaire et le coût moyen du stock n'en dépendent pas.
 */
class TvaDesAchatsParTauxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Trois articles différents à 10,01 € HT, à 20 %, puis un livre à
     * 5,5 %, validés en stock.
     *
     * @param  array<string, mixed>  $attributs  de la facture d'achat
     */
    private function acheterTroisArticlesEtUnLivre(array $attributs = []): FactureAchat
    {
        $produits = Produit::factory()->count(3)->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $livre = Produit::factory()->create(['taux_tva' => 5.5, 'suivi_stock' => true]);
        $lignes = $produits->map(fn (Produit $produit): array => ['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ht' => '10.01'])->all();
        $lignes[] = ['produit_id' => $livre->id, 'quantite' => 3, 'prix_unitaire_ht' => '10.01', 'remise_pourcentage' => 10];

        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), $lignes, $attributs));
    }

    /**
     * @return list<list<string>> le HT, la TVA et le TTC de chaque ligne, dans leur ordre
     */
    private function montantsDesLignes(FactureAchat $factureAchat): array
    {
        return $factureAchat->lignes()->orderBy('ordre')->get()
            ->map(fn (object $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])
            ->all();
    }

    /**
     * Essai du 17/10/2026 : trois lignes à 10,01 € HT à 20 % portaient 3 x
     * 2,00 € = 6,00 € de TVA. Le taux porte maintenant 20 % de 30,03 € =
     * 6,006, arrondi une fois : 6,01 €, réparti 2,00 + 2,00 + 2,01 ; le livre
     * (3 x 10,01 € remisés de 10 % = 27,03 € HT) a sa TVA à part, 5,5 % de
     * 27,03 € = 1,48665, soit 1,49 €.
     */
    public function test_une_facture_d_achat_de_trois_lignes_a_10_01_euros_porte_6_01_euros_de_tva(): void
    {
        $factureAchat = $this->acheterTroisArticlesEtUnLivre();

        $this->assertSame([
            ['10.01', '2.00', '12.01'],
            ['10.01', '2.00', '12.01'],
            ['10.01', '2.01', '12.02'],
            ['27.03', '1.49', '28.52'],
        ], $this->montantsDesLignes($factureAchat));
        $this->assertSame(['57.06', '7.50', '64.56'], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc]);
    }

    /**
     * Rendre toute une ligne au fournisseur rend exactement ce qu'elle a
     * payé, sa part de la TVA du taux comprise : 2,01 € pour la troisième
     * ligne (et non 20 % de 10,01 € = 2,00 €), 2,00 € pour la première.
     */
    public function test_un_avoir_fournisseur_de_toute_la_ligne_rend_exactement_sa_tva(): void
    {
        $factureAchat = $this->acheterTroisArticlesEtUnLivre();
        [$premiere, , $troisieme] = $factureAchat->lignes()->orderBy('ordre')->get()->all();

        $avoir = app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $troisieme->id, 'quantite' => 1],
            ['ligne_achat_id' => $premiere->id, 'quantite' => 1],
        ], 'Marchandise non conforme'))->fresh();

        $this->assertSame(
            [['10.01', '2.01', '12.02'], ['10.01', '2.00', '12.01']],
            $avoir->lignes()->orderBy('ordre')->get()->map(fn (object $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all(),
        );
        $this->assertSame(['20.02', '4.01', '24.03'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
    }

    /**
     * La TVA n'entre ni dans le coût d'un article ni dans le coût moyen :
     * avec l'escompte de 2 % de la facture, chaque article à 10,01 € HT
     * entre en stock à 9,8098 € (10,01 x 0,98), que sa ligne porte 2,00 € ou
     * 2,01 € de TVA ; le livre, à 10,01 € remisés de 10 % puis escomptés, à
     * 8,8288 € (10,01 x 0,9 x 0,98 = 8,82882).
     */
    public function test_le_cout_unitaire_et_le_cout_moyen_ne_changent_pas(): void
    {
        $factureAchat = $this->acheterTroisArticlesEtUnLivre(['escompte_pourcentage' => 2]);
        $lignes = $factureAchat->lignes()->orderBy('ordre')->with('produit', 'factureAchat')->get();

        $this->assertSame([9.8098, 9.8098, 9.8098, 8.8288], $lignes->map(fn (object $ligne): float => $ligne->coutUnitaireHt())->all());
        $this->assertSame(
            [['9.8098', 1], ['9.8098', 1], ['9.8098', 1], ['8.8288', 3]],
            $lignes->map(fn (object $ligne): array => [$ligne->produit->fresh()->cmup_ht, $ligne->produit->mouvementsStock()->where('type', TypeMouvementStock::ReceptionAchat)->sole()->quantite])->all(),
        );
    }
}
