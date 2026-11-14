<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supprime un devis brouillon jamais numéroté (voir Devis::estSupprimable()) :
 * un devis numéroté reste, pour qu'aucun trou n'apparaisse dans sa série.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne supprime plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis le devis sous le sien : une
 * fermeture du magasin, qui verrouille cette ligne (voir
 * FermerMagasinAction), passe tout entière avant ou après la suppression,
 * et un envoi qui numérote le devis (voir EmettreDevisAction), ou une
 * autre suppression, passe avant elle, qui est alors refusée, ou après
 * elle. L'auteur relu doit pouvoir supprimer le devis relu (voir
 * DevisPolicy::delete()) ; la suppression se fait à son nom (voir
 * OperateurCourant).
 */
class SupprimerDevisAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): void
    {
        DB::transaction(function () use ($auteur, $devis): void {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'deleteAny', $magasin, Devis::class);

            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);

            if (! $devis->estSupprimable()) {
                throw new LogicException(Devis::REFUS_SUPPRESSION);
            }

            $this->lecteur->exiger($compte, 'delete', $magasin, $devis);

            $this->operateur->pendant($compte, fn (): ?bool => $devis->delete());
        });
    }
}
