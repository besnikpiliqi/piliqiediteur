<?php

namespace App\Domain\TableauDeBord\Enums;

/**
 * La couleur de la ligne sous un chiffre clé du tableau de bord (choix de
 * l'éditeur du 16/11/2026, point 0 de GRAPH-AN) : la couleur suit le sens,
 * jamais le mérite. Une variation sur un an qui monte est verte
 * (« positif »), une qui baisse est rouge (« negatif »), même pour les
 * achats, dont la phrase ne juge pas ; une variation nulle, ou sans année
 * d'avant, reste neutre. Un retard est toujours rouge quand il y en a un
 * (un retard en vert serait faux), neutre sans retard (décision de Claude
 * du 22/11/2026, D76). L'écran donne à chaque ton sa couleur, lisible en
 * clair et en sombre.
 */
enum TonDUnChiffre: string
{
    case Positif = 'positif';
    case Negatif = 'negatif';
    case Neutre = 'neutre';

    /**
     * Le ton d'une variation en centièmes de point : verte au-dessus de 0,
     * rouge en dessous, neutre à 0.
     */
    public static function dUneVariation(int $centiemes): self
    {
        return match (true) {
            $centiemes > 0 => self::Positif,
            $centiemes < 0 => self::Negatif,
            default => self::Neutre,
        };
    }
}
