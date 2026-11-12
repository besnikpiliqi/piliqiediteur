<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\ConstaterLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RattacherUneDeclarationAction;
use App\Domain\Cabinets\Actions\RetirerLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Jobs\RattacherUneDeclaration;
use App\Domain\Cabinets\Mail\CompteDExpertComptableFermeMail;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinetEnFile;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\FicheDuClient;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\ListClientsDuCabinet;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Testing\Fakes\MailFake;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Le rattachement de la déclaration d'un expert-comptable de la société
 * au cabinet vérifié de son SIREN (décision de l'éditeur du 01/11/2026,
 * choix 3) : la même ligne devient le lien actif, avec ses autorisations
 * et ses magasins, sans copie ; les comptes que la société avait créés se
 * ferment, leur historique gardé ; le cabinet n'apprend rien d'elle avant,
 * ni rien des comptes fermés ensuite.
 */
class RattachementDesExpertsComptablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_quand_le_cabinet_du_siren_devient_verifie_la_declaration_devient_le_lien_avec_les_memes_autorisations_sans_copie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $autorisations = array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), ['client.view', 'devis.view']));
        $this->declarerSonExpertComptable($magasin, autorisations: $autorisations);
        $declaration = $this->declaration($magasin->societe);
        $lignes = DB::table('autorisations_cabinet')->where('lien_id', $declaration->id)->orderBy('autorisation')->get(['autorisation', DB::raw('xmin::text AS version')])->toJson();

        $cabinet = $this->ouvrirUnCabinet();

        $lien = $declaration->fresh();
        $this->assertSame([EtatDuLien::Actif, $cabinet->id, OrigineDuLien::DeclarationDuClient], [$lien->etat, $lien->cabinet_id, $lien->origine]);
        $this->assertNotNull($lien->actif_depuis);
        $this->assertSame($lignes, DB::table('autorisations_cabinet')->where('lien_id', $lien->id)->orderBy('autorisation')->get(['autorisation', DB::raw('xmin::text AS version')])->toJson());
        $this->assertNotContains('client.view', app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $lien->autorisations()));
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->count());
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
    }

    public function test_au_rattachement_les_comptes_d_expert_comptable_sont_retires_avec_leur_historique_et_leurs_liens_effaces(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $identifiant = $marc->identifiantDeConnexion();

        $this->ouvrirUnCabinet();

        foreach ([$marc, $sophie] as $compte) {
            $compte = $compte->fresh();
            $this->assertSame([StatutCompte::Retire, null], [$compte->statut, $compte->statut_change_par]);
            $this->assertFalse($compte->peutOuvrir($magasin));
            $this->assertNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail()->jeton_expire_le);
        }

        $this->assertSame($identifiant, $marc->fresh()->identifiantDeConnexion());
        $this->assertSame('expert_comptable', $marc->fresh()->role_societe->value);
    }

    public function test_le_rattachement_s_inscrit_au_jet_130_de_chaque_magasin_reel_et_aux_journaux_des_deux_societes(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $marc = $this->expertComptable($lyon1);
        $jet = [count($this->modificationsDesDroits($lyon1)), count($this->modificationsDesDroits($lyon2))];

        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        foreach ([[$lyon1, $jet[0]], [$lyon2, $jet[1]]] as [$magasin, $avant]) {
            $nouvelles = collect(array_slice($this->modificationsDesDroits($magasin), $avant));
            $retire = $nouvelles->firstWhere('membre.id', $marc->id);
            $this->assertIsArray($retire);
            $this->assertNotNull($retire['avant']);
            $this->assertNull($retire['apres']);
            $this->assertNotNull($nouvelles->firstWhere('membre.id', $proprietaire->id)['apres'] ?? null);
        }

        $rattache = collect($this->entreesDAudit($lyon1->societe, ActionAuditee::ExpertComptableRattache))->sole();
        $this->assertSame((string) $cabinet->siren, $rattache->nouvelles_valeurs['cabinet']['siren']);
        $this->assertArrayHasKey($marc->id, $rattache->nouvelles_valeurs['droits']);
        $active = collect($this->entreesDAudit($lyon1->societe, ActionAuditee::LienActive))->sole();
        $this->assertSame([OrigineDuLien::DeclarationDuClient->value, 'declarees', 'tous'], [$active->nouvelles_valeurs['origine'], $active->nouvelles_valeurs['autorisations'], $active->nouvelles_valeurs['magasins']]);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::LienActive));
        $this->assertCount(0, $this->entreesDAudit($cabinet, ActionAuditee::ExpertComptableRattache));
    }

    public function test_au_rattachement_aucun_avis_ni_journal_d_audit_du_cabinet_ne_nomme_les_comptes_fermes(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin, nom: 'Marc Petit');

        $cabinet = $this->ouvrirUnCabinet();

        $journal = app(TenantContext::class)->runAsSociete($cabinet, fn (): string => DB::table('journal_audit')->where('societe_id', $cabinet->id)->get()->toJson());
        $this->assertStringNotContainsString('Marc Petit', $journal);
        $this->assertStringNotContainsString('"'.$marc->id.'"', $journal);
        $avis = $this->proprietaireDuCabinet($cabinet)->notifications()->get()->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Marc Petit', $avis);
        $this->assertStringContainsString('Nouveau client relié', $avis);
    }

    public function test_le_rattachement_previent_les_deux_proprietaires_et_les_personnes_dont_le_compte_est_ferme(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');

        $cabinet = $this->ouvrirUnCabinet();

        $avis = $this->proprietaireDe($magasin)->notifications()->where('type', AvisDuCabinetEnFile::class)->sole();
        $this->assertSame('Votre expert-comptable a rejoint PILIQI', $avis->data['title']);
        $this->assertStringContainsString('Marc Petit, Sophie Lenoir', $avis->data['body']);
        $this->assertStringContainsString('mettez fin au lien', $avis->data['body']);
        $this->assertSame(1, $this->proprietaireDuCabinet($cabinet)->notifications()->where('data->title', 'Nouveau client relié')->count());
        Mail::assertQueued(CompteDExpertComptableFermeMail::class, 2);
        Mail::assertQueued(CompteDExpertComptableFermeMail::class, fn (CompteDExpertComptableFermeMail $mail): bool => $mail->hasTo('sophie.lenoir@petit-associes.test')
            && str_contains($mail->render(), 'quelqu\'un s\'est peut-être inscrit à sa place')
            && ! str_contains($mail->render(), 'identifiant'));
    }

    public function test_une_declaration_dont_toutes_les_personnes_sont_suspendues_ne_se_rattache_pas(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $marc);

        $cabinet = $this->ouvrirUnCabinet();

        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);
        $this->assertCount(0, $this->entreesDAudit($cabinet, ActionAuditee::LienActive));
        $this->assertSame(0, $this->proprietaireDuCabinet($cabinet)->notifications()->where('data->title', 'Nouveau client relié')->count());
    }

    public function test_reactiver_ou_reintegrer_une_personne_relance_le_rattachement(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $marc);
        $cabinet = $this->ouvrirUnCabinet();
        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);

        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $marc->fresh());

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->lienDe($magasin->societe)->etat, $this->lienDe($magasin->societe)->cabinet_id]);
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);

        $autre = $this->ouvrirUneSociete();
        $luc = $this->declarerSonExpertComptable($autre, siren: '000000935', nom: 'Luc Moreau', email: 'luc.moreau@audit-conseil.test');
        $sophie = $this->ajouterUnExpertComptable($autre->societe, 'Sophie Lenoir', 'sophie.lenoir@audit-conseil.test');
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($autre), $luc);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($autre), $sophie);
        $second = $this->ouvrirUnCabinet('000000935');
        $this->assertSame(EtatDuLien::Declare, $this->declaration($autre->societe)->etat);

        app(ChangerStatutCompteAction::class)->reintegrerLExpertComptable($this->proprietaireDe($autre), $luc->fresh());

        $this->assertSame([EtatDuLien::Actif, $second->id], [$this->lienDe($autre->societe)->etat, $this->lienDe($autre->societe)->cabinet_id]);
        $this->assertSame([StatutCompte::Retire, StatutCompte::Retire], [$luc->fresh()->statut, $sophie->fresh()->statut]);
    }

    public function test_un_cabinet_qui_n_est_pas_entierement_verifie_ne_rattache_rien_et_n_apprend_rien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $this->saisirLeCodeDuCourrier($cabinet);

        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $cabinet->id)->count());
        $this->assertCount(0, $this->entreesDAudit($cabinet->fresh(), ActionAuditee::LienActive));
        $this->assertSame(0, $this->proprietaireDuCabinet($cabinet)->notifications()->where('data->title', 'Nouveau client relié')->count());

        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        app(AnnuaireDeLOrdre::class)->inscrire(FauxRepertoireSirene::SIREN_CABINET, '75-00943', FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet->fresh(), ['numero_ordre' => '75-00943', 'conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST]);
        app(TenantContext::class)->clear();

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->lienDe($magasin->societe)->etat, $this->lienDe($magasin->societe)->cabinet_id]);
    }

    public function test_le_rattachement_retire_les_demandes_en_cours_entre_la_societe_et_ce_cabinet(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $marc = $this->expertComptablesDeLaSociete($magasin->societe)->sole();
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $marc);
        $cabinet = $this->ouvrirUnCabinet();
        app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $magasin->societe->siren, 'contact@boulangerie.test', self::MOT_DE_PASSE_CHOISI);
        app(TenantContext::class)->clear();
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::DemandeParLeCabinet->value)->sole();

        app(ChangerStatutCompteAction::class)->reactiver($this->proprietaireDe($magasin), $marc->fresh());

        $this->assertSame(EtatDuLien::Retire, $demande->fresh()->etat);
        $this->assertNull($demande->fresh()->getAttribute('jeton_hache'));
        $this->assertSame(EtatDuLien::Actif, $this->lienDe($magasin->societe)->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienRetiree));
    }

    public function test_un_rattachement_rejoue_ne_change_rien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $declaration = $this->declaration($magasin->societe);
        $cabinet = $this->ouvrirUnCabinet();
        $avant = $this->instantaneDesTables('liens_cabinet', 'users', 'journal_audit', 'notifications');

        $this->assertFalse(app(RattacherUneDeclarationAction::class)->handle($declaration->id, $cabinet->id));
        (new RattacherUneDeclaration($declaration->id, $cabinet->id))->handle(app(RattacherUneDeclarationAction::class));

        $this->assertSame($avant, $this->instantaneDesTables('liens_cabinet', 'users', 'journal_audit', 'notifications'));
    }

    public function test_la_tache_horaire_rattrape_un_rattachement_manque(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $this->ouvrirUnCabinet();
        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);
        Bus::assertDispatched(RattacherLesDeclarationsDuSiren::class, fn (RattacherLesDeclarationsDuSiren $job): bool => $job->siren === FauxRepertoireSirene::SIREN_CABINET);

        $this->artisan('app:rattacher-experts-comptables')->assertSuccessful();
        Bus::assertDispatchedTimes(RattacherLesDeclarationsDuSiren::class, 3);

        (new RattacherLesDeclarationsDuSiren(FauxRepertoireSirene::SIREN_CABINET))->handle(app(ExpertsComptablesDeclares::class));

        $this->assertSame(EtatDuLien::Actif, $this->lienDe($magasin->societe)->etat);
    }

    public function test_designer_le_cabinet_declare_le_rattache_aussitot_au_nom_du_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        $declaration = $this->declaration($magasin->societe);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        $this->assertSame(EtatDuLien::Declare, $declaration->fresh()->etat);

        $lien = app(DesignerUnCabinetAction::class)->handle($proprietaire, $magasin->societe, $cabinet);
        app(TenantContext::class)->clear();

        $this->assertSame([$declaration->id, EtatDuLien::Actif, $cabinet->id], [$lien->id, $lien->etat, $lien->cabinet_id]);
        $this->assertSame([StatutCompte::Retire, $proprietaire->id], [$marc->fresh()->statut, $marc->fresh()->statut_change_par]);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->whereIn('etat', [EtatDuLien::DemandeParLeClient->value, EtatDuLien::DemandeParLeCabinet->value])->count());
        $this->assertSame($proprietaire->id, collect($this->entreesDAudit($magasin->societe, ActionAuditee::ExpertComptableRattache))->sole()->getAttribute('auteur_id'));
        $this->assertSame(0, $proprietaire->notifications()->where('data->title', 'Votre expert-comptable a rejoint PILIQI')->count());
    }

    public function test_accepter_la_demande_du_cabinet_du_siren_declare_rattache_la_declaration_avec_ses_autorisations(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $autorisations = array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), ['devis.view']));
        $marc = $this->declarerSonExpertComptable($magasin, autorisations: $autorisations);
        $proprietaire = $this->proprietaireDe($magasin);
        $declaration = $this->declaration($magasin->societe);
        $attendues = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $declaration->autorisations());
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $magasin->societe->siren, 'contact@boulangerie.test', self::MOT_DE_PASSE_CHOISI);
        app(TenantContext::class)->clear();
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::DemandeParLeCabinet->value)->sole();

        $lien = app(AccepterLaDemandeDeLienAction::class)->handle($proprietaire, $demande);
        app(TenantContext::class)->clear();

        $this->assertSame([$declaration->id, EtatDuLien::Actif, $cabinet->id], [$lien->id, $lien->etat, $lien->cabinet_id]);
        $this->assertSame($attendues, app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $lien->autorisations()));
        $this->assertSame(EtatDuLien::Retire, $demande->fresh()->etat);
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }

    public function test_plusieurs_societes_qui_declarent_le_meme_siren_sont_rattachees_chacune_dans_sa_transaction(): void
    {
        $boulangerie = $this->ouvrirUneSociete();
        $atelier = $this->ouvrirUneSociete();
        $this->expertComptable($boulangerie);
        $this->expertComptable($atelier, nom: 'Sophie Lenoir');

        $cabinet = $this->ouvrirUnCabinet();

        foreach ([$boulangerie, $atelier] as $magasin) {
            $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->lienDe($magasin->societe)->etat, $this->lienDe($magasin->societe)->cabinet_id]);
            $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::LienActive));
        }

        $this->assertCount(2, $this->entreesDAudit($cabinet, ActionAuditee::LienActive));
        $this->assertSame(2, $this->proprietaireDuCabinet($cabinet)->notifications()->where('data->title', 'Nouveau client relié')->count());
        $this->assertStringNotContainsString('Sophie Lenoir', json_encode($this->entreesDAudit($boulangerie->societe, ActionAuditee::ExpertComptableRattache)[0]->nouvelles_valeurs, JSON_THROW_ON_ERROR));
    }

    public function test_le_rattachement_garde_le_choix_des_magasins_de_la_declaration(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $this->expertComptable($lyon1);
        $declaration = $this->declaration($lyon1->societe);
        app(TenantContext::class)->runAsSociete($lyon1->societe, fn () => DB::transaction(function () use ($declaration, $lyon1): void {
            $relue = LienCabinet::query()->lockForUpdate()->findOrFail($declaration->id);
            $relue->choisirLesMagasins([$lyon1->id]);
        }));

        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $this->assertFalse($this->lienDe($lyon1->societe)->tous_les_magasins);
        $this->assertTrue($proprietaire->fresh()->peutOuvrir($lyon1));
        $this->assertFalse($proprietaire->fresh()->peutOuvrir($lyon2));
    }

    public function test_la_fin_du_lien_ne_d_un_rattachement_ne_rouvre_ni_la_declaration_ni_les_comptes(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $this->ouvrirUnCabinet();
        $lien = $this->lienDe($magasin->societe);

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $lien);
        app(TenantContext::class)->clear();

        $this->assertSame(EtatDuLien::Termine, $lien->fresh()->etat);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->count());
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
        $this->assertFalse($marc->fresh()->peutOuvrir($magasin));
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrerLExpertComptable($this->proprietaireDe($magasin), $marc->fresh()), AuthorizationException::class);
    }

    /**
     * Le rattachement part du dernier des gestes qui rendent le cabinet
     * vérifié : la saisie du code de son courrier, la vérification de son
     * inscription à l'Ordre par l'annuaire, ou le constat de l'éditeur.
     */
    #[DataProvider('derniersGestes')]
    public function test_le_rattachement_part_de_la_saisie_du_code_de_la_verification_de_l_ordre_et_du_constat_de_l_editeur(string $dernier): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);

        if ($dernier === 'code') {
            $this->constaterLOrdre($cabinet);
            $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);
            $this->saisirLeCodeDuCourrier($cabinet);
        } else {
            $proprietaire = $this->saisirLeCodeDuCourrier($cabinet);
            $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);
            $dernier === 'constat' ? $this->constaterLOrdre($cabinet) : $this->verifierLOrdre($cabinet, $proprietaire);
        }

        app(TenantContext::class)->clear();
        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function derniersGestes(): array
    {
        return [
            'la saisie du code en dernier' => ['code'],
            'la vérification de l\'Ordre en dernier' => ['ordre'],
            'le constat de l\'éditeur en dernier' => ['constat'],
        ];
    }

    /**
     * Chaque geste envoie la tâche du rattachement de son SIREN, après son
     * commit : celle de la première validation ne rattache rien, le cabinet
     * n'étant pas encore vérifié ; celle de la seconde rattache, quel que
     * soit l'ordre des deux gestes.
     */
    public function test_le_job_de_la_premiere_validation_ne_rattache_rien_et_celui_de_la_seconde_rattache(): void
    {
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);

        foreach ([[FauxRepertoireSirene::SIREN_CABINET, 'code d\'abord'], ['000000927', 'Ordre d\'abord']] as [$siren, $ordre]) {
            $magasin = $this->ouvrirUneSociete(['nom' => $ordre, 'siren' => SocieteFactory::sirenFictif()]);
            $this->declarerSonExpertComptable($magasin, siren: $siren, email: "personne-{$siren}@cabinet.test");
            $cabinet = $this->inscrireLeCabinetDuSiren($siren);

            $ordre === 'code d\'abord' ? $this->saisirLeCodeDuCourrier($cabinet) : $this->constaterLOrdre($cabinet);
            $this->rejouerLesTachesDuSiren($siren);
            $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat, $ordre);

            $ordre === 'code d\'abord' ? $this->constaterLOrdre($cabinet) : $this->saisirLeCodeDuCourrier($cabinet);
            $this->rejouerLesTachesDuSiren($siren);
            $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id], $ordre);
            $this->assertCount(3, Bus::dispatched(RattacherLesDeclarationsDuSiren::class)->filter(fn (RattacherLesDeclarationsDuSiren $tache): bool => $tache->siren === $siren), $ordre);
        }
    }

    public function test_un_cabinet_radie_puis_constate_par_l_editeur_rattache_les_declarations_en_attente(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        app(RetirerLInscriptionALOrdreAction::class)->handle($cabinet->fresh(), $this->editeur(), 'Radiation constatée au tableau de l\'Ordre le 02/11/2026.');
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);

        $this->constaterLOrdre($cabinet);

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
    }

    /**
     * Un cabinet suspendu n'est pas vérifié : la déclaration de son SIREN
     * attend, et la tâche horaire la rattache dès sa réactivation (statut
     * écrit en base, faute de geste de l'éditeur avant l'étape 13).
     */
    public function test_un_cabinet_suspendu_ne_rattache_rien_et_la_tache_horaire_rattache_des_sa_reactivation(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        DB::table('societes')->where('id', $cabinet->id)->update(['statut' => 'suspendue']);
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);

        $this->artisan('app:rattacher-experts-comptables')->assertSuccessful();
        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);

        DB::table('societes')->where('id', $cabinet->id)->update(['statut' => 'active']);
        $this->artisan('app:rattacher-experts-comptables')->assertSuccessful();

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
    }

    /**
     * La déclaration d'une société suspendue se rattache (choix C33) : le
     * lien naît actif, et la société reste en lecture seule pour le
     * cabinet comme pour elle.
     */
    public function test_la_declaration_d_une_societe_suspendue_se_rattache_et_reste_en_lecture_seule(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        DB::table('societes')->where('id', $magasin->societe_id)->update(['statut' => 'suspendue']);

        $cabinet = $this->ouvrirUnCabinet();

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
        $this->assertTrue($magasin->societe->fresh()->estEnLectureSeule());
        $this->assertTrue($magasin->fresh()->estEnLectureSeule());
        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin->fresh()));
    }

    public function test_l_avis_et_l_e_mail_du_rattachement_disent_quoi_faire_si_le_cabinet_n_est_pas_le_bon(): void
    {
        config(['editeur.email' => 'contact@piliqi.test']);
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);

        $this->ouvrirUnCabinet();

        $avis = $this->proprietaireDe($magasin)->notifications()->where('type', AvisDuCabinetEnFile::class)->sole();
        $this->assertStringContainsString('Si ce n\'est pas votre expert-comptable, mettez fin au lien dans « Cabinet comptable » et prévenez l\'éditeur de PILIQI.', $avis->data['body']);
        Mail::assertQueued(CompteDExpertComptableFermeMail::class, fn (CompteDExpertComptableFermeMail $mail): bool => str_contains(html_entity_decode($mail->render(), ENT_QUOTES), 'Si votre cabinet n\'a pas ouvert d\'espace sur '.config('app.name').', prévenez aussitôt')
            && str_contains($mail->render(), 'contact@piliqi.test')
            && str_contains(html_entity_decode($mail->render(), ENT_QUOTES), 'quelqu\'un s\'est peut-être inscrit à sa place'));
    }

    public function test_apres_le_rattachement_seul_le_proprietaire_du_cabinet_voit_le_client_jusqu_a_ce_qu_il_le_confie(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);

        $this->assertTrue($this->proprietaireDuCabinet($cabinet)->peutOuvrir($magasin));
        $this->assertFalse($collaborateur->fresh()->peutOuvrir($magasin));

        $this->confier($collaborateur, [$magasin]);

        $this->assertTrue($collaborateur->fresh()->peutOuvrir($magasin));
    }

    public function test_au_rattachement_le_client_parait_dans_l_espace_du_cabinet_avec_l_origine_de_son_lien(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['raison_sociale' => 'BOULANGERIE BERNARD']);
        $this->expertComptable($magasin);
        $this->travelTo(self::aParis('2026-11-09 11:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $lien = $this->declaration($magasin->societe);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        Livewire::test(ListClientsDuCabinet::class)->assertCanSeeTableRecords([$lien])->assertSee('BOULANGERIE BERNARD')->assertSee('09/11/2026');
        Livewire::test(FicheDuClient::class, ['record' => $lien->getRouteKey()])->assertSee(OrigineDuLien::DeclarationDuClient->getLabel());
    }

    /**
     * La tâche du SIREN envoie une tâche par déclaration, chacune dans sa
     * transaction : une déclaration qui ne se rattache pas (toutes ses
     * personnes suspendues) n'arrête pas celle d'une autre société.
     */
    public function test_le_rattachement_d_un_siren_envoie_un_job_par_declaration_et_l_echec_d_une_societe_n_arrete_pas_les_autres(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Premier', 'siren' => SocieteFactory::sirenFictif()]);
        $second = $this->ouvrirUneSociete(['nom' => 'Second', 'siren' => SocieteFactory::sirenFictif()]);
        $suspendu = $this->declarerSonExpertComptable($premier);
        $this->declarerSonExpertComptable($second, email: 'luc.moreau@petit-associes.test');
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($premier), $suspendu);
        Bus::fake([RattacherLesDeclarationsDuSiren::class, RattacherUneDeclaration::class]);
        $this->ouvrirUnCabinet();

        (new RattacherLesDeclarationsDuSiren(FauxRepertoireSirene::SIREN_CABINET))->handle(app(ExpertsComptablesDeclares::class));

        $taches = Bus::dispatched(RattacherUneDeclaration::class);
        $this->assertSame([$this->declaration($premier->societe)->id, $this->declaration($second->societe)->id], $taches->map(fn (RattacherUneDeclaration $tache): int => $tache->declarationId)->sort()->values()->all());

        foreach ($taches as $tache) {
            $tache->handle(app(RattacherUneDeclarationAction::class));
        }

        $this->assertSame([EtatDuLien::Declare, EtatDuLien::Actif], [$this->declaration($premier->societe)->etat, $this->declaration($second->societe)->etat]);
    }

    public function test_le_job_d_une_declaration_rattache_si_le_cabinet_est_devenu_verifie_entre_temps(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);
        $this->saisirLeCodeDuCourrier($cabinet);
        $tache = new RattacherUneDeclaration($this->declaration($magasin->societe)->id, $cabinet->id);

        $tache->handle(app(RattacherUneDeclarationAction::class));
        $this->assertSame(EtatDuLien::Declare, $this->declaration($magasin->societe)->etat);

        $this->constaterLOrdre($cabinet);
        $tache->handle(app(RattacherUneDeclarationAction::class));

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
    }

    /**
     * Le rattachement fait pendant la requête d'un autre (ici, une requête
     * venue de l'adresse 203.0.113.7) n'inscrit l'adresse ni le navigateur
     * de ce visiteur au journal d'audit ni au journal des événements
     * d'aucune des deux sociétés.
     */
    public function test_le_rattachement_ne_laisse_aucune_trace_du_visiteur_dans_les_journaux_des_deux_societes(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        $declaration = $this->declaration($magasin->societe);
        Route::post('/test/rattacher', fn (): string => app(RattacherUneDeclarationAction::class)->handle($declaration->id, $cabinet->id) ? 'oui' : 'non');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('User-Agent', 'Navigateur du visiteur')->post('/test/rattacher')->assertOk()->assertSee('oui');

        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame(0, DB::table('traces_audit')->where('ip', '203.0.113.7')->orWhere('agent_utilisateur', 'Navigateur du visiteur')->count());
        $this->assertSame(0, DB::table('traces_evenements')->where('ip', '203.0.113.7')->orWhere('agent_utilisateur', 'Navigateur du visiteur')->count());
        $this->assertNotEmpty($this->entreesDAudit($magasin->societe, ActionAuditee::LienActive));
        $this->assertNotEmpty($this->entreesDAudit($cabinet, ActionAuditee::LienActive));
    }

    public function test_seules_les_declarations_du_siren_du_cabinet_se_rattachent(): void
    {
        $avecLeSiren = $this->ouvrirUneSociete(['nom' => 'Avec', 'siren' => SocieteFactory::sirenFictif()]);
        $avecUnAutre = $this->ouvrirUneSociete(['nom' => 'Autre', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($avecLeSiren);
        $this->declarerSonExpertComptable($avecUnAutre, siren: '000000927', email: 'luc.moreau@audit-conseil.test');

        $cabinet = $this->ouvrirUnCabinet();

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($avecLeSiren->societe)->etat, $this->declaration($avecLeSiren->societe)->cabinet_id]);
        $this->assertSame([EtatDuLien::Declare, null], [$this->declaration($avecUnAutre->societe)->etat, $this->declaration($avecUnAutre->societe)->cabinet_id]);
    }

    /**
     * La portée des cabinets vérifiés (Societe::scopeCabinetsVerifies()),
     * Societe::estUnCabinetVerifie() et la garde du rattachement en base
     * (GardesDesCabinets::LIENS) disent la même chose dans chaque état d'un
     * cabinet.
     */
    public function test_la_portee_des_cabinets_verifies_la_garde_du_rattachement_et_est_un_cabinet_verifie_disent_la_meme_chose(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        $declaration = $this->declaration($magasin->societe);
        DB::table('comptes_expert_comptable')->where('lien_id', $declaration->id)->update(['jeton_hache' => null, 'jeton_expire_le' => null]);
        DB::table('users')->whereIn('id', DB::table('comptes_expert_comptable')->where('lien_id', $declaration->id)->select('user_id'))->update(['statut' => 'retire']);

        foreach ([
            'vérifié' => [[], true],
            'suspendu' => [['societes', 'id', 'statut', 'suspendue'], false],
            'résilié' => [['societes', 'id', 'statut', 'resiliee'], false],
            'révoqué' => [['societes', 'id', 'statut', 'revoquee'], false],
            'non validé' => [['societes', 'id', 'validee_le', null], false],
            'activité non contrôlée' => [['cabinets', 'societe_id', 'activite_controlee_le', null], false],
            'Ordre non vérifié' => [['cabinets', 'societe_id', 'ordre_verifie_le', null], false],
            'radié' => [['cabinets', 'societe_id', 'ordre_retire_le', '2026-11-01 10:00:00+00'], false],
        ] as $etat => [$changement, $verifie]) {
            try {
                DB::transaction(function () use ($changement, $verifie, $etat, $cabinet, $declaration): void {
                    if ($changement !== []) {
                        [$table, $cle, $colonne, $valeur] = $changement;
                        DB::table($table)->where($cle, $cabinet->id)->update([$colonne => $valeur]);
                    }

                    $this->assertSame($verifie, Societe::query()->cabinetsVerifies()->whereKey($cabinet->id)->exists(), "portée, {$etat}");
                    $this->assertSame($verifie, Societe::query()->findOrFail($cabinet->id)->estUnCabinetVerifie(), "estUnCabinetVerifie(), {$etat}");
                    $accepte = true;

                    try {
                        $this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration->id)->update(['etat' => 'actif', 'cabinet_id' => $cabinet->id, 'actif_depuis' => now()]))();
                    } catch (QueryException) {
                        $accepte = false;
                    }

                    $this->assertSame($verifie, $accepte, "garde, {$etat}");

                    throw new EtatAnnule;
                });
            } catch (EtatAnnule) {
                // Chaque état s'annule avec son point de sauvegarde.
            }
        }
    }

    /**
     * Chaque geste qui peut rendre un cabinet vérifié envoie, après son
     * commit, la tâche du rattachement de son SIREN : la saisie du code de
     * son courrier, la vérification de son inscription à l'Ordre et le
     * constat de l'éditeur.
     */
    public function test_chaque_geste_qui_peut_rendre_un_cabinet_verifie_envoie_le_rattachement(): void
    {
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);
        $estPourLeSiren = fn (RattacherLesDeclarationsDuSiren $tache): bool => $tache->siren === FauxRepertoireSirene::SIREN_CABINET;

        $proprietaire = $this->saisirLeCodeDuCourrier($cabinet);
        Bus::assertDispatchedTimes(RattacherLesDeclarationsDuSiren::class, 1);
        $this->verifierLOrdre($cabinet, $proprietaire);
        Bus::assertDispatchedTimes(RattacherLesDeclarationsDuSiren::class, 2);
        $this->constaterLOrdre($cabinet);
        Bus::assertDispatchedTimes(RattacherLesDeclarationsDuSiren::class, 3);
        Bus::assertDispatched(RattacherLesDeclarationsDuSiren::class, $estPourLeSiren);
        $this->assertCount(3, Bus::dispatched(RattacherLesDeclarationsDuSiren::class)->filter($estPourLeSiren));
    }

    public function test_chaque_declaration_et_chaque_remplacement_envoient_le_rattachement_du_siren_declare(): void
    {
        $magasin = $this->ouvrirUneSociete();
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);

        $this->declarerSonExpertComptable($magasin);
        Bus::assertDispatched(RattacherLesDeclarationsDuSiren::class, fn (RattacherLesDeclarationsDuSiren $tache): bool => $tache->siren === FauxRepertoireSirene::SIREN_CABINET);

        $this->declarerSonExpertComptable($magasin, siren: '000000927', email: 'luc.moreau@audit-conseil.test', remplacer: true);
        Bus::assertDispatched(RattacherLesDeclarationsDuSiren::class, fn (RattacherLesDeclarationsDuSiren $tache): bool => $tache->siren === '000000927');
        Bus::assertDispatchedTimes(RattacherLesDeclarationsDuSiren::class, 2);
    }

    /**
     * Une messagerie en panne après le rattachement ne perd ni l'avis ni
     * l'e-mail de fermeture : le rattachement reste fait ; l'avis de la
     * cloche s'inscrit, son e-mail, qui n'est qu'un avis, se perd seulement
     * au journal (voir EnvoiImmediat::essayer()) ; la tâche de l'e-mail de
     * fermeture échoue seule, puis, rejouée, n'envoie qu'une fois.
     */
    public function test_une_messagerie_en_panne_apres_le_rattachement_ne_perd_ni_l_avis_ni_l_e_mail_de_fermeture(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->expertComptable($magasin);
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $cabinet = $this->ouvrirUnCabinet();
        Queue::fake([SendQueuedNotifications::class, SendQueuedMailable::class]);
        $messagerie = Mail::getFacadeRoot();
        $this->assertInstanceOf(MailFake::class, $messagerie);
        $messagerie->manager->forgetMailers();
        Mail::swap($messagerie->manager);
        app(ChannelManager::class)->forgetDrivers();

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle($this->declaration($magasin->societe)->id, $cabinet->id));

        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($magasin->societe)->etat, $this->declaration($magasin->societe)->cabinet_id]);
        $avis = Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $tache): bool => $tache->notification instanceof AvisDuCabinetEnFile)->values();
        $fermetures = Queue::pushed(SendQueuedMailable::class, fn (SendQueuedMailable $tache): bool => $tache->mailable instanceof CompteDExpertComptableFermeMail)->values();
        $this->assertSame(['database', 'database', 'mail', 'mail'], $avis->map(fn (SendQueuedNotifications $tache): string => $tache->channels[0])->sort()->values()->all());
        $this->assertCount(1, $fermetures);

        $envois = $messagerie->manager->mailer();
        $transport = $envois->getSymfonyTransport();
        $envois->setSymfonyTransport(new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection to "sandbox.smtp.mailtrap.io:2525" timed out.');
            }

            public function __toString(): string
            {
                return 'en-panne';
            }
        });
        $echecs = 0;

        foreach ([...$avis->all(), ...$fermetures->all()] as $tache) {
            try {
                $this->rejouer($tache);
            } catch (TransportException) {
                $echecs++;
            }

        }

        $this->assertSame(1, $echecs);
        $this->assertSame(0, $transport->messages()->count());
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->where('data->title', 'Votre expert-comptable a rejoint PILIQI')->count());

        $envois->setSymfonyTransport($transport);
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $avant = $transport->messages()->count();

        $this->rejouer($fermetures->sole());

        $this->assertSame(['marc.petit@petit-associes.test'], $transport->messages()->slice($avant)->map(fn (SentMessage $envoi): string => $envoi->getEnvelope()->getRecipients()[0]->getAddress())->values()->all());
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->where('data->title', 'Votre expert-comptable a rejoint PILIQI')->count());
    }

    /**
     * Le cabinet dont l'éditeur corrige le SIREN (lot A7b) garde ses liens ;
     * revérifié sous son nouveau SIREN, il rattache les déclarations de ce
     * SIREN, qui attendaient jusque-là.
     */
    public function test_un_cabinet_dont_le_siren_est_corrige_rattache_les_declarations_de_son_nouveau_siren_une_fois_reverifie_et_garde_ses_liens(): void
    {
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Cliente', 'siren' => SocieteFactory::sirenFictif()]);
        $lien = $this->lierLeCabinet($cabinet, $client);
        $sirenCorrect = SocieteFactory::sirenFictif();
        $declarante = $this->ouvrirUneSociete(['nom' => 'Déclarante', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($declarante, siren: $sirenCorrect, email: 'luc.moreau@petit-associes.test');

        $this->corrigerLeSirenParLEditeur($cabinet, $sirenCorrect);

        $this->assertSame([$sirenCorrect, false], [$cabinet->fresh()->siren, $cabinet->fresh()->estUnCabinetVerifie()]);
        $this->assertSame(EtatDuLien::Declare, $this->declaration($declarante->societe)->etat);

        app(AnnuaireDeLOrdre::class)->inscrire($sirenCorrect, '75-'.substr($sirenCorrect, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);
        $this->verifierLOrdre($cabinet, $this->proprietaireDuCabinet($cabinet));

        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        $this->assertSame([EtatDuLien::Actif, $cabinet->id], [$this->declaration($declarante->societe)->etat, $this->declaration($declarante->societe)->cabinet_id]);
        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
    }

    /**
     * Rejoue une tâche en file retenue par Queue::fake().
     */
    private function rejouer(SendQueuedNotifications|SendQueuedMailable $tache): void
    {
        $tache instanceof SendQueuedNotifications
            ? $tache->handle(app(ChannelManager::class))
            : $tache->handle(app(MailFactory::class));
    }

    /**
     * Un cabinet inscrit sous ce SIREN, d'activité 69.20Z, inscrit à
     * l'annuaire factice de l'Ordre, qui attend le code de son courrier et
     * la vérification de son inscription à l'Ordre.
     */
    private function inscrireLeCabinetDuSiren(string $siren): Societe
    {
        app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $annuaire = app(AnnuaireDeLOrdre::class);
        $this->assertInstanceOf(FauxAnnuaireDeLOrdre::class, $annuaire);
        $annuaire->inscrire($siren, '75-'.substr($siren, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);

        return $this->inscrireUneSociete($siren, "cabinet-{$siren}@cabinet.test", TypeDActivite::Cabinet);
    }

    private function verifierLOrdre(Societe $cabinet, User $proprietaire): void
    {
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet->fresh(), ['numero_ordre' => '75-'.substr((string) $cabinet->siren, -5), 'conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST]);
        app(TenantContext::class)->clear();
    }

    private function constaterLOrdre(Societe $cabinet): void
    {
        app(ConstaterLInscriptionALOrdreAction::class)->handle($cabinet->fresh(), $this->editeur(), '75-'.substr((string) $cabinet->siren, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true);
        app(TenantContext::class)->clear();
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur('Inscription au tableau vérifiée sur l\'annuaire de l\'Ordre', 'DOSSIER-2026-0101', ['alice', 'bruno']);
    }

    /**
     * Rejoue chaque tâche du rattachement de ce SIREN retenue par
     * Bus::fake(), puis les oublie.
     */
    private function rejouerLesTachesDuSiren(string $siren): void
    {
        foreach (Bus::dispatched(RattacherLesDeclarationsDuSiren::class)->filter(fn (RattacherLesDeclarationsDuSiren $tache): bool => $tache->siren === $siren) as $tache) {
            $tache->handle(app(ExpertsComptablesDeclares::class));
        }
    }

    /**
     * La déclaration de cette société, en attente ou non.
     */
    private function declaration(Societe $societe): LienCabinet
    {
        return LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->where('origine', OrigineDuLien::DeclarationDuClient->value)->firstOrFail();
    }

    /**
     * Le lien en cours de cette société (actif ou déclaré).
     */
    private function lienDe(Societe $societe): LienCabinet
    {
        return LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->whereIn('etat', [EtatDuLien::Actif->value, EtatDuLien::Declare->value])->firstOrFail();
    }

    /**
     * @return Collection<int, User>
     */
    private function expertComptablesDeLaSociete(Societe $societe): Collection
    {
        return User::query()->where('societe_id', $societe->id)->where('role_societe', 'expert_comptable')->orderBy('id')->get();
    }
}

/**
 * Annule un état essayé par le test de la portée des cabinets vérifiés.
 */
final class EtatAnnule extends \RuntimeException {}
