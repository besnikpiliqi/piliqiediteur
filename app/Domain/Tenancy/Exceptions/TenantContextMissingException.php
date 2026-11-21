<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée quand du code lit un modèle d'un magasin (BelongsToMagasin,
 * MagasinScope) sans magasin actif, ou un modèle propre à une société
 * (BelongsToSociete, SocieteScope) sans société active, dans le
 * TenantContext.
 *
 * C'est un choix délibéré de fail-closed : un job de queue mal configuré,
 * une commande Artisan oubliée, un webhook mal routé ne doivent JAMAIS se
 * traduire par une fuite de données entre magasins ou entre sociétés. Il
 * vaut mieux planter bruyamment en développement et en test que de
 * renvoyer silencieusement les données de tous les magasins ou de toutes
 * les sociétés en production. Le message nomme le scope à retirer et le
 * filtre à poser quand l'accès est volontairement hors du contexte.
 */
class TenantContextMissingException extends RuntimeException
{
    public static function sansMagasin(string $modelClass): self
    {
        return new self(sprintf(
            "Tentative d'accès au modèle [%s] sans magasin actif. ".
            'Si cet accès est volontairement hors du magasin courant (une lecture que CLAUDE.md permet, une procédure de l\'éditeur, une tâche planifiée), '.
            'passez explicitement par withoutGlobalScope(MagasinScope::class) et filtrez la requête par magasin.',
            $modelClass,
        ));
    }

    public static function sansSociete(string $modelClass): self
    {
        return new self(sprintf(
            "Tentative d'accès au modèle [%s] sans société active. ".
            'Si cet accès est volontairement hors de la société courante (une lecture que CLAUDE.md permet, une procédure de l\'éditeur, une tâche planifiée), '.
            'passez explicitement par withoutGlobalScope(SocieteScope::class) et filtrez la requête par société.',
            $modelClass,
        ));
    }
}
