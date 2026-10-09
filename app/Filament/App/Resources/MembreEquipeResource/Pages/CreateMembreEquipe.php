<?php

namespace App\Filament\App\Resources\MembreEquipeResource\Pages;

use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * name/email/password ne sont pas des colonnes du pivot magasin_user : ce
 * formulaire ne construit jamais un MagasinUser directement, il délègue
 * entièrement à CreerMembreMagasinAction (création du compte sans
 * vérification email + rattachement + autorisations), puis renvoie la
 * ligne de rattachement créée pour que Filament puisse rediriger
 * normalement.
 *
 * Les autorisations cochées ne sont transmises que par le propriétaire ;
 * sans elles (un administrateur, dont la liste n'est pas envoyée), le
 * membre reçoit le modèle de son rôle.
 */
class CreateMembreEquipe extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = MembreEquipeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(function () use ($data) {
            /** @var Magasin $magasin */
            $magasin = Filament::getTenant();

            $utilisateur = app(CreerMembreMagasinAction::class)->handle($magasin, [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ], RoleMagasin::from($data['role']), $data['permissions'] ?? null);

            return $magasin->membres()->where('user_id', $utilisateur->getKey())->firstOrFail();
        });
    }
}
