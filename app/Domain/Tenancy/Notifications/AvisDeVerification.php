<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\Societe\Pages\VerificationSociete;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Notification;

/**
 * Une notification en base, dans la cloche de l'espace d'administration
 * de la société (voir SocietePanelProvider), sur la vérification de la
 * société : validée, close, un courrier revenu, un écart relevé par une
 * revérification au répertoire Sirene. Elle porte la société
 * (`societe_id`) et mène à la page de la vérification.
 */
class AvisDeVerification extends Notification
{
    public const SUCCES = 'success';

    public const ATTENTION = 'warning';

    public const REFUS = 'danger';

    public function __construct(
        public readonly Societe $societe,
        public readonly string $titre,
        public readonly string $texte,
        public readonly string $nature = self::ATTENTION,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
                ->icon('heroicon-o-shield-check')
                ->status($this->nature)
                ->actions([
                    Action::make('voir')
                        ->label('Voir la vérification')
                        ->url(VerificationSociete::getUrl(panel: 'societe', tenant: $this->societe))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'societe_id' => $this->societe->getKey(),
        ];
    }
}
