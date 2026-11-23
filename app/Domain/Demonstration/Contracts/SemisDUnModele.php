<?php

namespace App\Domain\Demonstration\Contracts;

use App\Domain\Tenancy\Enums\TypeDActivite;

/**
 * Ce que la construction d'un modèle sème dans sa base de travail (lot
 * DEMO-COPIE, C.2, étape 4, et partie F), par les vraies actions et les
 * fournisseurs factices, planificateur suspendu. La connexion par défaut
 * est déjà branchée sur la base de travail, migrée. Le mot de passe de
 * démonstration est celui de chaque compte du modèle (choix 29).
 */
interface SemisDUnModele
{
    public function semer(TypeDActivite $type, string $motDePasse): void;
}
