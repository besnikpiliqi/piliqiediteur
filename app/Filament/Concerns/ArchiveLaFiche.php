<?php

namespace App\Filament\Concerns;

use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Model;

/**
 * Un client, un produit ou un fournisseur ne se supprime jamais : il
 * s'archive, n'est plus proposé, reste lisible sur ses documents et se
 * restaure depuis la liste des archivés. Filament montre chaque bouton
 * selon la policy (delete(), restore()) ; le geste lui-même passe par
 * ArchiverUneFicheAction, qui relit sous verrou le magasin, l'auteur et
 * la fiche, et dont un refus se lit en notification. L'archivage en masse
 * est SupprimeEnMasseLigneParLigne::archivageEnMasse().
 */
trait ArchiveLaFiche
{
    use GereLesExceptionsMetier;

    /**
     * Le bouton « Archiver » de la page de modification, qui revient
     * ensuite à la liste.
     */
    protected static function actionArchiver(string $titre, string $description, string $notification): DeleteAction
    {
        return DeleteAction::make('archiver')
            ->label('Archiver')
            ->modalHeading($titre)
            ->modalDescription($description)
            ->modalSubmitActionLabel('Archiver')
            ->modalIcon('heroicon-o-archive-box')
            ->successNotificationTitle($notification)
            ->using(fn (Model $record): bool => static::executerOuInterrompre(function () use ($record): bool {
                app(ArchiverUneFicheAction::class)->archiver(auth()->user(), $record);

                return true;
            }));
    }

    /**
     * Les fiches archivées restent hors de la liste tant qu'on ne les
     * affiche pas.
     */
    protected static function filtreDesArchives(): TrashedFilter
    {
        return TrashedFilter::make()
            ->label('Archivés')
            ->placeholder('Sans les archivés')
            ->trueLabel('Avec les archivés')
            ->falseLabel('Seulement les archivés');
    }

    protected static function actionRestaurer(): RestoreAction
    {
        return RestoreAction::make()
            ->label('Restaurer')
            ->using(fn (Model $record): bool => static::executerOuInterrompre(function () use ($record): bool {
                app(ArchiverUneFicheAction::class)->restaurer(auth()->user(), $record);

                return true;
            }));
    }
}
