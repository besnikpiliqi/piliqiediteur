<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Models\Affectation;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change le rôle et les cases de ce membre dans ce magasin, par
 * ModifierMembreAction, qui revérifie tout sur l'auteur et le compte relus :
 * ceux d'un vendeur ou d'un observateur, par qui gère tout son compte ;
 * rétrograder un responsable, par le propriétaire seul (voir
 * AffectationPolicy::update()).
 */
class EditMembreDuMagasin extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = EquipeDuMagasinResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Affectation $affectation */
        $affectation = $this->getRecord();

        return [...$data, 'role' => $affectation->role?->value, 'autorisations' => $affectation->autorisations()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Affectation $record */
        return static::executerOuInterrompre(function () use ($record, $data): Affectation {
            app(ModifierMembreAction::class)->handle(auth()->user(), $record->utilisateur, [
                $record->magasin_id => ['role' => $data['role'], 'autorisations' => $data['autorisations'] ?? []],
            ]);

            return $record->refresh();
        });
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
