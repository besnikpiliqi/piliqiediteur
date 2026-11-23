<?php

namespace App\Filament\Cabinet;

use Filament\Resources\Resource;

/**
 * La base de toute ressource de l'espace d'un cabinet d'expertise
 * comptable, en mode d'autorisation strict, comme celles de l'espace
 * d'administration d'une société (voir RessourceDuPanneauSociete) : la
 * question passe toujours à la Gate, qui refuse sans policy.
 */
abstract class RessourceDuPanneauCabinet extends Resource
{
    protected static bool $shouldCheckPolicyExistence = false;
}
