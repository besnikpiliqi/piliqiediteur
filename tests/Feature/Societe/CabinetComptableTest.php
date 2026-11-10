<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Mail\InvitationACreerUnEspaceMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La page « Cabinet comptable » de l'espace d'une société et sa page de
 * rattachement : son propriétaire seul y désigne son cabinet, répond aux
 * demandes, règle ce que son cabinet peut faire et met fin au lien.
 */
class CabinetComptableTest extends TestCase
{
    use RefreshDatabase;

    public function test_seul_le_proprietaire_ouvre_la_page_cabinet_comptable_et_la_page_de_rattachement(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $refuses = [$this->administrateur($magasin, null, AutorisationsSociete::toutes()), $this->responsable($magasin), $this->proprietaireDuCabinet($cabinet)];

        foreach ($refuses as $compte) {
            $this->flushSession();
            $this->actingAs($compte)->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
            $this->actingAs($compte)->get(RattachementDUnCabinet::getUrl(['lien' => $demande->uuid], panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        }

        $this->flushSession();
        $this->actingAs($this->proprietaireDe($magasin))->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))->assertOk();
        $this->actingAs($this->proprietaireDe($magasin))->get(RattachementDUnCabinet::getUrl(['lien' => $demande->uuid], panel: 'societe', tenant: $magasin->societe))->assertOk();

        $close = $this->inscrireUneSociete();
        app(CloreLInscriptionAction::class)->parLEditeur($close, new InterventionDeLEditeur('Courrier revenu, pièces insuffisantes.', 'DOSSIER-2026-0070', ['alice']), MotifCloture::Refus);
        $this->flushSession();
        $this->actingAs($close->proprietaire()->sole())->get(CabinetComptable::getUrl(panel: 'societe', tenant: $close))->assertForbidden();
    }

    public function test_la_page_designe_repond_regle_et_met_fin_au_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->demanderLeRattachement($autreCabinet, $magasin->societe, 'compta@cliente.test');
        $demandeRecue = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(CabinetComptable::class)
            ->assertSee(RattachementDUnCabinet::getUrl(['lien' => $demandeRecue->uuid], panel: 'societe', tenant: $magasin->societe))
            ->set('recherche', (string) $cabinet->siren)
            ->assertSeeText((string) $cabinet->raison_sociale)
            ->callAction('demanderLeLien', arguments: ['uuid' => $cabinet->uuid]);
        $designation = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->sole();
        $this->assertSame(EtatDuLien::DemandeParLeClient, $designation->etat);

        app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $designation);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(CabinetComptable::class)
            ->set('data.consultations', [PermissionRegistry::FACTURE_VIEW])
            ->set('data.achats', [PermissionRegistry::FACTURE_ACHAT_CREER])
            ->callAction('enregistrerLesAutorisations');
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER], $designation->fresh()->autorisations());

        Livewire::test(CabinetComptable::class)->callAction('mettreFin');
        $this->assertSame(EtatDuLien::Termine, $designation->fresh()->etat);
        $this->assertNotNull($page);
    }

    /**
     * Depuis la page, le propriétaire retire la demande de lien qu'il a
     * adressée à un cabinet, puis invite son expert-comptable, qui n'a pas
     * encore d'espace, à en créer un : chaque geste le dit.
     */
    public function test_la_page_retire_une_demande_de_lien_et_invite_l_expert_a_creer_son_espace(): void
    {
        Mail::fake();
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)
            ->callAction('retirerLaDemande', arguments: ['uuid' => $designation->uuid])
            ->assertNotified('Votre demande est retirée.')
            ->callAction('inviterSonExpert', ['email' => 'expert@cabinet.test'])
            ->assertHasNoActionErrors()
            ->assertNotified('L\'invitation est envoyée.');

        $this->assertSame(EtatDuLien::Retire, $designation->fresh()->etat);
        Mail::assertSent(InvitationACreerUnEspaceMail::class, fn (InvitationACreerUnEspaceMail $mail): bool => $mail->hasTo('expert@cabinet.test'));
    }

    public function test_la_page_ne_propose_que_les_autorisations_du_cabinet_en_deux_groupes(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(CabinetComptable::class)->assertFormFieldIsVisible('consultations')->assertFormFieldIsVisible('achats')->assertSeeText(['Consulter', 'Achats et stock', CabinetComptable::TEXTE_JAMAIS]);
        foreach (PermissionRegistry::toutes() as $autorisation) {
            in_array($autorisation, PermissionRegistry::duCabinet(TypeDActivite::Commerce), true)
                ? $page->assertSeeHtml('value="'.$autorisation.'"')
                : $page->assertDontSeeHtml('value="'.$autorisation.'"');
        }
    }

    public function test_une_requete_forgee_ne_designe_pas_une_societe_qui_n_est_pas_un_cabinet_ou_un_cabinet_non_verifie(): void
    {
        $nonVerifie = $this->ouvrirUnCabinet();
        $nonVerifie->cabinet()->sole()->forceFill(['ordre_retire_le' => now()])->save();
        $boutique = $this->ouvrirUneSociete(['nom' => 'Autre boutique'])->societe;
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        foreach ([$nonVerifie, $boutique] as $cible) {
            Livewire::test(CabinetComptable::class)->set('recherche', (string) $cible->siren)->callAction('demanderLeLien', arguments: ['uuid' => $cible->uuid])->assertNotified();
        }

        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());
    }

    public function test_la_page_cabinet_comptable_d_une_societe_de_services_n_a_que_le_groupe_consulter(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services]);
        $this->lierLeCabinet($cabinet, $magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)->assertFormFieldIsVisible('consultations')->assertFormFieldIsHidden('achats')->assertDontSeeText('Achats et stock');
    }
}
