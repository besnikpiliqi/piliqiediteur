<?php

namespace App\Domain\Stock\Listeners;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Events\StockDevenuNegatif;
use App\Domain\Stock\Notifications\StockNegatif;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\DestinatairesDesAlertes;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * Prévient le propriétaire de la société, les responsables du magasin et
 * toute autre personne qui peut ouvrir le magasin et y gère le stock (voir
 * PermissionRegistry::PRODUIT_AJUSTER_STOCK et DestinatairesDesAlertes)
 * qu'une vente sans stock a rendu un produit négatif -- jamais un compte
 * d'un autre magasin, ni un compte suspendu.
 *
 * Le produit est relu une fois la vente validée : rien ne part si un
 * achat ou un retour l'a déjà ramené à zéro ou au-dessus.
 */
class NotifierLeStockNegatif
{
    public function __construct(private readonly DestinatairesDesAlertes $destinataires) {}

    public function handle(StockDevenuNegatif $event): void
    {
        $magasin = Magasin::query()->findOrFail($event->produit->magasin_id);

        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $event): void {
            $produit = Produit::withTrashed()->find($event->produit->getKey());

            if ($produit === null || ! $produit->estEnStockNegatif()) {
                return;
            }

            Notification::send($this->destinataires->autorisesDans($magasin, PermissionRegistry::PRODUIT_AJUSTER_STOCK), new StockNegatif($produit, $event->numeroDeLaVente));
        });
    }
}
