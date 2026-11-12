<?php

use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutSociete;
use Database\Factories\SocieteFactory;

/*
 * Un cabinet d'expertise comptable suspendu (décision de l'éditeur du
 * 11/11/2026) : son propriétaire retire encore ses invitations ouvertes ;
 * le propriétaire d'une société lit que la demande de rattachement de ce
 * cabinet ne s'accepte pas tant qu'il l'est, et la refuse.
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
        ->cliquerSur('Refuser')
        ->soumettre('Confirmer');

    expect($demande->fresh()->etat)->toBe(EtatDuLien::Refuse);
});
