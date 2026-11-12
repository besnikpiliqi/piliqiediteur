<?php

namespace App\Integrations\Courriel;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Throwable;

/**
 * La cause d'un e-mail qui n'est pas parti, lue dans l'erreur du transport
 * d'envoi (constat de l'éditeur du 01/11/2026 et diagnostic vérifié du
 * 02/11/2026) : jamais le texte brut du serveur, qui peut porter
 * l'identifiant SMTP de la plateforme ou des adresses, mais une cause en
 * français, avec ce que le magasin peut faire et, pour qui règle le
 * serveur, son remède (voir la commande courriel:essayer).
 *
 * L'erreur se classe d'abord par son code SMTP (535, 530 ou 534 : les
 * identifiants ; 550, 553 ou 501 : une adresse refusée, sauf une limite
 * d'envois que le serveur dit en 550 ; 421, 450, 451 ou 452 : un serveur
 * occupé), puis par sa classe (MAIL_SCHEME inconnu, tentative interrompue
 * par la file d'attente, refus de l'API de Mailtrap, qui porte sa cause),
 * puis par les mots de son message : connexion chiffrée (STARTTLS,
 * certificat, SSL), nom d'hôte introuvable, connexion refusée, port filtré
 * (la connexion ne s'établit pas dans le délai), serveur muet (connexion
 * acceptée, puis rien dans le délai MAIL_TIMEOUT), serveur qui coupe la
 * connexion.
 *
 * Seule une adresse refusée tient au message lui-même : toute autre cause
 * tient à la plateforme, ne dit jamais de vérifier l'adresse, et
 * s'inscrit au journal de l'application au niveau critique.
 */
enum CauseDEchecDEnvoi: string
{
    case Identifiants = 'identifiants';
    case AdresseRefusee = 'adresse_refusee';
    case ServeurOccupe = 'serveur_occupe';
    case ServeurMuet = 'serveur_muet';
    case PortFiltre = 'port_filtre';
    case ConnexionRefusee = 'connexion_refusee';
    case NomDHoteInconnu = 'nom_d_hote_inconnu';
    case Chiffrement = 'chiffrement';
    case Schema = 'schema';
    case Configuration = 'configuration';
    case Indisponible = 'indisponible';
    case Interrompue = 'interrompue';
    case Inattendue = 'inattendue';

    public static function de(Throwable $erreur): self
    {
        if ($erreur instanceof RefusDeMailtrap) {
            return $erreur->cause;
        }

        $message = $erreur->getMessage();
        $contient = static fn (string ...$mots): bool => Str::contains($message, $mots, ignoreCase: true);

        return match (true) {
            $erreur instanceof UnsupportedSchemeException => self::Schema,
            $erreur instanceof MaxAttemptsExceededException, $erreur instanceof TimeoutExceededException => self::Interrompue,
            ! $erreur instanceof TransportExceptionInterface => self::Inattendue,
            in_array($erreur->getCode(), [530, 534, 535], true) => self::Identifiants,
            $contient('too many', 'rate limit', 'quota') => self::ServeurOccupe,
            in_array($erreur->getCode(), [501, 550, 553], true) => self::AdresseRefusee,
            in_array($erreur->getCode(), [421, 450, 451, 452], true) => self::ServeurOccupe,
            $contient('starttls', 'certificate', 'ssl operation', 'ssl routines', 'enable_crypto', 'tls required') => self::Chiffrement,
            $contient('getaddrinfo', 'name or service not known', 'no such host', 'name resolution') => self::NomDHoteInconnu,
            $contient('refused') => self::ConnexionRefusee,
            $contient('could not be established') && $contient('timed out', 'did not properly respond') => self::PortFiltre,
            $contient('timed out') => self::ServeurMuet,
            $contient('could not be established', 'closed unexpectedly', 'unable to read', 'unable to write') => self::Indisponible,
            default => self::Inattendue,
        };
    }

    /**
     * La cause, en quelques mots, après « En attente — » ou « L'e-mail
     * n'est pas parti : ».
     */
    public function libelle(): string
    {
        return match ($this) {
            self::Identifiants => 'le serveur d\'envoi a refusé les identifiants de la plateforme',
            self::AdresseRefusee => 'le serveur d\'envoi a refusé une adresse de l\'e-mail',
            self::ServeurOccupe => 'le serveur d\'envoi limite les envois pour le moment',
            self::ServeurMuet => 'le serveur d\'envoi ne répond pas',
            self::PortFiltre => 'le serveur d\'envoi ne se joint pas',
            self::ConnexionRefusee => 'le serveur d\'envoi refuse la connexion',
            self::NomDHoteInconnu => 'le serveur d\'envoi est introuvable',
            self::Chiffrement => 'la connexion chiffrée au serveur d\'envoi a échoué',
            self::Schema => 'le chiffrement du serveur d\'envoi est mal réglé',
            self::Configuration => 'le service d\'envoi n\'est pas configuré',
            self::Indisponible => 'le service d\'envoi est indisponible',
            self::Interrompue => 'l\'envoi a été interrompu avant d\'aboutir',
            self::Inattendue => 'une erreur inattendue a empêché l\'envoi',
        };
    }

