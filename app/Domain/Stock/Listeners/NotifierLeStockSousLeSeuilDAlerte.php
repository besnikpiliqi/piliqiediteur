<?php

namespace App\Domain\Stock\Listeners;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Events\StockPasseSousLeSeuilDAlerte;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\DestinatairesDesAlertes;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * Prévient le propriétaire de la société et toute personne qui peut ouvrir
 * le magasin et y gère le stock (voir PermissionRegistry::PRODUIT_AJUSTER_STOCK
 * et DestinatairesDesAlertes) -- jamais un compte d'un autre magasin, ni un
 * compte suspendu.
 *
 * Le produit est relu une fois la transaction validée : la notification
 * donne le stock qui reste vraiment (une facture peut porter plusieurs
 * lignes du même produit, chacune un mouvement), et rien ne part si un
 * mouvement suivant de la même transaction l'a fait remonter au-dessus du
 * seuil.
 */
class NotifierLeStockSousLeSeuilDAlerte
{
    public function __construct(private readonly DestinatairesDesAlertes $destinataires) {}

    public function handle(StockPasseSousLeSeuilDAlerte $event): void
    {
        $magasin = Magasin::query()->findOrFail($event->produit->magasin_id);

        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $event): void {
            $produit = Produit::query()->find($event->produit->getKey());

            if ($produit === null || ! $produit->enAlerteStock()) {
                return;
            }

            Notification::send($this->destinataires->autorisesDans($magasin, PermissionRegistry::PRODUIT_AJUSTER_STOCK), new StockSousLeSeuilDAlerte($produit));
        });
    }
}
