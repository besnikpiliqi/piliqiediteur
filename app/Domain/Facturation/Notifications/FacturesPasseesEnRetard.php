<?php

namespace App\Domain\Facturation\Notifications;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Filament\App\Resources\FactureResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Les factures du magasin que MarquerFacturesEnRetard vient de passer en
 * retard, pour son propriétaire : une seule notification par jour et par
 * magasin, qui nomme les premières.
 */
class FacturesPasseesEnRetard extends NotificationDuMagasin
{
    private const NUMEROS_AFFICHES = 10;

    /**
     * @param  list<string>  $numeros
     */
    public function __construct(Magasin $magasin, public readonly array $numeros)
    {
        parent::__construct($magasin);
    }

    protected function contenu(): NotificationFilament
    {
        $nombre = count($this->numeros);
        $autres = $nombre - self::NUMEROS_AFFICHES;

        return NotificationFilament::make()
            ->title($nombre > 1 ? "{$nombre} factures passées en retard" : '1 facture passée en retard')
            ->body('Échéance dépassée sans règlement complet : '
                .implode(', ', array_slice($this->numeros, 0, self::NUMEROS_AFFICHES))
                .($autres > 0 ? " et {$autres} autre(s)." : '.'))
            ->icon('heroicon-o-clock')
            ->warning()
            ->actions([
                Action::make('voir')
                    ->label('Voir les factures en retard')
                    ->url(FactureResource::getUrl('index', [
                        'tableFilters' => ['statut' => ['value' => StatutFacture::EnRetard->value]],
                    ], panel: 'app', tenant: $this->magasin))
                    ->markAsRead(),
            ]);
    }
}
