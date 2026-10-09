<?php

namespace App\Domain\Facturation\Notifications;

use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\FactureResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Un e-mail n'a pas pu partir après toutes ses tentatives (voir
 * EnvoyerDocumentJob::failed()) : celui qui l'a demandé, ou à défaut le
 * propriétaire du magasin pour une relance automatique, doit le savoir
 * pour réessayer ou prévenir son client autrement.
 */
class EnvoiDocumentEchoue extends NotificationDuMagasin
{
    public function __construct(public readonly EnvoiDocument $envoi)
    {
        parent::__construct($envoi->magasin);
    }

    protected function contenu(): NotificationFilament
    {
        $document = $this->envoi->document;
        $envoi = $this->envoi->nature === NatureEnvoi::Relance
            ? "La relance n°{$this->envoi->niveau_relance} à {$this->envoi->destinataire} n'est pas partie"
            : "L'e-mail à {$this->envoi->destinataire} n'est pas parti";

        return NotificationFilament::make()
            ->title('Échec de l\'envoi : '.($document === null ? 'document supprimé' : "{$document->libelleDocument()} {$document->numero}"))
            ->body("{$envoi}. {$this->envoi->erreur}")
            ->icon('heroicon-o-exclamation-triangle')
            ->danger()
            ->actions($document === null ? [] : [
                Action::make('voir')
                    ->label('Voir le document')
                    ->url($this->urlDuDocument($document))
                    ->markAsRead(),
            ]);
    }

    private function urlDuDocument(Facture|Devis|Avoir $document): string
    {
        $resource = match (true) {
            $document instanceof Facture => FactureResource::class,
            $document instanceof Devis => DevisResource::class,
            $document instanceof Avoir => AvoirResource::class,
        };

        return $resource::getUrl('view', ['record' => $document], panel: 'app', tenant: $this->magasin);
    }
}
