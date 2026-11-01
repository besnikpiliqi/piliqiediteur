<?php

namespace App\Domain\Cabinets\Jobs;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Les invitations ouvertes d'autres cabinets pour un SIREN qui vient
 * d'être inscrit (l'acceptation d'une invitation, voir
 * AccepterLInvitationAction) deviennent caduques, chacune dans le contexte
 * de son cabinet et sa propre transaction, après celle de l'acceptation :
 * modifiées dans la transaction de l'acceptation, elles interbloqueraient
 * une acceptation simultanée du même SIREN. Seuls l'identifiant et le
 * cabinet de chacune se lisent hors de la société active ; elle s'inscrit
 * au journal du cabinet au nom du système, sans trace (voir
 * TraceDuVisiteur) : sans file d'attente, le job suit la requête de la
 * personne qui vient d'inscrire ce SIREN, qui n'est pas du cabinet ; son
 * cabinet est prévenu. La commande horaire d'expiration rattrape un job perdu (voir
 * ExpirerInvitationsDesCabinets).
 */
class RendreCaduquesLesInvitationsDuSiren implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $siren,
        public readonly int $acceptee,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        InvitationCabinet::query()->withoutGlobalScopes()
            ->where('siren', $this->siren)
            ->where('etat', EtatInvitation::Ouverte->value)
            ->whereKeyNot($this->acceptee)
            ->orderBy('id')
            ->get(['id', 'societe_id'])
            ->each(fn (InvitationCabinet $invitation) => $this->rendreCaduque((int) $invitation->getKey(), (int) $invitation->societe_id));
    }

    /**
     * Rend cette invitation caduque, relue ouverte sous son verrou, dans
     * le contexte de son cabinet, au journal d'audit du cabinet, et le
     * prévient.
     */
    public function rendreCaduque(int $invitationId, int $cabinetId): void
    {
        $cabinet = Societe::query()->findOrFail($cabinetId);

        $siren = app(TenantContext::class)->runAsSociete($cabinet, fn (): ?string => DB::transaction(function () use ($invitationId, $cabinet): ?string {
            Societe::query()->whereKey($cabinet->getKey())->sharedLock()->firstOrFail();
            $invitation = InvitationCabinet::query()->whereKey($invitationId)->lockForUpdate()->first();

            if ($invitation === null || $invitation->etat !== EtatInvitation::Ouverte) {
                return null;
            }

            $invitation->forceFill(['etat' => EtatInvitation::Caduque, 'close_le' => now()])->save();
            app(TraceDuVisiteur::class)->horsDeSaSociete(fn () => app(JournalAudit::class)->inscrire(ActionAuditee::InvitationCaduque, null, null, null, ['siren' => $invitation->siren, 'raison' => 'siren_pris'], $invitation));

            return (string) $invitation->siren;
        }));

        if ($siren !== null) {
            $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                $cabinet,
                'Invitation caduque',
                "Votre invitation pour le SIREN {$siren} n'est plus valable : cette entreprise est désormais inscrite sur PILIQI.",
                InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
                'Voir les invitations et demandes',
                AvisDuCabinet::ATTENTION,
            ));
        }
    }
}
