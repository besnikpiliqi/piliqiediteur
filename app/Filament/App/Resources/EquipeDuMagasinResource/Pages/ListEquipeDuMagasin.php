<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Domain\Tenancy\Actions\ProposerUnRattachementAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Models\User;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * L'équipe du magasin, avec la création d'un compte et la proposition, par
 * son adresse, à une personne dont le compte a été retiré de sa société de
 * rejoindre l'équipe de ce magasin avec ce compte (voir
 * ProposerUnRattachementAction) : comme vendeur ou observateur, ou, par
 * le propriétaire, comme responsable. La réponse ne dit jamais si
 * l'adresse est celle d'un compte.
 */
class ListEquipeDuMagasin extends ListRecords
{
    protected static string $resource = EquipeDuMagasinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Créer un compte'),
            $this->proposerUnRattachementAction(),
        ];
    }

    public function proposerUnRattachementAction(): Actions\Action
    {
        return Actions\Action::make('proposerUnRattachement')
            ->label('Proposer à un compte existant')
            ->icon('heroicon-o-envelope')
            ->visible(fn (): bool => app(PorteeDesDroits::class)->gereSonEquipe($this->auteur()) && app(PorteeDesDroits::class)->gereLEquipeDe($this->auteur(), $this->magasin()))
            ->modalDescription('Une personne dont le compte a été retiré de sa société peut rejoindre l\'équipe de ce magasin avec ce compte, si elle accepte : elle reçoit une proposition à son adresse, valable sept jours.')
            ->form([
                TextInput::make('email')->label('Adresse e-mail de son compte')->email()->adresseDeConnexion()->required()->maxLength(255),
                EquipeDuMagasinResource::champRole(),
                ...EquipeDuMagasinResource::champsAutorisations(),
            ])
            ->action(function (array $data): void {
                EquipeDuMagasinResource::executerLeGeste(function () use ($data): void {
                    app(ProposerUnRattachementAction::class)->handle(
                        $this->auteur(),
                        $this->magasin()->societe,
                        $data['email'],
                        RoleSociete::Membre,
                        [$this->magasin()->getKey() => ['role' => $data['role'], 'autorisations' => EquipeDuMagasinResource::autorisationsSaisies($data, 'autorisations')]],
                    );
                    Notification::make()->title(ProposerUnRattachementAction::REPONSE)->success()->send();
                });
            });
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
