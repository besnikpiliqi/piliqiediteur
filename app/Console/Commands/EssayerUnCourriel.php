<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Courriel\CauseDEchecDEnvoi;
use App\Integrations\Courriel\CourrielDEssai;
use App\Integrations\Courriel\RefusDeMailtrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;

/**
 * Essaie le serveur d'envoi des e-mails (constat de l'éditeur du
 * 01/11/2026) : envoie aussitôt, sans file d'attente, un e-mail d'essai à
 * l'adresse donnée par le transport configuré (MAIL_MAILER : log, smtp ou
 * mailtrap), dans le délai MAIL_TIMEOUT, et dit s'il a été accepté ou,
 * sinon, sa cause en français et son remède (voir CauseDEchecDEnvoi :
 * port filtré, serveur muet, connexion refusée, nom d'hôte, identifiants,
 * STARTTLS ou certificat, MAIL_SCHEME). Elle ne montre jamais le message
 * brut du serveur, qui porte l'identifiant SMTP, ni MAIL_USERNAME, ni
 * MAIL_PASSWORD, ni le jeton de Mailtrap.
 *
 * Elle signale aussi une configuration en cache, qui garde un ancien
 * .env, et l'état de la file d'attente par laquelle partent les e-mails
 * des documents : tâches en attente, en attente d'une nouvelle tentative,
 * réservées par un travailleur arrêté ; avec QUEUE_CONNECTION=sync, qu'un
 * envoi n'y a qu'une tentative (voir EnvoyerDocumentJob). Elle ne lit de
 * la file que ces nombres.
 */
#[Signature('courriel:essayer {adresse : l\'adresse qui reçoit l\'e-mail d\'essai}')]
#[Description('Envoie un e-mail d\'essai par le transport configuré et dit si le serveur l\'a accepté')]
class EssayerUnCourriel extends Command
{
    public const RAPPEL = 'Après tout changement du .env : php artisan optimize:clear, puis relancez php artisan serve et php artisan queue:listen, qui gardent l\'ancien .env tant qu\'ils tournent.';

    public function handle(): int
    {
        $adresse = ValeursLues::texte($this->argument('adresse'));

        if (Validator::make(['adresse' => $adresse], ['adresse' => 'email:strict'])->fails()) {
            $this->error("« {$adresse} » n'est pas une adresse e-mail valide.");

            return self::FAILURE;
        }

        $transport = Reglage::texte('mail.default');

        if (! is_array(config("mail.mailers.{$transport}"))) {
            $this->error("MAIL_MAILER=« {$transport} » ne désigne aucun transport de config/mail.php : log, smtp ou mailtrap.");

            return self::FAILURE;
        }

        $this->line('Transport : '.$this->descriptionDuTransport($transport).'.');

        if (app()->configurationIsCached()) {
            $this->warn('La configuration est en cache (php artisan config:cache) : un changement du .env n\'agit qu\'après php artisan optimize:clear.');
        }

        $this->decrireLaFile();
        $resultat = $this->envoyer($transport, $adresse);
        $this->line(self::RAPPEL);

        return $resultat;
    }

    private function envoyer(string $transport, string $adresse): int
    {
        $debut = hrtime(true);

        try {
            Mail::mailer($transport)->to($adresse)->send(new CourrielDEssai($transport));
        } catch (TransportExceptionInterface|UnsupportedSchemeException $erreur) {
            $cause = CauseDEchecDEnvoi::de($erreur);
            $this->error("L'e-mail d'essai n'est pas parti, au bout de {$this->duree($debut)} : {$cause->libelle()}.");
            // Le message d'un refus de Mailtrap est déjà en français, sans
            // secret ; celui d'un autre transport porte l'identifiant SMTP
            // et ne s'affiche jamais.
            $this->line($erreur instanceof RefusDeMailtrap ? $erreur->getMessage() : $cause->remede());

            return self::FAILURE;
        }

        $this->info(match ($transport) {
            'log' => "L'e-mail d'essai s'est écrit dans storage/logs/laravel.log ({$this->duree($debut)}) : avec MAIL_MAILER=log, aucun e-mail ne part ; réglez MAIL_MAILER=smtp pour l'envoyer.",
            'array' => "L'e-mail d'essai est resté en mémoire ({$this->duree($debut)}) : avec MAIL_MAILER=array, aucun e-mail ne part.",
            default => "L'e-mail d'essai a été accepté par le serveur ({$this->duree($debut)}) : il doit arriver à {$adresse}.",
        });

        return self::SUCCESS;
    }

