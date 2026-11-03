<?php

namespace App\Integrations\Ordre\Contracts;

use App\Integrations\Ordre\AnnuaireDeLOrdreIndisponibleException;
use App\Integrations\Ordre\InscriptionALOrdre;
use LogicException;

/**
 * L'annuaire du tableau de l'Ordre des experts-comptables, qui vérifie
 * l'inscription d'un cabinet d'expertise comptable (voir
 * VerifierLOrdreAction). Aucun accès automatique officiel et documenté
 * n'existe à ce jour : le pilote de production est « editeur » (voir
 * AnnuaireTenuParLEditeur), qui n'en lit rien et fait vérifier chaque
 * cabinet par l'éditeur, dans sa procédure. Une implémentation factice
 * (voir FauxAnnuaireDeLOrdre) sert au développement et aux tests, jamais en
 * production (voir VerificationServiceProvider). Aucune clé n'est dans le
 * dépôt.
 */
interface AnnuaireDeLOrdre
{
    /**
     * L'annuaire offre-t-il un accès automatique ? Faux pour le pilote
     * « editeur ».
     */
    public function estAutomatique(): bool;

    /**
     * L'inscription au tableau de l'Ordre de la société de ce SIREN, null
     * si l'annuaire ne la connaît pas.
     *
     * @throws AnnuaireDeLOrdreIndisponibleException
     * @throws LogicException pour un annuaire qui n'est pas automatique
     */
    public function inscription(string $siren): ?InscriptionALOrdre;
}
