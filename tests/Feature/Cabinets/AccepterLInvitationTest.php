<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Jobs\RendreCaduquesLesInvitationsDuSiren;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Controllers\AccepterLInvitationDuCabinetController;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La personne qui a reçu le lien de l'invitation d'un cabinet d'expertise
 * comptable l'accepte en choisissant son mot de passe et le type
 * d'activité de la société, jamais « Cabinet d'expertise comptable » (voir
 * AccepterLInvitationAction) : l'entreprise contrôlée de nouveau active au
 * répertoire Sirene, la société naît validée sans courrier, son identité
 * lue au registre national, liée au cabinet avec les autorisations par
 * défaut de son type ; ou elle refuse l'invitation.
 */
class AccepterLInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesDUnClient(): array
    {
        return ['commerce' => [TypeDActivite::Commerce], 'services' => [TypeDActivite::Services]];
    }

    #[DataProvider('typesDUnClient')]
    public function test_accepter_l_invitation_cree_la_societe_validee_sans_courrier_avec_la_preuve_de_l_invitation_du_cabinet(TypeDActivite $type): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($siren, '47.42Z');
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');
        $envoisAvant = count(app(ServiceEnvoiRecommande::class)->envois());

        $proprietaire = $this->accepterLInvitation($jeton, $type);

        $client = Societe::query()->findOrFail($proprietaire->societe_id);
        $this->assertSame([$type, $siren, 'dirigeant@entreprise.test', RoleSociete::Proprietaire, true], [$client->type_activite, $client->siren, $proprietaire->email, $proprietaire->role_societe, $client->estValidee()]);
        $this->assertNotNull($proprietaire->email_verified_at);
        $this->assertSame(0, DB::table('codes_confirmation')->where('societe_id', $client->id)->count());
        $this->assertSame($envoisAvant, count(app(ServiceEnvoiRecommande::class)->envois()));
        [$inscription] = $this->entreesDAudit($client, ActionAuditee::SocieteInscrite);
        $this->assertSame([$type->value, '47.42Z', 'invitation'], [$inscription->nouvelles_valeurs['type_activite'], $inscription->nouvelles_valeurs['code_activite'], $inscription->nouvelles_valeurs['origine']]);
        [$validation] = $this->entreesDAudit($client, ActionAuditee::SocieteValidee);
        $this->assertSame(['invitation_cabinet', ['id' => $cabinet->id, 'raison_sociale' => $cabinet->raison_sociale, 'siren' => $cabinet->siren]], [$validation->nouvelles_valeurs['preuve'], $validation->nouvelles_valeurs['cabinet']]);
        $lien = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->assertSame([EtatDuLien::Actif, OrigineDuLien::Invitation, $cabinet->id, $client->id], [$lien->etat, $lien->origine, (int) $lien->cabinet_id, (int) $lien->societe_id]);
        $this->assertSame(PermissionRegistry::parDefautDuCabinet($type), $lien->autorisations());
        if ($type === TypeDActivite::Services) {
            $this->assertSame([], array_intersect($lien->autorisations(), PermissionRegistry::articles()));
        }
        $this->assertSame(EtatInvitation::Acceptee, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationAcceptee));
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::LienActive));
        $this->assertCount(1, $this->entreesDAudit($client, ActionAuditee::LienActive));
    }

    /**
     * @return array<string, array{0: list<RepresentantRne>, 1: string}>
     */
    public static function representants(): array
    {
        return [
            'personne physique' => [[new RepresentantRne('MARTIN', null, ['Louise'], '1975-03', 'Gérant', true, null, '2019-01-01')], 'Louise MARTIN'],
            'personne morale dirigeante' => [[new RepresentantRne('HOLDING DUBOIS', null, [], null, 'Président', true, '552100554', '2019-01-01')], 'HOLDING DUBOIS'],
        ];
    }

    /**
     * @param  list<RepresentantRne>  $dirigeants
     */
    #[DataProvider('representants')]
    public function test_l_identite_de_la_societe_est_lue_au_registre_national_a_l_acceptation(array $dirigeants, string $nomAttendu): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $rne = app(RegistreNationalDesEntreprises::class);
        $this->assertInstanceOf(FauxRne::class, $rne);
        $rne->dirigeants($siren, $dirigeants);
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');

        $proprietaire = $this->accepterLInvitation($jeton);

        $client = Societe::query()->findOrFail($proprietaire->societe_id);
        $this->assertSame(["ENTREPRISE {$siren}", FormeJuridique::Sas, '10000.00', 'RCS Paris', '1 RUE DE LA REPUBLIQUE', '75001', 'PARIS'], [$client->raison_sociale, $client->forme_juridique, (string) $client->capital_social, $client->immatriculation, $client->siege_adresse, $client->siege_code_postal, $client->siege_ville]);
        $this->assertSame(IdentifiantsInsee::numeroTvaPropose($siren), $client->numero_tva);
        $this->assertSame($nomAttendu, $proprietaire->name);
        $this->assertStringStartsWith($nomAttendu.', ', (string) $client->representant_legal);
    }

    public function test_un_registre_national_injoignable_refuse_l_acceptation_sans_rien_creer_et_l_invitation_reste_ouverte(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $societes = Societe::query()->count();
        app(RegistreNationalDesEntreprises::class)->rendreInjoignable();

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_REGISTRE_INDISPONIBLE);

        $this->assertSame($societes, Societe::query()->count());
        $this->assertSame(EtatInvitation::Ouverte, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function registresSansRepresentant(): array
    {
        return ['siren inconnu du registre' => [true], 'sans dirigeant qui représente' => [false]];
    }

    #[DataProvider('registresSansRepresentant')]
    public function test_un_siren_inconnu_du_registre_ou_sans_representant_legal_s_accepte_avec_l_identite_du_repertoire_sirene(bool $inconnu): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->nommer($siren, 'BOULANGERIE DU PORT');
        $rne = app(RegistreNationalDesEntreprises::class);
        $inconnu ? $rne->identifier($siren, null) : $rne->dirigeants($siren, [new RepresentantRne('DURAND', null, ['Marc'], null, 'Associé', false, null, null)]);
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');

        $proprietaire = $this->accepterLInvitation($jeton);

        $client = Societe::query()->findOrFail($proprietaire->societe_id);
        $this->assertTrue($client->estValidee());
        $this->assertNull($client->representant_legal);
        $this->assertSame('BOULANGERIE DU PORT', $proprietaire->name);
        $this->assertSame($inconnu ? 'BOULANGERIE DU PORT' : "ENTREPRISE {$siren}", $client->raison_sociale);
        $this->assertSame(EtatDuLien::Actif, LienCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    public function test_le_nouveau_proprietaire_est_connecte_et_mene_a_l_espace_de_sa_societe(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');

        $reponse = $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), [
            'password' => self::MOT_DE_PASSE_CHOISI,
            'password_confirmation' => self::MOT_DE_PASSE_CHOISI,
            'type_activite' => TypeDActivite::Commerce->value,
            'choix_definitif' => '1',
        ]);

        $proprietaire = User::query()->where('email', 'dirigeant@entreprise.test')->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $proprietaire->societe));
        $this->assertAuthenticatedAs($proprietaire);
        $this->assertNotNull($proprietaire->fresh()->derniere_connexion_le);
    }

    public function test_le_lien_d_une_invitation_ne_sert_qu_une_fois(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $this->accepterLInvitation($jeton);

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), InvitationInvalideException::class, InvitationInvalideException::MESSAGE);
        $this->assertSame(1, Societe::query()->where('siren', InvitationCabinet::query()->withoutGlobalScopes()->value('siren'))->count());
    }

    public function test_une_invitation_expiree_ou_retiree_ne_s_accepte_pas(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $expiree = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'a@b.test');
        $retiree = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'c@d.test');
        app(RetirerLInvitationAction::class)->handle($this->proprietaireDuCabinet($cabinet), InvitationCabinet::query()->withoutGlobalScopes()->where('email', 'c@d.test')->sole());

        $this->travelTo(self::aParis('2026-10-19 10:00:01'));

        foreach ([$expiree, $retiree] as $jeton) {
            $this->assertThrows(fn () => $this->accepterLInvitation($jeton), InvitationInvalideException::class, InvitationInvalideException::MESSAGE);
        }
    }

    public function test_une_adresse_deja_adresse_de_connexion_d_un_proprietaire_ne_s_accepte_pas_et_rien_n_est_cree(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre']))->forceFill(['email' => 'prise@exemple.test', 'email_verified_at' => now()])->save();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'prise@exemple.test');
        $societes = Societe::query()->count();

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_ADRESSE_PRISE);

        $this->assertSame($societes, Societe::query()->count());
        $this->assertSame(EtatInvitation::Ouverte, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    public function test_le_formulaire_d_acceptation_demande_le_mot_de_passe_et_le_type_d_activite_sans_cabinet_avec_la_case_du_choix_definitif(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');

        $page = $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk();

        $page->assertSee('name="password"', false)->assertSee('name="password_confirmation"', false)->assertSee('name="choix_definitif"', false);
        $page->assertSee('value="'.TypeDActivite::Commerce->value.'"', false)->assertSee('value="'.TypeDActivite::Services->value.'"', false);
        $page->assertDontSee('value="'.TypeDActivite::Cabinet->value.'"', false)->assertDontSee('checked', false);
        $page->assertSeeText([TypeDActivite::Commerce->aide(), TypeDActivite::Services->aide(), 'J\'ai compris que ce type d\'activité ne pourra plus changer.']);
        $this->assertSame(['password', 'password_confirmation', 'type_activite', 'choix_definitif'], array_values(array_unique(array_filter(preg_match_all('/name="([a-z_]+)"/', $page->getContent(), $champs) ? array_diff($champs[1], ['_token', 'referrer', 'viewport']) : []))));
    }

    /**
     * Sur la page de l'invitation, chaque champ obligatoire (mot de passe,
     * confirmation, activité et case du choix définitif) porte l'astérisque
     * rouge des champs obligatoires (décision de l'éditeur du 01/11/2026).
     */
    public function test_la_page_de_l_invitation_marque_d_un_asterisque_chaque_champ_obligatoire(): void
    {
        $jeton = $this->inviterUneEntreprise($this->ouvrirUnCabinet(), SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');

        $page = $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk();

        $this->assertAsterisquesDesChamps($page->getContent(), ['Votre mot de passe', 'Confirmez votre mot de passe', 'Votre activité', AccepterLInvitationDuCabinetController::CASE_DU_CHOIX_DEFINITIF]);
    }

    public function test_l_acceptation_sans_type_avec_le_type_cabinet_ou_sans_la_case_ne_cree_rien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $societes = Societe::query()->count();
        $base = ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI, 'type_activite' => TypeDActivite::Commerce->value, 'choix_definitif' => true];

        foreach ([[...$base, 'type_activite' => ''], [...$base, 'type_activite' => TypeDActivite::Cabinet->value], [...$base, 'choix_definitif' => false]] as $donnees) {
            $this->assertThrows(fn () => app(AccepterLInvitationAction::class)->handle($jeton, $donnees), ValidationException::class);
        }

        $this->assertSame($societes, Societe::query()->count());
        $this->assertSame(EtatInvitation::Ouverte, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    /**
     * Un formulaire d'acceptation que la validation refuse se réaffiche,
     * avec son erreur et le type d'activité choisi, sans rien créer ni
     * connecter personne : l'invitation reste ouverte.
     */
    public function test_un_formulaire_d_acceptation_refuse_se_reaffiche_avec_son_erreur_et_le_type_choisi(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $societes = Societe::query()->count();

        $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), [
            'password' => self::MOT_DE_PASSE_CHOISI,
            'password_confirmation' => 'un-autre-mot-de-passe',
            'type_activite' => TypeDActivite::Services->value,
            'choix_definitif' => '1',
        ])
            ->assertOk()
            ->assertSeeText('La confirmation du champ mot de passe ne correspond pas.')
            ->assertSee('value="'.TypeDActivite::Services->value.'" checked', false);

        $this->assertGuest();
        $this->assertSame($societes, Societe::query()->count());
        $this->assertSame(EtatInvitation::Ouverte, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function codesLus(): array
    {
        return [
            'services et 47.42Z' => ['47.42Z', 'vendent en général des articles'],
            'commerce et 10.71C' => ['10.71C', 'activité de fabrication'],
            'services et 69.20Z' => ['69.20Z', 'refusez cette invitation et inscrivez votre cabinet vous-même'],
        ];
    }

    #[DataProvider('codesLus')]
    public function test_la_page_d_acceptation_montre_les_avertissements_du_code_lu_au_repertoire(string $code, string $avertissement): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($siren, $code);
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');

        $page = $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk();

        $page->assertSeeText($avertissement)->assertSee('name="password"', false);
        $page->assertDontSeeText('choisissez « '.TypeDActivite::Cabinet->getLabel().' »');
    }

    public function test_le_repertoire_injoignable_a_l_ouverture_du_lien_ne_montre_pas_le_formulaire(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        app(RepertoireSirene::class)->rendreInjoignable();

        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))
            ->assertSeeText(AccepterLInvitationAction::MESSAGE_REPERTOIRE_INDISPONIBLE)
            ->assertDontSee('name="password"', false);
    }

    public function test_la_personne_invitee_refuse_l_invitation_et_le_cabinet_est_prevenu(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $societes = Societe::query()->count();

        $this->post(route('cabinets.invitation.refuser', ['jeton' => $jeton]))->assertOk()->assertSeeText('Vous avez refusé l\'invitation. Aucune société n\'a été créée.');

        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();
        $this->assertSame(EtatInvitation::Refusee, $invitation->etat);
        $this->assertNotNull($invitation->close_le);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationRefusee));
        $this->assertContains('Invitation refusée', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());
        $this->assertSame($societes, Societe::query()->count());
        $this->post(route('cabinets.invitation.refuser', ['jeton' => $jeton]))->assertStatus(410)->assertSeeText(InvitationInvalideException::MESSAGE);
        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), InvitationInvalideException::class);
    }

    /**
     * La personne invitée, qui n'est pas du cabinet, ne laisse ni son
     * adresse IP ni son navigateur au journal d'audit du cabinet, qu'elle
     * refuse ou qu'elle accepte l'invitation ; à l'acceptation, ils restent
     * au journal de la société née, dont elle devient propriétaire.
     */
    public function test_la_personne_invitee_ne_laisse_aucune_trace_au_journal_du_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $refusee = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'refus@entreprise.test');
        $acceptee = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'accord@entreprise.test');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->withHeader('User-Agent', 'NavigateurDuClient/1.0');

        $this->post(route('cabinets.invitation.refuser', ['jeton' => $refusee]))->assertOk();
        $this->post(route('cabinets.invitation.accepter', ['jeton' => $acceptee]), [
            'password' => self::MOT_DE_PASSE_CHOISI,
            'password_confirmation' => self::MOT_DE_PASSE_CHOISI,
            'type_activite' => TypeDActivite::Commerce->value,
            'choix_definitif' => '1',
        ])->assertRedirect();

        $client = User::query()->where('email', 'accord@entreprise.test')->sole()->societe;
        $entreesDuCabinet = array_map(fn ($entree): int => (int) $entree->id, [
            ...$this->entreesDAudit($cabinet, ActionAuditee::InvitationRefusee),
            ...$this->entreesDAudit($cabinet, ActionAuditee::InvitationAcceptee),
            ...$this->entreesDAudit($cabinet, ActionAuditee::LienActive),
        ]);
        $this->assertCount(3, $entreesDuCabinet);
        $this->assertSame(0, DB::table('traces_audit')->whereIn('entree_audit_id', $entreesDuCabinet)->count());
        $this->assertSame(0, DB::table('traces_audit')->where('societe_id', $cabinet->id)->count());
        $inscription = $this->entreesDAudit($client, ActionAuditee::SocieteInscrite)[0];
        $trace = DB::table('traces_audit')->where('entree_audit_id', $inscription->id)->first(['ip', 'agent_utilisateur', 'societe_id']);
        $this->assertSame(['203.0.113.77', 'NavigateurDuClient/1.0', (int) $client->id], [$trace?->ip, $trace?->agent_utilisateur, (int) $trace?->societe_id]);
    }

    public function test_une_entreprise_cessee_entre_l_invitation_et_l_acceptation_ne_s_accepte_pas(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');
        app(RepertoireSirene::class)->cesser($siren);
        $societes = Societe::query()->count();

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_ENTREPRISE_INACTIVE);
        $this->assertSame($societes, Societe::query()->count());
    }

    public function test_un_cabinet_qui_n_est_plus_verifie_ne_fait_plus_accepter_ses_invitations(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $cabinet->cabinet()->sole()->forceFill(['ordre_retire_le' => now()])->save();
        $societes = Societe::query()->count();

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_CABINET_NE_PEUT_PLUS);
        $this->assertSame($societes, Societe::query()->count());
    }

    public function test_un_siren_inscrit_entre_temps_rend_l_invitation_caduque_et_previent_le_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');
        $this->inscrireUneSociete($siren);

        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), LogicException::class, AccepterLInvitationAction::MESSAGE_SIREN_INSCRIT);

        $this->assertSame(EtatInvitation::Caduque, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
        $this->assertSame(['raison' => 'siren_pris', 'siren' => $siren], $this->entreesDAudit($cabinet, ActionAuditee::InvitationCaduque)[0]->nouvelles_valeurs);
        $this->assertSame(1, Societe::query()->where('siren', $siren)->count());
    }

    public function test_l_acceptation_rend_caduques_les_invitations_des_autres_cabinets_pour_ce_siren(): void
    {
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($premier, $siren, 'a@b.test');
        $this->inviterUneEntreprise($second, $siren, 'c@d.test');

        $this->accepterLInvitation($jeton);

        $this->assertSame(EtatInvitation::Caduque, InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $second->id)->sole()->etat);
        $this->assertCount(1, $this->entreesDAudit($second, ActionAuditee::InvitationCaduque));
        $this->assertCount(0, $this->entreesDAudit($premier, ActionAuditee::InvitationCaduque));

        $autreSiren = SocieteFactory::sirenFictif();
        $this->inviterUneEntreprise($second, $autreSiren, 'e@f.test');
        $this->inscrireUneSociete($autreSiren);
        $this->artisan('app:expirer-invitations-cabinets')->assertSuccessful();
        $this->assertSame(EtatInvitation::Caduque, InvitationCabinet::query()->withoutGlobalScopes()->where('siren', $autreSiren)->sole()->etat);
    }

    /**
     * Une invitation qui n'est plus ouverte quand le travail la relit sous
     * son verrou (retirée entre-temps par son cabinet) ne devient pas
     * caduque : son état reste le sien, rien ne s'inscrit au journal
     * d'audit du cabinet, et le cabinet n'est pas prévenu.
     */
    public function test_une_invitation_deja_close_quand_le_travail_la_relit_ne_devient_pas_caduque(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();
        app(RetirerLInvitationAction::class)->handle($this->proprietaireDuCabinet($cabinet), $invitation);
        app(TenantContext::class)->clear();
        $avisAvant = $this->proprietaireDuCabinet($cabinet)->notifications()->count();

        (new RendreCaduquesLesInvitationsDuSiren($siren, 0))->rendreCaduque($invitation->id, $cabinet->id);

        $this->assertSame(EtatInvitation::Retiree, $invitation->fresh()->etat);
        $this->assertSame([], $this->entreesDAudit($cabinet, ActionAuditee::InvitationCaduque));
        $this->assertSame($avisAvant, $this->proprietaireDuCabinet($cabinet)->notifications()->count());
    }

    public function test_l_acceptation_d_une_societe_sans_magasin_ne_verrouille_aucun_registre_et_active_le_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $verrous = [];
        DB::listen(function ($requete) use (&$verrous): void {
            if (str_contains($requete->sql, (new TeteRegistreFiscal)->getTable())) {
                $verrous[] = $requete->sql;
            }
        });

        $proprietaire = $this->accepterLInvitation($jeton);

        $this->assertSame([], $verrous);
        $this->assertSame(0, Societe::query()->findOrFail($proprietaire->societe_id)->magasins()->count());
        $this->assertSame(EtatDuLien::Actif, LienCabinet::query()->withoutGlobalScopes()->sole()->etat);
    }

    public function test_l_ouverture_du_lien_ne_change_rien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $avant = [Societe::query()->count(), DB::table('invitations_cabinet')->get()->all(), DB::table('journal_audit')->count()];

        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk();
        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertOk();

        $this->assertEquals($avant, [Societe::query()->count(), DB::table('invitations_cabinet')->get()->all(), DB::table('journal_audit')->count()]);
    }

    public function test_le_proprietaire_valide_par_une_invitation_signe_les_attestations_a_la_main(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->accepterLInvitation($this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test'));

        $this->assertNull($proprietaire->fresh()->code_du_courrier_saisi_le);
        $this->assertFalse($proprietaire->fresh()->signeLesAttestationsDansLApplication());
    }

    public function test_la_societe_nee_d_une_invitation_ne_change_jamais_de_type(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->accepterLInvitation($this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test'), TypeDActivite::Services);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $proprietaire->societe_id)->update(['type_activite' => TypeDActivite::Commerce->value])), QueryException::class);
        $this->assertNotContains('type_activite', (new Societe)->getFillable());
    }

    public function test_la_page_d_acceptation_ne_montre_rien_d_un_autre_cabinet(): void
    {
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $siren = SocieteFactory::sirenFictif();
        $jeton = $this->inviterUneEntreprise($premier, $siren, 'a@b.test');
        $this->inviterUneEntreprise($second, $siren, 'c@d.test');

        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSeeText((string) $premier->raison_sociale)
            ->assertDontSeeText([(string) $second->raison_sociale, 'c@d.test']);
    }

    public function test_un_compte_ne_change_rien_a_l_invitation_avant_son_lien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');

        app(RefuserLInvitationAction::class)->handle($jeton);

        $this->assertSame(0, app(TenantContext::class)->runAsSociete($cabinet, fn (): int => LienCabinet::query()->duCabinet($cabinet)->count()));
    }
}
