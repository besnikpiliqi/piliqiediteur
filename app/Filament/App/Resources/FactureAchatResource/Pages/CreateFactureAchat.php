<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFactureAchat extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FactureAchatResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return static::executerOuInterrompre(function () use ($data) {
            $fournisseur = Fournisseur::query()->findOrFail($data['fournisseur_id']);

            return app(CreerFactureAchatAction::class)->handle(auth()->user(), $fournisseur, array_values($data['lignes']), [
                'numero_fournisseur' => $data['numero_fournisseur'] ?? null,
                'date_facture' => $data['date_facture'] ?? null,
                'escompte_pourcentage' => $data['escompte_pourcentage'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }
}
