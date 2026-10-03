<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Filet de sécurité réglementaire : un magasin qui oublie de clôturer sa
 * caisse (bouton manuel, voir ClotureCaisseResource) ne doit jamais
 * laisser une journée de ventes aux particuliers sans e-reporting. Cette
 * commande planifiée (voir bootstrap/app.php, juste après
 * app:marquer-factures-en-retard) clôture la VEILLE pour chaque magasin
 * actif qui ne l'a pas déjà fait lui-même -- jamais le jour même, qui
 * n'est pas encore terminé au moment où elle tourne.
 */
#[Signature('app:cloturer-caisses-automatiquement')]
#[Description("Clôture automatiquement la caisse de la veille pour les magasins qui ne l'ont pas fait eux-mêmes")]
class ClotureAutomatiqueCaisse extends Command
{
    public function handle(TenantContext $tenantContext, CloturerCaisseAction $cloturerCaisse): int
    {
        $hier = now()->subDay()->toDateString();
        $totalClotures = 0;

        Magasin::query()
            ->where('statut', StatutMagasin::Active)
            ->each(function (Magasin $magasin) use ($tenantContext, $cloturerCaisse, $hier, &$totalClotures) {
                $tenantContext->runAs($magasin, function () use ($cloturerCaisse, $magasin, $hier, &$totalClotures) {
                    try {
                        // CloturerCaisseAction rejette déjà une journée
                        // déjà clôturée (voir son propre
                        // LogicException) : pas besoin de dupliquer cette
                        // vérification ici, un magasin fermé manuellement
                        // pour la veille est simplement ignoré.
                        $cloturerCaisse->handle($magasin, $hier);
                        $totalClotures++;
                    } catch (LogicException) {
                        // Déjà clôturée manuellement : rien à faire.
                    }
                });
            });

        $this->info("{$totalClotures} clôture(s) automatique(s) effectuée(s) pour le {$hier}.");

        return self::SUCCESS;
    }
}
