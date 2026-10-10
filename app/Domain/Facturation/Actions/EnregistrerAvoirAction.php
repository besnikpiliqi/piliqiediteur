<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Corrige un ticket ou une facture émis : crée l'avoir (voir
 * CreerAvoirAction) et l'émet (voir EmettreAvoirAction) dans une seule
 * transaction. Un échec pendant l'émission -- dans un écouteur d'AvoirEmis
 * (retour au stock, imputation sur les factures) ou à la numérotation --
 * ne laisse donc aucun brouillon d'avoir derrière lui : pour le magasin,
 * un avoir n'existe qu'une fois émis. C'est le seul chemin de l'interface
 * (voir CreateAvoir et l'action « Avoir » de FactureResource).
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture
 * de la transaction (voir RegistreFiscal), avant même les lignes de
 * facture que verrouille CreerAvoirAction : l'avoir impute son crédit sur
 * ce qui reste dû de ses factures, comme un paiement simultané, et le
 * relit sous ce verrou. Sans clé de signature, rien n'est créé. Ces lignes
 * restent verrouillées jusqu'à la fin de l'émission : un second retour sur
 * les mêmes lignes attend, puis est refusé dès sa création s'il dépasse ce
 * qui reste.
 */
class EnregistrerAvoirAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerAvoirAction $creerAvoir,
        private readonly EmettreAvoirAction $emettreAvoir,
    ) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: int|string}>  $lignesSelectionnees
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Client $client, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        return DB::transaction(function () use ($client, $lignesSelectionnees, $motif): Avoir {
            $magasin = app(TenantContext::class)->magasin();
            $this->registre->verrouiller($magasin);

            if ((int) $client->magasin_id !== $magasin->getKey()) {
                throw new LogicException('Ce client n\'est pas un client du magasin.');
            }

            return $this->emettreAvoir->handle($this->creerAvoir->handle($client, $lignesSelectionnees, $motif));
        });
    }
}
