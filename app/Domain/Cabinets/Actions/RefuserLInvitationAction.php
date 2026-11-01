<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * La personne qui a reçu le lien d'une invitation la refuse, sans compte
 * ni mot de passe : dans l'ordre des verrous de l'acceptation (la ligne du
 * cabinet, puis l'invitation), l'invitation encore ouverte passe à
 * « refusée », au journal d'audit du cabinet, au nom du système ; son
 * propriétaire est prévenu. Une invitation absente, close ou périmée donne
 * le message neutre de l'acceptation, sans rien écrire. Le cabinet
 * n'invite de nouveau ce SIREN qu'après 7 jours.
 */
class RefuserLInvitationAction
{
    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * @throws InvitationInvalideException
     */
    public function handle(#[SensitiveParameter] string $jeton): void
    {
        $invitation = InvitationCabinet::parSonJeton($jeton) ?? throw new InvitationInvalideException;

        if (! $invitation->estOuverte()) {
            throw new InvitationInvalideException;
        }

        $cabinet = Societe::query()->findOrFail($invitation->societe_id);

        app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($invitation, $cabinet): void {
            Societe::query()->whereKey($cabinet->getKey())->sharedLock()->firstOrFail();
            $relue = InvitationCabinet::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($relue === null || ! $relue->estOuverte()) {
                throw new InvitationInvalideException;
            }

            $relue->forceFill(['etat' => EtatInvitation::Refusee, 'close_le' => now()])->save();
            $this->journalAudit->inscrire(ActionAuditee::InvitationRefusee, null, null, null, ['siren' => $relue->siren], $relue);
        }));

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'Invitation refusée',
            "L'invitation pour le SIREN {$invitation->siren} a été refusée.",
            InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir les invitations et demandes',
            AvisDuCabinet::INFORMATION,
            parCourriel: true,
        ));
    }
}
