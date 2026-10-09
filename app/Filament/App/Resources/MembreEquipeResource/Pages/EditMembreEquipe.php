<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
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
     * -- le rôle et les autorisations par le seul propriétaire : pour un
     * autre membre qui gère l'équipe, ils sont affichés sans pouvoir être
     * changés ni envoyés (voir MettreAJourMembreMagasinAction). Jamais son
     * nom, son adresse ni son mot de passe : il les change lui-même dans
     * son profil (voir ModifierProfil), et « Nouveau mot de passe », sur la
     * liste, lui en donne un sans jamais montrer l'ancien.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            MembreEquipeResource::champRole()->disabled(fn (): bool => ! MembreEquipeResource::proprietaireConnecte()),
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

        $data['permissions'] = $membre->utilisateur->autorisationsCocheesDans($membre->magasin);

        return $data;
    }

    /**
     * Le rôle, le statut et les autorisations s'enregistrent ensemble ou
     * pas du tout, en un seul événement du journal (JET 130, voir
     * MettreAJourMembreMagasinAction). Un autre membre que le propriétaire
     * n'envoie ni rôle ni autorisations : le membre garde les siens, et
     * seul son statut change.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MagasinUser $record */
        return static::executerOuInterrompre(fn () => app(MettreAJourMembreMagasinAction::class)->handle(
            auth()->user(),
            $record,
            isset($data['role']) ? RoleMagasin::from($data['role']) : $record->role,
            StatutMembreMagasin::from($data['statut']),
            $data['permissions'] ?? null,
        ));
    }
}
