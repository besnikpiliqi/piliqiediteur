<?php

namespace App\Domain\Stock\Notifications;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Filament\App\Resources\ProduitResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Une vente sans stock, confirmée après l'avertissement, vient de rendre
 * négatif le stock d'un produit (R1) : sa quantité reste à régulariser par
 * un achat, une réception ou un inventaire.
 */
class StockNegatif extends NotificationDuMagasin
{
    public function __construct(
        public readonly Produit $produit,
        public readonly string $numeroDeLaVente,
    ) {
        parent::__construct($produit->magasin);
    }

    protected function contenu(): NotificationFilament
    {
        return NotificationFilament::make()
            ->title("Stock négatif : {$this->produit->nom}")
            ->body("La vente {$this->numeroDeLaVente} a été faite sans stock : il reste ".str_replace('-', '−', (string) $this->produit->stock_quantite)." article(s) (référence {$this->produit->reference}), à régulariser par un achat, une réception ou un inventaire.")
            ->icon('heroicon-o-exclamation-triangle')
            ->danger()
            ->actions([
                Action::make('voir')
                    ->label('Voir le produit')
                    ->url(ProduitResource::getUrl('edit', ['record' => $this->produit], panel: 'app', tenant: $this->magasin))
                    ->markAsRead(),
            ]);
    }
}
