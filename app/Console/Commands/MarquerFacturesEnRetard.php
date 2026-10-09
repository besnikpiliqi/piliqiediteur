<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Commande planifiée (voir bootstrap/app.php) qui passe en retard les
 * factures impayées dont l'échéance est dépassée, magasin par magasin --
 * le global scope MagasinScope exige un TenantContext positionné, une
 * commande n'en a par nature aucun au démarrage -- puis prévient le
 * propriétaire de chaque magasin concerné.
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
                $totalMarquees += $tenantContext->runAs($magasin, fn (): int => $this->marquerLesFacturesDe($magasin));
            });

        $this->info("{$totalMarquees} facture(s) passée(s) au statut en_retard.");

        return self::SUCCESS;
    }

    private function marquerLesFacturesDe(Magasin $magasin): int
    {
        $echues = fn (): Builder => Facture::query()
            ->whereIn('statut', [StatutFacture::Emise, StatutFacture::Envoyee, StatutFacture::PartiellementPayee])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now());

        $numeros = $echues()->orderBy('date_echeance')->orderBy('id')->pluck('numero', 'id');

        if ($numeros->isEmpty()) {
            return 0;
        }

        // update() en masse via le query builder ne passe PAS par les casts
        // Eloquent (contrairement à un forceFill()->save() unitaire) : il
        // faut donc fournir la valeur scalaire de l'enum explicitement. Les
        // mêmes conditions qu'à la lecture : une facture réglée entre-temps
        // n'est pas touchée.
        $marquees = $echues()->whereKey($numeros->keys())->update(['statut' => StatutFacture::EnRetard->value]);

        Notification::send($magasin->proprietaires()->get(), new FacturesPasseesEnRetard($magasin, $numeros->values()->all()));

        return $marquees;
    }
}
