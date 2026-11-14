<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Mail\DemandeDeRattachementMail;
use App\Domain\Cabinets\Mail\InvitationACreerUnEspaceMail;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        $refuses = [$this->administrateur($magasin, null, AutorisationsSociete::toutes()), $this->responsable($magasin), $this->proprietaireDuCabinet($cabinet), $this->declarerSonExpertComptable($magasin, siren: '000000927')];

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
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_ACHAT_CREER], $designation->fresh()->autorisations());

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
            in_array($autorisation, PermissionRegistry::casesDuCabinet(PermissionRegistry::duCabinet(TypeDActivite::Commerce)), true)
                ? $page->assertSeeHtml('value="'.$autorisation.'"')
                : $page->assertDontSeeHtml('value="'.$autorisation.'"');
        }
    }

    /**
     * Sur « Cabinet comptable », le propriétaire choisit les magasins que
     * voit son cabinet : tous, présents et futurs, d'un clic, ou une
     * sélection cochée magasin par magasin ; une sélection vide est refusée
     * sans rien changer ; « Accepter en ajustant » une demande de
     * rattachement offre le même choix (décision de l'éditeur du
     * 11/11/2026).
     */
    public function test_le_proprietaire_choisit_les_magasins_que_voit_son_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $lien = $this->lierLeCabinet($cabinet, $lyon1);
        app(TenantContext::class)->clear();
        $this->dansLEspaceDeLaSociete($lyon1->societe, $this->proprietaireDe($lyon1));

        $page = Livewire::test(CabinetComptable::class)
            ->assertSeeText(['Magasins que voit votre cabinet', 'Tous les magasins, présents et futurs'])
            ->assertFormFieldIsHidden('magasins')
            ->set('data.portee', CabinetComptable::SELECTION_DE_MAGASINS)
            ->assertFormFieldIsVisible('magasins')
            ->assertSeeText(['Lyon 1', 'Lyon 2'])
            ->set('data.magasins', [])
            ->callAction('enregistrerLesAutorisations')
            ->assertNotified(LienCabinet::MESSAGE_SELECTION_VIDE);
        $this->assertTrue($lien->fresh()->tous_les_magasins);

        $page->set('data.magasins', [(string) $lyon2->id])->callAction('enregistrerLesAutorisations');
        $this->assertSame([false, [$lyon2->id]], [$lien->fresh()->tous_les_magasins, $lien->fresh()->magasinsChoisis()]);
        Livewire::test(CabinetComptable::class)->assertSet('data.portee', CabinetComptable::SELECTION_DE_MAGASINS)->assertSet('data.magasins', [(string) $lyon2->id])
            ->set('data.portee', CabinetComptable::TOUS_LES_MAGASINS)->callAction('enregistrerLesAutorisations');
        $this->assertSame([true, []], [$lien->fresh()->tous_les_magasins, $lien->fresh()->magasinsChoisis()]);

        $autre = $this->ouvrirUneSociete(['nom' => 'Autre 1']);
        $autre2 = $this->ouvrirUnAutreMagasin($autre, ['nom' => 'Autre 2']);
        $this->demanderLeRattachement($cabinet, $autre->societe, 'dirigeant@autre.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->societe_id)->sole();
        app(TenantContext::class)->clear();
        $this->dansLEspaceDeLaSociete($autre->societe, $this->proprietaireDe($autre));
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demande->uuid])
            ->callAction('accepterEnAjustant', ['portee' => CabinetComptable::SELECTION_DE_MAGASINS, 'magasins' => [(string) $autre2->id]]);
        $this->assertSame([false, [$autre2->id]], [$demande->fresh()->tous_les_magasins, $demande->fresh()->magasinsChoisis()]);
    }

    /**
     * Pour un cabinet lié, les factures et les clôtures de caisse n'ont
     * qu'une case chacune, « voir toutes celles du magasin », sur la page
     * « Cabinet comptable », dans « Accepter en ajustant », sur la page et
     * dans l'e-mail d'une demande de rattachement ou d'une invitation :
     * jamais « (les siennes) » ni « celles de toute l'équipe » (décision de
     * l'éditeur du 01/11/2026). Cocher la case accorde les deux
     * autorisations de sa paire, la décocher les retire.
     */
    public function test_les_factures_et_les_clotures_n_ont_qu_une_case_pour_le_cabinet_qui_accorde_ou_retire_leur_paire(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique'])->societe;
        $this->demanderLeRattachement($cabinet, $autre, 'dirigeant@autre.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->id)->sole();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@libre.test');
        $cases = ['Factures : voir toutes celles du magasin', 'Clôtures de caisse : voir toutes celles du magasin'];
        $absents = ['(les siennes)', 'celles de toute l\'équipe'];

        Mail::sent(DemandeDeRattachementMail::class)->last()->assertSeeInText($cases[0])->assertSeeInText($cases[1])->assertDontSeeInText($absents[0])->assertDontSeeInText($absents[1]);
        Mail::sent(InvitationDuCabinetMail::class)->last()->assertSeeInText($cases[0])->assertDontSeeInText($absents[0])->assertDontSeeInText($absents[1]);
        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk()->assertSeeText($cases)->assertDontSeeText($absents);

        $this->dansLEspaceDeLaSociete($autre, $autre->proprietaire()->firstOrFail());
        Livewire::test(RattachementDUnCabinet::class, ['lien' => $demande->uuid])
            ->mountAction('accepterEnAjustant')
            ->assertSeeText($cases)->assertDontSeeText($absents)
            ->assertSeeHtml('value="'.PermissionRegistry::FACTURE_VIEW.'"')
            ->assertDontSeeHtml('value="'.PermissionRegistry::FACTURE_VIEW_TOUTES.'"');

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $page = Livewire::test(CabinetComptable::class)
            ->assertSeeText($cases)->assertDontSeeText($absents)
            ->assertDontSeeHtml('value="'.PermissionRegistry::FACTURE_VIEW_TOUTES.'"')
            ->assertDontSeeHtml('value="'.PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES.'"');
        $this->assertContains(PermissionRegistry::FACTURE_VIEW, $page->get('data.consultations'));
        $this->assertNotContains(PermissionRegistry::FACTURE_VIEW_TOUTES, $page->get('data.consultations'));

        $page->set('data.consultations', [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::CLOTURE_CAISSE_VIEW])->callAction('enregistrerLesAutorisations');
        $this->assertSame([PermissionRegistry::CLIENT_VIEW, PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES], $lien->fresh()->autorisations());
        $page->set('data.consultations', [PermissionRegistry::FACTURE_VIEW])->callAction('enregistrerLesAutorisations');
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], $lien->fresh()->autorisations());
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

    public function test_le_proprietaire_declare_son_expert_comptable_depuis_la_page_et_lit_son_identifiant(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        Mail::fake();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)
            ->assertSeeText(CabinetComptable::TEXTE_DE_LA_DECLARATION)
            ->mountAction('declarer')
            ->setActionData([
                'siren' => FauxRepertoireSirene::SIREN_CABINET,
                'name' => 'Marc Petit',
                'email' => 'marc.petit@petit-associes.test',
                'email_confirmation' => 'autre@petit-associes.test',
                'mot_de_passe' => 'password',
            ])
            ->callMountedAction()
            ->assertNotified('Les deux adresses ne sont pas les mêmes.')
            ->assertActionMounted('declarer')
            ->setActionData(['email_confirmation' => 'marc.petit@petit-associes.test'])
            ->callMountedAction()
            ->assertActionMounted(['declarer', 'confirmerLaDeclaration'])
            ->assertSeeHtml('<strong>marc.petit@petit-associes.test</strong>')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $marc = User::query()->where('role_societe', 'expert_comptable')->sole();
        $this->assertSame('Marc Petit', $marc->name);
        Livewire::test(CabinetComptable::class)
            ->assertSeeText(['Votre expert-comptable (sans espace', 'CABINET PETIT ET ASSOCIES', 'Marc Petit', (string) $marc->identifiantDeConnexion(), 'Lien valable jusqu\'au 12/11/2026 à 10:00', CabinetComptable::TEXTE_EN_ATTENTE])
            ->assertActionVisible('ajouterUnePersonne')
            ->assertActionHasLabel('mettreFin', 'Retirer votre expert-comptable');
    }

    public function test_une_societe_qui_attend_son_code_lit_pourquoi_elle_ne_declare_pas_et_peut_designer_ou_inviter(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);

        Livewire::test(CabinetComptable::class)
            ->assertSeeText(DeclarerSonExpertComptableAction::MESSAGE_SOCIETE_NON_VALIDEE)
            ->assertActionHidden('declarer')
            ->assertActionVisible('inviterSonExpert')
            ->assertSeeText('Désigner votre cabinet');
    }

    public function test_le_proprietaire_ajoute_une_personne_et_retire_son_expert_comptable_depuis_la_page(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)
            ->callAction('ajouterUnePersonne', ['name' => 'Sophie Lenoir', 'email' => 'sophie.lenoir@petit-associes.test', 'email_confirmation' => 'sophie.lenoir@petit-associes.test', 'mot_de_passe' => 'password'])
            ->assertActionMounted(['ajouterUnePersonne', 'confirmerLAjout'])
            ->assertSeeHtml('<strong>sophie.lenoir@petit-associes.test</strong>')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();
        $sophie = User::query()->where('name', 'Sophie Lenoir')->sole();
        $this->assertSame('expert_comptable', $sophie->role_societe->value);

        Livewire::test(CabinetComptable::class)
            ->assertSeeText(['Marc Petit', 'Sophie Lenoir'])
            ->callAction('mettreFin')
            ->assertNotified('Votre expert-comptable n\'est plus déclaré : ses comptes sont retirés.');

        $this->assertSame([StatutCompte::Retire, StatutCompte::Retire], [$marc->fresh()->statut, $sophie->fresh()->statut]);
        Livewire::test(CabinetComptable::class)->assertDontSeeText('Votre expert-comptable (sans espace')->assertActionHasLabel('declarer', 'Déclarer votre expert-comptable');
    }

    /**
     * La déclaration de l'expert-comptable de la société se règle par les
     * deux groupes du cabinet lié : « Consulter », précoché, et « Achats et
     * stock » ; jamais une autorisation de vente, de compte, de réglage ni
     * de caisse (décision de l'éditeur du 01/11/2026).
     */
    public function test_la_page_declare_un_expert_comptable_avec_ses_autorisations_en_deux_groupes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $cases = PermissionRegistry::casesDuCabinet(PermissionRegistry::duCabinet(TypeDActivite::Commerce));

        $page = Livewire::test(CabinetComptable::class)->mountAction('declarer')->assertSeeText(['Consulter', 'Achats et stock', CabinetComptable::TEXTE_JAMAIS]);
        foreach (PermissionRegistry::toutes() as $autorisation) {
            in_array($autorisation, $cases, true)
                ? $page->assertSeeHtml('value="'.$autorisation.'"')
                : $page->assertDontSeeHtml('value="'.$autorisation.'"');
        }

        $consultations = array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), [PermissionRegistry::DEVIS_VIEW]));
        $page->setActionData([
            'siren' => FauxRepertoireSirene::SIREN_CABINET,
            'name' => 'Marc Petit',
            'email' => 'marc.petit@petit-associes.test',
            'email_confirmation' => 'marc.petit@petit-associes.test',
            'consultations' => $consultations,
            'achats' => [PermissionRegistry::FACTURE_ACHAT_CREER],
            'mot_de_passe' => 'password',
        ])->callMountedAction()->assertActionMounted(['declarer', 'confirmerLaDeclaration'])->callMountedAction()->assertHasNoActionErrors();

        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        $accordees = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $declaration->autorisations());
        $this->assertContains(PermissionRegistry::FACTURE_ACHAT_CREER, $accordees);
        $this->assertNotContains(PermissionRegistry::DEVIS_VIEW, $accordees);
        $this->assertSame([], array_values(array_diff($accordees, PermissionRegistry::duCabinet(TypeDActivite::Commerce))));
    }

    /**
     * La page, chargée par une vraie requête, montre l'expert-comptable
     * déclaré, chacune de ses personnes avec son identifiant et l'état de
     * son lien : valable jusqu'à son échéance, ou son mot de passe choisi.
     */
    public function test_la_page_montre_l_expert_comptable_declare_ses_personnes_et_l_etat_de_leurs_liens(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $marc = $this->expertComptable($magasin);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');

        $this->actingAs($this->proprietaireDe($magasin))
            ->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))
            ->assertOk()
            ->assertSeeText(['Votre expert-comptable (sans espace', 'CABINET PETIT ET ASSOCIES', 'Marc Petit', (string) $marc->identifiantDeConnexion(), 'Sophie Lenoir', (string) $sophie->identifiantDeConnexion(), 'Lien valable jusqu\'au 12/11/2026 à 10:00', CabinetComptable::TEXTE_EN_ATTENTE]);
    }

    /**
     * Une requête Livewire forgée ne déclare ni n'ajoute rien pour une autre
     * société : la page ne sert que la société de son espace, et un
     * propriétaire n'entre pas dans l'espace d'une autre.
     */
    public function test_une_requete_forgee_ne_declare_ni_n_ajoute_pour_une_autre_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($autre, siren: '000000927', email: 'luc.moreau@audit-conseil.test');
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();

        $this->actingAs($this->proprietaireDe($magasin))->get(CabinetComptable::getUrl(panel: 'societe', tenant: $autre->societe))->assertNotFound();

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $this->forgerLAction(Livewire::test(CabinetComptable::class), 'ajouterUnePersonne', ['name' => 'Intrus', 'email' => 'intrus@exemple.test', 'email_confirmation' => 'intrus@exemple.test', 'mot_de_passe' => 'password']);

        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('name', 'Intrus')->count());
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->societe_id)->count());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->count());
    }

    /**
     * Le SIREN d'un cabinet déjà vérifié sur PILIQI ne se déclare pas : la
     * fenêtre le dit dès qu'il est saisi, « Continuer » le refuse sans rien
     * créer, et « Lui demander le lien », au pied de la fenêtre, demande le
     * lien à ce cabinet et ferme la fenêtre (cahier du lot EC2, R5).
     */
    public function test_le_siren_d_un_cabinet_verifie_se_refuse_et_lui_demander_le_lien_fait_la_designation(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935', 'Paul Garnier');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)
            ->mountAction('declarer')
            ->assertDontSeeText('Lui demander le lien')
            ->setActionData(['siren' => '000000935', 'name' => 'Paul Garnier', 'email' => 'paul@compta-rhone.test', 'email_confirmation' => 'paul@compta-rhone.test', 'mot_de_passe' => 'password'])
            ->assertSeeText([DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI, 'Lui demander le lien'])
            ->callMountedAction()
            ->assertNotified(DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI)
            ->assertActionMounted('declarer')
            ->call('mountAction', 'luiDemanderLeLien')
            ->assertNotified('Votre demande est envoyée au cabinet.')
            ->assertSet('mountedActions', []);

        $this->assertSame(EtatDuLien::DemandeParLeClient, LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->sole()->etat);
        $this->assertSame(0, User::query()->where('role_societe', 'expert_comptable')->count());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->count());
    }

    /**
     * « Continuer » ouvre la fenêtre qui répète en gras l'adresse où part
     * le lien, telle qu'elle s'enregistre ; « Corriger » ramène à la
     * saisie, gardée, sans rien créer ni envoyer ; un refus dans la
     * confirmation (le mot de passe redonné) y ramène aussi ; le bouton de
     * la confirmation déclare (cahier du lot EC2, R8 et C30).
     */
    public function test_la_confirmation_repete_l_adresse_en_gras_et_corriger_ramene_a_la_saisie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $page = Livewire::test(CabinetComptable::class)
            ->mountAction('declarer')
            ->setActionData(['siren' => FauxRepertoireSirene::SIREN_CABINET, 'name' => 'Marc Petit', 'email' => 'Marc.Petit@Petit-Associes.test', 'email_confirmation' => 'Marc.Petit@Petit-Associes.test', 'mot_de_passe' => 'mauvais'])
            ->callMountedAction()
            ->assertActionMounted(['declarer', 'confirmerLaDeclaration'])
            ->assertSeeText('Vérifiez l\'adresse avant l\'envoi')
            ->assertSeeHtml('<strong>marc.petit@petit-associes.test</strong>')
            ->call('unmountAction', false, false)
            ->assertActionMounted('declarer')
            ->assertActionDataSet(['name' => 'Marc Petit', 'email' => 'Marc.Petit@Petit-Associes.test'])
            ->callMountedAction()
            ->callMountedAction()
            ->assertNotified(MotDePasseRedemande::MESSAGE)
            ->assertActionMounted('declarer');

        $this->assertSame(0, User::query()->where('role_societe', 'expert_comptable')->count());
        Mail::assertNothingOutgoing();

        $page->setActionData(['mot_de_passe' => 'password'])
            ->callMountedAction()
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions', []);
        $this->assertSame('marc.petit@petit-associes.test', User::query()->where('role_societe', 'expert_comptable')->sole()->email);
    }

    /**
     * Une requête forgée qui ouvre directement la fenêtre de confirmation,
     * dans une société qui ne peut pas déclarer, ne déclare rien : la
     * confirmation ne s'ouvre que là où la fenêtre du geste s'ouvre.
     */
    public function test_une_confirmation_forgee_ne_declare_rien_dans_une_societe_qui_attend_son_code(): void
    {
        $societe = $this->inscrireUneSociete();
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();
        $this->dansLEspaceDeLaSociete($societe, $societe->proprietaire()->firstOrFail());

        Livewire::test(CabinetComptable::class)
            ->set('mountedActions', ['declarer', 'confirmerLaDeclaration'])
            ->set('mountedActionsArguments', [[], []])
            ->set('mountedActionsData', [['siren' => FauxRepertoireSirene::SIREN_CABINET, 'name' => 'Marc Petit', 'email' => 'marc.petit@petit-associes.test', 'email_confirmation' => 'marc.petit@petit-associes.test', 'mot_de_passe' => 'password', 'jeton' => 'jeton-forge'], []])
            ->call('callMountedAction');

        $this->assertSame(0, User::query()->where('role_societe', 'expert_comptable')->count());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());
    }

    /**
     * Déclarer quand la société n'a qu'une demande de lien envoyée : la
     * case qui la nomme commande son retrait ; sans elle, rien ne change et
     * la fenêtre le dit ; avec elle, la demande est retirée (C32).
     */
    public function test_declarer_avec_une_demande_envoyee_exige_la_case_qui_la_retire(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935', 'Paul Garnier');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        app(TenantContext::class)->clear();
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $saisie = ['siren' => FauxRepertoireSirene::SIREN_CABINET, 'name' => 'Marc Petit', 'email' => 'marc.petit@petit-associes.test', 'email_confirmation' => 'marc.petit@petit-associes.test', 'mot_de_passe' => 'password'];

        Livewire::test(CabinetComptable::class)
            ->mountAction('declarer')
            ->assertSeeText('Je retire ma demande à '.$cabinet->raison_sociale)
            ->setActionData($saisie)
            ->callMountedAction()
            ->callMountedAction()
            ->assertNotified('Cochez la case pour retirer votre demande à '.$cabinet->raison_sociale.'.')
            ->assertActionMounted('declarer')
            ->setActionData(['remplacer' => true])
            ->callMountedAction()
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(EtatDuLien::Retire, LienCabinet::query()->withoutGlobalScopes()->findOrFail($demande->id)->etat);
        $this->assertSame(1, User::query()->where('role_societe', 'expert_comptable')->count());
    }

    /**
     * « Enregistrer » les autorisations de l'expert-comptable déclaré
     * n'ouvre une fenêtre de confirmation que si plus aucune case n'est
     * cochée, et cette fenêtre parle de ses comptes, pas d'un cabinet lié.
     */
    public function test_enregistrer_ne_confirme_que_sans_aucune_autorisation_et_parle_de_l_expert_comptable_declare(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $this->expertComptable($magasin);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $sansClients = array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), [PermissionRegistry::CLIENT_VIEW]));

        Livewire::test(CabinetComptable::class)
            ->set('data.consultations', $sansClients)
            ->mountAction('enregistrerLesAutorisations')
            ->assertNotified('Les autorisations de votre expert-comptable sont enregistrées.')
            ->assertSet('mountedActions', []);
        $accordees = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $declaration->fresh()->autorisations());
        $this->assertNotContains(PermissionRegistry::CLIENT_VIEW, $accordees);
        $this->assertContains(PermissionRegistry::DEVIS_VIEW, $accordees);

        Livewire::test(CabinetComptable::class)
            ->set('data.consultations', [])
            ->set('data.achats', [])
            ->set('data.telechargements', [])
            ->mountAction('enregistrerLesAutorisations')
            ->assertActionMounted('enregistrerLesAutorisations')
            ->assertSeeText('Sans aucune autorisation, les comptes de votre expert-comptable déclaré voient votre identité')
            ->assertDontSeeText('votre cabinet reste lié')
            ->callMountedAction();
        $this->assertSame([], app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $declaration->fresh()->autorisations()));
    }

    /**
     * L'en-tête de l'expert-comptable déclaré dit qui l'a déclaré, et
     * quand.
     */
    public function test_l_en_tete_de_la_declaration_nomme_qui_l_a_declaree(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['name' => 'Julie Bernard'])->save();
        $this->expertComptable($magasin);

        $this->actingAs($proprietaire)
            ->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))
            ->assertOk()
            ->assertSeeText('déclaré le 05/11/2026 par Julie Bernard');
    }

    /**
     * Désigner depuis la page le cabinet que la société a déclaré, une fois
     * vérifié, le relie aussitôt et ferme les comptes de la déclaration :
     * l'avis le dit, au lieu d'annoncer une demande envoyée.
     */
    public function test_designer_le_cabinet_declare_annonce_le_rattachement_et_les_comptes_fermes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(CabinetComptable::class)
            ->set('recherche', (string) $cabinet->siren)
            ->callAction('demanderLeLien', arguments: ['uuid' => $cabinet->uuid])
            ->assertNotified('Votre expert-comptable déclaré est désormais relié à votre société par son cabinet : les comptes de Marc Petit sont fermés.');

        $this->assertSame(EtatDuLien::Actif, LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->sole()->etat);
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }
}
