<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Authorization\LecteurDesDroits;
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
 * -- jamais un doublon. Un service archivé que l'on tape de nouveau
 * redevient actif : le magasin le réalise à nouveau. Son auteur, relu en
 * base (voir LecteurDesDroits), doit pouvoir créer un service.
 */
class TrouverOuCreerServiceAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $auteur, string $nom): Service
    {
        $nom = Service::nettoyerNom($nom);

        if ($nom === '') {
            throw new InvalidArgumentException('Le nom du service est obligatoire.');
        }

        if (mb_strlen($nom) > Service::LONGUEUR_MAXIMALE_NOM) {
            throw new InvalidArgumentException('Le nom du service ne doit pas dépasser '.Service::LONGUEUR_MAXIMALE_NOM.' caractères.');
        }

        $this->lecteur->exiger($auteur, 'create', app(TenantContext::class)->magasin(), Service::class);

        // Deux caisses DU MÊME MAGASIN qui créent le même service au même
        // instant : l'unicité (magasin_id, nom_normalise) tranche, et
        // firstOrCreate() reprend celui de l'autre caisse. La création se
        // tente dans un point de sauvegarde, que le refus de l'index
        // annule seul : sous PostgreSQL, une erreur SQL annulerait sinon
        // toute la transaction de l'appelant. Les services d'un autre
        // magasin ne sont jamais vus ici (MagasinScope).
        $service = Service::query()->firstOrCreate(['nom_normalise' => Service::normaliserNom($nom)], ['nom' => $nom]);

        if (! $service->actif) {
            $service->update(['actif' => true]);
        }

        return $service;
    }
}
