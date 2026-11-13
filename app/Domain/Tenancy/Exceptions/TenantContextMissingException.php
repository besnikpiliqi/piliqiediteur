<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée quand du code tente d'accéder à un modèle scopé par magasin sans
 * qu'un contexte tenant ait été résolu au préalable.
 *
 * C'est un choix délibéré de fail-closed : un job de queue mal configuré,
 * une commande Artisan oubliée, un webhook mal routé ne doivent JAMAIS se
 * traduire par une fuite de données entre magasins. Il vaut mieux planter
 * bruyamment en développement/tests que de renvoyer silencieusement les
 * données de tous les magasins en production.
 */
class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            "Tentative d'accès au modèle [%s] sans contexte magasin résolu. ".
            'Si cet accès est volontairement hors du magasin courant (une lecture que CLAUDE.md permet, une procédure de l\'éditeur, une tâche planifiée), '.
            'passez explicitement par withoutGlobalScope(MagasinScope::class) et filtrez la requête par magasin.',
            $modelClass,
        ));
    }
}
