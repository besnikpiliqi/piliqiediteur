<?php

namespace Tests\Feature\Courriels;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * Le délai d'envoi des e-mails (MAIL_TIMEOUT, config/mail.php ; constat
 * de l'éditeur du 01/11/2026) : un serveur SMTP qui ne répond pas fait
 * échouer l'envoi en quelques secondes, au lieu des 60 secondes de PHP qui
 * faisaient tomber `queue:listen`. Aucune connexion ne sort de la machine.
 */
class DelaiDEnvoiTest extends TestCase
{
    /**
     * Sans réglage, le transport SMTP attend 10 secondes au plus.
     */
    public function test_le_transport_smtp_attend_dix_secondes_par_defaut(): void
    {
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $flux = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $flux);
        $this->assertSame(10.0, $flux->getTimeout());
    }

    /**
     * Le délai est toujours strictement positif : une variable vide,
     * « null », nulle, négative ou illisible donne 10 secondes ; un délai
     * de 0 ferait échouer tout envoi, même vers un serveur qui répond.
     */
    #[DataProvider('valeursDeMailTimeout')]
    public function test_mail_timeout_donne_un_delai_toujours_positif(?string $valeur, int $attendu): void
    {
        $avant = $_SERVER['MAIL_TIMEOUT'] ?? null;
        $valeur === null ? $this->oublier() : $_SERVER['MAIL_TIMEOUT'] = $valeur;

        try {
            $configuration = require config_path('mail.php');
        } finally {
            $avant === null ? $this->oublier() : $_SERVER['MAIL_TIMEOUT'] = $avant;
        }

        $this->assertSame($attendu, $configuration['mailers']['smtp']['timeout']);
        $this->assertSame($attendu, $configuration['mailers']['mailtrap']['timeout']);
    }

    /**
     * @return array<string, array{?string, int}>
     */
    public static function valeursDeMailTimeout(): array
    {
        return [
            'absente' => [null, 10],
            'vide' => ['', 10],
            '« null »' => ['null', 10],
            'nulle' => ['0', 10],
            'négative' => ['-5', 10],
            'illisible' => ['dix', 10],
            'réglée' => ['25', 25],
        ];
    }

    /**
     * Un serveur qui accepte la connexion sans jamais rien dire (le port
     * d'un antivirus qui intercepte les e-mails, par exemple) fait échouer
     * l'envoi dans le délai, avec l'erreur du transport.
     */
    public function test_un_serveur_muet_fait_echouer_l_envoi_dans_le_delai(): void
    {
        $serveur = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($serveur);
        [, $port] = explode(':', (string) stream_socket_get_name($serveur, false));
        config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => (int) $port, 'mail.mailers.smtp.timeout' => 1]);
        $debut = hrtime(true);

        try {
            Mail::mailer('smtp')->raw('Essai', fn ($message) => $message->to('essai@exemple.test'));
            $this->fail('Un serveur muet devait faire échouer l\'envoi.');
        } catch (TransportException $erreur) {
            $this->assertStringContainsString('timed out', $erreur->getMessage());
        } finally {
            fclose($serveur);
        }

        $this->assertLessThan(3.0, (hrtime(true) - $debut) / 1e9);
    }

    /**
     * Chaque variable que lit config/mail.php figure dans .env.example,
     * avec sa valeur ou en exemple commenté.
     */
    public function test_chaque_variable_de_la_configuration_des_e_mails_figure_dans_env_example(): void
    {
        $configuration = (string) preg_replace('~^\s*//.*$~m', '', (string) file_get_contents(config_path('mail.php')));
        preg_match_all("/env\\('([A-Z_]+)'/", $configuration, $variables);
        $exemple = (string) file_get_contents(base_path('.env.example'));

        foreach (array_unique($variables[1]) as $variable) {
            $this->assertMatchesRegularExpression("/^(# )?{$variable}=/m", $exemple, "{$variable} manque dans .env.example.");
        }
    }

    private function oublier(): void
    {
        unset($_SERVER['MAIL_TIMEOUT']);
    }
}
