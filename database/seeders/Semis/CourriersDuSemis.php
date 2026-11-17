<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Les e-mails du semis : retenus, comme par Mail::fake(), pour que le
 * semis y lise les liens qu'il suit (réponse à un devis, invitation d'un
 * cabinet, mot de passe d'un expert-comptable, transmission de la
 * propriété) ; aucun ne part. Pendant les derniers jours de l'histoire d'un
 * modèle du site de démonstration (voir DatabaseSeeder et
 * HorlogeDuSemis::auxDerniersJours()), chacun passe aussi, aussitôt, par le
 * vrai transport du site, qui l'écrit dans « E-mails qui seraient partis »
 * (lot DEMO-COPIE, F.4) : les e-mails d'une notification comme ceux d'un
 * document, à l'heure de l'histoire.
 */
final class CourriersDuSemis extends MailFake
{
    private bool $aussiParLeTransport = false;

    /**
     * Les e-mails retenus désormais, à la place du vrai service (ou d'un
     * faux déjà posé par un test).
     */
    public static function poser(): self
    {
        $racine = Mail::getFacadeRoot();
        $courriers = new self($racine instanceof MailFake ? $racine->manager : ValeursLues::instance($racine, MailManager::class));
        Mail::swap($courriers);

        return $courriers;
    }

    /**
     * Désormais, chaque e-mail passe aussi par le vrai transport.
     */
    public function envoyerAussiParLeTransport(): void
    {
        $this->aussiParLeTransport = true;
    }

    /**
     * @param  Mailable|string|array<int|string, string>  $view
     * @param  array<string, mixed>  $data
     * @param  \Closure|string|null  $callback
     */
    public function send($view, array $data = [], $callback = null)
    {
        if ($this->aussiParLeTransport) {
            $mailer = $this->manager->mailer($this->currentMailer);
            $view instanceof Mailable ? (clone $view)->send($mailer) : $mailer->send($view, $data, $callback);
        }

        return parent::send($view, $data, $callback);
    }

    /**
     * @param  Mailable|string|array<int|string, string>  $view
     * @param  \BackedEnum|string|null  $queue
     */
    public function queue($view, $queue = null)
    {
        if ($this->aussiParLeTransport && $view instanceof Mailable) {
            (clone $view)->send($this->manager->mailer($this->currentMailer));
        }

        return parent::queue($view, $queue);
    }
}
