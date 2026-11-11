<?php

namespace App\Console\Commands;

use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Jobs\RendreCaduquesLesInvitationsDuSiren;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Toutes les heures, cabinet par cabinet, sous le verrou de sa ligne : ses
 * invitations ouvertes et périmées (7 jours) expirent ; celles dont le
 * SIREN est désormais inscrit deviennent caduques, son propriétaire
 * prévenu (un job de l'acceptation perdu, un SIREN inscrit par une autre
 * voie). Puis, client par client, chaque demande de lien restée 7 jours
 * sans réponse expire, son jeton effacé, aux deux journaux d'audit (voir
 * RetirerLaDemandeDeLienAction::expirer()). Au nom du système ; chaque
 * requête est filtrée par la société traitée.
 */
#[Signature('app:expirer-invitations-cabinets')]
#[Description('Expire les invitations et les demandes de lien des cabinets restées sans réponse 7 jours')]
class ExpirerInvitationsDesCabinets extends Command
{
    public function handle(JournalAudit $journalAudit, RetirerLaDemandeDeLienAction $demandes): int
    {
        $cabinets = InvitationCabinet::query()->withoutGlobalScopes()->where('etat', EtatInvitation::Ouverte->value)->distinct()->orderBy('societe_id')->pluck('societe_id');

        foreach ($cabinets as $cabinetId) {
            $cabinet = Societe::query()->whereKey($cabinetId)->firstOrFail();
            $caduques = app(TenantContext::class)->runAsSociete($cabinet, fn (): array => DB::transaction(function () use ($cabinet): array {
                Societe::query()->whereKey($cabinet->getKey())->lockForUpdate()->firstOrFail();
                $caduques = [];

                InvitationCabinet::query()->where('etat', EtatInvitation::Ouverte->value)->orderBy('id')->lockForUpdate()->get()
                    ->each(function (InvitationCabinet $invitation) use (&$caduques): void {
                        if ($invitation->expire_le->isPast()) {
                            $invitation->forceFill(['etat' => EtatInvitation::Expiree, 'close_le' => now()])->save();
                        } elseif (Societe::sirenPris((string) $invitation->siren)) {
                            $caduques[] = CleDuModele::de($invitation);
                        }
                    });

                return $caduques;
            }));

            foreach ($caduques as $invitationId) {
                (new RendreCaduquesLesInvitationsDuSiren('', 0))->rendreCaduque($invitationId, CleDuModele::de($cabinet));
            }
        }

        LienCabinet::query()->withoutGlobalScopes()
            ->whereIn('etat', array_map(fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes()))
            ->where('expire_le', '<=', now())
            ->orderBy('id')
            ->get(['id', 'societe_id', 'cabinet_id'])
            ->each(function (LienCabinet $demande) use ($demandes): void {
                try {
                    $demandes->expirer($demande);
                } catch (LogicException) {
                    // Acceptée, refusée ou retirée entre-temps : rien à expirer.
                }
            });

        return self::SUCCESS;
    }
}
