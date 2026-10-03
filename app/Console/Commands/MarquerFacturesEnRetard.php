<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * StatutFacture::EnRetard existait depuis la Phase 3 mais n'était atteint
 * par aucun code : une facture en retard restait affichée "émise" ou
 * "partiellement_payee" indéfiniment. Cette commande planifiée (voir
 * bootstrap/app.php) ferme ce trou en parcourant chaque magasin -- le
 * global scope MagasinScope exige un TenantContext positionné, une
 * commande n'en a par nature aucun au démarrage.
 */
#[Signature('app:marquer-factures-en-retard')]
#[Description("Passe au statut 'en_retard' les factures impayées dont l'échéance est dépassée")]
class MarquerFacturesEnRetard extends Command
{
    public function handle(TenantContext $tenantContext): int
    {
        $totalMarquees = 0;

        Magasin::query()
            ->where('statut', StatutMagasin::Active)
            ->each(function (Magasin $magasin) use ($tenantContext, &$totalMarquees) {
                $totalMarquees += $tenantContext->runAs($magasin, function () {
                    // update() en masse via le query builder ne passe PAS
                    // par les casts Eloquent (contrairement à un
                    // forceFill()->save() unitaire) : il faut donc fournir
                    // la valeur scalaire de l'enum explicitement.
                    return Facture::query()
                        ->whereIn('statut', [StatutFacture::Emise, StatutFacture::Envoyee, StatutFacture::PartiellementPayee])
                        ->whereNotNull('date_echeance')
                        ->whereDate('date_echeance', '<', now())
                        ->update(['statut' => StatutFacture::EnRetard->value]);
                });
            });

        $this->info("{$totalMarquees} facture(s) passée(s) au statut en_retard.");

        return self::SUCCESS;
    }
}