    /**
     * Le transport, avec ce qui sert à le reconnaître, sans aucun secret.
     */
    private function descriptionDuTransport(string $transport): string
    {
        $delai = config("mail.mailers.{$transport}.timeout");
        $delai = is_int($delai) ? ", délai de {$delai} s (MAIL_TIMEOUT)" : '';

        return match (config("mail.mailers.{$transport}.transport")) {
            'smtp' => "« {$transport} », serveur SMTP ".ValeursLues::texte(config("mail.mailers.{$transport}.host")).':'.ValeursLues::entierOuTexte(config("mail.mailers.{$transport}.port"))
                .', chiffrement '.(is_string($schema = config("mail.mailers.{$transport}.scheme")) && $schema !== '' ? "« {$schema} »" : 'automatique (MAIL_SCHEME=null)').$delai,
            'mailtrap' => "« {$transport} », la boîte de test de Mailtrap par son API HTTPS (sandbox.api.mailtrap.io){$delai}",
            'log' => "« {$transport} » : chaque e-mail s'écrit dans storage/logs/laravel.log, sans partir",
            default => "« {$transport} »",
        };
    }

    /**
     * L'état de la file d'attente des e-mails des documents ; avec la
     * connexion sync, qu'un envoi n'a qu'une tentative.
     */
    private function decrireLaFile(): void
    {
        $connexion = Reglage::texte('queue.default');

        if (config("queue.connections.{$connexion}.driver") === 'sync') {
            $this->warn("File d'attente : « {$connexion} » : les e-mails des documents partent pendant le geste, en une seule tentative ; un refus du serveur d'envoi fait aussitôt échouer l'envoi. Pour les trois tentatives, QUEUE_CONNECTION=database et php artisan queue:listen.");

            return;
        }

        if (config("queue.connections.{$connexion}.driver") !== 'database') {
            $this->line("File d'attente : « {$connexion} » ; les e-mails des documents partent selon cette connexion.");

            return;
        }

        $maintenant = now()->getTimestamp();
        $retryAfter = config("queue.connections.{$connexion}.retry_after");
        $taches = DB::table(ValeursLues::texte(config("queue.connections.{$connexion}.table") ?? 'jobs'))->where('queue', config("queue.connections.{$connexion}.queue") ?? 'default');
        $enAttente = (clone $taches)->whereNull('reserved_at')->where('available_at', '<=', $maintenant)->count();
        $plusTard = (clone $taches)->whereNull('reserved_at')->where('available_at', '>', $maintenant)->count();
        $reservees = (clone $taches)->whereNotNull('reserved_at')->where('reserved_at', '<=', $maintenant - (is_int($retryAfter) ? $retryAfter : 90))->count();

        $this->line("File d'attente : {$enAttente} tâche(s) en attente, {$plusTard} en attente d'une nouvelle tentative, {$reservees} réservée(s) par un travailleur arrêté.");

        if ($enAttente + $reservees > 0) {
            $this->warn('Des tâches attendent : php artisan queue:listen doit tourner, dans une fenêtre restée ouverte, pour que partent les e-mails des documents.');
        }
    }

    private function duree(int $debut): string
    {
        return number_format((hrtime(true) - $debut) / 1e9, 1, ',', ' ').' s';
    }
}
