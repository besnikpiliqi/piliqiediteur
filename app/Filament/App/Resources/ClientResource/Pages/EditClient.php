<?php

namespace App\Filament\App\Resources\ClientResource\Pages;

use App\Domain\Facturation\Services\NumeroTva;
use App\Filament\App\Resources\ClientResource;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\RevientALaListe;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    use ArchiveLaFiche, RevientALaListe;

    protected static string $resource = ClientResource::class;

    /**
     * « Vérifier sur VIES » pour un professionnel de l'Union (voir
     * ClientResource::verifierSurVies()), seulement pour le n° que montre
     * le formulaire (voir exigerLeNumeroEnregistre()) ; le client comptoir
     * n'a pas de bouton « Archiver » (voir ClientPolicy::delete()).
     */
    protected function getHeaderActions(): array
    {
        return [
            ClientResource::verifierSurVies(Action::make('verifier_sur_vies'))
                ->before(fn (Action $action) => $this->exigerLeNumeroEnregistre($action)),
            static::actionArchiver(
                'Archiver ce client',
                'Ce client ne sera plus proposé pour une nouvelle vente. Ses devis, factures, avoirs et paiements restent lisibles, et vous pourrez le restaurer depuis les clients archivés.',
                'Client archivé',
            ),
        ];
    }

    /**
     * VIES vérifie le n° enregistré sur la fiche (voir
     * VerifierNumeroTvaAction) : tant que le formulaire en montre un autre,
     * pas encore enregistré, rien n'est vérifié, pour qu'une vérification
     * ne paraisse jamais porter sur le n° saisi. Le n° se compare tel qu'il
     * s'enregistre, sans ses séparateurs (voir NumeroTva::normaliser()).
     */
    private function exigerLeNumeroEnregistre(Action $action): void
    {
        $saisie = data_get($this->data, 'numero_tva');
        $numeroSaisi = is_string($saisie) && filled($saisie) ? NumeroTva::normaliser($saisie) : null;
        $numeroEnregistre = $this->getRecord()->getAttribute('numero_tva');

        if ($numeroSaisi === $numeroEnregistre) {
            return;
        }

        Notification::make()
            ->title("Enregistrez d'abord le n° de TVA modifié : VIES vérifie le n° enregistré sur la fiche, {$numeroEnregistre}.")
            ->warning()
            ->send();

        $action->cancel();
    }
}
