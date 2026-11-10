<?php

namespace App\Filament\Cabinet\Resources\CollaborateurResource\Pages;

use App\Domain\Cabinets\Actions\CreerCollaborateurAction;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\Cabinet\Resources\CollaborateurResource;
use App\Filament\Concerns\MontreLeCompteCree;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

/**
 * La liste des collaborateurs du cabinet, avec, pour son propriétaire, la
 * création d'un collaborateur : la fenêtre tire son jeton à son ouverture
 * (une demande répétée après une réponse perdue ne crée jamais un second
 * compte) et ne demande aucune adresse e-mail ; elle est suivie de la
 * fenêtre qui montre l'identifiant du compte créé, où la personne présente
 * choisit son mot de passe (voir GereLesComptes::fenetreDuCompteCree()).
 */
class ListCollaborateurs extends ListRecords
{
    use MontreLeCompteCree;

    protected static string $resource = CollaborateurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->creerUnCollaborateurAction(),
        ];
    }

    public function creerUnCollaborateurAction(): Action
    {
        return Action::make('creerUnCollaborateur')
            ->label('Créer un collaborateur')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => auth()->user()?->can('creerUnCollaborateur', User::class) ?? false)
            ->modalDescription('Un compte par collaborateur du cabinet, qui ne voit que les clients que vous lui confiez. Faute de poste reconnu, il ne se connecte pas avec son identifiant : s\'il est là, il choisit son mot de passe tout de suite, dans la fenêtre suivante.')
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid()])
            ->form([
                Hidden::make('jeton'),
                TextInput::make('name')->label('Nom complet')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $cree = null;

                CollaborateurResource::executerLeGeste(function () use ($data, &$cree): void {
                    $cree = app(CreerCollaborateurAction::class)->handle(CompteConnecte::exiger(), app(TenantContext::class)->societe(), ['name' => $data['name']], filled($data['jeton'] ?? null) ? (string) $data['jeton'] : null);
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }
}
