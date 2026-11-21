<?php

namespace Database\Seeders\Semis;

use Closure;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Le serveur d'envoi des e-mails arrêté, le temps d'un geste du semis
 * (voir pendant()) : il refuse la connexion, comme un serveur SMTP éteint,
 * sans qu'aucune connexion ne sorte de la machine. L'envoi d'un document
 * pendant ce temps échoue comme en production (voir EnvoyerDocumentJob et
 * CauseDEchecDEnvoi) : en échec aussitôt quand la tâche tourne pendant le
 * geste, en attente de sa tentative suivante quand elle tourne en file
 * (voir FileDAttenteDuSemis). Hors de ce geste, les e-mails restent
 * retenus (Mail::fake(), voir DatabaseSeeder) : aucun ne part.
 */
final class ServeurDEnvoiEnPanne extends AbstractTransport
{
    /** Le nom du service d'envoi en panne, le temps du geste. */
    private const SERVICE = 'serveur-en-panne-du-semis';

    /**
     * Ce geste, les e-mails confiés au serveur d'envoi arrêté.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    public static function pendant(Closure $geste): mixed
    {
        $retenus = Mail::getFacadeRoot();
        $avant = config('mail.default');
        $serveur = new MailManager(app());
        $serveur->extend(self::SERVICE, static fn (): self => new self);
        config(['mail.mailers.'.self::SERVICE => ['transport' => self::SERVICE], 'mail.default' => self::SERVICE]);
        Mail::swap($serveur);

        try {
            return $geste();
        } finally {
            Mail::swap($retenus);
            config(['mail.default' => $avant]);
        }
    }

    public function __toString(): string
    {
        return self::SERVICE.'://';
    }

    /**
     * @throws TransportException toujours : la connexion est refusée
     */
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('Connection could not be established with host "smtp.exemple.test:587": stream_socket_client(): Unable to connect to smtp.exemple.test:587 (Connection refused)');
    }
}