    /**
     * Seule une adresse refusée tient au message lui-même.
     */
    public function tientALaPlateforme(): bool
    {
        return $this !== self::AdresseRefusee;
    }

    /**
     * Une nouvelle tentative peut aboutir : tout sauf une adresse refusée,
     * qui le resterait.
     */
    public function vautUneNouvelleTentative(): bool
    {
        return $this !== self::AdresseRefusee;
    }

    /**
     * L'explication de l'échec définitif, pour le magasin et la personne
     * prévenue : une adresse refusée se vérifie ; une cause de la
     * plateforme ne fait jamais vérifier l'adresse, mais renvoyer plus tard
     * et prévenir l'éditeur.
     *
     * @param  string  $consigne  comment faire repartir l'e-mail (« renvoyez-le »)
     */
    public function messagePourLeMagasin(string $consigne): string
    {
        return match ($this) {
            self::AdresseRefusee => "Le serveur d'envoi des e-mails a refusé une adresse de ce message : vérifiez l'adresse du destinataire, puis {$consigne} ; si elle est juste, prévenez l'éditeur.",
            self::Identifiants, self::Chiffrement, self::Schema, self::Configuration => "L'e-mail n'est pas parti : {$this->libelle()}, un incident de la plateforme, sans rapport avec l'adresse du destinataire. Prévenez l'éditeur ; une fois le service rétabli, {$consigne}.",
            self::Inattendue => "Une erreur inattendue a empêché l'envoi : {$consigne}, et prévenez l'éditeur si elle se répète.",
            default => "L'e-mail n'est pas parti : {$this->libelle()}, à chaque tentative. Plus tard, {$consigne} ; si cela se répète, prévenez l'éditeur.",
        };
    }

    /**
     * Le remède, pour qui règle le serveur d'envoi (le .env du PC de
     * l'éditeur ou du serveur), sans aucun secret.
     */
    public function remede(): string
    {
        return match ($this) {
            self::Identifiants => 'L\'identifiant ou le mot de passe du serveur SMTP est refusé : recopiez MAIL_USERNAME et MAIL_PASSWORD depuis l\'onglet SMTP de la boîte Mailtrap (pas un jeton d\'API), ou ceux du prestataire d\'envoi.',
            self::AdresseRefusee => 'Le serveur a refusé une adresse : celle du destinataire donnée à la commande, ou l\'expéditeur MAIL_FROM_ADDRESS ; vérifiez-les.',
            self::ServeurOccupe => 'Le serveur limite les envois (quota mensuel de la boîte Mailtrap, ou trop d\'e-mails en quelques secondes) : réessayez plus tard.',
            self::ServeurMuet => 'La connexion est acceptée, mais le serveur ne dit rien dans le délai MAIL_TIMEOUT : un antivirus ou un pare-feu qui intercepte les e-mails en est souvent la cause (désactivez sa protection des e-mails), sinon essayez le port 587 (MAIL_PORT=587).',
            self::PortFiltre => 'La connexion ne s\'établit pas dans le délai MAIL_TIMEOUT : le port est filtré (pare-feu, réseau). Essayez le port 587 (MAIL_PORT=587) ; sous Windows, « Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525 », puis « -Port 587 », dit lequel passe.',
            self::ConnexionRefusee => 'Rien n\'écoute à cette adresse : vérifiez MAIL_HOST et MAIL_PORT (pour Mailtrap, sandbox.smtp.mailtrap.io, port 2525 ou 587).',
            self::NomDHoteInconnu => 'Le nom du serveur est introuvable : vérifiez l\'orthographe de MAIL_HOST, ou la connexion à Internet.',
            self::Chiffrement => 'La connexion chiffrée (STARTTLS) a échoué ou son certificat est refusé : un antivirus qui inspecte les connexions chiffrées en est souvent la cause (désactivez sa protection des e-mails). Sur les ports 2525 et 587, laissez MAIL_SCHEME=null.',
            self::Schema => 'MAIL_SCHEME n\'accepte que smtp, smtps ou null, jamais tls : MAIL_SCHEME=null sur les ports 2525 et 587, smtps sur le port 465.',
            self::Configuration => 'Le transport choisi par MAIL_MAILER n\'est pas configuré : voir le message ci-dessus.',
            self::Indisponible => 'Le serveur d\'envoi a coupé la connexion ou ne répond plus : réessayez dans quelques minutes.',
            self::Interrompue => 'La tâche d\'envoi a été coupée avant d\'aboutir : le délai MAIL_TIMEOUT doit rester sous les 60 secondes de queue:listen.',
            self::Inattendue => 'Erreur inattendue : lisez la dernière erreur de storage/logs/laravel.log, en masquant l\'identifiant SMTP avant de la transmettre.',
        };
    }
}
