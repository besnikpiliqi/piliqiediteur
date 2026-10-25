<?php

namespace App\Filament\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Tenancy\Exceptions\JournalAuditNonConfigureException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * La suppression en masse de Filament ne consulte la policy qu'une fois,
 * pour afficher son bouton (deleteAny()), jamais pour chaque ligne cochée,
 * et chargerait sinon les lignes au début de la requête pour les supprimer
 * telles qu'elle les a lues. Chaque ligne cochée passe donc par l'action
 * du domaine de son geste, la même que son bouton : un brouillon de
 * facture (voir SupprimerFactureAction), de devis (voir
 * SupprimerDevisAction) ou de facture d'achat (voir
 * SupprimerFactureAchatAction) se supprime, un client, un produit ou un
 * fournisseur s'archive (voir ArchiverUneFicheAction), chacun relu sous
 * verrou, avec le magasin et l'auteur. Une ligne que l'action refuse reste
 * : une facture émise ou un devis numéroté coché à côté d'un brouillon,
 * un brouillon validé, émis ou supprimé par une autre requête entre le
 * chargement des lignes cochées et leur suppression, un magasin fermé
 * entre-temps, un droit retiré, le client comptoir qui ne s'archive jamais
 * (voir Client::booted()), un journal des événements qui ne s'écrit pas
 * pour un brouillon de facture (voir Facture::delete()), un incident du
 * serveur alors signalé au journal de l'application (voir
 * GereLesExceptionsMetier). Les lignes refusées restent cochées, et
 * l'utilisateur sait combien ne sont pas parties.
 */
trait SupprimeEnMasseLigneParLigne
{
    use GereLesExceptionsMetier;

    /**
     * @param  Closure(Model): void  $supprimer  l'action du domaine qui supprime une ligne cochée, au nom de la personne connectée
     */
    protected static function suppressionEnMasse(Closure $supprimer): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->action(fn (DeleteBulkAction $action, Collection $records) => self::retirerLigneParLigne($action, $records, $supprimer, 'supprimée', 'supprimées', 'Vous ne pouvez pas la supprimer, ou elle ne peut plus l\'être.', 'Vous ne pouvez pas les supprimer, ou elles ne peuvent plus l\'être.'));
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
            ->action(fn (DeleteBulkAction $action, Collection $records) => self::retirerLigneParLigne($action, $records, fn (Model $fiche) => app(ArchiverUneFicheAction::class)->archiver(auth()->user(), $fiche), 'archivée', 'archivées', 'Vous ne pouvez pas l\'archiver.', 'Vous ne pouvez pas les archiver.'));
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  Closure(Model): void  $retirer
     */
    private static function retirerLigneParLigne(DeleteBulkAction $action, Collection $records, Closure $retirer, string $participe, string $participePluriel, string $raison, string $raisonPluriel): void
    {
        $conservees = $records->reject(fn (Model $record): bool => self::retirerSansCasser($retirer, $record))->count();

        if ($conservees === 0) {
            $action->success();

            return;
        }

        $action->deselectRecordsAfterCompletion(false);

        Notification::make()
            ->title($conservees === 1
                ? "1 ligne cochée n'a pas été {$participe}"
                : "{$conservees} lignes cochées n'ont pas été {$participePluriel}")
            ->body($conservees === 1 ? $raison : $raisonPluriel)
            ->warning()
            ->send();
    }

    /**
     * La ligne est-elle partie ? Un refus de l'action la garde ; un refus
     * fiscal ou du journal d'audit est en plus signalé au journal de
     * l'application.
     *
     * @param  Closure(Model): void  $retirer
     */
    private static function retirerSansCasser(Closure $retirer, Model $record): bool
    {
        try {
            $retirer($record);

            return true;
        } catch (LogicException|AuthorizationException|RefusFiscalException|JournalAuditNonConfigureException $refus) {
            if ($refus instanceof RefusFiscalException || $refus instanceof JournalAuditNonConfigureException) {
                report($refus);
            }

            return false;
        }
    }
}
