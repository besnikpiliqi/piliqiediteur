<?php

namespace App\Domain\Demonstration;

use App\Domain\Tenancy\Enums\TypeDActivite;
use SensitiveParameter;

/**
 * La construction en cours d'une base modèle du site de démonstration, que
 * seule la commande demonstration:construire-les-modeles pose dans le
 * conteneur pendant qu'elle sème une base de travail (lot DEMO-COPIE,
 * A.9) : sur le site de démonstration, le semeur ne remplit qu'une base
 * demo_travail_… pour laquelle cette construction est en cours, jamais une
 * autre base, et php artisan db:seed lancé à la main y est refusé (voir
 * DatabaseSeeder). C'est un objet du processus, pas une variable
 * d'environnement de plus.
 */
final readonly class ConstructionDUnModele
{
    public const MOTIF_DE_LA_BASE_DE_TRAVAIL = '/^demo_travail_[a-z0-9_]+$/';

    public function __construct(
        public TypeDActivite $type,
        #[SensitiveParameter] public string $motDePasse,
    ) {}

    /**
     * La construction en cours dans ce processus, ou null.
     */
    public static function enCours(): ?self
    {
        $construction = app()->bound(self::class) ? app(self::class) : null;

        return $construction instanceof self ? $construction : null;
    }
}
