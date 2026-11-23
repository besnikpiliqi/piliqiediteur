<?php

namespace App\Domain\Facturation\Notifications;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Filament\App\Resources\FactureResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * La plateforme agréée n'a pas reçu une facture après toutes les
 * tentatives automatiques (voir SubmitFactureToPdpJob::failed()) : le
 * propriétaire et les administrateurs qui suivent la plateforme agréée
 * dans ce magasin en sont prévenus, et peuvent la relancer depuis sa fiche
 * (décision de l'éditeur, Q-AUD3) ; PILIQI la relance de toute façon
 * chaque heure.
 */
class TransmissionNonAboutie extends NotificationDuMagasin
{
    public function __construct(public readonly Facture $facture)
    {
        parent::__construct($facture->magasin);
    }

    protected function contenu(): NotificationFilament
    {
        return NotificationFilament::make()
            ->title("Facture {$this->facture->numero} non transmise à la plateforme agréée")
            ->body("La plateforme agréée n'a pas pu être jointe après plusieurs tentatives : c'est un incident technique, pas un refus de la facture. PILIQI la relance chaque heure ; vous pouvez aussi la relancer depuis sa fiche.")
            ->icon('heroicon-o-exclamation-triangle')
            ->warning()
            ->actions([
                Action::make('voir')
                    ->label('Voir la facture')
                    ->url(FactureResource::getUrl('view', ['record' => $this->facture], panel: 'app', tenant: $this->magasin))
                    ->markAsRead(),
            ]);
    }
}
