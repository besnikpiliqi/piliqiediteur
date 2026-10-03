<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Filament\App\Resources\MembreEquipeResource;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMembreEquipe extends EditRecord
{
    protected static string $resource = MembreEquipeResource::class;

    /**
     * Seuls le rôle et le statut se modifient ici -- pas le nom, l'email
     * ou le mot de passe (voir l'action dédiée "Nouveau mot de passe" sur
     * la liste, qui ne les expose pas non plus en clair après coup).
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('role')
                ->label('Rôle')
                ->options(collect(RoleMagasin::assignables())->mapWithKeys(
                    fn (RoleMagasin $role) => [$role->value => $role->getLabel()]
                ))
                ->required(),
            Select::make('statut')
                ->label('Statut')
                ->options(StatutMembreMagasin::class)
                ->required(),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MagasinUser $record */
        return app(MettreAJourMembreMagasinAction::class)->handle(
            $record,
            RoleMagasin::from($data['role']),
            StatutMembreMagasin::from($data['statut']),
        );
    }
}
