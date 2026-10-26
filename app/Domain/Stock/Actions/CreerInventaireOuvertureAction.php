<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Services\ConstructeurLigneInventaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre un inventaire d'ouverture en brouillon, sans aucun effet sur
 * le catalogue ni sur le stock : ses lignes gardent les produits jamais
 * reçus et les fiches des nouveaux produits (voir
 * ConstructeurLigneInventaire), qui ne naissent qu'à sa validation (voir
 * ValiderInventaireOuvertureAction).
 *
 * Un magasin qui a déjà vendu n'en enregistre plus (voir
 * InventaireOuverture::verifierQuIlSeSaisitEncore()) : le registre fiscal
 * du magasin, que toute vente verrouille en premier, est le premier
 * verrou de la transaction, et la première vente passe tout entière avant
 * la saisie, qui est alors refusée, ou après elle. Un magasin suspendu,
 * fermé ou cédé, ou dont la société est en lecture seule, n'en enregistre
 * plus non plus (voir Magasin::verifierQuIlEstOuvert()) : sa ligne se
 * relit ensuite sous un verrou partagé, avant l'auteur, comme pour une
 * facture d'achat (voir CreerFactureAchatAction). Son auteur, relu sous le
 * verrou de sa ligne (voir LecteurDesDroits), doit pouvoir valider les
 * achats et créer des produits (voir InventaireOuverturePolicy::create()) ;
 * l'inventaire l'enregistre comme auteur, avec son nom figé.
 */
class CreerInventaireOuvertureAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
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
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, array $lignes, array $attributs = []): InventaireOuverture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->registre->sousVerrou($magasin, function () use ($auteur, $lignes, $attributs, $magasin): InventaireOuverture {
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $magasinRelu->verifierQuIlEstOuvert();
            InventaireOuverture::verifierQuIlSeSaisitEncore($magasinRelu);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, InventaireOuverture::class);

            return $this->operateur->pendant($compte, function () use ($compte, $lignes, $attributs): InventaireOuverture {
                $inventaire = (new InventaireOuverture(Arr::only($attributs, ['notes'])))->forceFill(['cree_par' => $compte->getKey()]);
                $inventaire->save();

                $this->constructeurLignes->enregistrer($inventaire, $lignes);

                return $inventaire->fresh('lignes');
            });
        });
    }
}
