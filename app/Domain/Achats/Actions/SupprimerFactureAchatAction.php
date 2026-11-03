<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supprime une facture d'achat brouillon, avec ses lignes, sans aucun
 * effet sur le catalogue ni sur le stock : un brouillon n'a rien reçu ni
 * rien créé. Une facture d'achat validée ne se supprime jamais (voir
 * FactureAchatPolicy::delete()) : elle se corrige, ou se retourne par un
 * avoir fournisseur.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne supprime plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis la facture d'achat sous son propre
 * verrou, dans l'ordre de sa validation (voir ValiderFactureAchatAction).
 * Une validation simultanée passe donc tout entière avant la suppression,
 * qui est alors refusée, ou après elle, et ne trouve plus la facture
 * d'achat : une facture validée ne disparaît jamais en laissant sa
 * réception en stock, ses nouveaux produits et un trou dans ses numéros.
 * L'auteur relu doit pouvoir supprimer la facture d'achat relue ; la
 * suppression se fait à son nom (voir OperateurCourant).
 */
class SupprimerFactureAchatAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, FactureAchat $factureAchat): void
    {
        DB::transaction(function () use ($auteur, $factureAchat): void {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'deleteAny', $magasin, FactureAchat::class);

            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(FactureAchat::REFUS_INTROUVABLE);

            if ($factureAchat->statut !== StatutFactureAchat::Brouillon) {
                throw new LogicException(FactureAchat::REFUS_VALIDEE);
            }

            $this->lecteur->exiger($compte, 'delete', $magasin, $factureAchat);

            $this->operateur->pendant($compte, fn (): ?bool => $factureAchat->delete());
        });
    }
}
