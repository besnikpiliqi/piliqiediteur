<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Filament\App\Resources\ClientResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Un nouveau client, créé par l'action du domaine, qui relit sous verrou
 * le magasin et son auteur (voir CreerUneFicheAction) : un refus se lit en
 * notification, et la saisie reste à l'écran.
 */
class CreateClient extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn (): Client => app(CreerUneFicheAction::class)->handle(auth()->user(), Client::class, $data));
    }
}
