<?php

namespace App\Filament\App\Resources\PaiementResource\Pages;

use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Filament\App\Resources\PaiementResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Ne construit jamais un Paiement directement : la création et
 * l'allocation aux factures (avec verrouillage et mise à jour du statut)
 * sont entièrement déléguées à EnregistrerPaiementAction, pour garantir
 * les mêmes garanties qu'un encaissement déclenché ailleurs (ex : action
 * rapide "Encaisser" sur FactureResource).
 */
class CreatePaiement extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = PaiementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $allocations = array_map(fn (array $allocation) => [
            'facture_id' => (int) $allocation['facture_id'],
            'montant' => (float) $allocation['montant'],
        ], $data['allocations']);

        // Le formulaire bloque déjà toute saisie dépassant le restant dû
        // via maxValue() : ce garde-fou ne se déclenche qu'en cas de
        // changement du solde entre l'affichage du formulaire et sa
        // soumission (ex : un autre paiement ou avoir appliqué entre-temps).
        return static::executerOuInterrompre(fn () => app(EnregistrerPaiementAction::class)->handle([
            'client_id' => $data['client_id'],
            'mode_paiement' => $data['mode_paiement'],
            'montant' => $data['montant'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recu_le' => $data['recu_le'],
        ], $allocations));
    }
}
