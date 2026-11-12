<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;

/**
 * Commande planifiée toutes les heures (voir bootstrap/app.php) qui
 * relance chaque facture dont la dernière transmission à la plateforme
 * agréée est en échec technique (voir SubmitFactureToPdpJob::failed()),
 * magasin par magasin : une commande n'a aucun TenantContext au démarrage,
 * et chaque lecture filtre en plus explicitement par le magasin. La
 * relance reprend la même transmission, sous la même référence de dépôt,
 * et ne dépose jamais deux fois la même facture ; un magasin en lecture
 * seule est traité comme les autres : la transmission des factures déjà
 * émises continue jusqu'au bout.
 */
#[Signature('app:relancer-transmissions-pdp')]
#[Description('Relance les transmissions à la plateforme agréée restées en échec technique')]
class RelancerTransmissionsPdp extends Command
{
    public function handle(TenantContext $tenantContext): int
    {
        $relancees = 0;

        Magasin::query()->with('societe')->each(function (Magasin $magasin) use ($tenantContext, &$relancees): void {
            foreach ($tenantContext->runAs($magasin, fn (): array => self::facturesEnEchecDe($magasin)) as $factureId) {
                SubmitFactureToPdpJob::dispatch($magasin->getKey(), $factureId);
                $relancees++;
            }
        });

        $this->info("{$relancees} transmission(s) relancée(s).");

        return self::SUCCESS;
    }

    /**
     * Les factures du magasin dont la dernière transmission sortante est en
     * échec technique.
     *
     * @return list<int>
     */
    private static function facturesEnEchecDe(Magasin $magasin): array
    {
        return ValeursLues::entiers(TransmissionPdp::query()
            ->where('magasin_id', $magasin->getKey())
            ->where('direction', 'outbound')
            ->where('transmissible_type', (new Facture)->getMorphClass())
            ->where('statut', StatutTransmissionPdp::EchecTechnique->value)
            ->whereNotExists(fn (Builder $plusRecentes) => $plusRecentes
                ->selectRaw('1')
                ->from('transmissions_pdp as plus_recentes')
                ->where('plus_recentes.magasin_id', $magasin->getKey())
                ->where('plus_recentes.direction', 'outbound')
                ->whereColumn('plus_recentes.transmissible_type', 'transmissions_pdp.transmissible_type')
                ->whereColumn('plus_recentes.transmissible_id', 'transmissions_pdp.transmissible_id')
                ->whereColumn('plus_recentes.id', '>', 'transmissions_pdp.id'))
            ->orderBy('id')
            ->pluck('transmissible_id'));
    }
}
