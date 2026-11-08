<?php

namespace App\Domain\Cabinets\Notifications;

use App\Domain\Tenancy\Models\Societe;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Une notification en base, dans la cloche de son destinataire, sur
 * l'espace des cabinets d'expertise comptable : la vérification d'un
 * cabinet (son inscription à l'Ordre demandée, vérifiée, refusée), et ce
 * que le lien entre un cabinet et un client fait savoir à l'une ou l'autre
 * partie. Elle porte la société de son destinataire (`societe_id`) et ne
 * dit que ce que celui-ci doit savoir : jamais un identifiant de
 * connexion, jamais l'adresse d'un tiers. Quand elle parle d'un client,
 * elle porte son identifiant (`client_id`), pour s'effacer de la cloche
 * d'un compte du cabinet qui perd l'accès à ce client. Avec $parCourriel,
 * elle part aussi par e-mail à l'adresse de connexion du propriétaire,
 * s'il en a une.
 */
class AvisDuCabinet extends Notification
{
    public const INFORMATION = 'info';

    public const SUCCES = 'success';

    public const ATTENTION = 'warning';

    public const REFUS = 'danger';

    public function __construct(
        public readonly Societe $societe,
        public readonly string $titre,
        public readonly string $texte,
        public readonly ?string $url = null,
        public readonly string $libelleDuLien = 'Voir',
        public readonly string $nature = self::INFORMATION,
        public readonly bool $parCourriel = false,
        public readonly ?int $clientId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->parCourriel ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("{$this->titre} · {$this->societe->getFilamentName()}")
            ->line($this->texte);

        if ($this->url !== null) {
            $message->action($this->libelleDuLien, $this->url);
        }

        return $message->line('Ce message vous est envoyé en tant que propriétaire sur '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = NotificationFilament::make()
            ->title($this->titre)
            ->body($this->texte)
            ->icon('heroicon-o-briefcase')
            ->status($this->nature);

        if ($this->url !== null) {
            $notification->actions([
                Action::make('voir')
                    ->label($this->libelleDuLien)
                    ->url($this->url)
                    ->markAsRead(),
            ]);
        }

        return [
            ...$notification->getDatabaseMessage(),
            'societe_id' => $this->societe->getKey(),
            ...($this->clientId === null ? [] : ['client_id' => $this->clientId]),
        ];
    }
}
