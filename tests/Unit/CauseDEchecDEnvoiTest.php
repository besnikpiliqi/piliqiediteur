<?php

namespace Tests\Unit;

use App\Integrations\Courriel\CauseDEchecDEnvoi;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Throwable;

/**
 * Le classement des échecs d'envoi (voir CauseDEchecDEnvoi), sur les
 * messages que lèvent vraiment le transport SMTP de Symfony et la file
 * d'attente de Laravel (diagnostic vérifié du 02/11/2026), sous Linux et,
 * pour la connexion, sous Windows, en anglais comme en français (le texte
 * de l'erreur du système suit la langue de Windows) ; l'identifiant SMTP
 * qu'ils portent ne figure jamais dans le message pour le magasin.
 */
class CauseDEchecDEnvoiTest extends TestCase
{
    /**
     * @param  callable(): Throwable  $erreur
     */
    #[DataProvider('erreursDuTransport')]
    public function test_chaque_erreur_du_transport_se_classe_par_sa_cause(callable $erreur, CauseDEchecDEnvoi $attendue): void
    {
        $cause = CauseDEchecDEnvoi::de($erreur());

        $this->assertSame($attendue, $cause);
        $this->assertStringNotContainsString('smtp-user', $cause->messagePourLeMagasin('renvoyez-le'));
    }

    /**
     * Une connexion qui ne s'établit pas, dans une langue du système que
     * PILIQI ne lit pas, donne les deux pistes : l'adresse et le port du
     * serveur, puis le port filtré et l'essai du port 587.
     */
    public function test_une_connexion_qui_ne_s_etablit_pas_donne_les_remedes_du_port_ferme_et_du_port_filtre(): void
    {
        $remede = CauseDEchecDEnvoi::ConnexionImpossible->remede();

        $this->assertStringContainsString('vérifiez MAIL_HOST et MAIL_PORT', $remede);
        $this->assertStringContainsString('MAIL_PORT=587', $remede);
        $this->assertStringContainsString('Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525', $remede);
    }

    /**
     * Un envoi qui n'a eu qu'une tentative (QUEUE_CONNECTION=sync) ne dit
     * pas qu'il a échoué « à chaque tentative ».
     */
    public function test_un_envoi_d_une_seule_tentative_ne_parle_pas_de_chaque_tentative(): void
    {
        $this->assertSame('L\'e-mail n\'est pas parti : le serveur d\'envoi ne répond pas, à chaque tentative. Plus tard, renvoyez-le ; si cela se répète, prévenez l\'éditeur.', CauseDEchecDEnvoi::ServeurMuet->messagePourLeMagasin('renvoyez-le'));
        $this->assertSame('L\'e-mail n\'est pas parti : le serveur d\'envoi ne répond pas. Plus tard, renvoyez-le ; si cela se répète, prévenez l\'éditeur.', CauseDEchecDEnvoi::ServeurMuet->messagePourLeMagasin('renvoyez-le', apresChaqueTentative: false));
    }

