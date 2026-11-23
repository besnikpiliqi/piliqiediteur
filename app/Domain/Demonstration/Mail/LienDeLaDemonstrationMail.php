<?php

namespace App\Domain\Demonstration\Mail;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * L'e-mail du lien de la démonstration (B.10), en français : la société
 * fictive qu'il ouvre, sans identifiant ni mot de passe, à garder pour soi
 * et son équipe, jusqu'à sa date de fin, avec le repère de la copie ; que
 * faire si le lien a été donné par erreur ; et où se trouve son secret
 * (choix 2). Il ne nomme jamais un autre type d'activité, ne part qu'à
 * l'adresse de connexion confirmée du propriétaire et ne porte jamais son
 * identifiant. Avec le transport « log », le lien est masqué avant
 * l'écriture du journal (voir MasquerLeLienDansLeJournal).
 */
final class LienDeLaDemonstrationMail extends Mailable
{
    public const OBJET = 'Votre démonstration PILIQI';

    public const PHRASE_DU_SECRET = 'Le secret du lien n\'est jamais envoyé par le navigateur au serveur. Il peut rester dans l\'historique du navigateur et chez une messagerie qui réécrit les liens : renouvelez le lien en cas de doute.';

    public function __construct(
        #[SensitiveParameter] public readonly string $lien,
        public readonly string $repere,
        public readonly string $fin,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::OBJET);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lien-de-la-demonstration', with: [
            'adresse' => DemanderUneDemonstrationAction::adresseDuLien($this->lien),
            'repere' => $this->repere,
            'fin' => $this->fin,
            'phraseDuSecret' => self::PHRASE_DU_SECRET,
        ]);
    }
}
