<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Exceptions\CabinetNonVerifieException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Http\Controllers\AccepterLInvitationDuCabinetController;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un cabinet d'expertise comptable suspendu (décision de l'éditeur du
 * 11/11/2026) : ses liens restent bloqués ; aucun client n'accepte son
 * invitation ni sa demande de lien, ni à l'écran ni par une requête
 * forgée, et chacun lit pourquoi ; le client peut toujours la refuser ; le
 * cabinet ne fait rien d'autre que retirer ses invitations et ses demandes
 * ouvertes (voir InvitationsTest) et mettre fin à ses liens.
 */
class CabinetSuspenduTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La page de l'invitation d'un cabinet suspendu dit qu'elle ne
     * s'accepte pas tant qu'il l'est, sans le formulaire ; une acceptation
     * envoyée quand même ne crée rien ; la personne invitée refuse
     * l'invitation.
     */
    public function test_l_invitation_d_un_cabinet_suspendu_ne_s_accepte_pas_mais_se_refuse(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $societes = Societe::query()->count();

        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))
            ->assertStatus(422)
            ->assertSeeText(AccepterLInvitationAction::MESSAGE_CABINET_SUSPENDU)
            ->assertDontSee('name="password"', false)
            ->assertSee(route('cabinets.invitation.refuser', ['jeton' => $jeton]), false);
        $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), [
            'password' => self::MOT_DE_PASSE_CHOISI,
            'password_confirmation' => self::MOT_DE_PASSE_CHOISI,
            'type_activite' => TypeDActivite::Commerce->value,
            'choix_definitif' => '1',
        ])->assertSeeText(AccepterLInvitationAction::MESSAGE_CABINET_SUSPENDU);
        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_CABINET_SUSPENDU);
        $this->assertSame($societes, Societe::query()->count());
        $this->assertGuest();

        $this->post(route('cabinets.invitation.refuser', ['jeton' => $jeton]))->assertOk()->assertSeeText(AccepterLInvitationDuCabinetController::MESSAGE_REFUSEE);
        $this->assertSame(EtatInvitation::Refusee, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    /**
     * La page de rattachement de la demande d'un cabinet suspendu dit
     * qu'elle ne s'accepte pas tant qu'il l'est et n'offre que le refus ;
     * l'acceptation forgée est refusée avec le même texte ; le propriétaire
     * du client refuse la demande.
     */
    public function test_la_demande_de_lien_d_un_cabinet_suspendu_ne_s_accepte_pas_mais_se_refuse(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $proprietaire = $client->proprietaire()->firstOrFail();
        $this->demanderLeRattachement($cabinet, $client, 'dirigeant@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaire, $demande), LogicException::class, AccepterLaDemandeDeLienAction::MESSAGE_CABINET_SUSPENDU);
        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaire, $demande, []), LogicException::class, AccepterLaDemandeDeLienAction::MESSAGE_CABINET_SUSPENDU);
        $this->assertSame(EtatDuLien::DemandeParLeCabinet, $demande->fresh()->etat);

        $this->dansLEspaceDeLaSociete($client, $proprietaire);
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demande->uuid])
            ->assertSeeText(AccepterLaDemandeDeLienAction::MESSAGE_CABINET_SUSPENDU)
            ->assertActionHidden('accepterParDefaut')
            ->assertActionHidden('accepterEnAjustant')
            ->assertActionVisible('refuser')
            ->callAction('refuser');

        $this->assertSame(EtatDuLien::Refuse, $demande->fresh()->etat);
    }

    /**
     * Un cabinet suspendu n'invite plus, n'accepte plus la demande d'un
     * client et ne se lie à personne : ses liens restent bloqués.
     */
    public function test_un_cabinet_suspendu_n_invite_ni_n_accepte_aucune_demande(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $designation = app(DesignerUnCabinetAction::class)->handle($client->proprietaire()->firstOrFail(), $client, $cabinet);
        app(TenantContext::class)->clear();
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($proprietaireDuCabinet, $cabinet->fresh(), SocieteFactory::sirenFictif(), 'dirigeant@autre.test', self::MOT_DE_PASSE_CHOISI), CabinetNonVerifieException::class);
        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaireDuCabinet, $designation), LogicException::class, AccepterLaDemandeDeLienAction::MESSAGE_CABINET_NON_LIABLE);

        $this->assertSame(EtatDuLien::DemandeParLeClient, $designation->fresh()->etat);
        $this->assertSame(0, InvitationCabinet::query()->withoutGlobalScopes()->count());
    }
}
