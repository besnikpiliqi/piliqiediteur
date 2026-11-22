<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;

/**
 * Une carte de la grille du tableau de bord : son graphique, le type
 * choisi parmi ceux qu'il permet, pour les meilleurs produits ce qui les
 * classe (le chiffre d'affaires HT ou la quantité), pour l'écart des
 * prix d'achat son affichage, un total par mois ou un seul total pour les
 * douze mois (décision de l'éditeur du 21/11/2026, D76, B6), et pour un
 * graphique par période ce que la carte montre (choix de l'éditeur du
 * 16/11/2026, lot GRAPH-AN) : les montants (le défaut) ou l'écart en % sur
 * un an, et la tendance, la moyenne de la période et des deux précédentes.
 * Ce sont des réglages de la carte, retenus comme la mesure, que chaque
 * changement de type garde.
 */
final class CarteDuTableauDeBord
{
    public const PAR_CHIFFRE_D_AFFAIRES = 'ca';

    public const PAR_QUANTITE = 'quantite';

    /** Un total par mois : la première option du menu « Afficher : », le défaut. */
    public const AFFICHAGE_PAR_MOIS = TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS;

    /** Un seul total pour les douze mois. */
    public const AFFICHAGE_SUR_DOUZE_MOIS = TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS;

    public function __construct(
        public readonly Graphique $graphique,
        public readonly TypeDeGraphique $type,
        public readonly string $mesure = self::PAR_CHIFFRE_D_AFFAIRES,
        public readonly string $affichage = self::AFFICHAGE_PAR_MOIS,
        public readonly bool $ecart = false,
        public readonly bool $tendance = false,
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
     * L'écart des prix d'achat montre-t-il un seul total pour les douze
     * mois ?
     */
    public function surDouzeMois(): bool
    {
        return $this->graphique === Graphique::EcartDesPrixDAchat && $this->affichage === self::AFFICHAGE_SUR_DOUZE_MOIS;
    }

    public function avecLeType(TypeDeGraphique $type): self
    {
        return new self($this->graphique, $type, $this->mesure, $this->affichage, $this->ecart, $this->tendance);
    }

    public function avecLaMesure(string $mesure): self
    {
        return new self($this->graphique, $this->type, $mesure, $this->affichage, $this->ecart, $this->tendance);
    }

    public function avecLAffichage(string $affichage): self
    {
        return new self($this->graphique, $this->type, $this->mesure, $affichage, $this->ecart, $this->tendance);
    }

    /**
     * La carte d'un graphique par période en écart en % sur un an, ou en
     * montants ; la tendance se garde, que l'écart met de côté.
     */
    public function avecLEcart(bool $ecart): self
    {
        return new self($this->graphique, $this->type, $this->mesure, $this->affichage, $ecart, $this->tendance);
    }

    public function avecLaTendance(bool $tendance): self
    {
        return new self($this->graphique, $this->type, $this->mesure, $this->affichage, $this->ecart, $tendance);
    }

    /**
     * @return array{graphique: string, type: string, mesure?: string, affichage?: string, ecart?: bool, tendance?: bool}
     */
    public function versTableau(): array
    {
        $carte = ['graphique' => $this->graphique->value, 'type' => $this->type->value];

        if ($this->graphique === Graphique::MeilleursProduits) {
            return [...$carte, 'mesure' => $this->mesure];
        }

        if ($this->graphique === Graphique::EcartDesPrixDAchat) {
            return [...$carte, 'affichage' => $this->affichage];
        }

        return $this->graphique->estParPeriode() ? [...$carte, 'ecart' => $this->ecart, 'tendance' => $this->tendance] : $carte;
    }

    /**
     * La carte écrite dans des préférences, ou null quand une seule de ses
     * valeurs est inconnue : un graphique hors du catalogue (comme « Chiffre
     * d'affaires HT par an », retiré), un type qu'il ne permet pas, une
     * mesure qui n'existe pas ou qu'il n'a pas, un affichage qui n'existe
     * pas ou qu'il n'a pas, un écart ou une tendance qui n'est pas un
     * booléen ou qu'il n'a pas, une clé inconnue.
     */
    public static function lire(mixed $brute): ?self
    {
        if (! is_array($brute) || array_diff(array_keys($brute), ['graphique', 'type', 'mesure', 'affichage', 'ecart', 'tendance']) !== []) {
            return null;
        }

        $graphique = is_string($brute['graphique'] ?? null) ? Graphique::tryFrom($brute['graphique']) : null;
        $type = is_string($brute['type'] ?? null) ? TypeDeGraphique::tryFrom($brute['type']) : null;

        if ($graphique === null || $type === null || ! in_array($type, $graphique->types(), true)) {
            return null;
        }

        $mesure = self::PAR_CHIFFRE_D_AFFAIRES;
        $affichage = self::AFFICHAGE_PAR_MOIS;

        if (array_key_exists('mesure', $brute)) {
            if ($graphique !== Graphique::MeilleursProduits || ! in_array($brute['mesure'], [self::PAR_CHIFFRE_D_AFFAIRES, self::PAR_QUANTITE], true)) {
                return null;
            }

            $mesure = $brute['mesure'];
        }

        if (array_key_exists('affichage', $brute)) {
            if ($graphique !== Graphique::EcartDesPrixDAchat || ! in_array($brute['affichage'], [self::AFFICHAGE_PAR_MOIS, self::AFFICHAGE_SUR_DOUZE_MOIS], true)) {
                return null;
            }

            $affichage = $brute['affichage'];
        }

        $reglages = ['ecart' => false, 'tendance' => false];

        foreach (array_keys($reglages) as $cle) {
            if (array_key_exists($cle, $brute)) {
                if (! $graphique->estParPeriode() || ! is_bool($brute[$cle])) {
                    return null;
                }

                $reglages[$cle] = $brute[$cle];
            }
        }

        return new self($graphique, $type, $mesure, $affichage, $reglages['ecart'], $reglages['tendance']);
    }
}
