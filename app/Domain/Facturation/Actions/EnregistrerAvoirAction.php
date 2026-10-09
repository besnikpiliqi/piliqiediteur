<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
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
 * Les lignes de facture verrouillées par CreerAvoirAction le restent
 * jusqu'à la fin de l'émission : un second retour sur les mêmes lignes
 * attend, puis est refusé dès sa création s'il dépasse ce qui reste.
 */
class EnregistrerAvoirAction
{
    public function __construct(
        private readonly CreerAvoirAction $creerAvoir,
        private readonly EmettreAvoirAction $emettreAvoir,
    ) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: int|string}>  $lignesSelectionnees
     *
     * @throws LogicException
     */
    public function handle(Client $client, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        return DB::transaction(fn (): Avoir => $this->emettreAvoir->handle(
            $this->creerAvoir->handle($client, $lignesSelectionnees, $motif),
        ));
    }
}
