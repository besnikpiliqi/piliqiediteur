<?php

namespace App\Filament\App\Resources\DevisResource\Pages;

use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\RevientALaListeSiLeDocumentEstSupprime;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDevis extends ViewRecord
{
    use RevientALaListeSiLeDocumentEstSupprime;

    protected static string $resource = DevisResource::class;

    /**
     * Le PDF, l'envoi par e-mail ou sans e-mail d'un brouillon, sa
     * modification, la transformation en facture d'un devis accepté
     * (désactivée et expliquée pour qui ne peut pas créer de facture, voir
     * DevisResource::actionTransformer()) et le retrait du lien de réponse
     * en ligne. L'« À faire » d'un brouillon seul mène ici (voir
     * ChosesAFaireDuMagasin) : son envoi s'y fait, et « Modifier » ouvre sa
     * page de modification, autorisée par DevisPolicy::update(), pour
     * changer une validité dépassée avant de l'envoyer.
     */
    protected function getHeaderActions(): array
    {
        return [
            DevisResource::actionTransformer(Actions\Action::make('convertir')),
            DevisResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            DevisResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
            DevisResource::envoyerSansEmail(Actions\Action::make('envoyer')),
            Actions\EditAction::make(),
            DevisResource::actionRetirerLeLien(Actions\Action::make('retirer_le_lien')),
        ];
    }
}
