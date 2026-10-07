<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Le service tapé par le vendeur à la caisse quand il n'est pas encore
 * dans les cases à cocher : créé une fois, il y sera proposé la prochaine
 * fois. S'il existe déjà sous une autre écriture ("main d’œuvre" pour
 * "Main d'œuvre", voir Service::normaliserNom()), c'est lui qui est repris
 * -- jamais un doublon. Un service archivé que l'on tape de nouveau
 * redevient actif : le magasin le réalise à nouveau.
 */
class TrouverOuCreerServiceAction
{
    /**
     * @throws InvalidArgumentException
     */
    public function handle(string $nom): Service
    {
        $nom = Service::nettoyerNom($nom);

        if ($nom === '') {
            throw new InvalidArgumentException('Le nom du service est obligatoire.');
        }

        if (mb_strlen($nom) > Service::LONGUEUR_MAXIMALE_NOM) {
            throw new InvalidArgumentException('Le nom du service ne doit pas dépasser '.Service::LONGUEUR_MAXIMALE_NOM.' caractères.');
        }

        $cle = ['nom_normalise' => Service::normaliserNom($nom)];

        try {
            $service = Service::query()->where($cle)->first() ?? Service::create(['nom' => $nom]);
        } catch (UniqueConstraintViolationException) {
            // Deux caisses DU MÊME MAGASIN viennent de créer le même service
            // au même instant : l'unicité (magasin_id, nom_normalise) a
            // tranché, on reprend celui de l'autre caisse. Les services d'un
            // autre magasin ne sont jamais vus ici (MagasinScope).
            $service = Service::query()->where($cle)->firstOrFail();
        }

        if (! $service->actif) {
            $service->update(['actif' => true]);
        }

        return $service;
    }
}
