<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Archive ou restaure une fiche du magasin : un client, un fournisseur ou
 * un produit, qui ne se supprime jamais (voir les SoftDeletes de chacun).
 * Archivée, elle n'est plus proposée pour un nouveau document, reste
 * lisible sur les anciens et se restaure.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, n'archive ni ne restaure plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis la fiche sous son propre verrou : une
 * fermeture du magasin, qui verrouille cette ligne (voir
 * FermerMagasinAction), passe tout entière avant ou après le geste. Une
 * fiche déjà archivée ne s'archive pas une seconde fois, une fiche active
 * n'a rien à restaurer : l'autre requête qui l'a fait entre-temps est
 * vue. L'auteur relu doit pouvoir archiver ou restaurer la fiche relue
 * (voir ClientPolicy, FournisseurPolicy et ProduitPolicy) ; le geste se
 * fait à son nom (voir OperateurCourant). Le client comptoir ne s'archive
 * jamais (voir Client::booted()).
 */
class ArchiverUneFicheAction
{
    public const REFUS_DEJA_ARCHIVEE = 'Cette fiche est déjà archivée.';

    public const REFUS_PAS_ARCHIVEE = 'Cette fiche n\'est pas archivée : elle n\'a rien à restaurer.';

    public const REFUS_INTROUVABLE = 'Cette fiche n\'existe pas dans ce magasin.';

    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function archiver(User $auteur, Client|Fournisseur|Produit $fiche): void
    {
        $this->sousVerrou($auteur, $fiche, 'delete', function (Client|Fournisseur|Produit $relue): void {
            if ($relue->trashed()) {
                throw new LogicException(self::REFUS_DEJA_ARCHIVEE);
            }
        }, fn (Client|Fournisseur|Produit $relue): ?bool => $relue->delete());
    }

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function restaurer(User $auteur, Client|Fournisseur|Produit $fiche): void
    {
        $this->sousVerrou($auteur, $fiche, 'restore', function (Client|Fournisseur|Produit $relue): void {
            if (! $relue->trashed()) {
                throw new LogicException(self::REFUS_PAS_ARCHIVEE);
            }
        }, fn (Client|Fournisseur|Produit $relue): bool => $relue->restore());
    }

    /**
     * Le magasin, puis l'auteur, puis la fiche, relus sous verrou ; la
     * fiche relue doit être dans l'état que le geste attend, et l'auteur
     * relu pouvoir faire ce geste sur elle.
     *
     * @param  Closure(Client|Fournisseur|Produit): void  $verifierLEtat
     * @param  Closure(Client|Fournisseur|Produit): mixed  $geste
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function sousVerrou(User $auteur, Client|Fournisseur|Produit $fiche, string $question, Closure $verifierLEtat, Closure $geste): void
    {
        DB::transaction(function () use ($auteur, $fiche, $question, $verifierLEtat, $geste): void {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'deleteAny', $magasin, $fiche::class);

            $relue = $fiche::withTrashed()->whereKey($fiche->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(self::REFUS_INTROUVABLE);
            $verifierLEtat($relue);
            $this->lecteur->exiger($compte, $question, $magasin, $relue);

            $this->operateur->pendant($compte, fn (): mixed => $geste($relue));
        });
    }
}
