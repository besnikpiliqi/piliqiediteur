<?php

namespace Tests\Feature\Courriels;

use App\Console\Commands\EssayerUnCourriel;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * La commande courriel:essayer (voir EssayerUnCourriel), sans aucune
 * connexion hors de la machine : un faux serveur SMTP local (voir
 * tests/Fixtures/Courriels/faux-serveur-smtp.php), un port qui écoute sans
 * jamais répondre, un port fermé. Elle dit si le serveur a accepté
 * l'e-mail, ou sa cause et son remède, sans jamais afficher l'identifiant
 * ni le mot de passe SMTP.
 */
class EssayerUnCourrielTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTIFIANT = 'utilisateur-fictif-0123';

    private const MOT_DE_PASSE = 'mot-de-passe-fictif-4567';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.timeout' => 1,
        ]);
    }

    public function test_un_serveur_qui_accepte_l_e_mail_le_dit(): void
    {
        $serveur = $this->fauxServeur('accepte', $port);
        config(['mail.mailers.smtp.port' => $port]);

        try {
            $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
                ->expectsOutputToContain("Transport : « smtp », serveur SMTP 127.0.0.1:{$port}, chiffrement automatique (MAIL_SCHEME=null), délai de 1 s (MAIL_TIMEOUT).")
                ->expectsOutputToContain('L\'e-mail d\'essai a été accepté par le serveur')
                ->expectsOutput(EssayerUnCourriel::RAPPEL)
                ->assertSuccessful();
        } finally {
            $serveur->stop();
        }
    }

    /**
     * Un serveur qui accepte la connexion sans jamais rien dire échoue
     * dans le délai MAIL_TIMEOUT, avec sa cause et son remède.
     */
    public function test_un_serveur_muet_echoue_dans_le_delai_avec_son_remede(): void
    {
        $serveur = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($serveur);
        config(['mail.mailers.smtp.port' => (int) explode(':', (string) stream_socket_get_name($serveur, false))[1]]);
        $this->avecDesIdentifiants();
        $debut = hrtime(true);

        try {
            $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
                ->expectsOutputToContain('le serveur d\'envoi ne répond pas.')
                ->expectsOutputToContain('un antivirus ou un pare-feu qui intercepte les e-mails en est souvent la cause')
                ->doesntExpectOutputToContain(self::IDENTIFIANT)
                ->doesntExpectOutputToContain(self::MOT_DE_PASSE)
                ->doesntExpectOutputToContain('timed out')
                ->assertFailed();
        } finally {
            fclose($serveur);
        }

        $this->assertLessThan(5.0, (hrtime(true) - $debut) / 1e9);
    }

    public function test_un_port_ferme_dit_que_rien_n_ecoute(): void
    {
        $libre = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($libre);
        $port = (int) explode(':', (string) stream_socket_get_name($libre, false))[1];
        fclose($libre);
        config(['mail.mailers.smtp.port' => $port]);
        $this->avecDesIdentifiants();

        $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
            ->expectsOutputToContain('le serveur d\'envoi refuse la connexion.')
            ->expectsOutputToContain('Rien n\'écoute à cette adresse : vérifiez MAIL_HOST et MAIL_PORT')
            ->doesntExpectOutputToContain(self::IDENTIFIANT)
            ->doesntExpectOutputToContain('Connection refused')
            ->assertFailed();
    }

    public function test_mail_scheme_tls_est_refuse_avec_son_remede(): void
    {
        config(['mail.mailers.smtp.scheme' => 'tls', 'mail.mailers.smtp.port' => 2525]);
        $this->avecDesIdentifiants();

        $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
            ->expectsOutputToContain('le chiffrement du serveur d\'envoi est mal réglé.')
            ->expectsOutputToContain('MAIL_SCHEME n\'accepte que smtp, smtps ou null, jamais tls')
            ->doesntExpectOutputToContain(self::MOT_DE_PASSE)
            ->assertFailed();
    }

    /**
     * Un serveur qui refuse les identifiants (535) : la cause et le
     * remède, jamais le message du serveur, qui porte l'identifiant.
     */
    public function test_des_identifiants_refuses_ne_montrent_jamais_l_identifiant(): void
    {
        $serveur = $this->fauxServeur('refuse-identifiants', $port);
        config(['mail.mailers.smtp.port' => $port]);
        $this->avecDesIdentifiants();

        try {
            $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
                ->expectsOutputToContain('le serveur d\'envoi a refusé les identifiants de la plateforme.')
                ->expectsOutputToContain('recopiez MAIL_USERNAME et MAIL_PASSWORD depuis l\'onglet SMTP de la boîte Mailtrap')
                ->doesntExpectOutputToContain(self::IDENTIFIANT)
                ->doesntExpectOutputToContain(self::MOT_DE_PASSE)
                ->doesntExpectOutputToContain('535')
                ->assertFailed();
        } finally {
            $serveur->stop();
        }
    }

    public function test_avec_mail_mailer_log_l_e_mail_s_ecrit_dans_le_journal(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
            ->expectsOutputToContain('Transport : « log » : chaque e-mail s\'écrit dans storage/logs/laravel.log, sans partir.')
            ->expectsOutputToContain('L\'e-mail d\'essai s\'est écrit dans storage/logs/laravel.log')
            ->assertSuccessful();
    }

    /**
     * L'état de la file d'attente des e-mails des documents : tâches en
     * attente, en attente d'une nouvelle tentative, réservées par un
     * travailleur arrêté.
     */
    public function test_la_commande_dit_l_etat_de_la_file_d_attente(): void
    {
        config(['mail.default' => 'log', 'queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $maintenant = now()->getTimestamp();
        foreach ([[null, $maintenant - 10], [null, $maintenant + 30], [$maintenant - 100, $maintenant - 200]] as [$reservee, $disponible]) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservee, 'available_at' => $disponible, 'created_at' => $disponible]);
        }

        $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
            ->expectsOutput('File d\'attente : 1 tâche(s) en attente, 1 en attente d\'une nouvelle tentative, 1 réservée(s) par un travailleur arrêté.')
            ->expectsOutputToContain('php artisan queue:listen doit tourner')
            ->assertSuccessful();
    }

    /**
     * Avec QUEUE_CONNECTION=sync, les e-mails des documents n'ont qu'une
     * tentative : la commande le dit, et comment en avoir trois.
     */
    public function test_la_commande_dit_qu_avec_la_file_sync_un_envoi_n_a_qu_une_tentative(): void
    {
        config(['mail.default' => 'log', 'queue.default' => 'sync']);

        $this->artisan('courriel:essayer', ['adresse' => 'essai@exemple.test'])
            ->expectsOutput('File d\'attente : « sync » : les e-mails des documents partent pendant le geste, en une seule tentative ; un refus du serveur d\'envoi fait aussitôt échouer l\'envoi. Pour les trois tentatives, QUEUE_CONNECTION=database et php artisan queue:listen.')
            ->assertSuccessful();
    }

    public function test_une_adresse_invalide_est_refusee(): void
    {
        $this->artisan('courriel:essayer', ['adresse' => 'pas-une-adresse'])
            ->expectsOutput('« pas-une-adresse » n\'est pas une adresse e-mail valide.')
            ->assertFailed();
    }

    private function avecDesIdentifiants(): void
    {
        config(['mail.mailers.smtp.username' => self::IDENTIFIANT, 'mail.mailers.smtp.password' => self::MOT_DE_PASSE]);
    }

    /**
     * Le faux serveur SMTP local, lancé dans son propre processus, et le
     * port où il écoute.
     */
    private function fauxServeur(string $mode, ?int &$port): InvokedProcess
    {
        $serveur = Process::timeout(30)->start([PHP_BINARY, base_path('tests/Fixtures/Courriels/faux-serveur-smtp.php'), $mode]);
        $lu = '';
        $serveur->waitUntil(function (string $type, string $sortie) use (&$lu): bool {
            $lu .= $type === 'out' ? $sortie : '';

            return str_contains($lu, "\n");
        });
        $port = (int) trim($lu);

        return $serveur;
    }
}
