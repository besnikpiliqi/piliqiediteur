<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Models\Facture;
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
 * commande planifiée à minuit, heure de Paris (voir bootstrap/app.php,
 * avant le marquage des factures en retard à 00:15), clôture la VEILLE
 * pour chaque magasin qui ne l'a pas déjà fait lui-même -- jamais le
 * jour même, qui n'est pas encore terminé au moment où elle tourne. Un
 * magasin suspendu ou fermé est clôturé comme les autres : ses clôtures
 * continuent jusqu'au bout. Seul un magasin supprimé n'est plus traité.
 *
 * Un ticket Z se produit chaque jour à partir de la première vente du
 * magasin, jamais avant : un magasin qui n'a encore rien vendu n'a aucune
 * journée à clôturer, ne numérote rien, et son code reste modifiable (voir
 * Magasin::codeEstDefinitif()).
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
            ->each(function (Magasin $magasin) use ($tenantContext, $cloturerCaisse, $hier, &$totalClotures) {
                $tenantContext->runAs($magasin, function () use ($cloturerCaisse, $magasin, $hier, &$totalClotures) {
                    if (! $this->aVenduAuPlusTardLe($hier)) {
                        return;
                    }

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

    /**
     * Le magasin du contexte avait-il émis sa première vente (ticket ou
     * facture) au plus tard ce jour-là, à l'heure de Paris ?
     */
    private function aVenduAuPlusTardLe(string $jour): bool
    {
        $premiereVente = Facture::query()->emises()->oldest('date_emission')->first();

        return $premiereVente !== null && $premiereVente->date_emission->toDateString() <= $jour;
    }
}
