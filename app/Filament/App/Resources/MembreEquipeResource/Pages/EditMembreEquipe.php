<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMembreEquipe extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = MembreEquipeResource::class;

    /**
     * Le rôle, le statut et les autorisations du membre se modifient ici
     * -- les autorisations par le seul propriétaire (voir
     * MembreEquipeResource::champAutorisations()). Jamais son nom, son
     * adresse ni son mot de passe : il les change lui-même dans son profil
     * (voir ModifierProfil), et « Nouveau mot de passe », sur la liste, lui
     * en donne un sans jamais montrer l'ancien.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            MembreEquipeResource::champRole(),
            Select::make('statut')
                ->label('Statut')
                ->options(StatutMembreMagasin::class)
                ->required(),
            MembreEquipeResource::champAutorisations(),
        ]);
    }

    /**
     * Les autorisations que le membre a aujourd'hui dans ce magasin.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MagasinUser $membre */
        $membre = $this->record;

        app(TenantContext::class)->set($membre->magasin);
        $data['permissions'] = $membre->utilisateur->getDirectPermissions()->pluck('name')->all();

        return $data;
    }

    /**
     * Le rôle, le statut et les autorisations s'enregistrent ensemble ou
     * pas du tout, en un seul événement du journal (JET 130, voir
     * MettreAJourMembreMagasinAction). Sans liste d'autorisations (un
     * administrateur ne l'envoie pas), un changement de rôle donne le
     * modèle du nouveau rôle, et un simple changement de statut garde
     * celles que le propriétaire a cochées.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MagasinUser $record */
        return static::executerOuInterrompre(fn () => app(MettreAJourMembreMagasinAction::class)->handle(
            $record,
            RoleMagasin::from($data['role']),
            StatutMembreMagasin::from($data['statut']),
            $data['permissions'] ?? null,
        ));
    }
}
