<?php

namespace App\Filament\App\Resources\ProduitResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\Reglage;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Vit délibérément sous Resources/ProduitResource/Widgets, PAS sous
 * Filament/App/Widgets (scanné par discoverWidgets() et donc rendu sur le
 * tableau de bord général) : ce widget n'a de sens que sur la liste des
 * produits, attaché explicitement via ListProduits::getHeaderWidgets().
 *
 * Valorisation du stock à son coût d'achat, le CMUP (valeur comptable
 * classique d'un inventaire, pas le chiffre d'affaires potentiel à la
 * vente, voir StockLedgerService) : la quantité de chaque produit x son
 * CMUP au dix-millième, arrondie une seule fois au centime, avec sa TVA
 * (voir CalculateurLigne::valeurAuCoutMoyen()). Le CMUP est une moyenne,
 * pas un prix affiché : l'arrondir au centime avant de le multiplier
 * fausserait la valeur d'un stock d'articles bon marché (2 000 articles à
 * 0,045 € valent 90,00 €, pas 100,00 €).
 *
 * Un produit archivé (voir les SoftDeletes de Produit) n'est plus proposé,
 * mais ce qu'il en reste en stock existe toujours : il compte dans la
 * valeur du stock. Épuisé, il ne compte pas parmi les références à
 * réapprovisionner, puisqu'il ne se rachète plus.
 *
 * Information sensible (elle révèle la trésorerie immobilisée en stock) :
 * réservée par défaut au propriétaire, aux responsables, aux
 * administrateurs et au cabinet lié, via produit.voir_valeur_stock (voir
 * PermissionRegistry::modeleDeLAdministrateur() et
 * PermissionRegistry::parDefautDuCabinet()) ; un vendeur continue de voir le prix et la quantité de chaque produit
 * dans le tableau, juste pas ce total agrégé.
 *
 * PAS de tuile "valeur des produits épuisés" : la valeur d'un stock est
 * quantité × prix, donc un produit à quantité 0 vaut TOUJOURS 0 € --
 * afficher "0,00 €" à côté d'un prix d'achat non nul aurait l'air d'un
 * bug plutôt que d'un fait comptable. Pour un produit épuisé,
 * l'information utile est son NOMBRE (combien de références
 * réapprovisionner), pas un montant.
 */
class ValeurStockApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return CompteConnecte::exiger()->can(PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK);
    }

    protected function getStats(): array
    {
        $produitsSuivis = Produit::withTrashed()->where('suivi_stock', true)->get();
        $produitsEnStock = $produitsSuivis->filter(fn (Produit $produit): bool => $produit->stock_quantite > 0);
        $nombreEpuises = $produitsSuivis->filter(fn (Produit $produit): bool => ! $produit->trashed() && $produit->stock_quantite <= 0)->count();

        $calculateur = app(CalculateurLigne::class);
        $valeurs = $produitsEnStock->map(
            fn (Produit $produit): array => $calculateur->valeurAuCoutMoyen($produit->stock_quantite, $calculateur->enDixMilliemes($produit->cmup_ht), $calculateur->enCentiemes($produit->taux_tva)),
        );
        $total = fn (string $montant): int => array_sum(array_column($valeurs->all(), $montant));

        return [
            Stat::make('Valeur du stock', $calculateur->enEuros($total('montant_ttc')).' TTC')
                ->description(sprintf(
                    '%s unités en stock · %s HT · %s TVA',
                    Number::format($produitsEnStock->sum(fn (Produit $produit): int => $produit->stock_quantite), locale: Reglage::texte('app.locale')),
                    $calculateur->enEuros($total('montant_ht')),
                    $calculateur->enEuros($total('montant_tva')),
                ))
                ->color('success'),
            Stat::make('Références épuisées', (string) $nombreEpuises)
                ->description($nombreEpuises > 0 ? 'À réapprovisionner' : 'Aucune rupture')
                ->color($nombreEpuises > 0 ? 'danger' : 'success'),
        ];
    }
}
