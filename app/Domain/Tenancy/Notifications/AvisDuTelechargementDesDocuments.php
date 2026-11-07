<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Notification;

/**
 * Dans la cloche de la personne qui l'a demandé : son téléchargement des
 * documents par période est prêt, avec son lien, valable jusqu'à la date
 * dite, ou n'a pas pu être préparé, avec le motif (voir
 * PreparerLeTelechargementDesDocumentsJob). Elle porte la société de son
 * destinataire (`societe_id`) et, pour un compte d'un cabinet, le client
 * dont il lit les documents (`client_id`), pour s'effacer de sa cloche
 * quand ce client ne lui est plus ouvert (voir
 * User::effacerLesAvisDesClients()) ; d'un seul magasin, ce magasin
 * (`magasin_id`, voir User::effacerLesNotificationsDesMagasins()). Le lien
 * redemande ses droits à chaque téléchargement (voir
 * TelechargerLesDocumentsController).
 */
class AvisDuTelechargementDesDocuments extends Notification
{
    public function __construct(public readonly TelechargementDeDocuments $telechargement) {}

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
        $telechargement = $this->telechargement;
        $periode = 'du '.Calendrier::afficherJour((string) $telechargement->du, 'd/m/Y').' au '.Calendrier::afficherJour((string) $telechargement->au, 'd/m/Y');
        $notification = $telechargement->statut === StatutDuTelechargement::Pret
            ? NotificationFilament::make()
                ->title("Vos documents {$periode} sont prêts")
                ->body(($telechargement->nombre_documents > 1 ? "{$telechargement->nombre_documents} documents" : '1 document').", {$telechargement->format->getLabel()}. Le lien vaut jusqu'au ".Calendrier::deParis()->afficher($telechargement->expire_le, 'd/m/Y à H:i').'.')
                ->icon('heroicon-o-arrow-down-tray')
                ->success()
                ->actions([
                    Action::make('telecharger')
                        ->label('Télécharger')
                        ->url($telechargement->lien())
                        ->markAsRead(),
                ])
            : NotificationFilament::make()
                ->title("Vos documents {$periode} n'ont pas pu être préparés")
                ->body((string) $telechargement->motif_echec)
                ->icon('heroicon-o-exclamation-triangle')
                ->danger();
        $magasins = $telechargement->magasins ?? [];

        return [
            ...$notification->getDatabaseMessage(),
            'societe_id' => (int) $notifiable->societe_id,
            ...((int) $telechargement->societe_id === (int) $notifiable->societe_id ? [] : ['client_id' => (int) $telechargement->societe_id]),
            ...(count($magasins) === 1 ? ['magasin_id' => (int) $magasins[0]] : []),
        ];
    }
}
