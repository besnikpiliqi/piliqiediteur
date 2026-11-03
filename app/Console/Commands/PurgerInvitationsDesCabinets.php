<?php

namespace App\Console\Commands;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Toutes les heures, cabinet par cabinet : efface les invitations closes
 * depuis plus de 48 heures (acceptées, retirées, expirées ou caduques),
 * comme les demandes d'inscription, et les invitations refusées depuis
 * plus de 7 jours, le temps du délai avant une nouvelle invitation du même
 * SIREN. L'acceptation et le refus restent prouvés au journal d'audit, et
 * le lien né d'une invitation reste.
 */
#[Signature('app:purger-invitations-cabinets')]
#[Description('Efface les invitations des cabinets closes depuis 48 heures, refusées depuis 7 jours')]
class PurgerInvitationsDesCabinets extends Command
{
    public const HEURES_APRES_LA_FIN = 48;

    public const JOURS_APRES_UN_REFUS = 7;

    public function handle(): int
    {
        $cabinets = InvitationCabinet::query()->withoutGlobalScopes()->whereNotNull('close_le')->distinct()->orderBy('societe_id')->pluck('societe_id');
        $effacees = 0;

        foreach ($cabinets as $cabinetId) {
            $effacees += app(TenantContext::class)->runAsSociete(Societe::query()->findOrFail($cabinetId), fn (): int => InvitationCabinet::query()
                ->where(fn ($invitations) => $invitations
                    ->where(fn ($closes) => $closes->where('etat', '<>', EtatInvitation::Refusee->value)->where('close_le', '<=', now()->subHours(self::HEURES_APRES_LA_FIN)))
                    ->orWhere(fn ($refusees) => $refusees->where('etat', EtatInvitation::Refusee->value)->where('close_le', '<=', Calendrier::deParis()->decaler(now(), CarbonInterval::days(-self::JOURS_APRES_UN_REFUS)))))
                ->delete());
        }

        $this->info("{$effacees} invitation(s) effacée(s).");

        return self::SUCCESS;
    }
}
