<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
 *
 * Son auteur est relu sous le verrou de sa ligne, après celui du registre
 * (voir LecteurDesDroits) : il doit pouvoir créer un avoir ; la création
 * et l'émission reçoivent ce même auteur relu.
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous le
 * verrou du registre : répétée après une réponse perdue, elle rend l'avoir
 * déjà émis, sans second avoir ni second retour en stock. L'émission
 * qu'elle compose n'en consomme aucun.
 */
class EnregistrerAvoirAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerAvoirAction $creerAvoir,
        private readonly EmettreAvoirAction $emettreAvoir,
        private readonly LecteurDesDroits $lecteur,
        private readonly ActionsUniques $actionsUniques,
    ) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: int|string}>  $lignesSelectionnees
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Client $client, array $lignesSelectionnees, ?string $motif = null, ?string $jeton = null): Avoir
    {
        $magasin = app(TenantContext::class)->magasin();
        $parametres = [
            'client_id' => $client->getKey(),
            'lignes' => array_values($lignesSelectionnees),
            'motif' => $motif,
        ];

        return $this->actionsUniques->executer($jeton, ActionsUniques::ENREGISTRER_AVOIR, $auteur, $parametres, fn () => $this->registre->verrouiller($magasin), fn (): Avoir => DB::transaction(function () use ($auteur, $client, $lignesSelectionnees, $motif, $magasin): Avoir {
            $this->registre->verrouiller($magasin);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Avoir::class);

            if ((int) $client->magasin_id !== $magasin->getKey()) {
                throw new LogicException('Ce client n\'est pas un client du magasin.');
            }

            return $this->emettreAvoir->handle($compte, $this->creerAvoir->handle($compte, $client, $lignesSelectionnees, $motif));
        }));
    }
}
