<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
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
 * propriétaire de la société de chaque magasin concerné. Un magasin en
 * lecture seule est traité comme les autres : les statuts de ses factures
 * continuent de suivre leurs échéances.
 */
#[Signature('app:marquer-factures-en-retard')]
#[Description("Passe au statut 'en_retard' les factures impayées dont l'échéance est dépassée")]
class MarquerFacturesEnRetard extends Command
{
    public function handle(TenantContext $tenantContext): int
    {
        $totalMarquees = 0;

        Magasin::query()
            ->each(function (Magasin $magasin) use ($tenantContext, &$totalMarquees) {
                $totalMarquees += $tenantContext->runAs($magasin, fn (): int => $this->marquerLesFacturesDe($magasin));
            });

        $this->info("{$totalMarquees} facture(s) passée(s) au statut en_retard.");

        return self::SUCCESS;
    }

    /**
     * Chaque facture échue passe en retard par sa propre mise à jour,
     * sous les mêmes conditions qu'à la lecture, et seules celles qu'elle a
     * réellement passées en retard sont annoncées au propriétaire et
     * comptées : une facture réglée entre-temps n'est ni touchée ni
     * annoncée. Sous PostgreSQL, une mise à jour qui trouve la facture
     * verrouillée par un paiement, une contre-passation ou l'imputation
     * d'un avoir attend qu'il soit validé, puis relit ses conditions sur la
     * facture qu'il a laissée. Chacune ne verrouille qu'une facture, le
     * temps de sa mise à jour : aucune n'attend en tenant une autre facture,
     * quel que soit l'ordre dans lequel un paiement de plusieurs factures
     * les verrouille. Les factures sont celles du magasin actif (voir
     * MagasinScope), à la lecture comme à chaque mise à jour.
     */
    private function marquerLesFacturesDe(Magasin $magasin): int
    {
        $aujourdhui = Calendrier::deParis()->aujourdhui();
        $echues = fn (): Builder => Facture::query()
            ->whereIn('statut', [StatutFacture::Emise, StatutFacture::Envoyee, StatutFacture::PartiellementPayee])
            ->where('date_echeance', '<', $aujourdhui);

        $marquees = $echues()->orderBy('date_echeance')->orderBy('id')->pluck('numero', 'id')
            ->filter(fn (string $numero, int $id): bool => $echues()->whereKey($id)->update(['statut' => StatutFacture::EnRetard]) === 1);

        if ($marquees->isNotEmpty()) {
            Notification::send($magasin->societe->proprietaire, new FacturesPasseesEnRetard($magasin, $marquees->values()->all()));
        }

        return $marquees->count();
    }
}
