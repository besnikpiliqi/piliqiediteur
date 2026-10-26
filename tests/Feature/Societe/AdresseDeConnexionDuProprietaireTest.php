<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Mail\InscriptionCloseMail;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Societe\Pages\TableauDeBord;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * L'adresse de connexion du propriétaire, décisions de l'éditeur du
 * 24/10/2026 :
 * - l'adresse que choisit une autre personne que celle qui a inscrit la
 *   société, en saisissant le code du courrier, ne sert à la connexion et
 *   à « Mot de passe oublié » qu'une fois confirmée par un lien envoyé à
 *   cette adresse, valable 24 heures, suivi connecté à ce compte ; d'ici
 *   là, la personne se connecte avec son identifiant ; un lien expiré se
 *   redemande depuis le profil ; une adresse déjà adresse de connexion
 *   d'un autre propriétaire est refusée au choix et à la confirmation
 *   (voir SaisirCodeDeConfirmationAction, DemanderUneNouvelleAdresseAction
 *   et ConfirmerNouvelleAdresseAction) ;
 * - à la clôture d'une inscription, sans code ou par le refus de
 *   l'éditeur, l'adresse de son propriétaire cesse d'être une adresse de
 *   connexion : le compte ne s'ouvre plus qu'avec son identifiant, et
 *   l'adresse peut servir à une nouvelle inscription (voir
 *   CloreLInscriptionAction).
 * Le refus d'une adresse prise au moment du choix est essayé dans
 * VerificationSocieteTest, le coût d'un essai de connexion par une telle
 * adresse dans ConnexionParIdentifiantTest, deux confirmations simultanées
 * et une demande d'adresse pendant la clôture dans
 * AdresseDeConnexionConcurrenceTest, la transmission de la propriété
 * d'une inscription close dans TransfererProprieteTest, l'isolation des
 * sociétés dans IsolationDesSocietesTest.
 */
class AdresseDeConnexionDuProprietaireTest extends TestCase
{
    use RefreshDatabase;

