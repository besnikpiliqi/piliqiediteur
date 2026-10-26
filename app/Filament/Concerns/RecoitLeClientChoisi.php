<?php

namespace App\Filament\Concerns;

use App\Domain\Contacts\Models\Client;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Resources\ClientResource;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;

/**
 * Une page qui saisit une vente (caisse, facture, devis) reçoit le client
 * que choisit le tableau de la loupe du champ « Client » (voir
 * SelecteurDeClients). Ce qui arrive du navigateur est revérifié : seul
 * un client du magasin actif (MagasinScope), non archivé, se pose dans le
 * formulaire.
 */
trait RecoitLeClientChoisi
{
    /**
     * Le client se pose dans le champ « Client » comme s'il y avait été
     * choisi : le champ fait ce qu'il fait à chaque choix (le mode de
     * paiement préféré à la caisse, la nature de l'opération d'une facture
     * ou d'un devis), et la fenêtre de la loupe se ferme.
     */
    #[On(SelecteurDeClients::EVENEMENT)]
    public function recevoirLeClientChoisi(mixed $clientId = null): void
    {
        $client = is_int($clientId) || (is_string($clientId) && ctype_digit($clientId)) ? Client::query()->find((int) $clientId) : null;

        if ($client === null) {
            Notification::make()->title(ClientResource::CLIENT_INTROUVABLE)->danger()->send();

            return;
        }

        /** @var Select $champ */
        $champ = $this->getForm('form')->getComponent('data.client_id');
        $champ->state($client->getKey());
        $champ->callAfterStateUpdated();

        if (end($this->mountedFormComponentActions) === 'chercherUnClient') {
            $this->unmountFormComponentAction();
        }
    }
}
