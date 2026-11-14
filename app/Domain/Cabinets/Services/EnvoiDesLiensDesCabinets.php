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
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Integrations\Courriel\CourrielNonParti;
use App\Integrations\Courriel\EnvoiImmediat;
use Closure;
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
 * pas, et le propriétaire du cabinet en est prévenu. Un serveur d'envoi
 * qui refuse l'e-mail ou se tait (dans le délai MAIL_TIMEOUT) le prévient
 * de même, dans sa cloche, et l'écran qui a demandé l'envoi le dit (voir
 * CourrielNonParti), sans erreur 500 : l'invitation ou la demande reste
 * enregistrée, sans son e-mail.
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

        self::envoyerOuPrevenir($cabinet, "L'e-mail de l'invitation pour le SIREN {$invitation->siren}", 'L\'invitation reste enregistrée, sans son e-mail : retirez-la, puis invitez de nouveau dans quelques minutes.', fn () => Mail::to($invitation->email)->send(new InvitationDuCabinetMail(
            route('cabinets.invitation.afficher', ['jeton' => $jeton]),
            $cabinet->getFilamentName(),
            $cabinet->siege_ville,
            (string) $invitation->siren,
            Calendrier::deParis()->afficher($invitation->expire_le, 'd/m/Y à H:i'),
        )));
    }

    public function envoyerLaDemande(int $lienId, string $adresse, #[SensitiveParameter] string $jeton): void
    {
        $lien = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->find($lienId, ['id', 'societe_id', 'cabinet_id', 'etat', 'expire_le']);
        $cabinet = $lien === null ? null : Societe::query()->find($lien->cabinet_id);

        if ($lien === null || $cabinet === null) {
            return;
        }

        $siren = ValeursLues::texte(Societe::query()->whereKey($lien->societe_id)->value('siren'));

        if ($lien->etat !== EtatDuLien::DemandeParLeCabinet || ! $lien->estUneDemandeValable() || ! $cabinet->estUnCabinetVerifie()) {
            self::prevenirDuNonEnvoi($cabinet, "La demande de rattachement pour le SIREN {$siren} n'est pas partie : elle n'est plus en cours, ou votre cabinet n'est plus vérifié.");

            return;
        }

        self::envoyerOuPrevenir($cabinet, "L'e-mail de la demande de rattachement pour le SIREN {$siren}", 'La demande reste enregistrée et s\'affiche dans l\'espace du propriétaire de la société.', fn () => Mail::to($adresse)->send(new DemandeDeRattachementMail(
            route('cabinets.rattachement.afficher', ['jeton' => $jeton]),
            $cabinet->getFilamentName(),
            $cabinet->siege_ville,
            $siren,
            Calendrier::deParis()->afficher(ValeurRequise::de($lien->expire_le, 'l\'échéance de la demande de lien'), 'd/m/Y à H:i'),
        )));
    }

    /**
     * L'e-mail part ; sinon le propriétaire du cabinet en est prévenu dans
     * sa cloche, et CourrielNonParti le dit à l'écran.
     *
     * @param  Closure(): mixed  $envoi
     *
     * @throws CourrielNonParti
     */
    private static function envoyerOuPrevenir(Societe $cabinet, string $quoi, string $suite, Closure $envoi): void
    {
        try {
            EnvoiImmediat::exiger($quoi, $envoi, $suite);
        } catch (CourrielNonParti $refus) {
            self::prevenirDuNonEnvoi($cabinet, $refus->getMessage());

            throw $refus;
        }
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
