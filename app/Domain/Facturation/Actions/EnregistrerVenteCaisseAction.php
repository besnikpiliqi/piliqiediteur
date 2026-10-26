<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Exceptions\StockInsuffisantException;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Point d'entrée unique de la caisse (voir app/Filament/App/Pages/Caisse.php) :
 * une vente comptoir est toujours payée intégralement et immédiatement,
 * contrairement à une facture B2B classique qui peut rester impayée ou se
 * régler plus tard. Compose donc en une seule transaction la création puis
 * l'émission de la facture avec son règlement, d'un seul geste, en un ou
 * plusieurs modes (voir ReglementComptant et EmettreFactureAction) : le
 * ticket signé au registre fiscal porte chaque paiement, et le crédit
 * d'avoir qui l'a couvert.
 *
 * Le numéro d'un avoir fait au client comptoir vaut bon d'avoir (K6) :
 * présenté dans la part « Avoir » d'une vente, quel qu'en soit le client,
 * il impute son crédit restant sur ce que le crédit d'avoir du client et
 * les bons saisis avant lui laissent dû de la nouvelle facture, et les
 * parts payées règlent le reste. Un numéro qui ne désigne aucun avoir du
 * client comptoir du magasin (celui d'un client nommé, ou d'un autre
 * magasin) ou dont le crédit est épuisé est refusé, comme des parts qui
 * n'égalent pas ce qui reste à payer, sans rien enregistrer.
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture
 * de la transaction et la création du brouillon (voir RegistreFiscal) :
 * sans clé de signature, rien n'est créé. Les bons d'avoir et leur crédit
 * restant se relisent sous ce verrou, comme ce que le règlement laisse dû
 * (voir EmettreFactureAction) : deux caisses qui présentent le même bon au
 * même instant passent l'une après l'autre, et la seconde ne trouve plus
 * que ce que la première a laissé. Le panier de caisse du vendeur, sa
 * vente étant encaissée, se vide dans la même transaction, après avoir
 * inscrit au journal des événements ce qu'il perd jusqu'aux lignes
 * vendues et chaque prix vendu plus bas que son prix arrêté (voir
 * PanierDeCaisse::encaisser()).
 *
 * La ligne du magasin se relit sous un verrou partagé après le registre :
 * le magasin doit pouvoir vendre (voir Magasin::verifierQuIlPeutVendre()).
 * Son auteur, le vendeur, est relu ensuite sous le verrou de sa ligne
 * (voir LecteurDesDroits) : il doit pouvoir créer et émettre
 * une facture et enregistrer un paiement dans ce magasin. Les actions
 * qu'elle compose reçoivent ce même auteur, relu : c'est lui que la vente
 * enregistre et que le registre signe. Une ligne remisée, ou à un autre
 * prix que celui de sa fiche, exige qu'il soit autorisé à accorder des
 * remises et fixer les prix (R2, voir CreerFactureAction), et une vente
 * au-delà du stock qu'il soit autorisé à vendre sans stock et ait
 * confirmé l'avertissement (R1, voir EmettreFactureAction).
 *
 * La vente porte le jeton de son panier (R3, voir ActionsUniques et
 * PanierDeCaisse), lié à ses lignes, à ses parts et à ses bons d'avoir :
 * répétée après une réponse perdue, elle rend le ticket déjà enregistré,
 * sans second ticket ni second encaissement ; le même jeton avec d'autres
 * parts est refusé. L'émission et les paiements qu'elle compose n'en
 * consomment aucun.
 */
class EnregistrerVenteCaisseAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerFactureAction $creerFacture,
        private readonly EmettreFactureAction $emettreFacture,
        private readonly PanierDeCaisse $panierDeCaisse,
        private readonly LecteurDesDroits $lecteur,
        private readonly ActionsUniques $actionsUniques,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  ConfirmationVenteSansStock|null  $confirmation  l'avertissement d'une vente sans stock, confirmé (R1)
     * @param  string|null  $jeton  le jeton du panier (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws StockInsuffisantException
     * @throws ConfirmationVenteSansStockRequiseException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Client $client, array $lignes, ReglementComptant $reglement, ?ConfirmationVenteSansStock $confirmation = null, ?string $jeton = null): Facture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::VENDRE_A_LA_CAISSE, $auteur, [
            'client_id' => $client->getKey(),
            'lignes' => self::lignesCanoniques($lignes),
            'reglement' => $reglement->enTableau(),
        ], fn () => $this->registre->verrouiller($magasin), fn (): Facture => $this->vendre($auteur, $magasin, $client, $lignes, $reglement, $confirmation));
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function vendre(User $auteur, Magasin $magasin, Client $client, array $lignes, ReglementComptant $reglement, ?ConfirmationVenteSansStock $confirmation): Facture
    {
        return DB::transaction(function () use ($auteur, $magasin, $client, $lignes, $reglement, $confirmation) {
            $this->registre->verrouiller($magasin);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlPeutVendre();
            $vendeur = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::FACTURE_CREATE, $magasin);

            foreach ([PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::PAIEMENT_CREER] as $autorisation) {
                $this->lecteur->exiger($vendeur, $autorisation, $magasin);
            }

            if ((int) $client->magasin_id !== $magasin->getKey()) {
                throw new LogicException('Ce client n\'est pas un client du magasin.');
            }

            $facture = $this->emettreFacture->handle(
                $vendeur,
                $this->creerFacture->handle($vendeur, $client, $lignes),
                $reglement,
                $confirmation,
            );

            $this->panierDeCaisse->encaisser($vendeur, $lignes);

            return $facture->fresh('lignes');
        });
    }

    /**
     * Les lignes de la vente telles qu'elles la distinguent d'une autre :
     * l'article, la quantité, le prix, le taux et la remise saisis, en
     * texte, dans leur ordre.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<list<string|null>>
     */
    private static function lignesCanoniques(array $lignes): array
    {
        return array_map(
            fn (mixed $ligne): array => array_map(
                fn (string $champ): ?string => is_array($ligne) && is_scalar($ligne[$champ] ?? null) && (string) $ligne[$champ] !== '' ? trim((string) $ligne[$champ]) : null,
                ['produit_id', 'service_id', 'quantite', 'prix_unitaire_ttc', 'taux_tva', 'remise_pourcentage'],
            ),
            array_values($lignes),
        );
    }
}
