<?php

namespace App\Filament\Concerns;

use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClientResource;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;

/**
 * Une page qui saisit une vente (caisse, facture, devis) reçoit le client
 * que choisit le tableau de la loupe du champ « Client » (voir
 * SelecteurDeClients). Ce qui arrive du navigateur est revérifié : seul
 * un client du magasin actif (MagasinScope), non archivé, se pose dans le
 * formulaire, et le client comptoir seulement à la caisse (décision de
 * l'éditeur du 01/11/2026).
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

        if ($client === null || ($client->estComptoir() && ! $this instanceof Caisse)) {
            Notification::make()->title(ClientResource::CLIENT_INTROUVABLE)->danger()->send();

            return;
        }

        $champ = ValeursLues::instance(ValeurRequise::de($this->getForm('form'), 'le formulaire de la page')->getComponent('data.client_id'), Select::class);
        $champ->state($client->getKey());
        $champ->callAfterStateUpdated();

        $actions = ValeurRequise::de($this->mountedFormComponentActions, 'les actions ouvertes du formulaire');

        if (end($actions) === 'chercherUnClient') {
            $this->unmountFormComponentAction();
        }
    }
}
