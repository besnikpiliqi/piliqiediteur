<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;

/**
 * Une visite d'une copie, retrouvée par son témoin (voir
 * TemoinsDesVisites::retrouver()).
 */
final readonly class Visite
{
    public function __construct(
        public Temoin $temoin,
        public Copie $copie,
        public bool $estTerminee,
    ) {}
}
