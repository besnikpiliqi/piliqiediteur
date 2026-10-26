<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;

/**
 * La fenêtre « Compte créé » d'une page qui crée des comptes (voir
 * GereLesComptes::fenetreDuCompteCree()) : elle ne montre que le compte que
 * cette page vient de créer, dont l'identifiant est posé côté serveur par
 * la création elle-même, dans une propriété verrouillée que le navigateur
 * ne peut ni écrire ni remplacer. Une demande forgée, qui désignerait un
 * autre compte, ne monte aucune fenêtre et ne montre aucun identifiant.
 * Pour une page de ressource dont la ressource gère les comptes (voir
 * GereLesComptes).
 */
trait MontreLeCompteCree
{
    #[Locked]
    public ?int $compteCreeId = null;

    public function compteCreeAction(): Action
    {
        return static::getResource()::fenetreDuCompteCree(Action::make('compteCree'), fn (): ?int => $this->compteCreeId);
    }

    /**
     * Retient le compte que la page vient de créer, puis remplace la
     * fenêtre de création par celle qui le montre ; si la personne
     * connectée ne peut pas voir son identifiant, seulement « Le compte est
     * créé. ».
     */
    protected function montrerLeCompteCree(User $cree): void
    {
        $this->compteCreeId = (int) $cree->getKey();
        $this->replaceMountedAction('compteCree');

        if ($this->getMountedAction() === null) {
            Notification::make()->title('Le compte est créé.')->success()->send();
        }
    }
}
