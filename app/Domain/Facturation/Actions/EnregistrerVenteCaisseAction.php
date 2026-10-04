<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique de la caisse (voir app/Filament/App/Pages/Caisse.php) :
 * une vente comptoir est toujours payée intégralement et immédiatement,
 * contrairement à une facture B2B classique qui peut rester impayée ou se
 * régler plus tard. Compose donc en une seule transaction la création, puis
 * l'émission (numérotation + décrément de stock, voir EmettreFactureAction)
 * et enfin l'encaissement intégral (voir EnregistrerPaiementAction) de la
 * facture.
 */
class EnregistrerVenteCaisseAction
{
    public function __construct(
        private readonly CreerFactureAction $creerFacture,
        private readonly EmettreFactureAction $emettreFacture,
        private readonly EnregistrerPaiementAction $enregistrerPaiement,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    public function handle(Client $client, array $lignes, ModePaiement $modePaiement, ?string $reference = null): Facture
    {
        return DB::transaction(function () use ($client, $lignes, $modePaiement, $reference) {
            $facture = $this->creerFacture->handle($client, $lignes);
            $facture = $this->emettreFacture->handle($facture);

            $this->enregistrerPaiement->handle(
                [
                    'client_id' => $client->getKey(),
                    'mode_paiement' => $modePaiement->value,
                    'montant' => $facture->total_ttc,
                    'reference' => $reference,
                    'recu_le' => now()->toDateString(),
                ],
                [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]],
            );

            return $facture->fresh('lignes');
        });
    }
}
