<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change le rôle et les cases de ce membre dans son magasin, par
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
        $affectation = ValeursLues::instance($this->getRecord(), Affectation::class);

        return [...$data, 'role' => $affectation->role?->value, ...EquipeDuMagasinResource::autorisationsAAfficher($affectation->autorisations(), 'autorisations')];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $affectation = ValeursLues::instance($record, Affectation::class);

        return static::executerOuInterrompre(function () use ($affectation, $data): Affectation {
            app(ModifierMembreAction::class)->handle(CompteConnecte::exiger(), $affectation->utilisateur, EquipeDuMagasinResource::roleChoisi($data), EquipeDuMagasinResource::autorisationsSaisies($data, 'autorisations'));

            return $affectation->refresh();
        });
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
