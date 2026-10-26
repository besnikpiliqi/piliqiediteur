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
 * Un inventaire d'ouverture brouillon reste librement modifiable, lignes
 * comprises (voir ConstructeurLigneInventaire), jusqu'à la première vente
 * du magasin ; validé, il ne se modifie plus jamais.
 *
 * Comme à la création (voir CreerInventaireOuvertureAction), le registre
 * fiscal du magasin, que toute vente verrouille en premier, est le premier
 * verrou de la transaction : une première vente passe tout entière avant
 * la modification, qui est alors refusée, ou après elle. Suivent la ligne
 * du magasin, sous un verrou partagé, son auteur, sous le verrou de sa
 * ligne, puis l'inventaire, sous son propre verrou : une validation
 * simultanée passe tout entière avant ou après la modification, qui est
 * alors refusée, comme celle d'un inventaire supprimé entre-temps.
 * L'auteur relu doit pouvoir modifier l'inventaire relu (voir
 * InventaireOuverturePolicy::update()) ; la modification se fait à son
 * nom (voir OperateurCourant).
 */
class ModifierInventaireOuvertureAction
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
    public function handle(User $auteur, InventaireOuverture $inventaire, array $lignes, array $attributs = []): InventaireOuverture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->registre->sousVerrou($magasin, function () use ($auteur, $inventaire, $lignes, $attributs, $magasin): InventaireOuverture {
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $magasinRelu->verifierQuIlEstOuvert();
            InventaireOuverture::verifierQuIlSeSaisitEncore($magasinRelu);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, InventaireOuverture::class);

            $inventaire = InventaireOuverture::query()->whereKey($inventaire->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(InventaireOuverture::REFUS_INTROUVABLE);

            if (! $inventaire->estBrouillon()) {
                throw new LogicException(InventaireOuverture::REFUS_VALIDE);
            }

            $this->lecteur->exiger($compte, 'update', $magasin, $inventaire);

            return $this->operateur->pendant($compte, function () use ($inventaire, $lignes, $attributs): InventaireOuverture {
                $inventaire->update(Arr::only($attributs, ['notes']));

                $this->constructeurLignes->enregistrer($inventaire, $lignes);

                return $inventaire->fresh('lignes');
            });
        });
    }
}
