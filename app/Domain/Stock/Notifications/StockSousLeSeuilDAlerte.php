<?php

namespace App\Domain\Stock\Notifications;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Filament\App\Resources\ProduitResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Le stock d'un produit vient de descendre à son seuil d'alerte, ou
 * dessous (voir Produit::enAlerteStock()) : il est temps de le
 * réapprovisionner.
 */
class StockSousLeSeuilDAlerte extends NotificationDuMagasin
{
    public function __construct(public readonly Produit $produit)
    {
        parent::__construct($produit->magasin);
    }

    protected function contenu(): NotificationFilament
    {
        $stock = $this->produit->stock_quantite;

        return NotificationFilament::make()
            ->title("Stock bas : {$this->produit->nom}")
            ->body(($stock > 0 ? "Plus que {$stock} article(s) en stock" : 'Plus aucun article en stock')
                ." (référence {$this->produit->reference}, seuil d'alerte : {$this->produit->seuil_alerte}).")
            ->icon('heroicon-o-archive-box-x-mark')
            ->warning()
            ->actions([
                Action::make('voir')
                    ->label('Voir le produit')
                    ->url(ProduitResource::getUrl('edit', ['record' => $this->produit], panel: 'app', tenant: $this->magasin))
                    ->markAsRead(),
            ]);
    }
}
