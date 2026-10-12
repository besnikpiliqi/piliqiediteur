<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Crée le compte d'un vendeur ou d'un observateur de ce magasin (ou, pour
 * le propriétaire, d'un responsable), avec son mot de passe provisoire,
 * par CreerMembreAction, qui revérifie tout sur l'auteur relu. Une
 * adresse refusée par l'action s'affiche sur son champ.
 */
class CreateMembreDuMagasin extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = EquipeDuMagasinResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        return static::executerOuInterrompre(function () use ($data, $magasin): Affectation {
            try {
                $compte = app(CreerMembreAction::class)->handle(
                    auth()->user(),
                    $magasin->societe,
                    ['name' => $data['name'], 'email' => $data['email']],
                    $data['mot_de_passe_provisoire'],
                    [$magasin->getKey() => ['role' => $data['role'], 'autorisations' => $data['autorisations'] ?? []]],
                );
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->errors())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }

            return Affectation::query()->where('user_id', $compte->getKey())->where('magasin_id', $magasin->getKey())->firstOrFail();
        });
    }
}
