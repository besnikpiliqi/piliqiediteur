<?php

namespace App\Filament\App\Resources\ServiceResource\Pages;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Filament\App\Resources\ServiceResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

/**
 * La liste des services, où ils se créent et se modifient, chacun dans
 * sa fenêtre. La création passe par l'action du domaine (voir
 * CreerUneFicheAction) ; la modification aussi (voir
 * ServiceResource::actionModifier()), depuis la fiche telle que la
 * fenêtre l'a lue à son ouverture, que la page garde sans que le
 * navigateur puisse la changer. Un refus se lit en notification et la
 * fenêtre reste ouverte.
 */
class ManageServices extends ManageRecords
{
    use GereLesExceptionsMetier;

    protected static string $resource = ServiceResource::class;

    /**
     * La fiche de chaque service ouvert dans sa fenêtre « Modifier », telle
     * que la fenêtre l'a lue (voir FicheLue::valeurs()), par identifiant.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $fichesLues = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->using(fn (array $data): Service => static::executerOuInterrompre(fn (): Service => app(CreerUneFicheAction::class)->handle(auth()->user(), Service::class, $data))),
        ];
    }
}
