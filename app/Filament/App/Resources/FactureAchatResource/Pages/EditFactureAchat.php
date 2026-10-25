<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessible tant que la facture d'achat reste modifiable (voir
 * FactureAchat::estModifiable() et FactureAchatPolicy::update()), lignes
 * comprises.
 */
class EditFactureAchat extends EditRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FactureAchatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureAchatResource::actionSupprimer(Actions\DeleteAction::make()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lignes'] = $this->record->lignes
            ->map(fn (LigneAchat $ligne): array => FactureAchatResource::ligneDejaEnregistree($ligne))
            ->all();
        $data['escompte_pourcentage'] = FactureAchatResource::pourcentageEnTexte($this->record->escompte_pourcentage);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Limité au magasin par MagasinScope ; withTrashed() garde le
        // fournisseur déjà rattaché s'il a été archivé depuis (voir
        // AppServiceProvider::limiterLesChoixAuMagasin()).
        $fournisseur = Fournisseur::withTrashed()->findOrFail($data['fournisseur_id']);

        return static::executerOuInterrompre(fn () => app(ModifierFactureAchatAction::class)->handle(auth()->user(), $record, $fournisseur, array_values($data['lignes']), [
            'numero_fournisseur' => $data['numero_fournisseur'] ?? null,
            'date_facture' => $data['date_facture'] ?? null,
            'escompte_pourcentage' => $data['escompte_pourcentage'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ]));
    }
}
