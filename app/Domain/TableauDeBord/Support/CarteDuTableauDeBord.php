<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;

/**
 * Une carte de la grille du tableau de bord : son graphique, le type
 * choisi parmi ceux qu'il permet, et, pour les meilleurs produits, ce qui
 * les classe (le chiffre d'affaires HT ou la quantité).
 */
final class CarteDuTableauDeBord
{
    public const PAR_CHIFFRE_D_AFFAIRES = 'ca';

    public const PAR_QUANTITE = 'quantite';

    public function __construct(
        public readonly Graphique $graphique,
        public readonly TypeDeGraphique $type,
        public readonly string $mesure = self::PAR_CHIFFRE_D_AFFAIRES,
    ) {}

    public static function parDefaut(Graphique $graphique, ?TypeDeGraphique $type = null): self
    {
        return new self($graphique, $type ?? $graphique->typeParDefaut());
    }

    /**
     * Les meilleurs produits se classent-ils par quantité ?
     */
    public function parQuantite(): bool
    {
        return $this->graphique === Graphique::MeilleursProduits && $this->mesure === self::PAR_QUANTITE;
    }

    /**
     * @return array{graphique: string, type: string, mesure?: string}
     */
    public function versTableau(): array
    {
        return $this->graphique === Graphique::MeilleursProduits
            ? ['graphique' => $this->graphique->value, 'type' => $this->type->value, 'mesure' => $this->mesure]
            : ['graphique' => $this->graphique->value, 'type' => $this->type->value];
    }

    /**
     * La carte écrite dans des préférences, ou null quand une seule de ses
     * valeurs est inconnue : un graphique hors du catalogue, un type qu'il
     * ne permet pas, une mesure qui n'existe pas ou qu'il n'a pas.
     */
    public static function lire(mixed $brute): ?self
    {
        if (! is_array($brute) || array_diff(array_keys($brute), ['graphique', 'type', 'mesure']) !== []) {
            return null;
        }

        $graphique = is_string($brute['graphique'] ?? null) ? Graphique::tryFrom($brute['graphique']) : null;
        $type = is_string($brute['type'] ?? null) ? TypeDeGraphique::tryFrom($brute['type']) : null;

        if ($graphique === null || $type === null || ! in_array($type, $graphique->types(), true)) {
            return null;
        }

        if (! array_key_exists('mesure', $brute)) {
            return new self($graphique, $type);
        }

        return $graphique === Graphique::MeilleursProduits && in_array($brute['mesure'], [self::PAR_CHIFFRE_D_AFFAIRES, self::PAR_QUANTITE], true)
            ? new self($graphique, $type, $brute['mesure'])
            : null;
    }
}
