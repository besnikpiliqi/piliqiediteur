<?php

namespace App\Filament\App\Resources\PaiementResource\Pages;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Models\Facture;
use App\Filament\App\Resources\PaiementResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Ne construit jamais un Paiement directement : la création et
 * l'allocation aux factures (avec verrouillage et mise à jour du statut)
 * sont entièrement déléguées à EnregistrerPaiementAction, pour garantir
 * les mêmes garanties qu'un encaissement déclenché ailleurs (ex : action
 * rapide "Encaisser" sur FactureResource).
 *
 * Un vendeur n'encaisse que ses factures (B16) : chaque facture imputée
 * doit être une facture que l'utilisateur voit, sinon la demande est
 * refusée (403) sans rien enregistrer, même avec un identifiant forgé
 * hors des choix proposés (voir PaiementResource). La page elle-même
 * exige l'autorisation d'enregistrer un paiement (voir
 * PaiementPolicy::create()). Ce qui reste dû de chaque facture se
 * vérifie ensuite sous verrou par l'action, avec son message. Le paiement
 * porte le jeton du formulaire (R3) : renvoyé après une réponse perdue,
 * il n'est enregistré qu'une fois.
 */
class CreatePaiement extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = PaiementResource::class;

    /**
     * @throws AuthorizationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Limité au magasin par MagasinScope ; withTrashed() garde le client
        // archivé qui règle ce qu'il doit encore (voir
        // Client::scopeQuiPeuventRegler()).
        $client = Client::withTrashed()->findOrFail($data['client_id']);
        $allocations = array_map(fn (array $allocation) => [
            'facture_id' => (int) $allocation['facture_id'],
            'montant' => (float) $allocation['montant'],
        ], $data['allocations']);

        foreach (Facture::query()->whereKey(array_column($allocations, 'facture_id'))->get() as $facture) {
            Gate::authorize('view', $facture);
        }

        // Le formulaire bloque déjà toute saisie dépassant le restant dû
        // via maxValue() : ce garde-fou ne se déclenche qu'en cas de
        // changement du solde entre l'affichage du formulaire et sa
        // soumission (ex : un autre paiement ou avoir appliqué entre-temps).
        return static::executerOuInterrompre(fn () => app(EnregistrerPaiementAction::class)->handle(auth()->user(), $client, [
            'mode_paiement' => $data['mode_paiement'],
            'montant' => $data['montant'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recu_le' => $data['recu_le'],
        ], $allocations, $data['jeton']));
    }
}
