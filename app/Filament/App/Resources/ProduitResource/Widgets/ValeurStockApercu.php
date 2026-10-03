<?php

namespace App\Filament\App\Resources\ProduitResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ProduitResource/Widgets, PAS sous
 * Filament/App/Widgets (scanné par discoverWidgets() et donc rendu sur le
 * tableau de bord général) : ce widget n'a de sens que sur la liste des
 * produits, attaché explicitement via ListProduits::getHeaderWidgets().
 *
 * Valorisation du stock au prix d'ACHAT HT (valeur comptable classique
 * d'un inventaire, pas le chiffre d'affaires potentiel à la vente), avec
 * sa TVA. Information sensible (elle révèle la trésorerie immobilisée en
 * stock) : réservée par défaut au propriétaire/administrateur/comptable
 * via produit.voir_valeur_stock (voir SeedMagasinRolesAction), un vendeur
 * continue de voir le prix et la quantité de chaque produit dans le
 * tableau, juste pas ce total agrégé.
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
        return auth()->user()->can(PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK);
    }

    protected function getStats(): array
    {
        $produitsSuivis = Produit::query()->where('suivi_stock', true)->get();
        $produitsEnStock = $produitsSuivis->filter(fn (Produit $produit) => (float) $produit->stock_quantite > 0);
        $nombreEpuises = $produitsSuivis->count() - $produitsEnStock->count();

        $quantiteTotale = $produitsEnStock->sum(fn (Produit $produit) => (float) $produit->stock_quantite);
        $valeurHt = $produitsEnStock->sum(fn (Produit $produit) => (float) $produit->stock_quantite * (float) $produit->prix_achat_ht);
        $tva = $produitsEnStock->sum(fn (Produit $produit) => (float) $produit->stock_quantite * (float) $produit->prix_achat_ht * ((float) $produit->taux_tva / 100));

        return [
            Stat::make('Valeur du stock', number_format($valeurHt + $tva, 2, ',', ' ').' € TTC')
                ->description(sprintf(
                    '%s unités en stock · %s € HT · %s € TVA',
                    rtrim(rtrim(number_format($quantiteTotale, 3, ',', ' '), '0'), ','),
                    number_format($valeurHt, 2, ',', ' '),
                    number_format($tva, 2, ',', ' '),
                ))
                ->color('success'),
            Stat::make('Références épuisées', (string) $nombreEpuises)
                ->description($nombreEpuises > 0 ? 'À réapprovisionner' : 'Aucune rupture')
                ->color($nombreEpuises > 0 ? 'danger' : 'success'),
        ];
    }
}
