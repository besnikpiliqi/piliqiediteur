<?php

use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Http\Controllers\AccepterLInvitationDuCabinetController;
use Database\Factories\SocieteFactory;

/*
 * Un cabinet d'expertise comptable suspendu (décision de l'éditeur du
 * 11/11/2026) : son propriétaire retire encore ses invitations et ses
 * demandes de lien ouvertes ; le propriétaire d'une société lit que la
 * demande de rattachement de ce cabinet ne s'accepte pas tant qu'il l'est,
 * sans bouton pour l'accepter, et la refuse ; la personne qui a reçu son
 * invitation lit de même qu'elle ne peut que la refuser.
 */

test('le propriétaire d’un cabinet suspendu retire son invitation encore ouverte', function () {
    $cabinet = $this->ouvrirUnCabinet();
    $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
    $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

    $this->seConnecter($this->proprietaireDuCabinet($cabinet), panneau: 'cabinet')
        ->aller('/cabinet/'.$cabinet->uuid.'/invitations-et-demandes')
        ->voir('dirigeant@entreprise.test')
        ->cliquerSur('Retirer')
        ->soumettre('Confirmer');

    expect(InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat)->toBe(EtatInvitation::Retiree);
});

test('le propriétaire d’une société lit que la demande d’un cabinet suspendu ne s’accepte pas, et la refuse', function () {
    $cabinet = $this->ouvrirUnCabinet();
    $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
    $this->demanderLeRattachement($cabinet, $client, 'dirigeant@cliente.test');
    $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
    $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

    $this->seConnecter($client->proprietaire()->firstOrFail(), 'password', panneau: 'societe')
        ->aller('/societe/'.$client->uuid.'/rattachement-cabinet/'.$demande->uuid)
        ->voir(AccepterLaDemandeDeLienAction::MESSAGE_CABINET_SUSPENDU)
        ->nePasVoir('Accepter avec les autorisations par défaut')
        ->nePasVoir('Accepter en ajustant les autorisations')
        ->cliquerSur('Refuser')
        ->soumettre('Confirmer');

    expect($demande->fresh()->etat)->toBe(EtatDuLien::Refuse);
});

test('le propriétaire d’un cabinet suspendu retire sa demande de lien encore ouverte', function () {
    $cabinet = $this->ouvrirUnCabinet();
    $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
    $this->demanderLeRattachement($cabinet, $client, 'dirigeant@cliente.test');
    $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
    $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

    $this->seConnecter($this->proprietaireDuCabinet($cabinet), panneau: 'cabinet')
        ->aller('/cabinet/'.$cabinet->uuid.'/invitations-et-demandes')
        ->voir((string) $client->siren)
        ->cliquerSur('Retirer')
        ->soumettre('Confirmer');

    expect($demande->fresh()->etat)->toBe(EtatDuLien::Retire);
});

test('la personne invitée par un cabinet suspendu lit qu’elle ne peut que refuser l’invitation, et la refuse', function () {
    $cabinet = $this->ouvrirUnCabinet();
    $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
    $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();
    // La page d'une invitation qui ne s'accepte pas répond 422, avec son
    // seul motif et le bouton qui la refuse.
    $this->tolerer(422, '/invitation-cabinet/');

    $onglet = $this->naviguer('/invitation-cabinet/'.$jeton)
        ->voir(AccepterLInvitationAction::MESSAGE_CABINET_SUSPENDU)
        ->nePasVoir('Accepter l\'invitation');
    // La personne répond « OK » à la question du navigateur qui confirme le
    // refus.
    $onglet->page()->evaluate('() => { window.confirm = () => true; }');
    $onglet->soumettre('Refuser l\'invitation')
        ->voir(AccepterLInvitationDuCabinetController::MESSAGE_REFUSEE);

    expect(InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat)->toBe(EtatInvitation::Refusee);
});
