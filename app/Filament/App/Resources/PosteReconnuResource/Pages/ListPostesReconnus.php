<?php

namespace App\Filament\App\Resources\PosteReconnuResource\Pages;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Filament\App\Resources\PosteReconnuResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

/**
 * Les postes reconnus du magasin, avec « Reconnaître ce navigateur » : le
 * navigateur que le propriétaire ou un responsable utilise devient, sous le
 * nom qu'il lui donne, un poste du magasin, et reçoit son témoin (voir
 * PostesReconnus::reconnaitre()).
 */
class ListPostesReconnus extends ListRecords
{
    use GereLesExceptionsMetier;

    protected static string $resource = PosteReconnuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconnaitre')
                ->label('Reconnaître ce navigateur')
                ->icon('heroicon-o-computer-desktop')
                ->authorize('create', PosteReconnu::class)
                ->modalDescription('Ce navigateur deviendra un poste du magasin : un compte dont le mot de passe est encore son identifiant pourra s\'y connecter. Ne reconnaissez qu\'un poste du magasin, jamais un appareil personnel.')
                ->form([
                    TextInput::make('nom')->label('Nom du poste')->placeholder('Caisse 1')->required()->maxLength(100),
                ])
                ->action(function (array $data): void {
                    static::executerSansCasser(function () use ($data): void {
                        try {
                            app(PostesReconnus::class)->reconnaitre($this->auteur(), $this->magasin(), (string) $data['nom'], request());
                        } catch (ValidationException $refus) {
                            Notification::make()->title(implode(' ', array_merge(...array_values($refus->errors()))))->danger()->send();

                            return;
                        }

                        Notification::make()->title('Ce navigateur est reconnu comme un poste du magasin.')->success()->send();
                    });
                }),
        ];
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
