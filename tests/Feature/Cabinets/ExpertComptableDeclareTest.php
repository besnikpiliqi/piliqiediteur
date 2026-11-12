<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AjouterUnExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Mail\LienDuMotDePasseDeLExpertComptableMail;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;
use Throwable;

/**
 * L'expert-comptable de la société, déclaré par le SIREN de son cabinet
 * (décision de l'éditeur du 01/11/2026) : seul le propriétaire d'une
 * société validée le déclare, en redonnant son mot de passe ; le SIREN est
 * contrôlé au répertoire Sirene (actif, 69.20Z), la dénomination lue, jamais
 * saisie ; chaque personne reçoit un compte de la société, qui n'a jamais
 * son identifiant pour mot de passe, et le lien de 7 jours de son mot de
 * passe à l'adresse que le propriétaire a saisie deux fois ; un seul
 * cabinet à la fois, remplacé sur confirmation.
 */
class ExpertComptableDeclareTest extends TestCase
{
    use RefreshDatabase;

    public function test_seul_le_proprietaire_declare_son_expert_comptable_en_redonnant_son_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $societe = $magasin->societe;
        $administrateur = $this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes());
        app(TenantContext::class)->clear();

        foreach ([$administrateur, $this->responsable($magasin)] as $compte) {
            $compte = $this->choisirSonMotDePasse($compte);
            $this->assertThrows(fn () => $this->declarer($compte->fresh(), $societe, motDePasse: self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, DeclarerSonExpertComptableAction::MESSAGE_PROPRIETAIRE_SEUL);
        }

        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($magasin), $societe, motDePasse: 'faux'), LogicException::class, MotDePasseRedemande::MESSAGE);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());

        $compte = $this->declarer($this->proprietaireDe($magasin), $societe);

        $this->assertSame(RoleSociete::ExpertComptable, $compte->role_societe);
        $this->assertSame($societe->id, $compte->societe_id);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame([EtatDuLien::Declare, OrigineDuLien::DeclarationDuClient, null, FauxRepertoireSirene::SIREN_CABINET], [$declaration->etat, $declaration->origine, $declaration->cabinet_id, $declaration->siren_cabinet]);
    }

    public function test_le_siren_declare_doit_exister_etre_actif_et_avoir_le_code_69_20z(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $repertoire = app(RepertoireSirene::class);
        $repertoire->exercer('000000026', '47.11B');

        foreach ([
            FauxRepertoireSirene::SIREN_CESSEE => ControleSirene::MOTIF_ENTREPRISE_CESSEE,
            FauxRepertoireSirene::SIREN_INCONNU => ControleSirene::MOTIF_ENTREPRISE_INCONNUE,
            '000000026' => DeclarerSonExpertComptableAction::MESSAGE_PAS_UN_CABINET_COMPTABLE,
        ] as $siren => $message) {
            $this->assertRefusSurLeSiren(fn () => $this->declarer($proprietaire, $magasin->societe, siren: $siren), $message);
        }

        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());
        $this->assertSame(RoleSociete::ExpertComptable, $this->declarer($proprietaire, $magasin->societe)->role_societe);
    }

    public function test_un_code_d_activite_absent_ou_d_une_autre_nomenclature_est_refuse_comme_un_autre_code(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);

        app(RepertoireSirene::class)->exercer('000000919', null);
        $this->assertRefusSurLeSiren(fn () => $this->declarer($proprietaire, $magasin->societe, siren: '000000919'), DeclarerSonExpertComptableAction::MESSAGE_PAS_UN_CABINET_COMPTABLE);

        app(RepertoireSirene::class)->exercer('000000919', ControleSirene::CODE_ACTIVITES_COMPTABLES, 'NAFRev1');
        $this->assertRefusSurLeSiren(fn () => $this->declarer($proprietaire, $magasin->societe, siren: '000000919'), DeclarerSonExpertComptableAction::MESSAGE_PAS_UN_CABINET_COMPTABLE);
    }

    public function test_un_repertoire_injoignable_ne_cree_rien_et_fait_reessayer(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $this->assertRefusSurLeSiren(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: FauxRepertoireSirene::SIREN_INJOIGNABLE), DeclarerSonExpertComptableAction::MESSAGE_REPERTOIRE_INJOIGNABLE);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->count());
        Mail::assertNothingSent();
    }

    public function test_les_controles_sirene_des_declarations_sont_limites_a_dix_par_heure_et_par_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        app(RepertoireSirene::class)->exercer('000000026', '47.11B');

        for ($essai = 1; $essai <= DeclarerSonExpertComptableAction::CONTROLES_DU_REPERTOIRE_PAR_HEURE; $essai++) {
            $this->assertRefusSurLeSiren(fn () => $this->declarer($proprietaire, $magasin->societe, siren: '000000026'), DeclarerSonExpertComptableAction::MESSAGE_PAS_UN_CABINET_COMPTABLE);
        }

        $this->assertRefusSurLeSiren(fn () => $this->declarer($proprietaire, $magasin->societe), DeclarerSonExpertComptableAction::MESSAGE_TROP_DE_CONTROLES);

        $autre = $this->ouvrirUneSociete(['siren' => SocieteFactory::sirenFictif()]);
        $this->assertSame(RoleSociete::ExpertComptable, $this->declarer($this->proprietaireDe($autre), $autre->societe)->role_societe);
        RateLimiter::clear('declaration-expert-comptable:'.$magasin->societe_id);
    }

    public function test_la_societe_ne_se_declare_pas_elle_meme_comme_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $this->assertRefusSurLeSiren(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: (string) $magasin->societe->siren), DeclarerSonExpertComptableAction::MESSAGE_SON_PROPRE_SIREN);
    }

    public function test_la_denomination_du_cabinet_est_lue_au_repertoire_et_jamais_saisie(): void
    {
        $magasin = $this->ouvrirUneSociete();
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, donnees: ['denomination_cabinet' => 'FAUX NOM']);

        $declaration = LienCabinet::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('CABINET PETIT ET ASSOCIES', $declaration->denomination_cabinet);
        $this->assertSame('Marc Petit (expert-comptable déclaré par la société, cabinet CABINET PETIT ET ASSOCIES)', $compte->fresh()->nomPourLHistorique());
    }

    public function test_en_diffusion_partielle_l_ecran_et_le_nom_fige_portent_le_siren_du_cabinet(): void
    {
        $magasin = $this->ouvrirUneSociete();
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE, ControleSirene::CODE_ACTIVITES_COMPTABLES);

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE);

        $this->assertNull(LienCabinet::query()->withoutGlobalScopes()->firstOrFail()->denomination_cabinet);
        $this->assertSame('Marc Petit (expert-comptable déclaré par la société, cabinet SIREN '.FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE.')', User::nomFigeDe($compte->id));
    }

    public function test_le_siren_d_un_cabinet_deja_verifie_ne_se_declare_pas_et_mene_a_la_designation(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete();

        $this->assertRefusSurLeSiren(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe), DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->count());

        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        $this->assertSame(EtatDuLien::DemandeParLeClient, $demande->etat);
    }

    public function test_un_cabinet_inscrit_mais_pas_encore_verifie_se_declare_comme_un_siren_inconnu_de_piliqi(): void
    {
        $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $magasin = $this->ouvrirUneSociete();

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe);

        $this->assertSame(RoleSociete::ExpertComptable, $compte->role_societe);
    }

    public function test_la_declaration_cree_un_compte_de_la_societe_dont_le_mot_de_passe_n_est_jamais_l_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe)->fresh();

        $this->assertFalse($compte->mot_de_passe_est_l_identifiant);
        $this->assertFalse(Hash::check((string) $compte->identifiantDeConnexion(), $compte->password));
        $this->assertNull($compte->magasinDeSonIdentifiant());
        $this->assertSame(0, $compte->affectations()->count());
        $this->assertSame([], $compte->autorisationsDeSociete());
        $this->assertTrue(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail()->lienValable());
    }

    public function test_l_adresse_saisie_par_le_proprietaire_est_le_contact_du_compte_et_jamais_une_adresse_de_connexion(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, email: '  Marc.Petit@Petit-Associes.test ')->fresh();

        $this->assertSame('marc.petit@petit-associes.test', $compte->email);
        $this->assertNull($compte->email_verified_at);
        $this->assertFalse($compte->aUneAdresseDeConnexion());
    }

    public function test_l_adresse_se_saisit_deux_fois_et_deux_adresses_differentes_sont_refusees(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, donnees: ['email_confirmation' => 'marc.petit@petit-associes.tes']), ValidationException::class);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());
    }

    public function test_ni_l_adresse_ni_l_identifiant_n_entrent_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete();

        $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe);

        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::ExpertComptableDeclare);
        $this->assertCount(1, $entrees);
        $texte = json_encode([$entrees[0]->anciennes_valeurs, $entrees[0]->nouvelles_valeurs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('petit-associes.test', $texte);
        $this->assertStringNotContainsString((string) $compte->identifiantDeConnexion(), $texte);
        $this->assertEquals(['siren' => FauxRepertoireSirene::SIREN_CABINET, 'raison_sociale' => LienCabinet::query()->withoutGlobalScopes()->firstOrFail()->denomination_cabinet], $entrees[0]->nouvelles_valeurs['cabinet']);
        $this->assertArrayHasKey((string) $compte->id, $entrees[0]->nouvelles_valeurs['droits']);
    }

    public function test_plusieurs_personnes_du_cabinet_declare_ont_chacune_leur_compte_et_leur_identifiant_avec_les_memes_autorisations(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin, autorisations: [PermissionRegistry::FACTURE_VIEW, 'client.view']);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');

        $this->assertNotSame($marc->identifiantDeConnexion(), $sophie->identifiantDeConnexion());
        $this->assertSame([RoleSociete::ExpertComptable, RoleSociete::ExpertComptable], [$marc->role_societe, $sophie->role_societe]);
        $this->assertSame($marc->fresh()->autorisationsDans($magasin), $sophie->fresh()->autorisationsDans($magasin));
        $this->assertSame(['client.view', PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], $sophie->fresh()->autorisationsDans($magasin));
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->count());
        $this->assertSame(2, CompteExpertComptable::query()->withoutGlobalScopes()->count());
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ExpertComptableCree));
        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, 2);
    }

    public function test_ajouter_une_personne_exige_le_mot_de_passe_du_proprietaire_et_une_declaration_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->firstOrFail();
        $personne = ['name' => 'Sophie Lenoir', 'email' => 'sophie.lenoir@petit-associes.test', 'email_confirmation' => 'sophie.lenoir@petit-associes.test'];

        $this->assertThrows(fn () => app(AjouterUnExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $declaration, $personne, 'faux'), LogicException::class, MotDePasseRedemande::MESSAGE);
        $responsable = $this->choisirSonMotDePasse($this->responsable($magasin));
        $this->assertThrows(fn () => app(AjouterUnExpertComptableAction::class)->handle($responsable, $declaration, $personne, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, DeclarerSonExpertComptableAction::MESSAGE_PROPRIETAIRE_SEUL);

        app(TenantContext::class)->clear();
        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $declaration);
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(AjouterUnExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $declaration->fresh(), $personne, 'password'), LogicException::class, AjouterUnExpertComptableAction::MESSAGE_PLUS_DECLARE);
        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->count());
    }

    public function test_ajouter_une_personne_est_refuse_quand_le_cabinet_du_siren_declare_est_verifie(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->firstOrFail();
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $marc);
        app(TenantContext::class)->clear();
        $this->ouvrirUnCabinet();

        $this->assertThrows(fn () => app(AjouterUnExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $declaration->fresh(), ['name' => 'Sophie Lenoir', 'email' => 'sophie.lenoir@petit-associes.test', 'email_confirmation' => 'sophie.lenoir@petit-associes.test'], 'password'), LogicException::class, AjouterUnExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI);
    }

    public function test_declarer_de_nouveau_le_meme_siren_ou_le_cabinet_lie_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarer($this->proprietaireDe($magasin), $magasin->societe);

        $this->assertRefusSurLeSiren(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, nom: 'Sophie Lenoir', email: 'sophie.lenoir@petit-associes.test', remplacer: true), DeclarerSonExpertComptableAction::MESSAGE_DEJA_DECLARE);

        $cabinet = $this->ouvrirUnCabinet('000000935');
        $autre = $this->ouvrirUneSociete(['siren' => SocieteFactory::sirenFictif()]);
        $this->lierLeCabinet($cabinet, $autre);
        $this->assertRefusSurLeSiren(fn () => $this->declarer($this->proprietaireDe($autre), $autre->societe, siren: '000000935', remplacer: true), DeclarerSonExpertComptableAction::MESSAGE_DEJA_LIE);
    }

    public function test_une_creation_repetee_avec_le_meme_jeton_ne_cree_ni_un_second_compte_ni_un_second_lien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $jeton = (string) Str::uuid();

        $premier = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, jeton: $jeton);
        $second = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, jeton: $jeton);

        $this->assertTrue($premier->is($second));
        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->count());
        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, 1);
    }

    public function test_le_meme_jeton_avec_une_autre_adresse_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $jeton = (string) Str::uuid();
        $this->declarer($this->proprietaireDe($magasin), $magasin->societe, jeton: $jeton);

        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, email: 'autre@petit-associes.test', jeton: $jeton), LogicException::class);
        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->count());
    }

    public function test_declarer_alors_qu_un_cabinet_est_lie_le_remplace_apres_confirmation(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935');
        $magasin = $this->ouvrirUneSociete();
        $lien = $this->lierLeCabinet($cabinet, $magasin);

        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe), ValidationException::class, 'Cochez la case pour le remplacer.');
        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);

        $this->declarer($this->proprietaireDe($magasin), $magasin->societe, remplacer: true);

        $lien = LienCabinet::query()->withoutGlobalScopes()->findOrFail($lien->id);
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::Remplace], [$lien->etat, $lien->motif_fin]);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->count());
        $this->assertFalse($this->proprietaireDuCabinet($cabinet)->fresh()->peutOuvrir($magasin));
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::LienTermine));
        $this->assertCount(0, $this->entreesDAudit($cabinet, ActionAuditee::ExpertComptableDeclare));
    }

    public function test_lier_un_autre_cabinet_met_fin_a_la_declaration_et_retire_ses_comptes(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935');
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin);

        $lien = $this->lierLeCabinet($cabinet, $magasin);

        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('origine', OrigineDuLien::DeclarationDuClient->value)->firstOrFail();
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::Remplace, null], [$declaration->etat, $declaration->motif_fin, $declaration->cabinet_id]);
        $this->assertSame(EtatDuLien::Actif, $lien->etat);
        $marc = $marc->fresh();
        $this->assertSame([StatutCompte::Retire, null], [$marc->statut, $marc->statut_change_par]);
        $this->assertFalse($marc->peutOuvrir($magasin));
        $entree = collect($this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine))->last();
        $this->assertSame(FauxRepertoireSirene::SIREN_CABINET, $entree->nouvelles_valeurs['cabinet']['siren']);
        $this->assertCount(0, $this->entreesDAudit($cabinet, ActionAuditee::LienTermine));
    }

    public function test_declarer_retire_la_demande_de_lien_envoyee_a_un_autre_cabinet_et_le_previent(): void
    {
        $cabinet = $this->ouvrirUnCabinet('000000935');
        $magasin = $this->ouvrirUneSociete();
        $demande = app(DesignerUnCabinetAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, $cabinet);
        app(TenantContext::class)->clear();

        $this->declarer($this->proprietaireDe($magasin), $magasin->societe);

        $this->assertSame(EtatDuLien::Retire, LienCabinet::query()->withoutGlobalScopes()->findOrFail($demande->id)->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienRetiree));
        $this->assertSame(1, $this->proprietaireDuCabinet($cabinet)->notifications()->where('data->title', 'Demande de lien retirée')->count());
    }

    public function test_declarer_un_autre_siren_remplace_la_declaration_et_retire_ses_comptes_apres_confirmation(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarer($this->proprietaireDe($magasin), $magasin->societe);
        app(RepertoireSirene::class)->exercer('000000927', ControleSirene::CODE_ACTIVITES_COMPTABLES);

        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: '000000927', nom: 'Luc Moreau', email: 'luc.moreau@audit-conseil.test'), ValidationException::class, 'Cochez la case pour le remplacer.');

        $luc = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: '000000927', nom: 'Luc Moreau', email: 'luc.moreau@audit-conseil.test', remplacer: true);

        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
        $this->assertSame(StatutCompte::Actif, $luc->fresh()->statut);
        $this->assertNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $marc->id)->firstOrFail()->jeton_expire_le);
        $this->assertSame(['000000927'], LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->pluck('siren_cabinet')->all());
        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, 2);
    }

    /**
     * La déclaration par l'action, comme depuis la page « Cabinet
     * comptable », avec les autorisations par défaut sauf mention.
     *
     * @param  list<string>|null  $autorisations
     * @param  array<string, mixed>  $donnees
     */
    public function test_les_consultations_sont_precochees_et_le_proprietaire_en_decoche_et_ajoute_les_achats_et_le_stock(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Mail::fake();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $consultations = PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce));

        Livewire::test(CabinetComptable::class)->mountAction('declarer')->assertActionDataSet(['consultations' => $consultations, 'achats' => [], 'telechargements' => []]);
        app(TenantContext::class)->clear();

        $voulues = [...array_values(array_diff($consultations, [PermissionRegistry::DEVIS_VIEW])), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::PRODUIT_CREATE];
        $marc = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, autorisations: $voulues);

        $attendues = [...array_values(array_diff(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), [PermissionRegistry::DEVIS_VIEW])), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::PRODUIT_CREATE];
        sort($attendues);
        $this->assertSame($attendues, $marc->fresh()->autorisationsDans($magasin));
        $this->assertNotContains(PermissionRegistry::DEVIS_VIEW, $marc->fresh()->autorisationsDans($magasin));
    }

    public function test_une_societe_de_services_ne_propose_ni_n_accorde_aucune_autorisation_des_articles_a_son_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($magasin);
        $articles = array_values(array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Commerce), PermissionRegistry::articles()));
        $this->assertNotEmpty($articles);

        foreach ($articles as $autorisation) {
            $this->assertThrows(fn () => $this->declarer($proprietaire, $magasin->societe, autorisations: [$autorisation]), ValidationException::class);
        }
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());

        $marc = $this->declarer($proprietaire, $magasin->societe);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        $this->assertSame([], array_values(array_intersect($marc->fresh()->autorisationsDans($magasin), PermissionRegistry::articles())));
        foreach ($articles as $autorisation) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($proprietaire, $declaration, [$autorisation]), InvalidArgumentException::class);
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $declaration->id, 'autorisation' => $autorisation])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(CabinetComptable::class)->assertFormFieldIsHidden('achats')->mountAction('declarer')->assertDontSeeText('Achats et stock');
    }

    public function test_aucune_autorisation_de_vente_de_compte_de_reglage_ni_de_caisse_ne_s_accorde_a_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $interdites = array_values(array_diff(PermissionRegistry::toutes(), PermissionRegistry::duCabinet(TypeDActivite::Commerce)));
        $this->assertNotEmpty($interdites);

        foreach ($interdites as $autorisation) {
            $this->assertThrows(fn () => $this->declarer($proprietaire, $magasin->societe, autorisations: [$autorisation]), ValidationException::class);
        }
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());

        $marc = $this->declarer($proprietaire, $magasin->societe);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        foreach ($interdites as $autorisation) {
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle($proprietaire, $declaration, [$autorisation]), InvalidArgumentException::class);
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $magasin->societe_id, 'lien_id' => $declaration->id, 'autorisation' => $autorisation])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        }
        $this->assertSame([], array_values(array_intersect($marc->fresh()->autorisationsDans($magasin), $interdites)));

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        $page = Livewire::test(CabinetComptable::class)->mountAction('declarer');
        foreach ([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::PAIEMENT_CREER, PermissionRegistry::PARAMETRES_GERER, PermissionRegistry::CLOTURE_CAISSE_GERER] as $absente) {
            $page->assertDontSeeHtml('value="'.$absente.'"');
        }
        $page->assertSeeHtml('value="'.PermissionRegistry::FACTURE_ACHAT_CREER.'"');
    }

    public function test_regler_les_autorisations_change_celles_de_tous_les_comptes_ouverts_et_les_previent(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $marc = $this->declarer($proprietaire, $magasin->societe);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $paul = $this->ajouterUnExpertComptable($magasin->societe, 'Paul Roux', 'paul.roux@petit-associes.test');
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $paul);
        app(TenantContext::class)->clear();
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($proprietaire, $declaration, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);
        app(TenantContext::class)->clear();

        foreach ([$marc, $ines] as $compte) {
            $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_ACHAT_CREER], $compte->fresh()->autorisationsDans($magasin), $compte->name);
            $this->assertContains('Autorisations changées', $compte->notifications()->get()->pluck('data.title')->all(), $compte->name);
        }
        $this->assertNotContains('Autorisations changées', $paul->notifications()->get()->pluck('data.title')->all());
        $this->assertSame([], $paul->fresh()->autorisationsDans($magasin));

        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $paul->fresh());
        app(TenantContext::class)->clear();
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_ACHAT_CREER], $paul->fresh()->autorisationsDans($magasin));
    }

    public function test_la_limite_du_jour_des_creations_vaut_pour_les_experts_comptables(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $limite = CreationDesComptes::limiteDuJour($magasin->societe);
        $this->declarer($proprietaire, $magasin->societe);
        $dejaCrees = User::query()->where('cree_par', $proprietaire->id)->count();
        User::factory()->membreDe($magasin)->count($limite - $dejaCrees)->create(['cree_par' => $proprietaire->id]);

        $this->assertThrows(fn () => $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test'), ValidationException::class, CreationDesComptes::messageDeLaLimite($limite));
        app(RepertoireSirene::class)->exercer('000000927', ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $this->assertThrows(fn () => $this->declarer($proprietaire, $magasin->societe, siren: '000000927', nom: 'Léa Blanc', email: 'lea.blanc@blanc-expertise.test', remplacer: true), ValidationException::class, CreationDesComptes::messageDeLaLimite($limite));
        $this->assertSame(0, User::query()->whereIn('name', ['Inès Garnier', 'Léa Blanc'])->count());
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->where('siren_cabinet', FauxRepertoireSirene::SIREN_CABINET)->count());

        $this->travelTo(self::aParis('2026-10-10 10:00:00'));
        $this->assertSame(RoleSociete::ExpertComptable, $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test')->role_societe);
    }

    public function test_une_societe_suspendue_regle_les_autorisations_et_gere_les_comptes_de_son_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $marc = $this->declarer($proprietaire, $magasin->societe);
        app(TenantContext::class)->runAsSociete($magasin->societe, fn (): bool => $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save());
        app(TenantContext::class)->clear();
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($proprietaire, $declaration, [PermissionRegistry::FACTURE_VIEW]);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $statuts = app(ChangerStatutCompteAction::class);
        $statuts->suspendre($proprietaire, $ines);
        $statuts->reactiver($proprietaire, $ines->fresh());
        $statuts->retirer($proprietaire, $ines->fresh());
        $statuts->reintegrerLExpertComptable($proprietaire, $ines->fresh());
        $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $marc->fresh(), (string) Str::uuid()));
        app(TenantContext::class)->clear();

        $this->assertSame(StatutCompte::Actif, $ines->fresh()->statut);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $declaration->fresh()->autorisations()));
        $this->dansLeMagasin($magasin->fresh(), $ines->fresh());
        $this->assertTrue($ines->fresh()->can('viewAny', Facture::class));
    }

    public function test_la_revocation_de_la_societe_met_fin_a_la_declaration_et_retire_ses_comptes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();

        $this->revoquerLaSociete($magasin->societe);

        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::SocieteRevoquee], [$declaration->fresh()->etat, $declaration->fresh()->motif_fin]);
        foreach ([$marc, $ines] as $compte) {
            $this->assertSame([StatutCompte::Retire, RoleSociete::ExpertComptable], [$compte->fresh()->statut, $compte->fresh()->role_societe], $compte->name);
            $this->assertNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->sole()->jeton_expire_le, $compte->name);
            $this->assertFalse($compte->fresh()->peutOuvrir($magasin), $compte->name);
        }
    }

    public function test_declarer_ajouter_regler_suspendre_retirer_et_reintegrer_s_inscrivent_au_jet_130_de_chaque_magasin_reel_et_a_l_audit(): void
    {
        $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
        $societe = $lyon1->societe;
        $proprietaire = $this->proprietaireDe($lyon1);
        $entrees = fn (User $compte): array => array_map(fn ($magasin): int => count(array_filter($this->modificationsDesDroits($magasin), fn (array $details): bool => $details['membre']['id'] === $compte->id)), [$lyon1, $lyon2]);
        $statuts = app(ChangerStatutCompteAction::class);

        $marc = $this->declarer($proprietaire, $societe);
        $this->assertSame([1, 1], $entrees($marc));
        $ines = $this->ajouterUnExpertComptable($societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $this->assertSame([1, 1], $entrees($ines));
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($proprietaire, $declaration, [PermissionRegistry::FACTURE_VIEW]);
        $this->assertSame([[2, 2], [2, 2]], [$entrees($marc), $entrees($ines)]);
        $statuts->suspendre($proprietaire, $ines->fresh());
        $this->assertSame([3, 3], $entrees($ines));
        $statuts->retirer($proprietaire, $marc->fresh());
        $this->assertSame([3, 3], $entrees($marc));
        $statuts->reintegrerLExpertComptable($proprietaire, $marc->fresh());
        app(TenantContext::class)->clear();
        $this->assertSame([4, 4], $entrees($marc));

        $dernier = collect($this->modificationsDesDroits($lyon2))->where('membre.id', $marc->id)->last();
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], $dernier['apres']['permissions']);
        $this->assertSame(RoleSociete::ExpertComptable->value, $dernier['apres']['role_societe']);
        foreach ([ActionAuditee::ExpertComptableDeclare, ActionAuditee::ExpertComptableCree, ActionAuditee::AutorisationsDuCabinetModifiees, ActionAuditee::CompteSuspendu, ActionAuditee::CompteRetire, ActionAuditee::CompteReintegre] as $action) {
            $this->assertCount(1, $this->entreesDAudit($societe, $action), $action->value);
        }
    }

    private function declarer(User $auteur, Societe $societe, string $siren = FauxRepertoireSirene::SIREN_CABINET, string $nom = 'Marc Petit', string $email = 'marc.petit@petit-associes.test', ?array $autorisations = null, bool $remplacer = false, string $motDePasse = 'password', ?string $jeton = null, array $donnees = []): User
    {
        if (! Mail::getFacadeRoot() instanceof MailFake) {
            Mail::fake();
        }

        app(TenantContext::class)->clear();

        try {
            return app(DeclarerSonExpertComptableAction::class)->handle($auteur, $societe->fresh(), [
                'siren' => $siren,
                'name' => $nom,
                'email' => $email,
                'email_confirmation' => trim($email),
                'autorisations' => $autorisations ?? PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($societe->type_activite)),
                'remplacer' => $remplacer,
                ...$donnees,
            ], $motDePasse, $jeton);
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_retirer_la_derniere_personne_met_fin_a_la_declaration(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->declarerSonExpertComptable($magasin);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $proprietaire = $this->proprietaireDe($magasin);
        $statuts = app(ChangerStatutCompteAction::class);

        $statuts->suspendre($proprietaire, $sophie);
        $statuts->retirer($proprietaire, $marc);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('origine', OrigineDuLien::DeclarationDuClient->value)->firstOrFail();
        $this->assertSame(EtatDuLien::Declare, $declaration->etat);

        $statuts->retirer($proprietaire, $sophie->fresh());

        $declaration->refresh();
        $this->assertSame([EtatDuLien::Termine, MotifDeFinDuLien::ParLeClient, $proprietaire->id], [$declaration->etat, $declaration->motif_fin, $declaration->termine_par]);
        $this->assertSame(0, DB::table('autorisations_cabinet')->where('lien_id', $declaration->id)->count());
        $this->assertSame(StatutCompte::Retire, $sophie->fresh()->statut);
        $entree = collect($this->entreesDAudit($magasin->societe, ActionAuditee::LienTermine))->sole();
        $this->assertSame([FauxRepertoireSirene::SIREN_CABINET, MotifDeFinDuLien::ParLeClient->value], [$entree->nouvelles_valeurs['cabinet']['siren'], $entree->nouvelles_valeurs['motif']]);
    }

    public function test_seul_le_proprietaire_suspend_reactive_retire_reintegre_et_redonne_l_acces_a_un_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $administrateur = $this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes());
        $responsable = $this->membre($magasin, RoleMagasin::Responsable);
        $statuts = app(ChangerStatutCompteAction::class);

        foreach ([$administrateur, $responsable] as $autre) {
            $this->assertThrows(fn () => $statuts->suspendre($autre, $marc), AuthorizationException::class);
            $this->assertThrows(fn () => $statuts->retirer($autre, $marc), AuthorizationException::class);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->parUnNouveauLien($autre, $marc, (string) Str::uuid()), AuthorizationException::class);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($autre, $marc, true, 'Nouveau-Mot-2026!', (string) Str::uuid()), AuthorizationException::class);
        }

        $this->assertFalse(app(PorteeDesDroits::class)->voit($administrateur, $marc));
        $this->assertSame(StatutCompte::Actif, $marc->fresh()->statut);

        $proprietaire = $this->proprietaireDe($magasin);
        $statuts->suspendre($proprietaire, $marc);
        $this->assertThrows(fn () => $statuts->reactiver($administrateur, $marc->fresh()), AuthorizationException::class);
        $statuts->reactiver($proprietaire, $marc->fresh());
        $statuts->retirer($proprietaire, $marc->fresh());
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }

    public function test_suspendre_un_expert_comptable_efface_son_lien_et_la_reactivation_n_en_rouvre_aucun(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $sophie = $this->declarerSonExpertComptable($magasin, nom: 'Sophie Lenoir', email: 'sophie.lenoir@petit-associes.test');
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $proprietaire = $this->proprietaireDe($magasin);
        $statuts = app(ChangerStatutCompteAction::class);

        $statuts->suspendre($proprietaire, $sophie);
        $ligne = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $sophie->id)->firstOrFail();
        $this->assertNull($ligne->jeton_expire_le);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertStatus(410);

        $statuts->reactiver($proprietaire, $sophie->fresh());
        $this->assertNull($ligne->refresh()->jeton_expire_le);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertStatus(410);
        $this->assertSame(StatutCompte::Actif, $sophie->fresh()->statut);
    }

    public function test_un_compte_d_expert_comptable_retire_se_reintegre_tant_que_sa_declaration_est_en_attente_et_jamais_ensuite(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $proprietaire = $this->proprietaireDe($magasin);
        $statuts = app(ChangerStatutCompteAction::class);
        $administrateur = $this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes());

        $statuts->retirer($proprietaire, $marc);
        $this->assertThrows(fn () => $statuts->reintegrerLExpertComptable($administrateur, $marc->fresh()), AuthorizationException::class);
        $statuts->reintegrerLExpertComptable($proprietaire, $marc->fresh());
        $this->assertSame(StatutCompte::Actif, $marc->fresh()->statut);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $marc->fresh()->password));
        $this->assertTrue($marc->fresh()->peutOuvrir($magasin));

        $statuts->retirer($proprietaire, $marc->fresh());
        $statuts->retirer($proprietaire, $sophie->fresh());
        $this->assertSame(EtatDuLien::Termine, LienCabinet::query()->withoutGlobalScopes()->where('origine', OrigineDuLien::DeclarationDuClient->value)->firstOrFail()->etat);

        $this->assertThrows(fn () => $statuts->reintegrerLExpertComptable($proprietaire, $marc->fresh()), AuthorizationException::class);
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }

    public function test_la_propriete_ne_se_transmet_jamais_a_un_expert_comptable_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertFalse($proprietaire->can('transmettreLaPropriete', $marc));
        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $marc, 'password'), Throwable::class);
        $this->assertSame(0, DB::table('transmissions_propriete')->count());
    }

    public function test_une_requete_forgee_ne_rend_personne_expert_comptable_par_le_changement_de_role(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $marc = $this->expertComptable($magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'changerLeRoleDeSociete', ['role_societe' => RoleSociete::ExpertComptable->value, 'mode' => 'plus_tard']);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()]), 'changerLeRoleDeSociete', ['role_societe' => RoleSociete::Administrateur->value, 'mode' => 'plus_tard']);

        $this->assertSame([RoleSociete::Membre, RoleSociete::ExpertComptable], [$vendeur->fresh()->role_societe, $marc->fresh()->role_societe]);
    }

    /**
     * Seule une société validée et ouverte déclare son expert-comptable
     * (P12 a) : ni une inscription qui attend son code, ni une société
     * révoquée, ni un cabinet ; une société suspendue ou résiliée le peut,
     * ses magasins restant en lecture seule.
     */
    public function test_une_societe_close_revoquee_qui_attend_son_code_ou_un_cabinet_ne_declare_pas_mais_une_societe_suspendue_ou_resiliee_le_peut(): void
    {
        app(RepertoireSirene::class)->exercer('000000927', ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $enAttente = $this->inscrireUneSociete();
        $this->assertThrows(fn () => $this->declarer($enAttente->proprietaire()->firstOrFail(), $enAttente, motDePasse: self::MOT_DE_PASSE_CHOISI), LogicException::class, DeclarerSonExpertComptableAction::MESSAGE_SOCIETE_NON_VALIDEE);

        $cabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif());
        $this->assertThrows(fn () => $this->declarer($this->proprietaireDuCabinet($cabinet), $cabinet, siren: '000000927', motDePasse: self::MOT_DE_PASSE_CHOISI), Throwable::class);

        $revoquee = $this->ouvrirUneSociete(['nom' => 'Révoquée', 'siren' => SocieteFactory::sirenFictif()]);
        DB::table('societes')->where('id', $revoquee->societe_id)->update(['statut' => 'revoquee']);
        $this->assertThrows(fn () => $this->declarer($this->proprietaireDe($revoquee), $revoquee->societe, siren: '000000927'), Throwable::class);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->count());

        foreach (['suspendue', 'resiliee'] as $statut) {
            $magasin = $this->ouvrirUneSociete(['nom' => $statut, 'siren' => SocieteFactory::sirenFictif()]);
            DB::table('societes')->where('id', $magasin->societe_id)->update(['statut' => $statut]);

            $compte = $this->declarer($this->proprietaireDe($magasin), $magasin->societe, siren: '000000927', email: "{$statut}@petit-associes.test");

            $this->assertSame(RoleSociete::ExpertComptable, $compte->role_societe, $statut);
            $this->assertTrue($magasin->fresh()->estEnLectureSeule(), $statut);
        }
    }

    public function test_retirer_la_declaration_retire_tous_ses_comptes_et_annule_leurs_liens(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin);
        $jetonDeMarc = $this->jetonDuDernierLienDExpertComptable();
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $jetonDeSophie = $this->jetonDuDernierLienDExpertComptable();
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();

        app(MettreFinAuLienAction::class)->handle($this->proprietaireDe($magasin), $declaration);

        $this->assertSame(EtatDuLien::Termine, $declaration->fresh()->etat);
        $this->assertSame([StatutCompte::Retire, StatutCompte::Retire], [$marc->fresh()->statut, $sophie->fresh()->statut]);
        $this->assertSame(0, CompteExpertComptable::query()->withoutGlobalScopes()->whereNotNull('jeton_hache')->count());
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jetonDeMarc]))->assertStatus(410);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jetonDeSophie]))->assertStatus(410);
        $this->assertFalse($marc->fresh()->peutOuvrir($magasin));
    }

    /**
     * Le rôle d'expert-comptable de la société ne se prend ni ne se quitte :
     * le propriétaire ne change jamais le rôle d'un expert-comptable, et
     * aucun compte ne le devient par un changement de rôle.
     */
    public function test_un_expert_comptable_ne_change_jamais_de_role_et_aucun_compte_ne_le_devient(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertFalse(app(PorteeDesDroits::class)->peutChangerLeRoleDeSociete($proprietaire, $marc));
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $marc->fresh(), null, [], []), Throwable::class);
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versMembre($proprietaire, $marc->fresh(), RoleMagasin::Vendeur, null, $magasin->id), Throwable::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $vendeur->id)->update(['role_societe' => RoleSociete::ExpertComptable->value, 'email' => 'x@exemple.test'])), Throwable::class);

        $this->assertSame([RoleSociete::ExpertComptable, RoleSociete::Membre], [$marc->fresh()->role_societe, $vendeur->fresh()->role_societe]);
    }

    public function test_un_administrateur_ne_voit_ni_ne_touche_aucun_compte_d_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $administrateur = $this->choisirSonMotDePasse($this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes()));
        $statuts = app(ChangerStatutCompteAction::class);

        $this->assertFalse(app(PorteeDesDroits::class)->voit($administrateur, $marc));
        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->assertFalse($administrateur->can('view', $marc));
        Livewire::test(ListMembres::class)->assertCanNotSeeTableRecords([$marc]);
        $this->assertThrows(fn () => $statuts->suspendre($administrateur, $marc), AuthorizationException::class);
        $this->assertThrows(fn () => $statuts->retirer($administrateur, $marc), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->parUnNouveauLien($administrateur, $marc, (string) Str::uuid()), AuthorizationException::class);
        $this->flushSession();
        $this->actingAs($administrateur)->get(MembreResource::getUrl('view', ['record' => $marc], panel: 'societe', tenant: $magasin->societe))->assertNotFound();
        $this->assertSame(StatutCompte::Actif, $marc->fresh()->statut);
    }

    /**
     * Une requête forgée d'un administrateur, même autorisé à tout dans la
     * société, ne déclare ni n'ajoute aucun expert-comptable : la page
     * « Cabinet comptable » lui est refusée (403), il n'en obtient donc
     * aucun instantané Livewire à rejouer, et les actions elles-mêmes le
     * refusent.
     */
    public function test_une_requete_livewire_forgee_d_un_administrateur_ne_declare_ni_n_ajoute_rien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $administrateur = $this->choisirSonMotDePasse($this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes()));
        $this->flushSession();

        $this->actingAs($administrateur)->get(CabinetComptable::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        app(RepertoireSirene::class)->exercer('000000927', ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
        $this->assertThrows(fn () => $this->declarer($administrateur, $magasin->societe, siren: '000000927', nom: 'Intrus', email: 'intrus@exemple.test', remplacer: true, motDePasse: self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
        $this->assertThrows(fn () => app(AjouterUnExpertComptableAction::class)->handle($administrateur, $declaration, ['name' => 'Intrus', 'email' => 'intrus@exemple.test', 'email_confirmation' => 'intrus@exemple.test'], self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);

        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::ExpertComptable->value)->count());
    }

    public function test_la_transmission_de_la_propriete_garde_les_comptes_d_expert_comptable_au_nouveau_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->expertComptable($magasin);
        $repreneur = $this->administrateurPretAReprendre($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($repreneur, $this->demanderLaTransmission($proprietaire, $repreneur), self::MOT_DE_PASSE_CHOISI);
        app(TenantContext::class)->clear();

        $nouveau = $repreneur->fresh();
        $this->assertTrue($nouveau->estProprietaire());
        $this->assertSame([StatutCompte::Actif, RoleSociete::ExpertComptable], [$marc->fresh()->statut, $marc->fresh()->role_societe]);
        $this->assertTrue($marc->fresh()->peutOuvrir($magasin));
        $this->assertFalse(app(PorteeDesDroits::class)->voit($proprietaire->fresh(), $marc));
        app(ChangerStatutCompteAction::class)->suspendre($nouveau, $marc->fresh());
        $this->assertSame(StatutCompte::Suspendu, $marc->fresh()->statut);
    }

    private function assertRefusSurLeSiren(Closure $geste, string $message): void
    {
        try {
            $geste();
            $this->fail("Refus attendu : {$message}");
        } catch (ValidationException $refus) {
            $this->assertSame([$message], $refus->errors()['siren'] ?? []);
        }
    }
}
