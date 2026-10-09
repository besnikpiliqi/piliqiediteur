<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ReglementComptant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Point d'entrée unique de la caisse (voir app/Filament/App/Pages/Caisse.php) :
 * une vente comptoir est toujours payée intégralement et immédiatement,
 * contrairement à une facture B2B classique qui peut rester impayée ou se
 * régler plus tard. Compose donc en une seule transaction la création puis
 * l'émission de la facture avec son règlement comptant (voir
 * EmettreFactureAction) : le ticket signé au registre fiscal porte ce
 * règlement, ou le crédit d'avoir qui l'a couvert.
 *
 * Le registre fiscal du magasin se verrouille avant la création du
 * brouillon (voir RegistreFiscal) : sans clé de signature, rien n'est
 * créé.
 */
class EnregistrerVenteCaisseAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerFactureAction $creerFacture,
        private readonly EmettreFactureAction $emettreFacture,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Client $client, array $lignes, ModePaiement $modePaiement, ?string $reference = null): Facture
    {
        return DB::transaction(function () use ($client, $lignes, $modePaiement, $reference) {
            $this->registre->verrouiller($client->magasin);

            $facture = $this->emettreFacture->handle(
                $this->creerFacture->handle($client, $lignes),
                new ReglementComptant($modePaiement, $reference),
            );

            return $facture->fresh('lignes');
        });
    }
}
