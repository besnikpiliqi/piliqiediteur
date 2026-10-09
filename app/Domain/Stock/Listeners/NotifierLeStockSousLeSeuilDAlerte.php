<?php

namespace App\Domain\Stock\Listeners;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Events\StockPasseSousLeSeuilDAlerte;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Prévient les membres du magasin qui gèrent son stock (permission
 * d'ajuster le stock, voir ProduitPolicy) -- jamais un membre d'un autre
 * magasin, ni un membre invité ou suspendu.
 *
 * Les permissions d'un membre dépendent du magasin (voir
 * MagasinTeamResolver) : elles sont lues dans le contexte du magasin du
 * produit, quel que soit le contexte de l'appelant.
 *
 * Le produit est relu une fois la transaction validée : la notification
 * donne le stock qui reste vraiment (une facture peut porter plusieurs
 * lignes du même produit, chacune un mouvement), et rien ne part si un
 * mouvement suivant de la même transaction l'a fait remonter au-dessus du
 * seuil.
 */
class NotifierLeStockSousLeSeuilDAlerte
{
    public function handle(StockPasseSousLeSeuilDAlerte $event): void
    {
        $magasin = Magasin::query()->findOrFail($event->produit->magasin_id);

        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $event): void {
            $produit = Produit::query()->find($event->produit->getKey());

            if ($produit === null || ! $produit->enAlerteStock()) {
                return;
            }

            $destinataires = $magasin->membresActifs()->get()
                ->filter(fn (User $membre): bool => $membre->can(PermissionRegistry::PRODUIT_AJUSTER_STOCK));

            Notification::send($destinataires, new StockSousLeSeuilDAlerte($produit));
        });
    }
}
