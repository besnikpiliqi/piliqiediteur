<?php

namespace Tests\Feature\Editeur;

use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\ConstaterLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Exceptions\CabinetNonVerifieException;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AvisAuxClientsDuCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use LogicException;
use Tests\TestCase;

/**
 * La correction du SIREN d'un cabinet d'expertise comptable par l'éditeur
 * (voir CorrigerSirenAction) : son contrôle 69.20Z et la vérification de
 * son inscription à l'Ordre sont remis à faire sous le nouveau SIREN ;
 * d'ici là, ses liens restent, mais ni lui ni ses collaborateurs n'ouvrent
 * plus aucun client, dès leur requête suivante, il n'invite ni ne lie
 * personne, et ses invitations et demandes ouvertes ne s'acceptent pas ;
 * son propriétaire met encore fin à ses liens. L'accès revient une fois
 * l'activité contrôlée et l'inscription à l'Ordre vérifiée sous le nouveau
 * SIREN ; le propriétaire du cabinet est prévenu de la correction, celui de
 * chaque client lié de la suspension puis du retour de l'accès. Aucun
 * autre cabinet ni client n'est touché.
 */
class CorrectionDuSirenDUnCabinetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    /**
     * D'ici sa nouvelle vérification, le cabinet n'ouvre plus son client,
     * ni dans le panneau des magasins ni dans son espace ; il n'invite plus
     * personne ; une entreprise qu'il avait invitée ne peut pas accepter, ni
     * une société inscrite accepter sa demande de rattachement, ni son
     * propriétaire accepter la désignation qu'une société lui avait
     * adressée, ni une autre société le désigner ; rien de tout cela
     * n'écrit.
     */
    public function test_d_ici_sa_nouvelle_verification_le_cabinet_n_ouvre_aucun_client_et_ses_invitations_et_demandes_ne_s_acceptent_pas(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $sirenInvite = SocieteFactory::sirenFictif();
        $jetonDeLInvitation = $this->inviterUneEntreprise($cabinet, $sirenInvite, 'invitee@example.test');
        $inscrite = $this->ouvrirUneSociete(['nom' => 'Inscrite'])->societe;
        $this->demanderLeRattachement($cabinet, $inscrite, 'inscrite@example.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $inscrite->id)->sole();
        $designante = $this->ouvrirUneSociete(['nom' => 'Désignante'])->societe;
        app(TenantContext::class)->clear();
        $designation = app(DesignerUnCabinetAction::class)->handle($designante->proprietaire()->sole(), $designante, $cabinet->fresh());
        app(TenantContext::class)->clear();
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre'])->societe;

        $this->corrigerLeSirenParLEditeur($cabinet, $this->sirenDActiviteComptable());

        foreach ([$this->proprietaireDuCabinet($cabinet), $collaborateur] as $compte) {
            $this->assertFalse($compte->fresh()->peutOuvrir($magasin));
            $this->flushSession();
            $this->actingAs($compte)->get("/app/{$magasin->uuid}")->assertNotFound();
            $this->get(ClientDuCabinetResource::getUrl('view', ['record' => $lien->uuid], panel: 'cabinet', tenant: $cabinet))->assertNotFound();
        }
        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet->fresh(), SocieteFactory::sirenFictif(), 'nouvelle@example.test', self::MOT_DE_PASSE_CHOISI), CabinetNonVerifieException::class);
        $this->assertThrows(fn () => $this->accepterLInvitation($jetonDeLInvitation), LogicException::class, AccepterLInvitationAction::MESSAGE_CABINET_NE_PEUT_PLUS);
        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($inscrite->proprietaire()->sole(), $demande), LogicException::class, AccepterLaDemandeDeLienAction::MESSAGE_CABINET_NON_LIABLE);
        $this->assertThrows(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $designation), LogicException::class, AccepterLaDemandeDeLienAction::MESSAGE_CABINET_NON_LIABLE);
        $this->assertThrows(fn () => app(DesignerUnCabinetAction::class)->handle($autre->proprietaire()->sole(), $autre, $cabinet->fresh()), LogicException::class, DesignerUnCabinetAction::MESSAGE_CABINET_NON_LIABLE);

        $this->assertSame([EtatDuLien::Actif, EtatDuLien::DemandeParLeCabinet, EtatDuLien::DemandeParLeClient], [$lien->fresh()->etat, $demande->fresh()->etat, $designation->fresh()->etat]);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $autre->id)->count());
        $this->assertFalse(Societe::query()->where('siren', $sirenInvite)->exists());
    }

    /**
     * D'ici là, le propriétaire du cabinet met encore fin à son lien,
     * depuis le tableau de bord de son cabinet, qui ne lui montre plus que
     * ses liens actifs.
     */
    public function test_d_ici_la_le_proprietaire_du_cabinet_met_encore_fin_a_son_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $lien = $this->lierLeCabinet($cabinet, $magasin);
        $this->corrigerLeSirenParLEditeur($cabinet, $this->sirenDActiviteComptable());
        $this->dansLEspaceDuCabinet($cabinet->fresh(), $this->proprietaireDuCabinet($cabinet));
        $tableau = Livewire::test(TableauDeBordDuCabinet::class);
        $this->assertSame([], $tableau->viewData('clients'));
        $this->assertSame([$lien->id], $tableau->viewData('liensActifs')->modelKeys());

        $tableau->callAction('mettreFinAuLien', arguments: ['uuid' => $lien->uuid]);

        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);
    }

    /**
     * Une session ouverte d'un collaborateur dans un magasin de son client
     * perd l'accès dès sa requête suivante après la correction, sans être
     * déconnectée de l'espace de son cabinet.
     */
    public function test_une_session_ouverte_d_un_compte_du_cabinet_perd_l_acces_des_sa_requete_suivante(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $instantane = $this->instantaneLivewire($this->actingAs($collaborateur)->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk()->getContent(), app(ComponentRegistry::class)->getName(ListFactures::class));

        $this->corrigerLeSirenParLEditeur($cabinet, $this->sirenDActiviteComptable());

        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertNotFound();
        $this->assertContains($this->requeteLivewire($instantane, [], '$refresh')->status(), [403, 404]);
        $this->assertTrue($this->isAuthenticated());
        $this->assertTrue($collaborateur->fresh()->ouvreLEspaceDuCabinet());
    }

    /**
     * Le propriétaire du cabinet apprend la correction, avec ce qu'il lui
     * reste à faire ; le propriétaire de chaque client lié apprend
     * seulement que l'accès de son cabinet est suspendu jusqu'à sa nouvelle
     * vérification, sans rien de la correction.
     */
    public function test_le_proprietaire_du_cabinet_et_ceux_de_ses_clients_lies_sont_prevenus(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premier = $this->ouvrirUneSociete(['nom' => 'Première cliente']);
        $second = $this->ouvrirUneSociete(['nom' => 'Seconde cliente']);
        $this->lierLeCabinet($cabinet, $premier);
        $this->lierLeCabinet($cabinet, $second);
        $sirenCorrect = $this->sirenDActiviteComptable();

        $this->corrigerLeSirenParLEditeur($cabinet, $sirenCorrect);

        $avis = $this->proprietaireDuCabinet($cabinet)->notifications()->get()->firstWhere('data.title', 'Le SIREN de votre cabinet est corrigé');
        $this->assertNotNull($avis);
        $this->assertStringContainsString("désormais {$sirenCorrect}", $avis->data['body']);
        $this->assertStringContainsString('n\'ouvre plus aucun client', $avis->data['body']);
        foreach ([$premier, $second] as $magasin) {
            $suspendu = $this->proprietaireDe($magasin)->notifications()->get()->where('data.title', AvisAuxClientsDuCabinet::TITRE_SUSPENDU);
            $this->assertCount(1, $suspendu);
            $this->assertSame("L'accès du cabinet {$cabinet->fresh()->getFilamentName()} à votre société est suspendu jusqu'à sa nouvelle vérification.", $suspendu->sole()->data['body']);
            $this->assertStringNotContainsString('SIREN', $suspendu->sole()->data['body']);
        }
    }

    /**
     * Après la correction vers un SIREN d'activité 69.20Z, l'inscription à
     * l'Ordre se vérifie de nouveau par l'annuaire sous ce SIREN : le
     * cabinet redevient vérifié, ses comptes rouvrent leurs clients, et le
     * propriétaire de chaque client lié apprend que l'accès revient.
     */
    public function test_l_acces_revient_apres_la_verification_a_l_ordre_sous_le_nouveau_siren_et_les_clients_en_sont_prevenus(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$magasin]);
        $sirenCorrect = $this->sirenDActiviteComptable();
        $this->corrigerLeSirenParLEditeur($cabinet, $sirenCorrect);
        $this->assertFalse($collaborateur->fresh()->peutOuvrir($magasin));
        app(AnnuaireDeLOrdre::class)->inscrire($sirenCorrect, '75-00042', FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);

        app(VerifierLOrdreAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet->fresh(), ['numero_ordre' => '75-00042', 'conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST]);

        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        $this->assertTrue($collaborateur->fresh()->peutOuvrir($magasin));
        $this->flushSession();
        $this->actingAs($collaborateur)->get("/app/{$magasin->uuid}")->assertOk();
        $retabli = $this->proprietaireDe($magasin)->notifications()->get()->where('data.title', AvisAuxClientsDuCabinet::TITRE_RETABLI);
        $this->assertCount(1, $retabli);
        $this->assertSame("Le cabinet {$cabinet->fresh()->getFilamentName()} accède de nouveau à votre société, selon les autorisations que vous lui accordez.", $retabli->sole()->data['body']);
    }

    /**
     * Sous un nouveau SIREN sans code d'activité au répertoire Sirene, le
     * code reste à contrôler : l'éditeur le contrôle avec l'inscription à
     * l'Ordre, par sa procédure, et l'accès revient ; le client l'apprend.
     */
    public function test_un_code_absent_se_controle_par_l_editeur_avec_l_ordre_et_l_acces_revient(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($sirenCorrect, null, null);
        $this->corrigerLeSirenParLEditeur($cabinet, $sirenCorrect);
        $activite = collect($this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee))->last();
        $this->assertSame(['code_activite' => null, 'nomenclature' => null, 'resultat' => 'a_controler'], $activite->nouvelles_valeurs);
        $this->assertFalse($cabinet->cabinet()->sole()->aSonActiviteControlee());

        app(ConstaterLInscriptionALOrdreAction::class)->handle($cabinet->fresh(), $this->editeur(), '75-00042', FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true);

        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
        $this->assertCount(1, $this->proprietaireDe($magasin)->notifications()->get()->where('data.title', AvisAuxClientsDuCabinet::TITRE_RETABLI));
    }

    /**
     * Sous un nouveau SIREN d'une autre activité que 69.20Z en NAF rév. 2,
     * le cabinet reste non vérifié : son inscription à l'Ordre se vérifie,
     * mais son activité ne se contrôle pas, pas même par l'éditeur, dont la
     * procédure est refusée sans rien écrire ; son client n'apprend aucun
     * retour de l'accès.
     */
    public function test_une_autre_activite_laisse_le_cabinet_non_verifie_et_ne_se_controle_pas(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->corrigerLeSirenParLEditeur($cabinet, $sirenCorrect);
        app(AnnuaireDeLOrdre::class)->inscrire($sirenCorrect, '75-00042', FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);
        app(VerifierLOrdreAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet->fresh(), ['numero_ordre' => '75-00042', 'conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST]);
        $avant = Arr::only($cabinet->cabinet()->sole()->getRawOriginal(), ['code_activite', 'activite_controlee_le', 'ordre_verifie_le', 'ordre_voie', 'updated_at']);

        $this->assertThrows(fn () => app(ConstaterLInscriptionALOrdreAction::class)->handle($cabinet->fresh(), $this->editeur(), '75-00042', FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true), LogicException::class, ConstaterLInscriptionALOrdreAction::MESSAGE_AUTRE_ACTIVITE);

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame($avant, Arr::only($verification->getRawOriginal(), ['code_activite', 'activite_controlee_le', 'ordre_verifie_le', 'ordre_voie', 'updated_at']));
        $this->assertSame([FauxRepertoireSirene::ACTIVITE_PAR_DEFAUT, true, false], [$verification->code_activite, $verification->aUneAutreActivite(), $cabinet->fresh()->estUnCabinetVerifie()]);
        $this->assertSame('autre_activite', collect($this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee))->last()->nouvelles_valeurs['resultat']);
        $this->assertFalse($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
        $this->assertCount(0, $this->proprietaireDe($magasin)->notifications()->get()->where('data.title', AvisAuxClientsDuCabinet::TITRE_RETABLI));
    }

    /**
     * La correction ne touche aucun autre cabinet ni ses clients : l'autre
     * cabinet reste vérifié, ses comptes ouvrent toujours leur client, son
     * client n'est prévenu de rien ; le client du cabinet corrigé garde son
     * identité.
     */
    public function test_la_correction_ne_touche_aucun_autre_cabinet_ni_client(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $autreClient = $this->ouvrirUneSociete(['nom' => 'Cliente de l\'autre']);
        $this->lierLeCabinet($autreCabinet, $autreClient);
        $collaborateurDeLAutre = $this->confier($this->collaborateur($autreCabinet), [$autreClient]);
        $identiteDuClient = $magasin->societe->fresh()->only(['siren', 'numero_tva', 'raison_sociale']);
        $notificationsDeLAutreClient = $this->proprietaireDe($autreClient)->notifications()->count();

        $this->corrigerLeSirenParLEditeur($cabinet, $this->sirenDActiviteComptable());

        $this->assertTrue($autreCabinet->fresh()->estUnCabinetVerifie());
        $this->assertTrue($collaborateurDeLAutre->fresh()->peutOuvrir($autreClient));
        $this->assertSame($notificationsDeLAutreClient, $this->proprietaireDe($autreClient)->notifications()->count());
        $this->assertSame([], $this->entreesDAudit($autreCabinet, ActionAuditee::OrdreARefaire));
        $this->assertSame($identiteDuClient, $magasin->societe->fresh()->only(['siren', 'numero_tva', 'raison_sociale']));
    }

    /**
     * Un SIREN libre, d'activité 69.20Z en NAF rév. 2 au répertoire Sirene
     * factice.
     */
    private function sirenDActiviteComptable(): string
    {
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);

        return $siren;
    }

    private function editeur(): InterventionDeLEditeur
    {
        app(TenantContext::class)->clear();

        return new InterventionDeLEditeur('Inscription au tableau lue dans l\'annuaire de l\'Ordre', 'ORDRE-2026-0042', ['alice']);
    }
}
