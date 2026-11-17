<?php

namespace App\Domain\Demonstration\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Avec le transport « log » du développement, le lien d'une démonstration
 * est remplacé par « (lien masqué dans le journal) » avant que l'e-mail ne
 * s'écrive (B.10) : il n'entre jamais dans storage/logs. Avec un vrai
 * transport, l'e-mail part tel quel, à la seule adresse de connexion
 * confirmée du propriétaire.
 */
final class MasquerLeLienDansLeJournal
{
    public const REMPLACEMENT = '(lien masqué dans le journal)';

    /**
     * Une adresse du site de démonstration qui porte un secret de lien
     * (« /d# » suivi de 43 caractères en base64url).
     */
    public const MOTIF = '~(?:https?://[^\s"\'<>]*)?/d#[A-Za-z0-9_-]{43}~';

    public function handle(MessageSending $evenement): void
    {
        $mailer = config('mail.default');

        if (! is_string($mailer) || config('mail.mailers.'.$mailer.'.transport') !== 'log') {
            return;
        }

        $html = $evenement->message->getHtmlBody();
        $texte = $evenement->message->getTextBody();

        if (is_string($html)) {
            $evenement->message->html((string) preg_replace(self::MOTIF, self::REMPLACEMENT, $html));
        }

        if (is_string($texte)) {
            $evenement->message->text((string) preg_replace(self::MOTIF, self::REMPLACEMENT, $texte));
        }
    }
}
