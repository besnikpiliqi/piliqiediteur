<?php

namespace App\Domain\Stock\Notifications;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * La vérification de chaque nuit a trouvé, dans le stock du magasin, ce
 * que seule une écriture faite en dehors de PILIQI explique : un stock qui
 * ne correspond plus à son grand livre, un achat validé sans trace au
 * journal d'audit, un coût moyen ou un fournisseur réécrits (voir
 * VerificateurDuStock). Le propriétaire de la société en est prévenu,
 * comme l'éditeur (journal de l'application) ; rien n'est bloqué, le
 * magasin continue de vendre en attendant l'examen (décision de
 * l'éditeur, Q-AUD1).
 */
class EcartDansLeStock extends NotificationDuMagasin
{
    public function __construct(Magasin $magasin, public readonly int $nombreDEcarts)
    {
        parent::__construct($magasin);
    }

    protected function contenu(): NotificationFilament
    {
        return NotificationFilament::make()
            ->title('Écart dans le stock')
            ->body("La vérification de l'intégrité a relevé {$this->nombreDEcarts} écart(s) dans le stock : une écriture faite en dehors de PILIQI. L'éditeur est prévenu et l'examine ; le magasin continue de vendre.")
            ->icon('heroicon-o-exclamation-triangle')
            ->danger();
    }
}
