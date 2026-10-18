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
 * Un inventaire d'ouverture brouillon reste librement modifiable, lignes
 * comprises (voir ConstructeurLigneInventaire), jusqu'à la première vente
 * du magasin ; validé, il ne se modifie plus jamais.
 *
 * Comme à la création (voir CreerInventaireOuvertureAction), la ligne du
 * magasin se relit sous un verrou partagé et son auteur en base, avant
 * l'inventaire, relu sous son propre verrou : une validation simultanée
 * passe tout entière avant ou après la modification, qui est alors
 * refusée. L'auteur doit pouvoir modifier cet inventaire relu (voir
 * InventaireOuverturePolicy::update()).
 */
class ModifierInventaireOuvertureAction
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
    public function handle(User $auteur, InventaireOuverture $inventaire, array $lignes, array $attributs = []): InventaireOuverture
    {
        return DB::transaction(function () use ($auteur, $inventaire, $lignes, $attributs): InventaireOuverture {
            $magasin = app(TenantContext::class)->magasin();
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $magasinRelu->verifierQuIlEstOuvert();
            InventaireOuverture::verifierQuIlSeSaisitEncore($magasinRelu);
            $compte = $this->lecteur->exiger($auteur, 'create', $magasin, InventaireOuverture::class);

            $inventaire = InventaireOuverture::query()->whereKey($inventaire->getKey())->lockForUpdate()->firstOrFail();

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
