<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
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
 * autre compte, ne monte aucune fenêtre et ne montre aucun identifiant. Le
 * mot de passe provisoire du compte créé se tient de même, en clair, le
 * temps que la fenêtre le montre, puis s'oublie.
 * Pour une page de ressource dont la ressource gère les comptes (voir
 * GereLesComptes).
 */
trait MontreLeCompteCree
{
    #[Locked]
    public ?int $compteCreeId = null;

    /**
     * Le mot de passe provisoire du compte que la page vient de créer, en
     * clair, le temps de le montrer une seule fois à qui le remet en main
     * propre (voir MotDePasseProvisoire) : posé côté serveur par la
     * création elle-même, oublié dès que la fenêtre se ferme, jamais
     * enregistré.
     */
    #[Locked]
    public ?string $motDePasseProvisoireMontre = null;

    public function compteCreeAction(): Action
    {
        return ValeursLues::instance(static::getResource()::fenetreDuCompteCree(
            Action::make('compteCree'),
            fn (): ?int => $this->compteCreeId,
            fn (): ?string => $this->motDePasseProvisoireMontre,
            function (): void {
                $this->motDePasseProvisoireMontre = null;
            },
        ), Action::class);
    }

    /**
     * Retient le compte que la page vient de créer, puis remplace la
     * fenêtre de création par celle qui le montre ; si la personne
     * connectée ne peut pas voir son identifiant, seulement « Le compte est
     * créé. ».
     */
    protected function montrerLeCompteCree(User $cree): void
    {
        $this->compteCreeId = CleDuModele::de($cree);
        $this->motDePasseProvisoireMontre = $cree->motDePasseProvisoire?->valeur();
        $this->replaceMountedAction('compteCree');

        if ($this->getMountedAction() === null) {
            Notification::make()->title('Le compte est créé.')->success()->send();
        }
    }
}
