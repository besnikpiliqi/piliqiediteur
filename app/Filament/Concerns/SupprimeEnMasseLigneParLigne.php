<?php

namespace App\Filament\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * La suppression en masse de Filament ne consulte la policy qu'une fois,
 * pour afficher son bouton (deleteAny()), jamais pour chaque ligne cochée :
 * sans ce filtre, cocher une facture émise ou un devis déjà numéroté à
 * côté d'un brouillon les supprimerait aussi. Chaque ligne repasse donc
 * par delete() ; celles qu'il refuse restent, et l'utilisateur le sait.
 * Une ligne dont le modèle refuse la suppression reste aussi : le journal
 * des événements qui ne s'écrit pas pour un brouillon de facture (voir
 * Facture::delete()), le devis numéroté entre-temps (voir
 * Devis::booted()), le client comptoir qui ne s'archive jamais (voir
 * Client::booted()), un incident du serveur alors signalé au journal de
 * l'application (voir GereLesExceptionsMetier).
 *
 * Un brouillon de facture ou de devis jamais numéroté se supprime
 * (suppressionEnMasse()) ;
 * un client, un produit ou un fournisseur s'archive (archivageEnMasse()),
 * avec le même filtre : delete() d'un modèle SoftDeletes l'archive, et la
 * fiche se restaure depuis la liste des archivés.
 */
trait SupprimeEnMasseLigneParLigne
{
    protected static function suppressionEnMasse(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->action(fn (DeleteBulkAction $action, Collection $records) => self::retirerLigneParLigne($action, $records, 'supprimée', 'supprimées', 'Vous ne pouvez pas la supprimer, ou elle ne peut plus l\'être.', 'Vous ne pouvez pas les supprimer, ou elles ne peuvent plus l\'être.'));
    }

    protected static function archivageEnMasse(): DeleteBulkAction
    {
        return DeleteBulkAction::make('archiver')
            ->label('Archiver')
            ->modalHeading(fn (DeleteBulkAction $action): string => "Archiver les {$action->getPluralModelLabel()} sélectionnés")
            ->modalDescription('Ils ne seront plus proposés pour un nouveau document, restent lisibles sur les anciens, et se restaurent depuis la liste des archivés.')
            ->modalSubmitActionLabel('Archiver')
            ->successNotificationTitle('Archivés')
            ->icon('heroicon-m-archive-box')
            ->modalIcon('heroicon-o-archive-box')
            ->action(fn (DeleteBulkAction $action, Collection $records) => self::retirerLigneParLigne($action, $records, 'archivée', 'archivées', 'Vous ne pouvez pas l\'archiver.', 'Vous ne pouvez pas les archiver.'));
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private static function retirerLigneParLigne(DeleteBulkAction $action, Collection $records, string $participe, string $participePluriel, string $raison, string $raisonPluriel): void
    {
        $retirees = $records->filter(fn (Model $record): bool => auth()->user()->can('delete', $record) && self::supprimerSansCasser($record));

        $conservees = $records->count() - $retirees->count();

        if ($conservees === 0) {
            $action->success();

            return;
        }

        Notification::make()
            ->title($conservees === 1
                ? "1 ligne cochée n'a pas été {$participe}"
                : "{$conservees} lignes cochées n'ont pas été {$participePluriel}")
            ->body($conservees === 1 ? $raison : $raisonPluriel)
            ->warning()
            ->send();
    }

    private static function supprimerSansCasser(Model $record): bool
    {
        try {
            return $record->delete() !== false;
        } catch (LogicException|RefusFiscalException $refus) {
            if ($refus instanceof RefusFiscalException) {
                report($refus);
            }

            return false;
        }
    }
}
