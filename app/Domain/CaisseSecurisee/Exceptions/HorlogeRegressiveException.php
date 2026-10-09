<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * L'horloge du serveur donne une heure antérieure au dernier
 * enregistrement du registre fiscal du magasin : rien n'est enregistré,
 * car l'horodatage d'un registre fiscal ne recule jamais. L'opération
 * reste refusée tant que l'horloge (synchronisée par NTP) n'est pas
 * corrigée ; RegistreFiscal signale chaque refus dans le journal de
 * l'application (Log::critical).
 */
class HorlogeRegressiveException extends RuntimeException
{
    public static function pour(int $magasinId, CarbonInterface $instant, CarbonInterface $dernierHorodatage): self
    {
        return new self(sprintf(
            "L'horloge du serveur a reculé : il est %s UTC, mais le registre fiscal du magasin n°%d a déjà un enregistrement daté de %s UTC. Aucune opération fiscale n'est enregistrée tant que l'horloge n'est pas corrigée.",
            $instant->format('d/m/Y H:i:s'),
            $magasinId,
            $dernierHorodatage->format('d/m/Y H:i:s'),
        ));
    }
}