    /**
     * @return array<string, array{callable(): Throwable, CauseDEchecDEnvoi}>
     */
    public static function erreursDuTransport(): array
    {
        return [
            'identifiants refusés (535)' => [fn () => new TransportException('Failed to authenticate on SMTP server with username "smtp-user" using the following authenticators: "LOGIN", "PLAIN", "CRAM-MD5". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".".', 535), CauseDEchecDEnvoi::Identifiants],
            'authentification exigée (530)' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "530", with message "530 5.7.0 Authentication required".', 530), CauseDEchecDEnvoi::Identifiants],
            'destinataire refusé (550)' => [fn () => new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <client@exemple.test>: Recipient address rejected: User unknown".', 550), CauseDEchecDEnvoi::AdresseRefusee],
            'adresse mal formée (553)' => [fn () => new UnexpectedResponseException('Expected response code "250/251/252" but got code "553", with message "553 5.1.3 Invalid address".', 553), CauseDEchecDEnvoi::AdresseRefusee],
            'syntaxe de l\'adresse (501)' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "501", with message "501 5.1.7 Bad sender address syntax".', 501), CauseDEchecDEnvoi::AdresseRefusee],
            'trop d\'e-mails par seconde (550 de Mailtrap)' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.7.0 Requested action not taken: too many emails per second".', 550), CauseDEchecDEnvoi::ServeurOccupe],
            'serveur occupé (421)' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 4.7.0 Try again later, closing connection".', 421), CauseDEchecDEnvoi::ServeurOccupe],
            'serveur muet' => [fn () => new TransportException('Connection to "127.0.0.1:2527" timed out.'), CauseDEchecDEnvoi::ServeurMuet],
            'port filtré (Linux)' => [fn () => new TransportException('Connection could not be established with host "127.0.0.1:2529": stream_socket_client(): Unable to connect to 127.0.0.1:2529 (Connection timed out)'), CauseDEchecDEnvoi::PortFiltre],
            'port filtré (Windows)' => [fn () => new TransportException('Connection could not be established with host "sandbox.smtp.mailtrap.io:2525": stream_socket_client(): Unable to connect to sandbox.smtp.mailtrap.io:2525 (A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond)'), CauseDEchecDEnvoi::PortFiltre],
            'connexion refusée (Linux)' => [fn () => new TransportException('Connection could not be established with host "127.0.0.1:2531": stream_socket_client(): Unable to connect to 127.0.0.1:2531 (Connection refused)'), CauseDEchecDEnvoi::ConnexionRefusee],
            'connexion refusée (Windows)' => [fn () => new TransportException('Connection could not be established with host "127.0.0.1:2531": stream_socket_client(): Unable to connect to 127.0.0.1:2531 (No connection could be made because the target machine actively refused it)'), CauseDEchecDEnvoi::ConnexionRefusee],
            'port filtré (Windows en français)' => [fn () => new TransportException('Connection could not be established with host "sandbox.smtp.mailtrap.io:2525": stream_socket_client(): Unable to connect to sandbox.smtp.mailtrap.io:2525 (Une tentative de connexion a échoué car le parti connecté n’a pas répondu convenablement au-delà d’une certaine durée ou une connexion établie a échoué car l’hôte de connexion n’a pas répondu)'), CauseDEchecDEnvoi::PortFiltre],
            'connexion refusée (Windows en français)' => [fn () => new TransportException('Connection could not be established with host "127.0.0.1:2531": stream_socket_client(): Unable to connect to 127.0.0.1:2531 (Aucune connexion n’a pu être établie car l’ordinateur cible l’a expressément refusée)'), CauseDEchecDEnvoi::ConnexionRefusee],
            'connexion impossible, dans une autre langue du système' => [fn () => new TransportException('Connection could not be established with host "sandbox.smtp.mailtrap.io:2525": stream_socket_client(): Unable to connect to sandbox.smtp.mailtrap.io:2525 (Ein Verbindungsversuch ist fehlgeschlagen, da die Gegenstelle nach einer bestimmten Zeitspanne nicht richtig reagiert hat)'), CauseDEchecDEnvoi::ConnexionImpossible],
            'nom d\'hôte introuvable' => [fn () => new TransportException('Connection could not be established with host "sandbox.smtp.mailtrap.oi:2525": stream_socket_client(): php_network_getaddresses: getaddrinfo for sandbox.smtp.mailtrap.oi failed: Name or service not known'), CauseDEchecDEnvoi::NomDHoteInconnu],
            'STARTTLS et certificat' => [fn () => new TransportException('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed'), CauseDEchecDEnvoi::Chiffrement],
            'smtps refusé, sans cause de chiffrement' => [fn () => new TransportException('Connection could not be established with host "ssl://127.0.0.1:465": stream_socket_client(): Unable to connect to ssl://127.0.0.1:465 (Connection refused)'), CauseDEchecDEnvoi::ConnexionRefusee],
            'MAIL_SCHEME=tls' => [fn () => new UnsupportedSchemeException(new Dsn('tls', '127.0.0.1')), CauseDEchecDEnvoi::Schema],
            'connexion coupée par le serveur' => [fn () => new TransportException('Connection to "127.0.0.1:2526" has been closed unexpectedly.'), CauseDEchecDEnvoi::Indisponible],
            'tentatives épuisées par la file' => [fn () => new MaxAttemptsExceededException('App\Domain\Facturation\Jobs\EnvoyerDocumentJob has been attempted too many times.'), CauseDEchecDEnvoi::Interrompue],
            'tâche coupée par son délai' => [fn () => new TimeoutExceededException('App\Domain\Facturation\Jobs\EnvoyerDocumentJob has timed out.'), CauseDEchecDEnvoi::Interrompue],
            'réponse vide du serveur' => [fn () => new UnexpectedResponseException('Expected response code "220" but got empty code.'), CauseDEchecDEnvoi::Inattendue],
            'erreur sans rapport avec le transport' => [fn () => new LogicException('Connection refused'), CauseDEchecDEnvoi::Inattendue],
        ];
    }

    /**
     * Seule une adresse refusée fait vérifier l'adresse et ne vaut pas de
     * nouvelle tentative ; toute cause de la plateforme dit de renvoyer
     * plus tard ou de prévenir l'éditeur, jamais de vérifier l'adresse.
     */
    public function test_seule_une_adresse_refusee_fait_verifier_l_adresse(): void
    {
        foreach (CauseDEchecDEnvoi::cases() as $cause) {
            $message = $cause->messagePourLeMagasin('renvoyez-le');

            if ($cause === CauseDEchecDEnvoi::AdresseRefusee) {
                $this->assertStringContainsString('vérifiez l\'adresse du destinataire', $message);
                $this->assertFalse($cause->tientALaPlateforme());
                $this->assertFalse($cause->vautUneNouvelleTentative());
            } else {
                $this->assertStringNotContainsString('vérifiez l\'adresse', $message, $cause->name);
                $this->assertMatchesRegularExpression('/renvoyez-le|prévenez l\'éditeur/', $message, $cause->name);
                $this->assertTrue($cause->tientALaPlateforme(), $cause->name);
                $this->assertTrue($cause->vautUneNouvelleTentative(), $cause->name);
            }
        }
    }
}
