<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Enums\ResultatDInvitation;
use App\Domain\Cabinets\Exceptions\CabinetNonVerifieException;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Mail\DemandeDeRattachementMail;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesCabinets;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire d'un cabinet d'expertise comptable vérifié invite un
 * client par son seul SIREN, avec l'adresse e-mail à laquelle part le lien,
 * en redonnant son mot de passe (voir InviterUneEntrepriseAction) : un
 * SIREN libre reçoit une invitation, un SIREN déjà pris une demande de
 * rattachement à la société qui le tient, dont le cabinet ne connaît que le
 * SIREN tant que le lien n'est pas actif.
 */
class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_du_cabinet_invite_un_siren_libre_et_actif_en_redonnant_son_mot_de_passe(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();

        $jeton = $this->inviterUneEntreprise($cabinet, $siren, 'Dirigeant@Entreprise.test');

        Mail::assertSent(InvitationDuCabinetMail::class, fn (InvitationDuCabinetMail $mail): bool => $mail->hasTo('dirigeant@entreprise.test'));
        $invitation = app(TenantContext::class)->runAsSociete($cabinet, fn (): InvitationCabinet => InvitationCabinet::query()->sole());
        $this->assertSame([$siren, 'dirigeant@entreprise.test', EtatInvitation::Ouverte, hash('sha256', $jeton)], [$invitation->siren, $invitation->email, $invitation->etat, DB::table('invitations_cabinet')->value('jeton_hache')]);
        $this->assertMemeInstant(self::aParis('2026-10-19 10:00:00'), $invitation->expire_le);
        [$entree] = $this->entreesDAudit($cabinet, ActionAuditee::InvitationEnvoyee);
        $this->assertSame(['siren' => $siren], $entree->nouvelles_valeurs);
        $this->assertStringNotContainsString('entreprise.test', json_encode($entree->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_l_e_mail_d_invitation_nomme_le_cabinet_et_le_siren_invite(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $this->inviterUneEntreprise($cabinet, $siren, 'dirigeant@entreprise.test');

        $mail = Mail::sent(InvitationDuCabinetMail::class)->sole();
        $rendu = $mail->render();

        $this->assertStringContainsString((string) $cabinet->raison_sociale, $mail->envelope()->subject);
        foreach ([$siren, 'mot de passe', 'type d\'activité', 'sans courrier ni QR code', 'refuser'] as $texte) {
            $this->assertStringContainsString($texte, html_entity_decode($rendu, ENT_QUOTES));
        }
    }

    public function test_le_cabinet_n_invite_que_par_un_siren(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        Mail::fake();

        foreach (['Boulangerie Martin', '123456789', '12345678'] as $saisie) {
            $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $saisie, 'a@b.test', self::MOT_DE_PASSE_CHOISI), ValidationException::class);
        }

        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(InvitationsEtDemandes::class)
            ->callAction('inviter', ['siren' => 'Boulangerie Martin', 'email' => 'a@b.test', 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
            ->assertHasActionErrors(['siren']);
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('invitations_cabinet')->count());
    }

    /**
     * « Inviter un client », dans l'espace du cabinet, dit ce qu'a fait le
     * geste : une invitation à un SIREN libre, puis qu'elle est déjà
     * ouverte ; une demande de rattachement à un SIREN inscrit, puis qu'elle
     * est déjà en cours ; un geste refusé (le SIREN du cabinet lui-même)
     * s'affiche, sans rien envoyer de plus.
     */
    public function test_inviter_un_client_depuis_l_espace_du_cabinet_dit_ce_que_le_geste_a_fait(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $inscrit = (string) $this->ouvrirUneSociete()->societe->siren;
        $libre = SocieteFactory::sirenFictif();
        Mail::fake();
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        foreach ([
            [$libre, ResultatDInvitation::InvitationEnvoyee],
            [$libre, ResultatDInvitation::InvitationDejaEnvoyee],
            [$inscrit, ResultatDInvitation::DemandeEnvoyee],
            [$inscrit, ResultatDInvitation::DemandeDejaEnvoyee],
        ] as [$siren, $resultat]) {
            Livewire::test(InvitationsEtDemandes::class)
                ->callAction('inviter', ['siren' => $siren, 'email' => 'dirigeant@entreprise.test', 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
                ->assertHasNoActionErrors()
                ->assertNotified($resultat->message('dirigeant@entreprise.test'));
        }

        Livewire::test(InvitationsEtDemandes::class)
            ->callAction('inviter', ['siren' => (string) $cabinet->siren, 'email' => 'dirigeant@entreprise.test', 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
            ->assertNotified(InviterUneEntrepriseAction::MESSAGE_SOI_MEME);

        Mail::assertSent(InvitationDuCabinetMail::class, 1);
        Mail::assertSent(DemandeDeRattachementMail::class, 1);
        $this->assertSame(1, DB::table('invitations_cabinet')->count());
    }

    public function test_un_mot_de_passe_faux_n_envoie_aucune_invitation(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        Mail::fake();

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, SocieteFactory::sirenFictif(), 'a@b.test', 'pas-le-bon'), LogicException::class, MotDePasseRedemande::MESSAGE);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('invitations_cabinet')->count());
    }

    public function test_une_entreprise_cessee_ou_inconnue_n_est_pas_invitee(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        Mail::fake();

        foreach ([FauxRepertoireSirene::SIREN_CESSEE, FauxRepertoireSirene::SIREN_INCONNU] as $siren) {
            $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'a@b.test', self::MOT_DE_PASSE_CHOISI), ValidationException::class);
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('invitations_cabinet')->count());
    }

    public function test_un_siren_pris_envoie_a_l_adresse_saisie_une_demande_de_rattachement_qui_nomme_le_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'raison_sociale' => 'BOULANGERIE SECRETE'])->societe;
        Mail::fake();

        $resultat = app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $client->siren, 'compta@cliente.test', self::MOT_DE_PASSE_CHOISI);

        $this->assertSame(ResultatDInvitation::DemandeEnvoyee, $resultat);
        Mail::assertNotSent(InvitationDuCabinetMail::class);
        $mail = Mail::sent(DemandeDeRattachementMail::class)->sole();
        $this->assertTrue($mail->hasTo('compta@cliente.test'));
        $rendu = html_entity_decode($mail->render(), ENT_QUOTES);
        $this->assertStringContainsString((string) $client->siren, $rendu);
        $this->assertStringContainsString((string) $cabinet->raison_sociale, $mail->envelope()->subject);
        $this->assertStringNotContainsString('BOULANGERIE SECRETE', $rendu);
        $this->assertSame(0, DB::table('invitations_cabinet')->count());
        $lien = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->assertSame([EtatDuLien::DemandeParLeCabinet, OrigineDuLien::DemandeDuCabinet, $client->id], [$lien->etat, $lien->origine, (int) $lien->societe_id]);
        $this->assertSame(hash('sha256', Str::afterLast($mail->lien, '/')), DB::table('liens_cabinet')->value('jeton_hache'));
        $this->assertStringNotContainsString('cliente.test', json_encode(DB::table('liens_cabinet')->get()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('cliente.test', json_encode(DB::table('journal_audit')->get()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_le_proprietaire_de_la_societe_inscrite_voit_la_demande_dans_son_espace_sans_second_e_mail(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['email' => 'patron@cliente.test', 'email_verified_at' => now()])->save();

        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');

        Mail::assertNotSent(DemandeDeRattachementMail::class, fn ($mail): bool => $mail->hasTo('patron@cliente.test'));
        $avis = $proprietaire->notifications()->sole();
        $this->assertSame('Demande d\'un cabinet comptable', $avis->data['title']);
        $this->assertStringContainsString('rattachement-cabinet/', $avis->data['actions'][0]['url']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(TableauDeBord::class)->assertSeeText($cabinet->raison_sociale.' demande à suivre votre société');
        Livewire::test(CabinetComptable::class)->assertSeeText('demande à suivre votre société');
    }

    public function test_un_siren_tenu_par_un_cabinet_est_refuse_par_un_message_neutre(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autre = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        Mail::fake();

        $refus = null;
        try {
            app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $autre->siren, 'a@b.test', self::MOT_DE_PASSE_CHOISI);
        } catch (LogicException $exception) {
            $refus = $exception->getMessage();
        }

        $this->assertSame(InviterUneEntrepriseAction::MESSAGE_SIREN_NEUTRE, $refus);
        $this->assertStringNotContainsStringIgnoringCase('cabinet d\'expertise', (string) $refus);
        $this->assertSame([0, 0], [DB::table('invitations_cabinet')->count(), DB::table('liens_cabinet')->count()]);
        Mail::assertNothingSent();
    }

    public function test_avant_le_lien_actif_le_cabinet_ne_voit_que_le_siren_qu_il_a_saisi(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'raison_sociale' => 'BOULANGERIE SECRETE', 'siege_ville' => 'Villesecrete'])->societe;
        $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        Livewire::test(InvitationsEtDemandes::class)->assertSeeText((string) $client->siren)->assertDontSeeText(['BOULANGERIE SECRETE', 'Villesecrete']);

        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        app(TenantContext::class)->clear();
        app(RefuserLaDemandeDeLienAction::class)->handle($client->proprietaire()->sole(), $demande);
        $this->assertStringNotContainsString('BOULANGERIE SECRETE', json_encode($proprietaire->notifications()->get()->pluck('data')->all(), JSON_THROW_ON_ERROR));
        $journal = json_encode(app(TenantContext::class)->runAsSociete($cabinet, fn () => EntreeAudit::query()->get()->map->getAttributes()->all()), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('BOULANGERIE SECRETE', $journal);
        $this->assertStringNotContainsString('Villesecrete', $journal);
    }

    /**
     * Les demandes de rattachement envoyées montrent leur état : une
     * demande refusée ou retirée reste visible, avec la date de sa fin, tant
     * qu'elle empêche une nouvelle demande au même SIREN, sans le bouton
     * « Retirer », que garde seule une demande en cours ; passé ce délai,
     * elle disparaît ; une demande dont l'échéance est passée se lit
     * expirée, avant même le passage de la tâche horaire.
     */
    public function test_les_demandes_envoyees_montrent_leur_etat_tant_qu_elles_empechent_une_nouvelle_demande(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $refusee = $this->ouvrirUneSociete(['nom' => 'Boutique refusée'])->societe;
        $retiree = $this->ouvrirUneSociete(['nom' => 'Boutique retirée'])->societe;
        $enCours = $this->ouvrirUneSociete(['nom' => 'Boutique en cours'])->societe;
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        foreach ([$refusee, $retiree, $enCours] as $client) {
            $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        }
        $demandeDe = fn (Societe $client): LienCabinet => LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->id)->sole();
        $this->travelTo(self::aParis('2026-10-13 09:00:00'));
        app(TenantContext::class)->clear();
        app(RefuserLaDemandeDeLienAction::class)->handle($refusee->proprietaire()->sole(), $demandeDe($refusee));
        app(RetirerLaDemandeDeLienAction::class)->handle($proprietaire, $demandeDe($retiree));

        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);
        $page = Livewire::test(InvitationsEtDemandes::class);

        $this->assertEqualsCanonicalizing([
            [(string) $refusee->siren, 'Refusée', false],
            [(string) $retiree->siren, 'Retirée', false],
            [(string) $enCours->siren, 'En cours', true],
        ], $page->instance()->demandesEnvoyees()->map(fn (array $demande): array => [$demande['siren'], $demande['etat'], $demande['en_cours']])->all());
        $page->assertSeeText(['Refusée le 13/10/2026', 'Retirée le 13/10/2026', 'expire le 19/10/2026 · En cours']);
        $this->assertSame(1, substr_count($page->html(), 'Retirer'));

        $this->travelTo(self::aParis('2026-10-20 09:00:01'));
        $page = Livewire::test(InvitationsEtDemandes::class);
        $this->assertSame([[(string) $enCours->siren, 'Expirée', false]], $page->instance()->demandesEnvoyees()->map(fn (array $demande): array => [$demande['siren'], $demande['etat'], $demande['en_cours']])->all());
        $page->assertSeeText('Expirée le 19/10/2026');
        $this->assertSame(0, substr_count($page->html(), 'Retirer'));
    }

    public function test_une_demande_compte_dans_les_cinquante_par_jour_et_ne_se_renouvelle_pas_avant_sept_jours(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        $this->assertSame(1, RateLimiter::attempts("emails:personne:{$proprietaire->id}"));

        app(RetirerLaDemandeDeLienAction::class)->handle($proprietaire, LienCabinet::query()->withoutGlobalScopes()->sole());
        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, (string) $client->siren, 'compta@cliente.test', self::MOT_DE_PASSE_CHOISI), LogicException::class, InviterUneEntrepriseAction::MESSAGE_DEMANDE_RECENTE);

        $this->travelTo(self::aParis('2026-10-19 10:01:00'));
        $this->assertSame(ResultatDInvitation::DemandeEnvoyee, app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, (string) $client->siren, 'compta@cliente.test', self::MOT_DE_PASSE_CHOISI));
    }

    /**
     * Une demande de rattachement dont l'échéance est passée, que la tâche
     * horaire n'a pas encore expirée, n'est pas « déjà envoyée » : inviter
     * de nouveau ce SIREN la lit expirée et refuse une nouvelle demande
     * avant 7 jours après son échéance, sans rien écrire ; ensuite, elle
     * expire, son lien ne valant plus rien, et la nouvelle demande part.
     */
    public function test_inviter_de_nouveau_apres_l_echeance_expire_la_demande_perimee_sans_attendre_la_tache_horaire(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();

        $this->travelTo(self::aParis('2026-10-19 10:30:00'));
        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, (string) $client->siren, 'compta@cliente.test', self::MOT_DE_PASSE_CHOISI), LogicException::class, InviterUneEntrepriseAction::MESSAGE_DEMANDE_RECENTE);

        $this->assertSame(EtatDuLien::DemandeParLeCabinet, $demande->fresh()->etat);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->count());

        $this->travelTo(self::aParis('2026-10-26 10:00:01'));
        $this->assertSame(ResultatDInvitation::DemandeEnvoyee, app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, (string) $client->siren, 'compta@cliente.test', self::MOT_DE_PASSE_CHOISI));

        $this->assertSame([EtatDuLien::Expire, null], [$demande->fresh()->etat, $demande->fresh()->jeton_hache]);
        $this->assertSame(2, LienCabinet::query()->withoutGlobalScopes()->count());
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienExpiree));
    }

    public function test_une_invitation_repetee_a_la_meme_adresse_ne_cree_ni_ne_renvoie_rien(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $siren = SocieteFactory::sirenFictif();
        $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');

        $this->assertSame(ResultatDInvitation::InvitationDejaEnvoyee, app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, $siren, 'A@B.test', self::MOT_DE_PASSE_CHOISI));
        Mail::assertSent(InvitationDuCabinetMail::class, 1);
        $this->assertSame(1, DB::table('invitations_cabinet')->count());

        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        foreach (['compta@cliente.test', 'autre@cliente.test'] as $adresse) {
            $this->assertSame(ResultatDInvitation::DemandeDejaEnvoyee, app(InviterUneEntrepriseAction::class)->handle($proprietaire, $cabinet, (string) $client->siren, $adresse, self::MOT_DE_PASSE_CHOISI));
        }
        Mail::assertSent(DemandeDeRattachementMail::class, 1);
        $this->assertSame(1, DB::table('liens_cabinet')->count());
    }

    public function test_une_invitation_refusee_ne_se_renouvelle_pas_avant_sept_jours(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        app(RefuserLInvitationAction::class)->handle($this->inviterUneEntreprise($cabinet, $siren, 'a@b.test'));

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'a@b.test', self::MOT_DE_PASSE_CHOISI), LogicException::class, InviterUneEntrepriseAction::MESSAGE_INVITATION_REFUSEE);

        $this->travelTo(self::aParis('2026-10-19 10:01:00'));
        $this->assertSame(ResultatDInvitation::InvitationEnvoyee, app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'a@b.test', self::MOT_DE_PASSE_CHOISI));
    }

    public function test_une_seconde_invitation_du_meme_siren_a_une_autre_adresse_est_refusee_tant_que_la_premiere_est_ouverte(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $siren = SocieteFactory::sirenFictif();
        $this->inviterUneEntreprise($cabinet, $siren, 'a@b.test');

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'autre@b.test', self::MOT_DE_PASSE_CHOISI), LogicException::class, InviterUneEntrepriseAction::MESSAGE_INVITATION_OUVERTE);
        $this->assertSame(1, DB::table('invitations_cabinet')->count());
    }

    public function test_le_cabinet_retire_une_invitation_ouverte_et_son_lien_ne_vaut_plus(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'a@b.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();

        app(RetirerLInvitationAction::class)->handle($this->proprietaireDuCabinet($cabinet), $invitation);

        $relue = $invitation->fresh();
        $this->assertSame([EtatInvitation::Retiree, $this->proprietaireDuCabinet($cabinet)->id, 'Claire Martin (cabinet '.$cabinet->raison_sociale.')'], [$relue->etat, (int) $relue->retiree_par, $relue->retiree_par_nom]);
        $this->assertNotNull($relue->close_le);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationRetiree));
        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), InvitationInvalideException::class);
        $this->get(route('cabinets.invitation.afficher', ['jeton' => $jeton]))->assertStatus(410)->assertSeeText(InvitationInvalideException::MESSAGE);
    }

    /**
     * « Retirer », dans l'espace du cabinet : son propriétaire retire une
     * invitation ouverte et une demande de rattachement en cours, chacune
     * le dit, et leurs liens ne servent plus.
     */
    public function test_le_proprietaire_du_cabinet_retire_une_invitation_et_une_demande_depuis_son_espace(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $inscrite = $this->ouvrirUneSociete(['nom' => 'Boutique inscrite'])->societe;
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@libre.test');
        $this->demanderLeRattachement($cabinet, $inscrite, 'dirigeant@inscrite.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        Livewire::test(InvitationsEtDemandes::class)
            ->callAction('retirerLInvitation', arguments: ['uuid' => $invitation->uuid])
            ->assertNotified('L\'invitation est retirée.')
            ->callAction('retirerLaDemande', arguments: ['uuid' => $demande->uuid])
            ->assertNotified('La demande est retirée.');

        $this->assertSame([EtatInvitation::Retiree, EtatDuLien::Retire], [$invitation->fresh()->etat, $demande->fresh()->etat]);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationRetiree));
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::DemandeDeLienRetiree));
    }

    /**
     * Un cabinet suspendu ne retire plus ses invitations ouvertes : son
     * propriétaire peut seulement mettre fin à ses liens (voir
     * ControleDesDroits) ; l'invitation reste ouverte.
     */
    public function test_un_cabinet_suspendu_ne_retire_plus_ses_invitations(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'a@b.test');
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

        $this->assertThrows(fn () => app(RetirerLInvitationAction::class)->handle($this->proprietaireDuCabinet($cabinet), $invitation), AuthorizationException::class);
        $this->assertSame(EtatInvitation::Ouverte, $invitation->fresh()->etat);
    }

    public function test_une_invitation_expire_apres_sept_jours(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'a@b.test');

        $this->travelTo(self::aParis('2026-10-19 10:00:01'));
        $this->artisan('app:expirer-invitations-cabinets')->assertSuccessful();

        $this->assertSame(EtatInvitation::Expiree, InvitationCabinet::query()->withoutGlobalScopes()->sole()->etat);
        $this->assertThrows(fn () => $this->accepterLInvitation($jeton), InvitationInvalideException::class);
    }

    /**
     * La limite se compte par jour de Paris : la quatrième invitation de la
     * journée est refusée, et la limite repart après minuit à Paris.
     */
    public function test_un_cabinet_n_envoie_pas_plus_de_cinquante_invitations_par_jour(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        config(['cabinets.invitations_par_jour' => 3]);
        $cabinet = $this->ouvrirUnCabinet();
        $this->travelTo(self::aParis('2026-10-12 23:58:00'));

        foreach (range(1, 3) as $numero) {
            $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), "client{$numero}@b.test");
        }

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, SocieteFactory::sirenFictif(), 'client4@b.test', self::MOT_DE_PASSE_CHOISI), LogicException::class, 'Votre cabinet a déjà envoyé 3 invitations ou demandes aujourd\'hui : réessayez demain.');
        $this->assertSame(3, DB::table('invitations_cabinet')->count());
        $this->assertSame(50, (require config_path('cabinets.php'))['invitations_par_jour']);

        $this->travelTo(self::aParis('2026-10-13 00:00:01'));
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'client4@b.test');
        $this->assertSame(4, DB::table('invitations_cabinet')->count());
    }

    public function test_l_invitation_ne_part_pas_si_le_cabinet_n_est_plus_verifie_au_moment_de_l_envoi(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        Mail::fake();
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'a@b.test');
        Mail::fake();
        $invitation = InvitationCabinet::query()->withoutGlobalScopes()->sole();
        $cabinet->forceFill(['statut' => StatutSociete::Suspendue])->save();

        app(EnvoiDesLiensDesCabinets::class)->envoyerLInvitation((int) $invitation->getKey(), str_repeat('a', 64));

        Mail::assertNothingSent();
        $this->assertContains('E-mail non envoyé', $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.title')->all());

        $cabinet->forceFill(['statut' => StatutSociete::Active])->save();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');
        Mail::fake();
        $demande = LienCabinet::query()->withoutGlobalScopes()->sole();
        app(RetirerLaDemandeDeLienAction::class)->handle($this->proprietaireDuCabinet($cabinet), $demande);
        app(EnvoiDesLiensDesCabinets::class)->envoyerLaDemande((int) $demande->getKey(), 'compta@cliente.test', str_repeat('b', 64));
        Mail::assertNothingSent();
    }

    public function test_un_collaborateur_n_invite_pas(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);
        Mail::fake();

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($collaborateur, $cabinet, SocieteFactory::sirenFictif(), 'a@b.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class);
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        $this->get(InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet))->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_deux_cabinets_peuvent_inviter_le_meme_siren_sans_rien_apprendre_l_un_de_l_autre(): void
    {
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $siren = SocieteFactory::sirenFictif();

        $this->inviterUneEntreprise($premier, $siren, 'a@b.test');
        $this->inviterUneEntreprise($second, $siren, 'c@d.test');

        $this->assertSame(2, DB::table('invitations_cabinet')->where('siren', $siren)->count());
        $this->dansLEspaceDuCabinet($second, $this->proprietaireDuCabinet($second));
        Livewire::test(InvitationsEtDemandes::class)->assertSeeText('c@d.test')->assertDontSeeText(['a@b.test', (string) $premier->raison_sociale]);
    }

    public function test_un_cabinet_non_verifie_n_invite_pas(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $cabinet->cabinet()->sole()->forceFill(['ordre_verifie_le' => null])->save();
        Mail::fake();

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, SocieteFactory::sirenFictif(), 'a@b.test', self::MOT_DE_PASSE_CHOISI), CabinetNonVerifieException::class);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(InvitationsEtDemandes::class)->assertActionDisabled('inviter');
    }
}
