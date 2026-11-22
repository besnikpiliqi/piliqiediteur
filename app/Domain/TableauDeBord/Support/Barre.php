<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;

/**
 * Une barre d'un graphique par période (voir DecoupageDesPeriodes) : un
 * jour, un mois, un trimestre ou une année, du premier au dernier jour
 * « AAAA-MM-JJ » qu'elle couvre, dans le calendrier du magasin comme tout
 * jour du tableau de bord. Son étiquette est celle de l'axe (« janv.
 * 2026 », « T1 2026 », « 2025-26 », « 3 »), son intitulé celui de
 * l'info-bulle et du tableau « Voir les chiffres » (« Janvier 2026 »,
 * « Mardi 3 mars 2026 »).
 *
 * Une barre à venir n'a pas de valeur ; une barre en cours se compare aux
 * mêmes jours de l'an dernier, pas à la période entière (voir
 * unAnPlusTot()), comme les chiffres clés. Son jeton la désigne dans un
 * geste de l'écran, qui n'est accepté que parmi les barres que la carte
 * montre.
 */
final readonly class Barre
{
    public function __construct(
        public NiveauDeLaBarre $niveau,
        public string $premierJour,
        public string $dernierJour,
        public string $etiquette,
        public string $intitule,
        public bool $aVenir,
        public bool $enCours,
    ) {}

    /**
     * Ce qui la désigne : « mois:2026-03-01:2026-03-31 ».
     */
    public function jeton(): string
    {
        return "{$this->niveau->value}:{$this->premierJour}:{$this->dernierJour}";
    }

    /**
     * Un clic sur la barre l'ouvre-t-il en barres plus fines ? Un jour ouvre
     * la liste de ses documents, il ne se redécoupe pas.
     */
    public function peutSeDetailler(): bool
    {
        return $this->niveau->plusFin() !== null;
    }

    /**
     * La même barre un an plus tôt, du premier au dernier jour compris :
     * un jour est le même quantième de l'an dernier ; une barre en cours va
     * du début de la même barre à ce même quantième de l'an dernier ; les
     * autres, du premier au dernier jour des mêmes mois un an plus tôt.
     *
     * @return array{0: string, 1: string}
     */
    public function unAnPlusTot(string $aujourdhui): array
    {
        if ($this->niveau === NiveauDeLaBarre::Jour) {
            $jour = Mois::memeJourUnAnPlusTot($this->premierJour);

            return [$jour, $jour];
        }

        return [
            Mois::premierJour(Mois::decaler(Mois::duJour($this->premierJour), -12)),
            $this->enCours ? Mois::memeJourUnAnPlusTot($aujourdhui) : Mois::dernierJour(Mois::decaler(Mois::duJour($this->dernierJour), -12)),
        ];
    }
}
