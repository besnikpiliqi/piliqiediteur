<?php

namespace App\Domain\Tenancy\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Le compte connecté, lu là où seul un compte connecté arrive : les pages,
 * les actions et les tableaux des panneaux de Filament, derrière leur
 * contrôle de l'authentification, et les routes de l'espace qui l'exigent.
 * Sans compte connecté, c'est une erreur du code, refusée par une
 * exception, et non un visiteur à qui répondre ; jamais le refus d'un
 * geste (une LogicException, que les écrans montrent à la personne, voir
 * GereLesExceptionsMetier) : comme l'erreur de PHP qu'elle remplace, elle
 * remonte jusqu'au journal de l'application.
 */
final class CompteConnecte
{
    public const REFUS = 'Aucun compte n\'est connecté : cette page ou cette action exige une connexion.';

    /**
     * @throws RuntimeException aucun compte connecté
     */
    public static function exiger(): User
    {
        $compte = Auth::user();

        return $compte instanceof User ? $compte : throw new RuntimeException(self::REFUS);
    }
}
