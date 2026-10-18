<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Retourne au fournisseur des articles d'une facture d'achat validée :
 * crée l'avoir fournisseur (voir CreerAvoirFournisseurAction) et l'émet
 * (voir EmettreAvoirFournisseurAction) dans une seule transaction, comme
 * EnregistrerAvoirAction côté vente. Un refus à l'émission ne laisse donc
 * aucun brouillon derrière lui. C'est le seul chemin de l'interface, depuis
 * le menu « Avoirs fournisseur », la liste des factures d'achat ou la fiche
 * de l'une d'elles (voir FactureAchatResource::creerUnAvoirFournisseur()).
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous les
 * premiers verrous de l'émission : la ligne du magasin, sous un verrou
 * partagé, puis celle de son auteur. Répétée après une réponse perdue,
 * elle rend l'avoir fournisseur déjà émis, sans second avoir ni seconde
 * sortie de stock. La création et l'émission qu'elle compose n'en
 * consomment aucun.
 */
class EnregistrerAvoirFournisseurAction
{
    public function __construct(
        private readonly CreerAvoirFournisseurAction $creerAvoirFournisseur,
        private readonly EmettreAvoirFournisseurAction $emettreAvoirFournisseur,
        private readonly ActionsUniques $actionsUniques,
    ) {}

    /**
     * @param  list<array{ligne_achat_id: int|string, quantite: int|string}>  $lignesSelectionnees
     * @param  string|null  $numeroFournisseur  le n° de l'avoir chez le fournisseur, facultatif
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, FactureAchat $factureAchat, array $lignesSelectionnees, ?string $motif = null, ?string $numeroFournisseur = null, ?string $jeton = null): AvoirFournisseur
    {
        $magasin = app(TenantContext::class)->magasin();
        $verrou = function () use ($magasin, $auteur): void {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            ActionsUniques::verrouDe($auteur)();
        };
        $parametres = ['facture_achat_id' => $factureAchat->getKey(), 'lignes' => array_values($lignesSelectionnees), 'motif' => $motif, 'numero_fournisseur' => $numeroFournisseur];

        return $this->actionsUniques->executer($jeton, ActionsUniques::ENREGISTRER_AVOIR_FOURNISSEUR, $auteur, $parametres, $verrou, fn (): AvoirFournisseur => DB::transaction(
            fn (): AvoirFournisseur => $this->emettreAvoirFournisseur->handle($auteur, $this->creerAvoirFournisseur->handle($auteur, $factureAchat, $lignesSelectionnees, $motif, $numeroFournisseur)),
        ));
    }
}
