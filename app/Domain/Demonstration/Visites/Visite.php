<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;

/**
 * Une visite d'une copie, retrouvée par son témoin (voir
 * TemoinsDesVisites::retrouver()). Celle de la requête en cours, une fois
 * sa copie ouverte par OuvrirLaCopie, se lit par enCours().
 */
final readonly class Visite
{
    public function __construct(
        public Temoin $temoin,
        public Copie $copie,
        public bool $estTerminee,
    ) {}

    /**
     * La visite de la requête en cours, dont la copie est ouverte, ou null
     * hors d'une copie.
     */
    public static function enCours(): ?self
    {
        $visite = app()->bound(self::class) ? app(self::class) : null;

        return $visite instanceof self ? $visite : null;
    }
}
