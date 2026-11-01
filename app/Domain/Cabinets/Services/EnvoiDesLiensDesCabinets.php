<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Mail\DemandeDeRattachementMail;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * L'envoi des e-mails d'un cabinet d'expertise comptable à l'adresse que
 * son propriétaire a saisie : l'invitation d'un SIREN libre (voir
 * InvitationDuCabinetMail) et la demande de rattachement d'une société
 * déjà inscrite (voir DemandeDeRattachementMail), chacun avec le lien de
 * son jeton, que seule cette adresse reçoit. Au moment de l'envoi,
 * l'invitation ou la demande est relue : l'e-mail ne part que si elle est
 * encore en cours et que le cabinet est encore vérifié ; sinon il ne part
 * pas, et le propriétaire du cabinet en est prévenu.
 */
class EnvoiDesLiensDesCabinets
{
    public function envoyerLInvitation(int $invitationId, #[SensitiveParameter] string $jeton): void
    {
        $invitation = InvitationCabinet::query()->withoutGlobalScope(SocieteScope::class)->find($invitationId, ['id', 'societe_id', 'siren', 'email', 'etat', 'expire_le']);
        $cabinet = $invitation === null ? null : Societe::query()->find($invitation->societe_id);

        if ($invitation === null || $cabinet === null) {
            return;
        }

        if (! $invitation->estOuverte() || ! $cabinet->estUnCabinetVerifie()) {
            self::prevenirDuNonEnvoi($cabinet, "L'invitation pour le SIREN {$invitation->siren} n'est pas partie : elle n'est plus ouverte, ou votre cabinet n'est plus vérifié.");

            return;
        }

        Mail::to($invitation->email)->send(new InvitationDuCabinetMail(
            route('cabinets.invitation.afficher', ['jeton' => $jeton]),
            $cabinet->getFilamentName(),
            $cabinet->siege_ville,
            (string) $invitation->siren,
            Calendrier::deParis()->afficher($invitation->expire_le, 'd/m/Y à H:i'),
        ));
    }

    public function envoyerLaDemande(int $lienId, string $adresse, #[SensitiveParameter] string $jeton): void
    {
        $lien = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->find($lienId, ['id', 'societe_id', 'cabinet_id', 'etat', 'expire_le']);
        $cabinet = $lien === null ? null : Societe::query()->find($lien->cabinet_id);

        if ($lien === null || $cabinet === null) {
            return;
        }

        $siren = (string) Societe::query()->whereKey($lien->societe_id)->value('siren');

        if ($lien->etat !== EtatDuLien::DemandeParLeCabinet || ! $lien->estUneDemandeValable() || ! $cabinet->estUnCabinetVerifie()) {
            self::prevenirDuNonEnvoi($cabinet, "La demande de rattachement pour le SIREN {$siren} n'est pas partie : elle n'est plus en cours, ou votre cabinet n'est plus vérifié.");

            return;
        }

        Mail::to($adresse)->send(new DemandeDeRattachementMail(
            route('cabinets.rattachement.afficher', ['jeton' => $jeton]),
            $cabinet->getFilamentName(),
            $cabinet->siege_ville,
            $siren,
            Calendrier::deParis()->afficher($lien->expire_le, 'd/m/Y à H:i'),
        ));
    }

    private static function prevenirDuNonEnvoi(Societe $cabinet, string $texte): void
    {
        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'E-mail non envoyé',
            $texte,
            InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir les invitations et demandes',
            AvisDuCabinet::ATTENTION,
        ));
    }
}
