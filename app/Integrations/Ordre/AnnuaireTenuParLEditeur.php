<?php

namespace App\Integrations\Ordre;

use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use LogicException;

/**
 * Le pilote de production tant qu'aucun accès automatique officiel à
 * l'annuaire de l'Ordre des experts-comptables n'existe : il ne lit rien,
 * et chaque cabinet est vérifié une fois par l'éditeur, dans sa procédure
 * (voir VerifierLOrdreAction et ConstaterLInscriptionALOrdreAction).
 */
class AnnuaireTenuParLEditeur implements AnnuaireDeLOrdre
{
    public function estAutomatique(): bool
    {
        return false;
    }

    /**
     * @throws LogicException
     */
    public function inscription(string $siren): ?InscriptionALOrdre
    {
        throw new LogicException('L\'annuaire de l\'Ordre n\'offre pas d\'accès automatique : l\'éditeur vérifie l\'inscription dans sa procédure.');
    }
}
