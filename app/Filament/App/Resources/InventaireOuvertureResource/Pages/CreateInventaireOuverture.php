<?php

namespace App\Filament\App\Resources\InventaireOuvertureResource\Pages;

use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Un inventaire d'ouverture s'enregistre en brouillon (voir
 * CreerInventaireOuvertureAction), puis la page revient à la liste des
 * inventaires, d'où sa fiche s'ouvre pour le valider.
 */
class CreateInventaireOuverture extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = InventaireOuvertureResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(fn (): Model => app(CreerInventaireOuvertureAction::class)->handle(
            CompteConnecte::exiger(),
            array_values(ValeursLues::lignes($data['lignes'] ?? [])),
            ['notes' => ValeurSimple::enTexteOuNul($data['notes'] ?? null)],
        ));
    }
}
