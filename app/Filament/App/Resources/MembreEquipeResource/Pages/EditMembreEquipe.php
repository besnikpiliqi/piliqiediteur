<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\DefinirPermissionsSupplementairesMembreAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditMembreEquipe extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = MembreEquipeResource::class;

    /**
     * Seuls le rôle, le statut, et -- pour le propriétaire uniquement --
     * les permissions individuelles supplémentaires se modifient ici. Pas
     * le nom, l'email ou le mot de passe (voir l'action dédiée "Nouveau
     * mot de passe" sur la liste, qui ne les expose pas non plus en
     * clair après coup).
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
            CheckboxList::make('permissions_supplementaires')
                ->label('Permissions supplémentaires')
                ->helperText('En plus de celles déjà incluses dans le rôle choisi ci-dessus -- pour autoriser ponctuellement ce membre à voir ou faire quelque chose que son rôle ne couvre pas par défaut.')
                ->options(PermissionRegistry::libelles())
                ->columns(2)
                ->visible(fn () => auth()->user()->hasRole(RoleMagasin::Proprietaire->value))
                ->dehydrated(fn () => auth()->user()->hasRole(RoleMagasin::Proprietaire->value)),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MagasinUser $membre */
        $membre = $this->record;

        app(TenantContext::class)->set($membre->magasin);
        $data['permissions_supplementaires'] = $membre->utilisateur->getDirectPermissions()->pluck('name')->all();

        return $data;
    }

    /**
     * Le rôle, le statut et les permissions supplémentaires s'enregistrent
     * ensemble ou pas du tout : chaque changement s'inscrit au journal des
     * événements du magasin (JET 130), dans la même transaction.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MagasinUser $record */
        $permissionsSupplementaires = $data['permissions_supplementaires'] ?? null;

        return static::executerOuInterrompre(fn () => DB::transaction(function () use ($record, $data, $permissionsSupplementaires) {
            $record = app(MettreAJourMembreMagasinAction::class)->handle(
                $record,
                RoleMagasin::from($data['role']),
                StatutMembreMagasin::from($data['statut']),
            );

            // null (pas propriétaire, champ non déshydraté) : on ne touche
            // surtout pas aux permissions directes existantes du membre.
            if ($permissionsSupplementaires !== null) {
                app(DefinirPermissionsSupplementairesMembreAction::class)->handle($record, $permissionsSupplementaires);
            }

            return $record;
        }));
    }
}
