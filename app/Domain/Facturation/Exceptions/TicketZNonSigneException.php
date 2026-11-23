<?php

namespace App\Domain\Facturation\Exceptions;

use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\Calendrier\Calendrier;
use LogicException;

/**
 * Un ticket Z du magasin que le registre fiscal ne connaît pas : daté
 * après la dernière journée qu'une clôture signée a close, ou sans
 * l'événement 50 qui le signe. Il n'a pas été produit par la clôture de sa
 * journée, mais écrit directement dans la base : la clôture du magasin
 * s'arrête (voir CloturerCaisseAction) jusqu'à l'examen de l'éditeur, qui
 * le retire sur pièces par sa procédure.
 */
final class TicketZNonSigneException extends LogicException
{
    /**
     * @param  string  $journee  AAAA-MM-JJ
     */
    public function __construct(public readonly int $clotureId, public readonly string $numero, public readonly string $journee)
    {
        parent::__construct(sprintf(
            'Le ticket Z %s du %s n\'a pas été produit par la clôture de sa journée : la clôture du magasin s\'arrête jusqu\'à l\'examen de l\'éditeur.',
            $numero,
            Calendrier::afficherJour($journee, 'd/m/Y'),
        ));
    }

    /**
     * L'anomalie que le journal des événements inscrit (JET 90).
     */
    public function anomalie(): AnomalieFiscale
    {
        return AnomalieFiscale::defaut('cloture', $this->getMessage(), ['cloture_id' => $this->clotureId, 'numero' => $this->numero]);
    }
}
