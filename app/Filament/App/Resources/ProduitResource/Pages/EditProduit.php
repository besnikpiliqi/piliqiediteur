<?php

namespace App\Filament\App\Resources\ProduitResource\Pages;

use App\Filament\App\Resources\ProduitResource;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduit extends EditRecord
{
    use ArchiveLaFiche, GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = ProduitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::actionArchiver(
                'Archiver ce produit',
                'Il ne sera plus proposé à la vente ni à l\'achat. Il reste sur ses documents et compte toujours dans la valeur du stock ; vous pourrez le restaurer depuis les produits archivés.',
                'Produit archivé',
            ),
        ];
    }

    /**
     * Un changement de taux de TVA s'inscrit au journal des événements du
     * magasin (voir JournaliseSesParametresDeConformite) : quand le
     * registre fiscal ne peut pas l'inscrire, la fiche n'est pas modifiée
     * et l'écran dit pourquoi.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
