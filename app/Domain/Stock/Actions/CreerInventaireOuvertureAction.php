<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Services\ConstructeurLigneInventaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre un inventaire d'ouverture en brouillon, sans aucun effet sur
 * le catalogue ni sur le stock : ses lignes gardent les produits jamais
 * reçus et les fiches des nouveaux produits (voir
 * ConstructeurLigneInventaire), qui ne naissent qu'à sa validation (voir
 * ValiderInventaireOuvertureAction).
 *
 * Un magasin suspendu ou fermé n'en enregistre plus (voir
 * Magasin::verifierQuIlEstOuvert()), ni un magasin qui a déjà vendu (voir
 * InventaireOuverture::verifierQuIlSeSaisitEncore()) : sa ligne se relit
 * en base sous un verrou partagé, avant l'auteur, comme pour une facture
 * d'achat (voir CreerFactureAchatAction). Son auteur, relu en base (voir
 * LecteurDesDroits), doit pouvoir valider les achats et créer des
 * produits (voir InventaireOuverturePolicy::create()) ; l'inventaire
 * l'enregistre comme auteur, avec son nom figé.
 */
class CreerInventaireOuvertureAction
{
    public function __construct(
        private readonly ConstructeurLigneInventaire $constructeurLignes,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes  voir ConstructeurLigneInventaire::enregistrer()
     * @param  array{notes?: string|null}  $attributs
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, array $lignes, array $attributs = []): InventaireOuverture
    {
        return DB::transaction(function () use ($auteur, $lignes, $attributs): InventaireOuverture {
            $magasin = app(TenantContext::class)->magasin();
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $magasinRelu->verifierQuIlEstOuvert();
            InventaireOuverture::verifierQuIlSeSaisitEncore($magasinRelu);
            $compte = $this->lecteur->exiger($auteur, 'create', $magasin, InventaireOuverture::class);

            return $this->operateur->pendant($compte, function () use ($compte, $lignes, $attributs): InventaireOuverture {
                $inventaire = (new InventaireOuverture(Arr::only($attributs, ['notes'])))->forceFill(['cree_par' => $compte->getKey()]);
                $inventaire->save();

                $this->constructeurLignes->enregistrer($inventaire, $lignes);

                return $inventaire->fresh('lignes');
            });
        });
    }
}
