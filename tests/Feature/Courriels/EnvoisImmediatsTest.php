<?php

namespace Tests\Feature\Courriels;

use App\Domain\Cabinets\Actions\InviterSonCabinetACreerSonEspaceAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Integrations\Courriel\CourrielNonParti;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Les e-mails qui partent aussitôt, sans file d'attente, pendant le geste
 * de la personne (diagnostic vérifié des e-mails du 02/11/2026, E8) : un
 * serveur d'envoi qui ne répond pas ne donne jamais une erreur 500, mais
 * un message en français, et le journal le dit au niveau critique. Le
 * transport des tests lève l'erreur que lève le transport SMTP d'un
 * serveur muet ; aucune connexion ne part.
 */
class EnvoisImmediatsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVEUR_MUET = 'L\'e-mail de confirmation n\'est pas parti : le serveur d\'envoi ne répond pas. Réessayez dans quelques minutes.';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::extend('en-panne', fn (): AbstractTransport => new class extends AbstractTransport
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
        config(['mail.mailers.en-panne' => ['transport' => 'en-panne'], 'mail.default' => 'en-panne']);
    }

    /**
     * L'inscription garde son formulaire et dit que le lien n'est pas
     * parti ; la demande, jamais envoyée, ne reste pas.
     */
    public function test_l_inscription_dit_que_son_lien_n_est_pas_parti(): void
    {
        Log::spy();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(InscriptionSociete::class)
            ->fillForm(['siren' => SocieteFactory::sirenFictif(), 'type_activite' => TypeDActivite::Commerce->value, 'email' => 'nouvelle@exemple.fr', 'password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
            ->call('register')
            ->assertNotified(self::SERVEUR_MUET)
            ->assertSet('demandeEnvoyee', false);

        $this->assertSame(0, DemandeInscription::query()->count());
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => $message === 'L\'e-mail de confirmation n\'est pas parti : le serveur d\'envoi ne répond pas.')->once();
    }

    /**
     * « Mot de passe oublié » répond comme toujours, sans rien révéler et
     * sans erreur ; le journal dit que le lien n'est pas parti.
     */
    public function test_mot_de_passe_oublie_repond_comme_toujours_quand_le_lien_ne_part_pas(): void
    {
        Log::spy();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertNotified(DemandeDeLien::REPONSE);

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => $message === 'L\'e-mail de la notification LienPourChoisirSonMotDePasse n\'est pas parti : le serveur d\'envoi ne répond pas.')->once();
    }

    /**
     * L'invitation d'un cabinet reste enregistrée sans son e-mail ; le
     * propriétaire du cabinet le lit à l'écran et dans sa cloche.
     */
    public function test_l_invitation_d_un_cabinet_dit_que_son_e_mail_n_est_pas_parti(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->clear();
        $siren = SocieteFactory::sirenFictif();
        $attendu = "L'e-mail de l'invitation pour le SIREN {$siren} n'est pas parti : le serveur d'envoi ne répond pas. L'invitation reste enregistrée, sans son e-mail : retirez-la, puis invitez de nouveau dans quelques minutes.";

        $this->assertThrows(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, $siren, 'dirigeant@entreprise.test', self::MOT_DE_PASSE_CHOISI), CourrielNonParti::class, $attendu);

        $this->assertSame(1, InvitationCabinet::query()->withoutGlobalScopes()->where('siren', $siren)->count());
        $avis = $this->proprietaireDuCabinet($cabinet)->notifications()->where('type', AvisDuCabinet::class)->get()->sole(fn ($avis): bool => $avis->data['title'] === 'E-mail non envoyé');
        $this->assertSame($attendu, $avis->data['body']);
    }

    /**
     * La demande de rattachement à une société inscrite reste enregistrée,
     * et l'avis de son propriétaire dans sa cloche, quand son e-mail ne
     * part pas ; le cabinet le lit à l'écran.
     */
    public function test_la_demande_de_rattachement_reste_enregistree_quand_son_e_mail_ne_part_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $magasin->societe->siren, 'dirigeant@client.test', self::MOT_DE_PASSE_CHOISI),
            CourrielNonParti::class,
            "L'e-mail de la demande de rattachement pour le SIREN {$magasin->societe->siren} n'est pas parti : le serveur d'envoi ne répond pas. La demande reste enregistrée et s'affiche dans l'espace du propriétaire de la société.",
        );

        $this->assertSame('Demande d\'un cabinet comptable', $this->proprietaireDe($magasin)->notifications()->sole()->data['title']);
    }

    /**
     * L'invitation à créer l'espace d'un cabinet n'est ni comptée ni
     * inscrite au journal d'audit quand son e-mail ne part pas.
     */
    public function test_l_invitation_a_creer_un_espace_de_cabinet_n_est_pas_inscrite_quand_elle_ne_part_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(InviterSonCabinetACreerSonEspaceAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, 'expert@cabinet.test'),
            CourrielNonParti::class,
            'L\'e-mail d\'invitation n\'est pas parti : le serveur d\'envoi ne répond pas. Réessayez dans quelques minutes.',
        );

        $this->assertSame(0, EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::EspaceCabinetSuggere->value)->count());
    }

    /**
     * Un avis par e-mail qui ne part pas ne casse pas le geste qui l'a fait
     * naître : il reste dans la cloche, et le journal le dit.
     */
    public function test_un_avis_par_e_mail_qui_ne_part_pas_reste_dans_la_cloche(): void
    {
        Log::spy();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        $proprietaire->notify(new AvisDeLEquipe($magasin->societe, 'Accès redonné', 'Un accès a été redonné.', parCourriel: true));

        $this->assertSame('Accès redonné', $proprietaire->notifications()->sole()->data['title']);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => $message === 'L\'e-mail de la notification AvisDeLEquipe n\'est pas parti : le serveur d\'envoi ne répond pas.')->once();
    }
}
