<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Notification;

/**
 * Prévient le propriétaire de chaque responsable qu'un administrateur
 * nomme : la nomination prend effet aussitôt, et il peut l'annuler en un
 * clic depuis la fiche du compte, dans l'espace d'administration de la
 * société (voir AnnulerUneNominationAction). Elle nomme l'administrateur,
 * la personne et le magasin, et porte la société (`societe_id`).
 */
class ResponsableNommeParUnAdministrateur extends Notification
{
    public function __construct(
        public readonly User $administrateur,
        public readonly User $responsable,
        public readonly Magasin $magasin,
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
                ->title("{$this->magasin->nom} : responsable nommé par un administrateur")
                ->body("{$this->administrateur->name} a nommé {$this->responsable->name} responsable de {$this->magasin->nom} : cette personne a tous les droits dans ce magasin. Vous pouvez annuler cette nomination.")
                ->icon('heroicon-o-user-plus')
                ->status('warning')
                ->actions([
                    Action::make('voir')
                        ->label('Voir ou annuler')
                        ->url(MembreResource::getUrl('view', ['record' => $this->responsable], panel: 'societe', tenant: $this->magasin->societe))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'societe_id' => (int) $this->magasin->societe_id,
        ];
    }
}
