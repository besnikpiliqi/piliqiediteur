<?php

namespace Database\Factories;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Catalogue\Enums\OrigineProduit;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\OrigineDesProduits;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Services\ConstructeurLigneInventaire;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Un produit ne naît que de la validation d'une facture d'achat ou d'un
 * inventaire d'ouverture (voir OrigineDesProduits) : la fabrique enregistre
 * les siens dans le contexte d'origine d'un achat, comme des produits déjà
 * reçus, que la fabrique soit appelée seule ou depuis celle d'un autre
 * modèle.
 *
 * Un produit naît sans stock, et son stock n'entre que par un mouvement du
 * grand livre justifié par un document de son magasin (voir
 * GardesDuStock) : le stock qu'un test demande (stock_quantite) entre par
 * un inventaire d'ouverture validé par le propriétaire de la société, au
 * coût moyen du produit, comme un vrai démarrage de magasin (inventaire
 * numéroté, mouvement du grand livre, entrée au journal d'audit). Un
 * produit demandé sans suivi du stock avec une quantité reproduit
 * l'histoire réelle : suivi, stock entré, puis suivi coupé. Un stock
 * négatif ne naît que d'une vente sans stock : la fabrique le refuse, en
 * nommant la préparation à faire.
 *
 * @extends Factory<Produit>
 */
class ProduitFactory extends Factory
{
    /**
     * Le refus d'un stock de départ négatif.
     */
    public const REFUS_STOCK_NEGATIF = 'Un stock négatif ne naît que d\'une vente sans stock : créez le produit sans stock, puis vendez-le sans stock (voir TestCase::vendreJusquAuStockNegatif()).';

    protected $model = Produit::class;

    public function definition(): array
    {
        return [
            'reference' => strtoupper(fake()->unique()->bothify('REF-####??')),
            'nom' => fake()->words(3, true),
            'cmup_ht' => fake()->randomFloat(2, 1, 100),
            'prix_vente_ttc' => fake()->randomFloat(2, 1, 240),
            'taux_tva' => 20.00,
            'suivi_stock' => true,
            'seuil_alerte' => 5,
        ];
    }

    /**
     * @param  Collection<int, Produit>  $results
     */
    protected function store(Collection $results): void
    {
        $stocksDeDepart = $results->map(function (Produit $produit): array {
            $quantite = $produit->getAttribute('stock_quantite') === null ? 0 : ValeursLues::entier($produit->getAttribute('stock_quantite'));

            if ($quantite < 0) {
                throw new LogicException(self::REFUS_STOCK_NEGATIF);
            }

            $suiviStock = (bool) $produit->suivi_stock;
            $produit->forceFill(['stock_quantite' => 0, 'suivi_stock' => $suiviStock || $quantite > 0]);

            return [$quantite, $suiviStock];
        })->all();

        app(OrigineDesProduits::class)->pendant(OrigineProduit::Achat, fn () => parent::store($results));

        foreach ($results as $cle => $produit) {
            [$quantite, $suiviStock] = $stocksDeDepart[$cle];

            if ($quantite > 0) {
                $this->faireEntrerLeStockDeDepart($produit, $quantite);
            }

            if (! $suiviStock && $produit->suivi_stock) {
                $produit->update(['suivi_stock' => false]);
            }
        }
    }

    /**
     * Le stock de départ du produit, par un inventaire d'ouverture validé
     * par le propriétaire de la société de son magasin, qui le fait entrer
     * au coût moyen du produit (qui ne change donc pas).
     */
    private function faireEntrerLeStockDeDepart(Produit $produit, int $quantite): void
    {
        $magasin = Magasin::query()->findOrFail($produit->magasin_id);
        $proprietaire = User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->first()
            ?? throw new LogicException("Le stock de départ d'un produit entre par un inventaire d'ouverture validé par le propriétaire de la société : créez d'abord la société avec son propriétaire (SocieteFactory::avecProprietaire()).");
        $maintenant = CarbonImmutable::now();

        $inventaire = (new InventaireOuverture)->forceFill(['magasin_id' => $magasin->getKey(), 'cree_par' => $proprietaire->getKey()]);
        $inventaire->save();
        $ligne = (new LigneInventaireOuverture)->forceFill(['magasin_id' => $magasin->getKey()])->fill([
            'inventaire_ouverture_id' => $inventaire->getKey(),
            'produit_id' => $produit->getKey(),
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'cout_unitaire_ht' => $produit->cmup_ht,
            'suivi_stock' => true,
            'valeur_ht' => 0,
        ]);
        $ligne->save();
        app(ConstructeurLigneInventaire::class)->valoriser($inventaire, $inventaire->lignes()->with('produit')->get());

        $inventaire->forceFill([
            'numero' => app(DocumentNumberingService::class)->suivant($magasin, TypeDocument::InventaireOuverture, $magasin->calendrier()->anneeDe($maintenant)),
            'statut' => StatutInventaireOuverture::Valide,
            'date_validation' => $maintenant,
            'valide_par' => $proprietaire->getKey(),
        ])->save();

        app(OperateurCourant::class)->pendant($proprietaire, function () use ($produit, $quantite, $inventaire, $magasin, $proprietaire, $ligne): void {
            app(StockLedgerService::class)->entrerALInventaireDOuverture($produit, $quantite, app(CalculateurLigne::class)->enDixMilliemes($produit->cmup_ht), $inventaire, "Inventaire d'ouverture {$inventaire->numero}");
            app(JournalAudit::class)->inscrire(ActionAuditee::InventaireOuvertureValide, $proprietaire, $magasin, null, [
                'inventaire' => $inventaire->numero,
                'valeur_ht' => $inventaire->fresh()?->valeur_ht,
                'lignes' => [[
                    'reference' => $ligne->reference,
                    'quantite' => $quantite,
                    'suivi_stock' => true,
                    'cout_unitaire_ht' => $ligne->cout_unitaire_ht,
                    'produit_cree' => false,
                ]],
            ], $inventaire);
        });
    }
}
