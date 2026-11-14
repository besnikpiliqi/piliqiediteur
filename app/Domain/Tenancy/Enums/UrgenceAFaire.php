<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * L'urgence d'une chose de l'« À faire » (voir ChoseAFaire), qui en fixe
 * l'ordre et la couleur : d'abord ce qui est en retard ou bloque, en
 * rouge ; puis ce qui attend un geste, en orange ; enfin, en gris, ce qui
 * se fera tout seul si personne ne le fait avant (une caisse ouverte, la
 * journée pas encore clôturée) ou ne fait qu'attendre le client (un
 * avoir non utilisé). Choix de présentation de la session (décision de
 * l'éditeur du 26/10/2026, règle 7 de l'« À faire »).
 */
enum UrgenceAFaire: string implements HasColor, HasLabel
{
    case Rouge = 'rouge';
    case Orange = 'orange';
    case Gris = 'gris';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rouge => 'Urgent',
            self::Orange => 'En attente',
            self::Gris => 'Pour information',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rouge => 'danger',
            self::Orange => 'warning',
            self::Gris => 'gray',
        };
    }

    /**
     * Son rang dans l'ordre de l'« À faire » : le rouge d'abord.
     */
    public function rang(): int
    {
        return match ($this) {
            self::Rouge => 0,
            self::Orange => 1,
            self::Gris => 2,
        };
    }
}
