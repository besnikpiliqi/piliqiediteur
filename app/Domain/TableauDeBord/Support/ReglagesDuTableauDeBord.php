<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use Illuminate\Support\Str;

/**
 * Les préférences d'un compte sur un écran du tableau de bord (décision de
 * l'éditeur du 14/11/2026) : ses cartes, dans leur ordre, chacune avec son
 * type et, pour un graphique par période, ce qu'elle montre (les montants
 * ou l'écart en % sur un an, avec ou sans tendance) ; la période ; le
 * magasin choisi (null : tous ses magasins) ; la comparaison avec l'année
 * précédente ; le découpage (« Découper par » : mois, trimestre ou année) ;
 * le cumul depuis le début de l'année ou de l'exercice (choix de l'éditeur
 * du 16/11/2026, lot GRAPH-AN). Immuable : chaque geste en rend une
 * nouvelle, que PreferencesDesGraphiques enregistre.
 *
 * Ce qui se lit en base est vérifié valeur par valeur (voir lire()) :
 * toute valeur inconnue (un graphique hors du catalogue, un type qu'il ne
 * permet pas, une carte en double, une période ou une comparaison
 * illisible, un magasin qui n'est pas un identifiant) est rejetée et
 * remplacée par le défaut (voir parDefaut()). Un exercice retenu par une
 * société dont l'exercice finit en décembre se lit comme l'année
 * correspondante, la même période (voir
 * PeriodeDuTableauDeBord::pourLaSociete()).
 */
final class ReglagesDuTableauDeBord
{
    /**
     * @param  list<CarteDuTableauDeBord>  $cartes
     */
    public function __construct(
        public readonly array $cartes,
        public readonly PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois,
        public readonly ?string $magasin = null,
        public readonly bool $comparer = false,
        public readonly DecoupageDuTableauDeBord $decoupage = DecoupageDuTableauDeBord::Mois,
        public readonly bool $cumul = false,
    ) {}

    /**
     * Les réglages d'un compte qui n'a encore rien choisi : « Ventes et
     * achats HT par mois », « TVA à payer par mois (estimation) » et
     * « Chiffre d'affaires HT par mois », en barres, puis, dans l'espace
     * de la société quand il y a plus d'un magasin, « Chiffre d'affaires
     * par magasin » en anneau, puis « Factures à encaisser par ancienneté »
     * en barres (accord de l'éditeur du 17/11/2026, lot GRAPH-AN) ; un
     * graphique que le compte ne voit pas est omis, donc cette dernière
     * carte seulement pour qui voit ces factures dans les magasins comptés
     * (voir Perimetre::voit()). Les 12 derniers mois par mois, tous ses
     * magasins, sans comparaison ni cumul.
     *
     * Sur le site de démonstration, toutes les cartes que le compte voit
     * sur cet écran, dans l'ordre du catalogue, chacune à son type par
     * défaut, pour que la société fictive se voie tout de suite en entier
     * (précision de l'éditeur du 16/11/2026 vers 23 h 59) : une règle, pas
     * une liste, qui ouvre aussi les graphiques ajoutés plus tard. Une
     * préférence déjà enregistrée prime, partout.
     */
    public static function parDefaut(Perimetre $perimetre): self
    {
        if (SiteDePiliqi::estLaDemonstration()) {
            return new self(array_values(array_map(
                static fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique),
                array_filter(Graphique::cases(), static fn (Graphique $graphique): bool => $perimetre->voit($graphique)),
            )));
        }

