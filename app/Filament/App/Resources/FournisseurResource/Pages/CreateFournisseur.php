<?php

namespace App\Filament\App\Resources\FournisseurResource\Pages;

use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Un nouveau fournisseur, créé par l'action du domaine, qui relit sous
 * verrou le magasin et son auteur (voir CreerUneFicheAction) : un refus se
 * lit en notification, et la saisie reste à l'écran.
 */
class CreateFournisseur extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FournisseurResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn (): Fournisseur => app(CreerUneFicheAction::class)->handle(auth()->user(), Fournisseur::class, $data));
    }
}
