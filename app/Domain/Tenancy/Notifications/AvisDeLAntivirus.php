<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\LigneDeCourriel;
use App\Domain\Tenancy\Support\Reglage;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'avis au propriétaire de la société d'un fichier que l'antivirus a
 * reconnu comme une menace (décision de l'éditeur du 07/11/2026, lot FI) :
 * refusé au dépôt, ou, déjà conservé, bloqué au téléchargement, gardé
 * comme pièce justificative mais plus jamais servi. Dans sa cloche, et par
 * e-mail à son adresse de connexion, s'il en a une : le document, le nom
 * du fichier et la menace, jamais le fichier lui-même.
 */
class AvisDeLAntivirus extends Notification
{
    public function __construct(
        public readonly Societe $societe,
        public readonly string $titre,
        public readonly string $texte,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->titre} · {$this->societe->getFilamentName()}")
            ->line(LigneDeCourriel::de($this->texte))
            ->line('Ce message vous est envoyé en tant que propriétaire de la société sur '.Reglage::texte('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...NotificationFilament::make()
                ->title($this->titre)
                ->body($this->texte)
                ->icon('heroicon-o-shield-exclamation')
                ->status('danger')
                ->getDatabaseMessage(),
            'societe_id' => $this->societe->getKey(),
        ];
    }
}