        $cartes = [
            CarteDuTableauDeBord::parDefaut(Graphique::VentesEtAchatsParMois, TypeDeGraphique::Barres),
            CarteDuTableauDeBord::parDefaut(Graphique::TvaAPayerParMois, TypeDeGraphique::Barres),
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois, TypeDeGraphique::Barres),
        ];

        if ($perimetre->ecran === EcranDuTableauDeBord::Societe && $perimetre->magasins()->count() > 1) {
            $cartes[] = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMagasin, TypeDeGraphique::Anneau);
        }

        $cartes[] = CarteDuTableauDeBord::parDefaut(Graphique::FacturesParAnciennete, TypeDeGraphique::Barres);

        return new self(array_values(array_filter($cartes, fn (CarteDuTableauDeBord $carte): bool => $perimetre->voit($carte->graphique))));
    }

    /**
     * Les réglages écrits en base pour ce périmètre, vérifiés valeur par
     * valeur ; le défaut pour chacune qui ne se lit pas (voir le docblock
     * de la classe).
     */
    public static function lire(mixed $brut, Perimetre $perimetre): self
    {
        $defaut = self::parDefaut($perimetre);

        if (! is_array($brut)) {
            return $defaut;
        }

        $periode = is_string($brut['periode'] ?? null) ? PeriodeDuTableauDeBord::tryFrom($brut['periode'])?->pourLaSociete($perimetre->moisDeFinDExercice()) : null;
        $decoupage = is_string($brut['decoupage'] ?? null) ? DecoupageDuTableauDeBord::tryFrom($brut['decoupage']) : null;
        $magasin = $brut['magasin'] ?? null;

        return new self(
            self::lireLesCartes($brut['cartes'] ?? null) ?? $defaut->cartes,
            $periode ?? $defaut->periode,
            is_string($magasin) && Str::isUuid($magasin) ? $magasin : null,
            is_bool($brut['comparer'] ?? null) ? $brut['comparer'] : $defaut->comparer,
            $decoupage ?? $defaut->decoupage,
            is_bool($brut['cumul'] ?? null) ? $brut['cumul'] : $defaut->cumul,
        );
    }

    /**
     * @return array{cartes: list<array{graphique: string, type: string, mesure?: string, affichage?: string, ecart?: bool, tendance?: bool}>, periode: string, magasin: string|null, comparer: bool, decoupage: string, cumul: bool}
     */
    public function versTableau(): array
    {
        return [
            'cartes' => array_map(fn (CarteDuTableauDeBord $carte): array => $carte->versTableau(), $this->cartes),
            'periode' => $this->periode->value,
            'magasin' => $this->magasin,
            'comparer' => $this->comparer,
            'decoupage' => $this->decoupage->value,
            'cumul' => $this->cumul,
        ];
    }

    /**
     * Les cartes que ce périmètre montre, dans leur ordre : celles des
     * graphiques que le compte voit encore.
     *
     * @return list<CarteDuTableauDeBord>
     */
    public function cartesVisibles(Perimetre $perimetre): array
    {
        return array_values(array_filter($this->cartes, fn (CarteDuTableauDeBord $carte): bool => $perimetre->voit($carte->graphique)));
    }

    public function carte(Graphique $graphique): ?CarteDuTableauDeBord
    {
        foreach ($this->cartes as $carte) {
            if ($carte->graphique === $graphique) {
                return $carte;
            }
        }

        return null;
    }

    /**
     * Ce graphique ajouté en dernière carte, à son type par défaut ; rien
     * ne change s'il y est déjà.
     */
    public function avecLeGraphique(Graphique $graphique): self
    {
        return $this->carte($graphique) !== null ? $this : $this->avecLesCartes([...$this->cartes, CarteDuTableauDeBord::parDefaut($graphique)]);
    }

    public function sansLeGraphique(Graphique $graphique): self
    {
        return $this->avecLesCartes(array_values(array_filter($this->cartes, fn (CarteDuTableauDeBord $carte): bool => $carte->graphique !== $graphique)));
    }

    /**
     * La carte de ce graphique avec ce type et, pour les meilleurs
     * produits, cette mesure ; rien ne change sans la carte.
     */
    public function avecLaCarte(CarteDuTableauDeBord $nouvelle): self
    {
        return $this->avecLesCartes(array_map(fn (CarteDuTableauDeBord $carte): CarteDuTableauDeBord => $carte->graphique === $nouvelle->graphique ? $nouvelle : $carte, $this->cartes));
    }

    /**
     * La carte de ce graphique déplacée d'un rang vers le haut (-1) ou vers
     * le bas (+1) parmi les cartes que ce périmètre montre (voir
     * cartesVisibles()) : elle prend la place de la plus proche carte
     * visible dans ce sens, qui prend la sienne ; les cartes que le
     * périmètre ne montre pas (un graphique d'un autre magasin du filtre,
     * d'un autre client du cabinet) gardent leur place. Rien ne change en
     * bout de grille, ni pour une carte que le périmètre ne montre pas.
     */
    public function avecLaCarteDeplacee(Graphique $graphique, int $sens, Perimetre $perimetre): self
    {
        $visibles = array_keys(array_filter($this->cartes, fn (CarteDuTableauDeBord $carte): bool => $perimetre->voit($carte->graphique)));

        foreach ($visibles as $rangVisible => $rang) {
            $cible = $visibles[$rangVisible + $sens] ?? null;

            if ($this->cartes[$rang]->graphique === $graphique && $cible !== null) {
                $cartes = $this->cartes;
                [$cartes[$rang], $cartes[$cible]] = [$cartes[$cible], $cartes[$rang]];

                return $this->avecLesCartes($cartes);
            }
        }

        return $this;
    }

    public function avecLaPeriode(PeriodeDuTableauDeBord $periode): self
    {
        return new self($this->cartes, $periode, $this->magasin, $this->comparer, $this->decoupage, $this->cumul);
    }

    public function avecLeMagasin(?string $magasin): self
    {
        return new self($this->cartes, $this->periode, $magasin, $this->comparer, $this->decoupage, $this->cumul);
    }

    public function avecLaComparaison(bool $comparer): self
    {
        return new self($this->cartes, $this->periode, $this->magasin, $comparer, $this->decoupage, $this->cumul);
    }

    public function avecLeDecoupage(DecoupageDuTableauDeBord $decoupage): self
    {
        return new self($this->cartes, $this->periode, $this->magasin, $this->comparer, $decoupage, $this->cumul);
    }

    public function avecLeCumul(bool $cumul): self
    {
        return new self($this->cartes, $this->periode, $this->magasin, $this->comparer, $this->decoupage, $cumul);
    }

    /**
     * Le cumul depuis le début de l'année, ou de l'exercice, se coche-t-il ?
     * Pas quand le découpage est l'année : chaque barre est déjà une année
     * entière, il n'y a rien à cumuler.
     */
    public function cumulPossible(): bool
    {
        return $this->decoupage !== DecoupageDuTableauDeBord::Annee;
    }

    /**
     * La comparaison avec l'année précédente se coche-t-elle ? Pas quand le
     * découpage est l'année : chaque barre est déjà une année.
     */
    public function comparaisonPossible(): bool
    {
        return $this->decoupage !== DecoupageDuTableauDeBord::Annee;
    }

    /**
     * Les mêmes réglages avec ces cartes, remises dans l'ordre de leurs
     * rangs.
     *
     * @param  array<int, CarteDuTableauDeBord>  $cartes
     */
    private function avecLesCartes(array $cartes): self
    {
        return new self(array_values($cartes), $this->periode, $this->magasin, $this->comparer, $this->decoupage, $this->cumul);
    }

    /**
     * Les cartes écrites en base, ou null dès que l'une ne se lit pas
     * (voir CarteDuTableauDeBord::lire()) ou revient deux fois.
     *
     * @return list<CarteDuTableauDeBord>|null
     */
    private static function lireLesCartes(mixed $brutes): ?array
    {
        if (! is_array($brutes) || ! array_is_list($brutes)) {
            return null;
        }

        $cartes = [];

        foreach ($brutes as $brute) {
            $carte = CarteDuTableauDeBord::lire($brute);

            if ($carte === null || isset($cartes[$carte->graphique->value])) {
                return null;
            }

            $cartes[$carte->graphique->value] = $carte;
        }

        return array_values($cartes);
    }
}
