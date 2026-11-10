<?php

namespace App\Filament\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Exceptions\JournalAuditNonConfigureException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
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
 * GereLesExceptionsMetier). L'utilisateur sait combien ne sont pas
 * parties, et seules les lignes refusées restent cochées : le tableau de
 * Filament garde les lignes cochées dans le navigateur et ne sait que
 * toutes les décocher, sur l'événement deselectAllTableRecords ; l'action
 * envoie donc à la place l'événement LIGNES_GARDEES avec les seules clés
 * refusées encore dans le magasin, que le tableau de ce composant reprend
 * comme sa sélection (voir la vue filament.app.lignes-cochees-gardees,
 * posée dans chaque tableau du panneau du magasin par AppPanelProvider).
 * Les lignes supprimées ou archivées, par l'action ou par une autre
 * requête, et celles qui avaient déjà quitté la liste, ne restent pas
 * cochées.
 */
trait SupprimeEnMasseLigneParLigne
{
    use GereLesExceptionsMetier;

    public const LIGNES_GARDEES = 'lignes-cochees-gardees';

    /**
     * @template TLigne of Model
     *
     * @param  class-string<TLigne>  $classe  la classe des lignes du tableau
     * @param  Closure(TLigne): mixed  $supprimer  l'action du domaine qui supprime une ligne cochée, au nom de la personne connectée
     */
    protected static function suppressionEnMasse(string $classe, Closure $supprimer): DeleteBulkAction
    {
        return self::retraitEnMasse(DeleteBulkAction::make(), $classe, $supprimer, 'supprimée', 'supprimées', 'Vous ne pouvez pas la supprimer, ou elle ne peut plus l\'être.', 'Vous ne pouvez pas les supprimer, ou elles ne peuvent plus l\'être.');
    }

    protected static function archivageEnMasse(): DeleteBulkAction
    {
        return self::retraitEnMasse(
            DeleteBulkAction::make('archiver')
                ->label('Archiver')
                ->modalHeading(fn (DeleteBulkAction $action): string => "Archiver les {$action->getPluralModelLabel()} sélectionnés")
                ->modalDescription('Ils ne seront plus proposés pour un nouveau document, restent lisibles sur les anciens, et se restaurent depuis la liste des archivés.')
                ->modalSubmitActionLabel('Archiver')
                ->successNotificationTitle('Archivés')
                ->icon('heroicon-m-archive-box')
                ->modalIcon('heroicon-o-archive-box'),
            Model::class,
            fn (Model $fiche) => app(ArchiverUneFicheAction::class)->archiver(auth()->user(), $fiche instanceof Client || $fiche instanceof Fournisseur || $fiche instanceof Produit ? $fiche : throw new LogicException('Seule une fiche de client, de fournisseur ou de produit s\'archive.')),
            'archivée',
            'archivées',
            'Vous ne pouvez pas l\'archiver.',
            'Vous ne pouvez pas les archiver.',
        );
    }

    /**
     * L'action groupée qui retire chaque ligne cochée par le geste du
     * domaine, celles qu'il refuse restant cochées.
     *
     * @template TLigne of Model
     *
     * @param  class-string<TLigne>  $classe  la classe des lignes du tableau
     * @param  Closure(TLigne): mixed  $retirer
     */
    private static function retraitEnMasse(DeleteBulkAction $action, string $classe, Closure $retirer, string $participe, string $participePluriel, string $raison, string $raisonPluriel): DeleteBulkAction
    {
        return $action->action(fn (DeleteBulkAction $action, Collection $records) => self::retirerLigneParLigne(
            $action,
            $records->map(fn (Model $record): Model => $record instanceof $classe ? $record : throw new LogicException('Une ligne cochée n\'est pas une ligne de ce tableau.')),
            $retirer,
            $participe,
            $participePluriel,
            $raison,
            $raisonPluriel,
        ));
    }

    /**
     * @template TLigne of Model
     *
     * @param  Collection<int, TLigne>  $records
     * @param  Closure(TLigne): mixed  $retirer
     */
    private static function retirerLigneParLigne(DeleteBulkAction $action, Collection $records, Closure $retirer, string $participe, string $participePluriel, string $raison, string $raisonPluriel): void
    {
        $refusees = $records->reject(fn (Model $record): bool => self::retirerSansCasser($retirer, $record));
        $conservees = $refusees->count();

        if ($conservees === 0) {
            $action->success();

            return;
        }

        $action->deselectRecordsAfterCompletion(false);
        $tableau = $action->getLivewire();

        if ($tableau instanceof Component) {
            $tableau->dispatch(self::LIGNES_GARDEES, composant: $tableau->getId(), cles: self::clesEncoreDansLeMagasin($refusees));
        }

        Notification::make()
            ->title($conservees === 1
                ? "1 ligne cochée n'a pas été {$participe}"
                : "{$conservees} lignes cochées n'ont pas été {$participePluriel}")
            ->body($conservees === 1 ? $raison : $raisonPluriel)
            ->warning()
            ->send();
    }

    /**
     * Les clés des lignes refusées encore dans le magasin, qui restent
     * cochées : une ligne refusée parce qu'une autre requête l'a supprimée
     * ou archivée entre-temps ne l'est plus.
     *
     * @param  Collection<int, Model>  $refusees
     * @return list<string>
     */
    private static function clesEncoreDansLeMagasin(Collection $refusees): array
    {
        $modele = $refusees->first();

        return array_values($modele->newQuery()->whereKey($refusees->modelKeys())->pluck($modele->getQualifiedKeyName())
            ->map(fn (mixed $cle): string => (string) $cle)
            ->sort()
            ->all());
    }

    /**
     * La ligne est-elle partie ? Un refus de l'action la garde ; un refus
     * fiscal ou du journal d'audit est en plus signalé au journal de
     * l'application.
     *
     * @template TLigne of Model
     *
     * @param  Closure(TLigne): mixed  $retirer
     * @param  TLigne  $record
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
