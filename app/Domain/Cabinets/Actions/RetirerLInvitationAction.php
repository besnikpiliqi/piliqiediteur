<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le propriétaire d'un cabinet retire une invitation encore ouverte (voir
 * InvitationCabinetPolicy::retirer()) : son lien ne vaut plus rien.
 * Relue sous le verrou de la ligne du cabinet puis de l'invitation ; une
 * invitation déjà close ne change pas, et le geste le dit. L'éditeur
 * retire de même, sans auteur, les invitations ouvertes d'un cabinet qu'il
 * révoque (voir parLaRevocationDuCabinet()).
 */
class RetirerLInvitationAction
{
    public const MESSAGE_PLUS_OUVERTE = 'Cette invitation n\'est plus ouverte.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, InvitationCabinet $invitation): void
    {
        $cabinet = Societe::query()->findOrFail($invitation->societe_id);

        app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($auteur, $invitation, $cabinet): void {
            Societe::query()->whereKey($cabinet->getKey())->lockForUpdate()->firstOrFail();
            $relue = InvitationCabinet::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $auteurRelu = $this->journalDesDroits->exigerDansSaSociete($auteur, 'retirer', $relue);

            if ($relue->etat !== EtatInvitation::Ouverte) {
                throw new LogicException(self::MESSAGE_PLUS_OUVERTE);
            }

            $relue->forceFill(['etat' => EtatInvitation::Retiree, 'close_le' => now(), 'retiree_par' => $auteurRelu->getKey()])->save();
            $this->journalAudit->inscrire(ActionAuditee::InvitationRetiree, $auteurRelu, null, null, ['siren' => $relue->siren], $relue);
        }));
    }

    /**
     * Le retrait d'une invitation ouverte d'un cabinet que l'éditeur révoque
     * (voir RevoquerSocieteAction), dans sa propre transaction, après la
     * révocation, sans auteur, avec l'intervention de l'éditeur ; une
     * invitation close entre-temps ne change pas.
     */
    public function parLaRevocationDuCabinet(InvitationCabinet $invitation, InterventionDeLEditeur $editeur): void
    {
        $cabinet = Societe::query()->findOrFail($invitation->societe_id);

        app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($invitation, $cabinet, $editeur): void {
            Societe::query()->whereKey($cabinet->getKey())->lockForUpdate()->firstOrFail();
            $relue = InvitationCabinet::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ($relue->etat !== EtatInvitation::Ouverte) {
                return;
            }

            $relue->forceFill(['etat' => EtatInvitation::Retiree, 'close_le' => now()])->save();
            $this->journalAudit->inscrire(ActionAuditee::InvitationRetiree, null, null, null, ['siren' => $relue->siren], $relue, $editeur);
        }));
    }
}
