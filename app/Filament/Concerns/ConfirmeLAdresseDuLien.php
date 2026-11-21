<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * La fenêtre qui confirme l'adresse où part le lien du mot de passe d'un
 * expert-comptable de la société, avant l'envoi (« Déclarer votre
 * expert-comptable », « Ajouter une personne de ce cabinet », « Corriger
 * l'adresse ») : l'adresse saisie deux fois y est répétée en gras, car une
 * faute de frappe donnerait à un tiers l'entrée dans les magasins de la
 * société (cahier du lot EC2, R8 et C30). La fenêtre du geste, ouverte la
 * première, valide sa saisie et l'adresse, puis ouvre par-dessus elle la
 * fenêtre de confirmation, qui garde la saisie : « Corriger » y ramène, et
 * son bouton fait le geste avec cette saisie, puis ferme les deux fenêtres.
 * Le geste du domaine revérifie tout, sous ses verrous.
 */
trait ConfirmeLAdresseDuLien
{
    /**
     * Valide l'adresse saisie deux fois dans la fenêtre du geste, puis
     * ouvre par-dessus elle la fenêtre de confirmation de ce nom (voir
     * confirmationDeLAdresse()).
     *
     * @param  array<string, mixed>  $saisie
     *
     * @throws ValidationException
     */
    protected function demanderLaConfirmationDeLAdresse(array $saisie, string $confirmation): void
    {
        app(CreationDesComptes::class)->validerLAdresse($saisie['email'] ?? null, $saisie['email_confirmation'] ?? null);
        $this->mountAction($confirmation);
    }

    /**
     * Fait de cette action la fenêtre de confirmation, imbriquée dans celle
     * du geste : elle ne s'ouvre que là où la fenêtre du geste s'ouvre
     * ($visible), répète l'adresse en gras dans ce texte (« :adresse » y
     * marque sa place) et passe au geste la saisie de la fenêtre du geste.
     *
     * @param  Closure(): bool  $visible
     * @param  Closure(array<string, mixed>, Action): void  $geste
     */
    protected function confirmationDeLAdresse(Action $confirmation, string $texte, string $bouton, Closure $visible, Closure $geste): Action
    {
        return $confirmation
            ->visible($visible)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-at-symbol')
            ->modalHeading('Vérifiez l\'adresse avant l\'envoi')
            ->modalDescription(fn (): Htmlable => self::adresseEnGras($texte, $this->saisieDeLaFenetreDuGeste()['email'] ?? null))
            ->modalSubmitActionLabel($bouton)
            ->modalCancelActionLabel('Corriger')
            ->cancelParentActions()
            ->action(fn (Action $action) => $geste($this->saisieDeLaFenetreDuGeste(), $action));
    }

    /**
     * La saisie de la fenêtre du geste, la première ouverte, sous la
     * fenêtre de confirmation.
     *
     * @return array<string, mixed>
     */
    protected function saisieDeLaFenetreDuGeste(): array
    {
        return ValeursLues::champs(array_values($this->mountedActionsData ?? [])[0] ?? []);
    }

    /**
     * Après un refus dans la fenêtre de confirmation, revient à la fenêtre
     * du geste, qui garde sa saisie, pour la corriger ; dans la fenêtre du
     * geste elle-même, la garde ouverte.
     */
    protected function revenirALaSaisie(Action $action): void
    {
        if (count($this->mountedActions ?? []) > 1) {
            $this->unmountAction(shouldCancelParentActions: false);
        }

        $action->halt();
    }

    /**
     * Ce texte, échappé, avec l'adresse normalisée en gras à la place de
     * « :adresse ».
     */
    public static function adresseEnGras(string $texte, mixed $adresse): Htmlable
    {
        $adresse = is_string($adresse) ? User::normaliserEmail($adresse) : '';

        return new HtmlString(str_replace(e(':adresse'), '<strong>'.e($adresse).'</strong>', e($texte)));
    }
}
