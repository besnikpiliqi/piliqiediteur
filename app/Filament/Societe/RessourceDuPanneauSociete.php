<?php

namespace App\Filament\Societe;

use Filament\Resources\Resource;

/**
 * La base de toute ressource du panneau de l'administration d'une
 * société, en mode d'autorisation strict. Par défaut, Filament autorise un geste
 * dont la policy ou la méthode manque (voir Filament\authorize()) ; ici,
 * la question passe toujours à la Gate, qui refuse sans policy. La
 * propriété est redéclarée sur cette classe, et non changée par
 * checkPolicyExistence(), qui la changerait pour toutes les ressources
 * des deux panneaux.
 */
abstract class RessourceDuPanneauSociete extends Resource
{
    protected static bool $shouldCheckPolicyExistence = false;
}
