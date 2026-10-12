<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une facture d'achat en brouillon, sans aucun impact sur le
 * stock (voir ValiderFactureAchatAction). Ses lignes, et les produits
 * existants qu'elles corrigent, sont enregistrés par
 * ConstructeurLigneAchat ; ses nouveaux produits ne naissent qu'à sa
 * validation. Quand une ligne change le taux de TVA d'un
 * produit, le registre fiscal du magasin est le premier verrou de la
 * transaction (voir ConstructeurLigneAchat::verrouillerLeRegistreSiUnTauxChange()).
 *
 * Son auteur, relu en base après ce verrou (voir LecteurDesDroits), doit
 * pouvoir enregistrer une facture d'achat, et, pour chaque ligne qui crée
 * ou corrige un produit, le faire au catalogue ; la facture l'enregistre
 * comme auteur, avec son nom figé.
 */
class CreerFactureAchatAction
{
    public function __construct(
        private readonly ConstructeurLigneAchat $constructeurLignes,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     */
    public function handle(User $auteur, Fournisseur $fournisseur, array $lignes, array $attributs = []): FactureAchat
    {
        return DB::transaction(function () use ($auteur, $fournisseur, $lignes, $attributs) {
            $this->constructeurLignes->verrouillerLeRegistreSiUnTauxChange($lignes);
            $compte = $this->lecteur->exiger($auteur, 'create', app(TenantContext::class)->magasin(), FactureAchat::class);

            return $this->operateur->pendant($compte, function () use ($compte, $fournisseur, $lignes, $attributs): FactureAchat {
                $factureAchat = (new FactureAchat([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]))->forceFill(['cree_par' => $compte->getKey()]);
                $factureAchat->save();

                $this->constructeurLignes->enregistrer($compte, $factureAchat, $lignes);

                return $factureAchat->fresh('lignes');
            });
        });
    }
}
