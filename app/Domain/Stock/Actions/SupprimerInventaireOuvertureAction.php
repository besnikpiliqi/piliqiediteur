<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supprime un inventaire d'ouverture brouillon, avec ses lignes, sans
 * aucun effet sur le catalogue ni sur le stock : un brouillon n'a rien
 * créé. Un brouillon resté après la première vente du magasin se supprime
 * encore ; un inventaire validé, jamais (voir
 * InventaireOuverturePolicy::delete()).
 *
 * Comme pour sa modification (voir ModifierInventaireOuvertureAction), la
 * ligne du magasin se relit sous un verrou partagé (un magasin suspendu,
 * fermé ou cédé, ou dont la société est en lecture seule, ne supprime plus
 * rien, voir Magasin::verifierQuIlEstOuvert()), puis son auteur sous le
 * verrou de sa ligne (voir LecteurDesDroits), avant l'inventaire, relu
 * sous son propre verrou. Une validation simultanée, qui tient ce verrou
 * jusqu'à sa fin (voir ValiderInventaireOuvertureAction), passe donc tout
 * entière avant la suppression, qui est alors refusée, ou après elle, et
 * ne trouve plus l'inventaire : un inventaire validé ne disparaît jamais
 * en laissant ses produits, son stock, son numéro et son entrée d'audit.
 * Un inventaire déjà supprimé par une autre requête est refusé. L'auteur
 * relu doit pouvoir supprimer l'inventaire relu ; la suppression se fait
 * à son nom (voir OperateurCourant).
 */
class SupprimerInventaireOuvertureAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, InventaireOuverture $inventaire): void
    {
        DB::transaction(function () use ($auteur, $inventaire): void {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'deleteAny', $magasin, InventaireOuverture::class);

            $inventaire = InventaireOuverture::query()->whereKey($inventaire->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(InventaireOuverture::REFUS_INTROUVABLE);

            if (! $inventaire->estBrouillon()) {
                throw new LogicException(InventaireOuverture::REFUS_VALIDE);
            }

            $this->lecteur->exiger($compte, 'delete', $magasin, $inventaire);

            $this->operateur->pendant($compte, fn (): ?bool => $inventaire->delete());
        });
    }
}
