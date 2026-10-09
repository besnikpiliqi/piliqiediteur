<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * La suppression en masse de Filament ne consulte la policy qu'une fois,
 * pour afficher son bouton (deleteAny()), jamais pour chaque ligne cochée :
 * sans ce filtre, cocher une facture émise ou un devis déjà converti à
 * côté d'un brouillon les supprimerait aussi. Chaque ligne repasse donc
 * par delete() ; celles qu'il refuse restent, et l'utilisateur le sait.
 */
trait SupprimeEnMasseLigneParLigne
{
    protected static function suppressionEnMasse(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->action(function (DeleteBulkAction $action, Collection $records): void {
                $supprimables = $records->filter(fn (Model $record): bool => auth()->user()->can('delete', $record));
                $supprimables->each(fn (Model $record) => $record->delete());

                $conservees = $records->count() - $supprimables->count();

                if ($conservees === 0) {
                    $action->success();

                    return;
                }

                Notification::make()
                    ->title($conservees === 1
                        ? '1 ligne cochée n\'a pas été supprimée'
                        : "{$conservees} lignes cochées n'ont pas été supprimées")
                    ->body($conservees === 1
                        ? 'Vous ne pouvez pas la supprimer, ou elle ne peut plus l\'être.'
                        : 'Vous ne pouvez pas les supprimer, ou elles ne peuvent plus l\'être.')
                    ->warning()
                    ->send();
            });
    }
}
