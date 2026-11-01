<?php

namespace App\Filament\Cabinet\Resources\CollaborateurResource\Pages;

use App\Domain\Tenancy\Actions\AnnulerLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Filament\Cabinet\Resources\CollaborateurResource;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'un collaborateur du cabinet, avec les gestes de son
 * propriétaire (voir CollaborateurResource et PorteeDesDroits) : redonner
 * l'accès, en sa présence seulement (son identifiant n'ouvre aucun compte
 * de cabinet), suspendre, réactiver, retirer du cabinet, réintégrer, et
 * transmettre la propriété du cabinet à un collaborateur actif. Chaque
 * bouton n'apparaît qu'à qui peut faire ce geste, et l'action du domaine
 * le revérifie sous ses verrous sur des comptes relus.
 */
class FicheDuCollaborateur extends ViewRecord
{
    protected static string $resource = CollaborateurResource::class;

    protected function getHeaderActions(): array
    {
        $compte = fn (): User => $this->compte();

        return [
            $this->transmettreLaProprieteAction(),
            $this->renvoyerLeLienDeTransmissionAction(),
            $this->annulerLaTransmissionAction(),
            $this->reintegrerAction(),
            CollaborateurResource::actionRedonnerLAcces(Action::make('redonnerLAcces'), $compte)->after(fn () => $this->getRecord()->refresh()),
            CollaborateurResource::actionSuspendre(Action::make('suspendre'), $compte)->after(fn () => $this->getRecord()->refresh()),
            CollaborateurResource::actionReactiver(Action::make('reactiver'), $compte)->after(fn () => $this->getRecord()->refresh()),
            CollaborateurResource::actionRetirerDeLaSociete(Action::make('retirerDeLaSociete'), $compte)->label('Retirer du cabinet')->after(fn () => $this->getRecord()->refresh()),
        ];
    }

    /**
     * Le propriétaire demande la transmission de la propriété du cabinet à
     * ce collaborateur actif, en redonnant son mot de passe : un lien part
     * à l'adresse que le collaborateur a renseignée lui-même, et la
     * propriété ne passe que quand il le suit (voir
     * DemanderLaTransmissionDeLaProprieteAction).
     */
    public function transmettreLaProprieteAction(): Action
    {
        return Action::make('transmettreLaPropriete')
            ->label('Transmettre la propriété du cabinet')
            ->icon('heroicon-o-key')
            ->color('danger')
            ->visible(fn (): bool => (auth()->user()?->can('transmettreLaPropriete', $this->compte()) ?? false) && $this->transmissionEnAttente() === null)
            ->modalDescription(fn (): string => "Un lien, valable 24 heures, part à l'adresse que {$this->compte()->name} a renseignée dans son profil : la propriété ne passera que quand il l'aura suivi, connecté à son compte, et vous pourrez annuler d'ici là. Il deviendra alors le propriétaire du cabinet ; vous en deviendrez collaborateur, avec tous les clients du cabinet confiés. Il doit avoir choisi son propre mot de passe et renseigné lui-même son adresse.")
            ->form([
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required(),
            ])
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    app(DemanderLaTransmissionDeLaProprieteAction::class)->handle(auth()->user(), $this->compte(), $data['mot_de_passe']);
                    Notification::make()->title('Un lien a été envoyé à l\'adresse de ce collaborateur : la propriété ne passera que quand il l\'aura suivi.')->success()->send();
                });
            });
    }

    public function renvoyerLeLienDeTransmissionAction(): Action
    {
        return Action::make('renvoyerLeLienDeTransmission')
            ->label('Renvoyer le lien de transmission')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalDescription('Un nouveau lien, valable 24 heures, part à son adresse ; le précédent ne sert plus.')
            ->visible(fn (): bool => (auth()->user()?->can('transmettreLaPropriete', $this->compte()) ?? false) && $this->transmissionEnAttente() !== null)
            ->action(function (): void {
                $this->agir(function (): void {
                    app(DemanderLaTransmissionDeLaProprieteAction::class)->renvoyerLeLien(auth()->user(), $this->compte());
                    Notification::make()->title('Un nouveau lien a été envoyé à l\'adresse de ce collaborateur.')->success()->send();
                });
            });
    }

    public function annulerLaTransmissionAction(): Action
    {
        return Action::make('annulerLaTransmission')
            ->label('Annuler la transmission')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Le lien envoyé ne sert plus ; rien n\'a changé dans le cabinet.')
            ->visible(fn (): bool => (auth()->user()?->estProprietaire() ?? false) && $this->transmissionEnAttente() !== null)
            ->action(function (): void {
                $this->agir(function (): void {
                    app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle(auth()->user(), $this->compte());
                    Notification::make()->title('La transmission de la propriété est annulée.')->success()->send();
                });
            });
    }

    /**
     * Réintégrer un collaborateur retiré : le propriétaire seul ; il
     * revient sans aucun client confié.
     */
    public function reintegrerAction(): Action
    {
        return Action::make('reintegrer')
            ->label('Réintégrer')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Il retrouve son compte, sans aucun client confié.')
            ->visible(fn (): bool => $this->compte()->estRetire() && (auth()->user()?->can('creerUnCollaborateur', User::class) ?? false))
            ->action(function (): void {
                $this->agir(fn () => app(ChangerStatutCompteAction::class)->reintegrerLeCollaborateur(auth()->user(), $this->compte()));
            });
    }

    /**
     * La demande de transmission de la propriété qui attend pour ce compte.
     */
    private function transmissionEnAttente(): ?TransmissionPropriete
    {
        return TransmissionPropriete::query()
            ->where('destinataire_id', $this->compte()->getKey())
            ->whereNull('annulee_le')
            ->whereNull('acceptee_le')
            ->where('expire_le', '>', now())
            ->first();
    }

    /**
     * Exécute le geste, affiche un refus sans casser la page, puis relit
     * le compte pour que la fiche montre son nouvel état.
     */
    private function agir(Closure $geste): void
    {
        CollaborateurResource::executerLeGeste($geste);
        $this->getRecord()->refresh();
    }

    private function compte(): User
    {
        /** @var User */
        return $this->getRecord();
    }
}