    private const ADRESSE_CHOISIE = 'representante@exemple.fr';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    /**
     * L'adresse choisie en saisissant le code attend sur le nouveau compte
     * du propriétaire, qui n'a encore aucune adresse de connexion, et le
     * lien qui la confirme, valable 24 heures, part à cette adresse : il
     * dit de le suivre connecté au compte. D'ici là, ni la connexion ni
     * « Mot de passe oublié » ne la reconnaissent, avec la même réponse
     * que pour une adresse inconnue ; l'identifiant et le mot de passe
     * choisi ouvrent le compte.
     */
    public function test_l_adresse_choisie_en_saisissant_le_code_n_ouvre_rien_avant_son_lien(): void
    {
        Mail::fake();
        Notification::fake();
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');

        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($societe, 'Representante@Exemple.FR');

        $this->assertSame(
            [null, null, self::ADRESSE_CHOISIE, self::aParis('2026-10-16 10:00:00')->getTimestamp(), false],
            [$nouveau->email, $nouveau->email_verified_at, $nouveau->nouvelle_adresse, $nouveau->nouvelle_adresse_expire_le?->getTimestamp(), $nouveau->aUneAdresseDeConnexion()],
        );
        $lien = Mail::sent(ConfirmationNouvelleAdresseMail::class, fn (ConfirmationNouvelleAdresseMail $mail): bool => $mail->hasTo(self::ADRESSE_CHOISIE))->sole();
        $this->assertTrue($lien->premiereAdresse);
        $this->assertStringContainsString('en étant connecté à ce compte', $lien->render());

        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter(self::ADRESSE_CHOISIE)->errors()->get('data.login'));
        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter('personne@exemple.fr')->errors()->get('data.login'));
        $this->assertGuest();
        Livewire::test(DemandeDeLien::class)->fillForm(['email' => self::ADRESSE_CHOISIE])->call('request')->assertNotified(DemandeDeLien::REPONSE);
        Notification::assertNotSentTo($nouveau, LienPourChoisirSonMotDePasse::class);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->seConnecter((string) $nouveau->identifiantDeConnexion())->assertHasNoErrors();
        $this->assertAuthenticatedAs($nouveau);
    }

    /**
     * Sur la page du code, l'autre personne qui devient propriétaire est
     * connectée, et lit, en arrivant dans l'espace de sa société, que le
     * lien de son adresse vient de partir et qu'elle se connecte d'ici là
     * avec son identifiant, qui lui est montré.
     */
    public function test_la_page_du_code_montre_a_l_autre_personne_le_lien_en_attente_et_son_identifiant(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $code = $this->codeDuCourrier($societe);
        app(TenantContext::class)->clear();

        $reponse = $this->post(route('code.saisir'), ['siren' => $societe->siren, 'code' => $code, 'email' => self::ADRESSE_CHOISIE, 'password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI]);

        $proprietaire = $societe->proprietaire()->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe));
        $this->assertAuthenticatedAs($proprietaire);
        $avis = collect(session('filament.notifications'))->sole();
        $this->assertSame('Confirmez votre adresse de connexion', $avis['title']);
        $this->assertStringContainsString(self::ADRESSE_CHOISIE, $avis['body']);
        $this->assertStringContainsString((string) $proprietaire->identifiantDeConnexion(), $avis['body']);
    }

    /**
     * Une messagerie en panne au moment d'envoyer le lien de l'adresse
     * choisie ne défait rien et ne bloque pas l'accès : la société est
     * validée, l'échec est signalé, la page connecte quand même la
     * personne, lui montre son identifiant et lui dit que le lien n'est
     * pas parti ; l'adresse ne l'attend plus, et le profil ne dit pas
     * qu'un lien est parti. Le lien se redemande depuis le profil et lui
     * sert.
     */
    public function test_un_lien_qui_ne_part_pas_a_la_saisie_du_code_laisse_la_personne_connectee_avec_son_identifiant(): void
    {
        Exceptions::fake();
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $code = $this->codeDuCourrier($societe);
        app(TenantContext::class)->clear();
        $messagerieEnPanne = true;
        Event::listen(MessageSending::class, function () use (&$messagerieEnPanne): void {
            if ($messagerieEnPanne) {
                throw new TransportException('Connexion au serveur de messagerie impossible.');
            }
        });

        $reponse = $this->post(route('code.saisir'), ['siren' => $societe->siren, 'code' => $code, 'email' => self::ADRESSE_CHOISIE, 'password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI]);

        $proprietaire = $societe->proprietaire()->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe));
        $this->assertAuthenticatedAs($proprietaire);
        $this->assertTrue($societe->fresh()->estValidee());
        Exceptions::assertReported(TransportException::class);
        $this->assertSame([null, null, null], [$proprietaire->email, $proprietaire->nouvelle_adresse, $proprietaire->nouvelle_adresse_jeton_hache]);
        $avis = collect(session('filament.notifications'))->sole();
        $this->assertStringContainsString('Le lien de confirmation n\'a pas pu être envoyé à '.self::ADRESSE_CHOISIE, $avis['body']);
        $this->assertStringContainsString((string) $proprietaire->identifiantDeConnexion(), $avis['body']);
        $this->assertStringContainsString('dans votre profil', $avis['body']);

        $messagerieEnPanne = false;
        Mail::fake();
        Livewire::test(ModifierProfil::class)
            ->assertSee('Votre compte n\'a pas encore d\'adresse de connexion : saisissez-la ici et enregistrez')
            ->assertDontSee('le lien envoyé à')
            ->fillForm(['email' => self::ADRESSE_CHOISIE, 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->actingAs($proprietaire)->post(route('profil.adresse.confirmer', ['jeton' => $this->jetonDeLAdresseEnAttente(self::ADRESSE_CHOISIE)]))->assertOk();
        $this->assertSame(self::ADRESSE_CHOISIE, $proprietaire->refresh()->email);
    }

    /**
     * Le lien de l'adresse choisie ne se suit que connecté à ce compte :
     * ailleurs, la page le dit, sans bouton, mène à la connexion, qui
     * ramène au lien, et le bouton ne change rien, même pour le
     * propriétaire connecté d'une autre société. Connecté à ce compte, il
     * pose l'adresse de connexion, à sa date, s'inscrit au journal d'audit
     * sans aucune adresse, sans ancienne adresse à prévenir ; l'adresse
     * ouvre alors le compte et reçoit le lien « Mot de passe oublié ».
     */
    public function test_le_lien_de_l_adresse_choisie_ne_se_suit_que_connecte_a_ce_compte(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($societe, self::ADRESSE_CHOISIE);
        $autreProprietaire = $this->inscrireUneSociete(null, 'autre@exemple.fr')->proprietaire()->sole();
        $jeton = $this->jetonDeLAdresseEnAttente(self::ADRESSE_CHOISIE);
        $lien = route('profil.adresse.afficher', ['jeton' => $jeton]);

        $this->get($lien)
            ->assertOk()
            ->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE)
            ->assertSee(Filament::getPanel('app')->getLoginUrl())
            ->assertDontSee('Confirmer mon adresse')
            ->assertSessionHas('url.intended', $lien);
        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE);
        $this->actingAs($autreProprietaire)->get($lien)->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE);
        $this->actingAs($autreProprietaire)->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_CONNEXION_REQUISE);
        $this->assertSame([null, self::ADRESSE_CHOISIE], [$nouveau->refresh()->email, $nouveau->nouvelle_adresse]);
        $this->assertSame('autre@exemple.fr', $autreProprietaire->refresh()->email);

        $this->actingAs($nouveau)->get($lien)->assertOk()->assertSee('Confirmer mon adresse');
        $this->actingAs($nouveau)->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSeeText('Votre adresse de connexion est désormais '.self::ADRESSE_CHOISIE);

        $nouveau->refresh();
        $this->assertSame([self::ADRESSE_CHOISIE, self::aParis('2026-10-15 10:00:00')->getTimestamp(), null, true], [$nouveau->email, $nouveau->email_verified_at?->getTimestamp(), $nouveau->nouvelle_adresse, $nouveau->aUneAdresseDeConnexion()]);
        Mail::assertNotSent(AdresseChangeeMail::class);
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::AdresseDeConnexionChangee);
        $this->assertSame(['compte' => ['id' => $nouveau->id, 'nom' => $nouveau->name]], $entree->nouvelles_valeurs);
        $this->assertTrue(Auth::validate(['login' => self::ADRESSE_CHOISIE, 'password' => self::MOT_DE_PASSE_CHOISI]));
        Notification::fake();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(DemandeDeLien::class)->fillForm(['email' => self::ADRESSE_CHOISIE])->call('request')->assertNotified(DemandeDeLien::REPONSE);
        Notification::assertSentTo($nouveau, LienPourChoisirSonMotDePasse::class);
    }

    /**
     * Une adresse en attente ne bloque rien : prise entre-temps comme
     * adresse de connexion par le propriétaire d'une autre société, elle
     * est refusée à la confirmation, sans rien dire d'autre que le refus ;
     * le compte reste sans adresse de connexion, et le lien ne sert plus.
     */
    public function test_une_adresse_prise_par_un_autre_proprietaire_avant_la_confirmation_est_refusee(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($societe, self::ADRESSE_CHOISIE);
        $jeton = $this->jetonDeLAdresseEnAttente(self::ADRESSE_CHOISIE);

        $autre = $this->inscrireUneSociete(null, self::ADRESSE_CHOISIE);
        $this->actingAs($nouveau)->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(ConfirmerNouvelleAdresseAction::MESSAGE_ADRESSE_INUTILISABLE);

        $nouveau->refresh();
        $this->assertSame([null, null], [$nouveau->email, $nouveau->nouvelle_adresse]);
        $this->assertNull(ConfirmerNouvelleAdresseAction::compteEnAttente($jeton));
        $this->assertSame(self::ADRESSE_CHOISIE, $autre->proprietaire()->sole()->email);
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::AdresseDeConnexionChangee));
    }

    /**
     * Le lien vaut 24 heures : expiré, il ne sert plus, et le profil le
     * dit ; un nouveau lien se redemande depuis le profil, avec le mot de
     * passe actuel, et lui sert.
     */
    public function test_un_lien_expire_se_redemande_depuis_le_profil(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $nouveau = $this->saisirLeCodeCommeUneAutrePersonne($societe, self::ADRESSE_CHOISIE);
        $expire = $this->jetonDeLAdresseEnAttente(self::ADRESSE_CHOISIE);

        $this->travelTo(self::aParis('2026-10-16 10:01:00'));
        $this->actingAs($nouveau)->get(route('profil.adresse.afficher', ['jeton' => $expire]))->assertStatus(410);
        Livewire::test(ModifierProfil::class)
            ->assertSee('Le lien envoyé à '.self::ADRESSE_CHOISIE.' a expiré')
            ->fillForm(['email' => self::ADRESSE_CHOISIE, 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $nouveauJeton = $this->jetonDeLAdresseEnAttente(self::ADRESSE_CHOISIE);
        $this->assertNotSame($expire, $nouveauJeton);
        $this->assertMemeInstant(self::aParis('2026-10-17 10:01:00'), $nouveau->refresh()->nouvelle_adresse_expire_le);
        $this->actingAs($nouveau)->post(route('profil.adresse.confirmer', ['jeton' => $nouveauJeton]))->assertOk();
        $this->assertSame(self::ADRESSE_CHOISIE, $nouveau->refresh()->email);
    }

    /**
     * Trente jours sans code après le courrier, la clôture de l'inscription
     * retire l'adresse de connexion de son propriétaire, dans la même
     * transaction : son adresse et sa date de confirmation, une nouvelle
     * adresse en attente et son lien, le lien « Mot de passe oublié » de
     * cette adresse ; le retrait s'inscrit au journal d'audit sans aucune
     * adresse, et l'avis de la clôture le dit. Le compte reste, actif, et
     * ne s'ouvre plus qu'avec son identifiant ; l'adresse ne reçoit plus de
     * lien « Mot de passe oublié ».
     */
    public function test_la_cloture_sans_code_retire_l_adresse_de_connexion_et_le_compte_s_ouvre_par_son_identifiant(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $proprietaire = $societe->proprietaire()->sole();
        Password::broker()->createToken($proprietaire);
        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'jeanne.nouvelle@exemple.fr');
        $jeton = $this->jetonDeLAdresseEnAttente('jeanne.nouvelle@exemple.fr');
        app(TenantContext::class)->clear();

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $proprietaire->refresh();
        $this->assertSame(
            [null, null, null, null, true, StatutCompte::Actif],
            [$proprietaire->email, $proprietaire->email_verified_at, $proprietaire->nouvelle_adresse, $proprietaire->nouvelle_adresse_jeton_hache, $proprietaire->estProprietaire(), $proprietaire->statut],
        );
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertNull(ConfirmerNouvelleAdresseAction::compteEnAttente($jeton));
        [$retrait] = $this->entreesDAudit($societe, ActionAuditee::AdresseDeConnexionRetiree);
        $this->assertSame(['compte' => ['id' => $proprietaire->id, 'nom' => $proprietaire->name]], $retrait->nouvelles_valeurs);
        $this->assertStringNotContainsString('exemple.fr', (string) json_encode([$retrait->anciennes_valeurs, $retrait->nouvelles_valeurs]));
        $this->assertStringContainsString('Votre adresse e-mail n\'est plus une adresse de connexion', $proprietaire->notifications()->sole()->data['body']);

        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter('jeanne@exemple.fr')->errors()->get('data.login'));
        Notification::fake();
        Livewire::test(DemandeDeLien::class)->fillForm(['email' => 'jeanne@exemple.fr'])->call('request')->assertNotified(DemandeDeLien::REPONSE);
        Notification::assertNotSentTo($proprietaire, LienPourChoisirSonMotDePasse::class);
        $this->seConnecter((string) $proprietaire->identifiantDeConnexion())->assertHasNoErrors();
        $this->assertAuthenticatedAs($proprietaire);
    }

    /**
     * La clôture prévient par e-mail l'adresse qu'elle retire, après sa
     * transaction : l'inscription est close, avec son motif, l'adresse
     * peut servir à une nouvelle inscription, dont l'e-mail donne le lien,
     * et le compte clos ne s'ouvre plus qu'avec son identifiant, que montre
     * son profil ; l'e-mail ne dit jamais cet identifiant.
     */
    public function test_la_cloture_previent_par_e_mail_l_adresse_retiree_sans_dire_l_identifiant(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $identifiant = (string) $societe->proprietaire()->sole()->identifiantDeConnexion();
        app(TenantContext::class)->clear();

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $avis = Mail::sent(InscriptionCloseMail::class, fn (InscriptionCloseMail $mail): bool => $mail->hasTo('jeanne@exemple.fr'))->sole();
        $texte = $avis->render();
        $this->assertSame('aucun code saisi dans les trente jours du dernier courrier', $avis->motif);
        $this->assertStringContainsString('elle peut servir à une nouvelle inscription', $texte);
        $this->assertStringContainsString(route('filament.app.auth.register'), $texte);
        $this->assertStringContainsString('ne s\'ouvre plus qu\'avec son identifiant de connexion, que montre son profil', $texte);
        $this->assertStringNotContainsString($identifiant, $texte);
    }

    /**
     * Un e-mail de clôture qui ne part pas (une messagerie en panne) est
     * signalé sans rien défaire : l'inscription est close et l'adresse
     * retirée ; la commande ne compte aucun échec.
     */
    public function test_un_e_mail_de_cloture_qui_ne_part_pas_n_empeche_pas_la_cloture(): void
    {
        Exceptions::fake();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $proprietaire = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();
        Event::listen(MessageSending::class, fn () => throw new TransportException('Connexion au serveur de messagerie impossible.'));

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        Exceptions::assertReported(TransportException::class);
        $this->assertTrue($societe->fresh()->estClose());
        $this->assertNull($proprietaire->refresh()->email);
    }

    /**
     * Une adresse qui attend sur le compte du propriétaire d'une
     * inscription close, quelle que soit la façon dont elle s'y est mise,
     * ne se confirme pas : la confirmation le dit, efface l'adresse en
     * attente et son lien, et le compte reste sans adresse de connexion.
     */
    public function test_une_adresse_en_attente_ne_se_confirme_pas_dans_une_inscription_close(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $proprietaire = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->cloreSansCode($societe->fresh(), now()->toImmutable());
        app(TenantContext::class)->clear();
        $proprietaire->refresh();
        $jeton = app(DemanderUneNouvelleAdresseAction::class)->mettreEnAttente($proprietaire, 'jeanne.b@exemple.fr');
        $proprietaire->save();

        $this->actingAs($proprietaire)->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))->assertSeeText(DemanderUneNouvelleAdresseAction::MESSAGE_INSCRIPTION_CLOSE);

        $proprietaire->refresh();
        $this->assertSame([null, null, null], [$proprietaire->email, $proprietaire->nouvelle_adresse, $proprietaire->nouvelle_adresse_jeton_hache]);
        $this->assertNull(ConfirmerNouvelleAdresseAction::compteEnAttente($jeton));
    }

    /**
     * Le refus de l'éditeur clôt l'inscription de la même façon : l'adresse
     * de son propriétaire cesse d'être une adresse de connexion, le retrait
     * s'inscrit au journal d'audit avec l'intervention de l'éditeur, et
     * l'e-mail de la clôture en donne le motif.
     */
    public function test_le_refus_de_l_editeur_retire_aussi_l_adresse_de_connexion(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $proprietaire = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();

        app(CloreLInscriptionAction::class)->refuser($societe, new InterventionDeLEditeur('Examen du dossier de vérification par l\'éditeur', 'DOSSIER-2026-0001', ['alice']));

        $this->assertSame(MotifCloture::Refus, $societe->fresh()->motif_cloture);
        $this->assertSame([null, false], [$proprietaire->refresh()->email, Auth::validate(['login' => 'jeanne@exemple.fr', 'password' => self::MOT_DE_PASSE_CHOISI])]);
        [$retrait] = $this->entreesDAudit($societe, ActionAuditee::AdresseDeConnexionRetiree);
        $this->assertSame(['alice'], $retrait->operateur['operateurs']);
        Mail::assertSent(InscriptionCloseMail::class, fn (InscriptionCloseMail $mail): bool => $mail->hasTo('jeanne@exemple.fr') && $mail->motif === 'refus de l\'éditeur');
    }

    /**
     * L'adresse d'une inscription close sert à une nouvelle inscription, du
     * même SIREN ou d'un autre : son propriétaire devient celui de la
     * nouvelle société, avec cette adresse de connexion ; le compte de
     * l'inscription close garde son identifiant.
     */
    public function test_une_nouvelle_inscription_avec_l_adresse_d_une_inscription_close_reussit(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $close = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $ancien = $close->proprietaire()->sole();
        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->cloreSansCode($close->fresh(), now()->toImmutable());
        app(TenantContext::class)->clear();

        $nouvelle = app(CreerSocieteAction::class)->handle((string) $close->siren, 'Jeanne@Exemple.FR', self::MOT_DE_PASSE_CHOISI);

        $this->assertTrue($nouvelle->attendSonCode());
        $proprietaire = $nouvelle->proprietaire()->sole();
        $this->assertSame(['jeanne@exemple.fr', true], [$proprietaire->email, $proprietaire->aUneAdresseDeConnexion()]);
        $this->assertNotSame($ancien->id, $proprietaire->id);
        $this->assertSame([null, true], [$ancien->refresh()->email, Auth::validate(['login' => (string) $ancien->identifiantDeConnexion(), 'password' => self::MOT_DE_PASSE_CHOISI])]);
        $this->assertTrue(Auth::validate(['login' => 'jeanne@exemple.fr', 'password' => self::MOT_DE_PASSE_CHOISI]));
    }

    /**
     * Le propriétaire d'une inscription close ne demande plus d'adresse de
     * connexion : son profil le lui dit, et l'action le refuse, sans rien
     * envoyer.
     */
    public function test_le_proprietaire_d_une_inscription_close_ne_demande_plus_d_adresse_de_connexion(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'jeanne@exemple.fr');
        $proprietaire = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->cloreSansCode($societe->fresh(), now()->toImmutable());
        app(TenantContext::class)->clear();

        $this->actingAs($proprietaire->refresh());
        Livewire::test(ModifierProfil::class)->assertSee(DemanderUneNouvelleAdresseAction::MESSAGE_INSCRIPTION_CLOSE);
        $this->assertThrows(fn () => app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'jeanne@exemple.fr'), LogicException::class, DemanderUneNouvelleAdresseAction::MESSAGE_INSCRIPTION_CLOSE);

        Mail::assertNotSent(ConfirmationNouvelleAdresseMail::class);
        $this->assertSame([null, null], [$proprietaire->refresh()->email, $proprietaire->nouvelle_adresse]);
    }

    /**
     * Un essai de connexion au panneau des magasins, sans session ouverte.
     */
    private function seConnecter(string $saisie): Testable
    {
        $this->flushSession();
        auth()->logout();

        return Livewire::test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->call('authenticate');
    }
}
