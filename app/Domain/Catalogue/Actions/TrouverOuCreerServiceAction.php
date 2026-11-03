<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Le service tapé par le vendeur à la caisse quand il n'est pas encore
 * dans les cases à cocher : créé une fois, il y sera proposé la prochaine
 * fois. S'il existe déjà sous une autre écriture ("main d’œuvre" pour
 * "Main d'œuvre", voir Service::normaliserNom()), c'est lui qui est repris
 * -- jamais un doublon, et son prix de référence ne change pas. Un service
 * archivé que l'on tape de nouveau redevient actif : le magasin le réalise
 * à nouveau.
 *
 * Dans une transaction, comme toute création d'une fiche (voir
 * CreerUneFicheAction) : la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne crée ni ne réactive rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits) : il doit pouvoir créer un service ; pour
 * fixer en même temps le prix de référence d'un nouveau service, il doit
 * aussi être autorisé à accorder des remises et fixer le prix d'un
 * service (R2, voir DerogationsDePrix). Un service existant se relit
 * ensuite sous son propre verrou avant d'être réactivé. Le geste se fait
 * au nom de l'auteur relu (voir OperateurCourant).
 */
class TrouverOuCreerServiceAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  string|null  $prixTtc  le prix de référence TTC d'un nouveau service, fixé avec son taux
     * @param  string|null  $tauxTva  le taux de TVA de ce prix
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, string $nom, ?string $prixTtc = null, ?string $tauxTva = null): Service
    {
        $nom = Service::nettoyerNom($nom);

        if ($nom === '') {
            throw new InvalidArgumentException('Le nom du service est obligatoire.');
        }

        if (mb_strlen($nom) > Service::LONGUEUR_MAXIMALE_NOM) {
            throw new InvalidArgumentException('Le nom du service ne doit pas dépasser '.Service::LONGUEUR_MAXIMALE_NOM.' caractères.');
        }

        $prixDeReference = blank($prixTtc) ? [] : ['prix_ttc' => $prixTtc, 'taux_tva' => $tauxTva];

        if ($prixDeReference !== [] && (! is_numeric($prixTtc) || ! is_numeric($tauxTva))) {
            throw new InvalidArgumentException('Le prix de référence d\'un service et son taux de TVA se fixent ensemble, en nombres.');
        }

        return DB::transaction(function () use ($auteur, $nom, $prixDeReference): Service {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Service::class);

            if ($prixDeReference !== []) {
                $this->lecteur->exiger($compte, PermissionRegistry::VENTE_REMISES_ET_PRIX, $magasin);
            }

            return $this->operateur->pendant($compte, function () use ($nom, $prixDeReference): Service {
                // Deux caisses DU MÊME MAGASIN qui créent le même service au
                // même instant : l'unicité (magasin_id, nom_normalise)
                // tranche, et firstOrCreate() reprend celui de l'autre
                // caisse. La création se tente dans un point de sauvegarde,
                // que le refus de l'index annule seul : sous PostgreSQL, une
                // erreur SQL annulerait sinon toute la transaction. Les
                // services d'un autre magasin ne sont jamais vus ici
                // (MagasinScope).
                $service = Service::query()->firstOrCreate(['nom_normalise' => Service::normaliserNom($nom)], ['nom' => $nom, 'actif' => true, ...$prixDeReference]);

                if ($service->wasRecentlyCreated) {
                    return $service;
                }

                $relu = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();

                if (! $relu->actif) {
                    $relu->update(['actif' => true]);
                }

                return $relu;
            });
        });
    }
}
