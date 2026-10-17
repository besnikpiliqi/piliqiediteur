<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Le service tapé par le vendeur à la caisse quand il n'est pas encore
 * dans les cases à cocher : créé une fois, il y sera proposé la prochaine
 * fois. S'il existe déjà sous une autre écriture ("main d’œuvre" pour
 * "Main d'œuvre", voir Service::normaliserNom()), c'est lui qui est repris
 * -- jamais un doublon, et son prix de référence ne change pas. Un service
 * archivé que l'on tape de nouveau redevient actif : le magasin le réalise
 * à nouveau. Son auteur, relu en base (voir LecteurDesDroits), doit
 * pouvoir créer un service ; pour fixer en même temps le prix de
 * référence d'un nouveau service, il doit aussi être autorisé à accorder
 * des remises et fixer le prix d'un service (R2, voir DerogationsDePrix).
 */
class TrouverOuCreerServiceAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * @param  string|null  $prixTtc  le prix de référence TTC d'un nouveau service, fixé avec son taux
     * @param  string|null  $tauxTva  le taux de TVA de ce prix
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
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

        $magasin = app(TenantContext::class)->magasin();
        $compte = $this->lecteur->exiger($auteur, 'create', $magasin, Service::class);
        $prixDeReference = blank($prixTtc) ? [] : ['prix_ttc' => $prixTtc, 'taux_tva' => $tauxTva];

        if ($prixDeReference !== []) {
            $this->lecteur->exiger($compte, PermissionRegistry::VENTE_REMISES_ET_PRIX, $magasin);

            if (! is_numeric($prixTtc) || ! is_numeric($tauxTva)) {
                throw new InvalidArgumentException('Le prix de référence d\'un service et son taux de TVA se fixent ensemble, en nombres.');
            }
        }

        // Deux caisses DU MÊME MAGASIN qui créent le même service au même
        // instant : l'unicité (magasin_id, nom_normalise) tranche, et
        // firstOrCreate() reprend celui de l'autre caisse. La création se
        // tente dans un point de sauvegarde, que le refus de l'index
        // annule seul : sous PostgreSQL, une erreur SQL annulerait sinon
        // toute la transaction de l'appelant. Les services d'un autre
        // magasin ne sont jamais vus ici (MagasinScope).
        $service = Service::query()->firstOrCreate(['nom_normalise' => Service::normaliserNom($nom)], ['nom' => $nom, ...$prixDeReference]);

        if (! $service->actif) {
            $service->update(['actif' => true]);
        }

        return $service;
    }
}
