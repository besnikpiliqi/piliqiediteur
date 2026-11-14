<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\FacturesDuTableauDeBord;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le calcul d'une page du tableau de bord (voir SeriesDesGraphiques et
 * ChiffresDuTableauDeBord) : ses mois, ceux de la comparaison avec
 * l'année précédente, ceux des chiffres clés et du chiffre d'affaires par
 * an, et ce que chaque source a déjà lu, pour qu'elle ne se lise qu'une
 * fois quel que soit le nombre de cartes. Les sommes se font ici, en
 * centimes, sur les montants lus.
 *
 * @phpstan-type Ligne array{magasin: int, jour: string}
 */
final class CalculDesSeries
{
    /** Le nombre d'années (ou d'exercices) du chiffre d'affaires par an. */
    public const ANNEES = 5;

    /** @var list<string> les mois de la période */
    public readonly array $mois;

    /** @var list<string>|null les mêmes mois un an plus tôt, quand la comparaison est cochée */
    public readonly ?array $moisCompares;

    /** @var list<array{document: 'facture'|'avoir', id: int, magasin: int, jour: string, ht: int, tva: int, ttc: int, type_client: string, client: int, client_nom: string|null}>|null */
    public ?array $documentsDeVente = null;

    /** @var list<array{document: 'facture'|'avoir', document_id: int, ligne_id: int, magasin: int, jour: string, nature: string, produit: int|null, service: int|null, description: string, quantite: int, taux: int, ht: int, tva: int, ttc: int}>|null */
    public ?array $lignesDeVente = null;

    /** @var list<array{document: 'facture'|'avoir', id: int, magasin: int, jour: string, ht: int, tva: int, ttc: int, type_client: string, client: int, client_nom: string|null}>|null */
    public ?array $avoirs = null;

    /** @var list<array{document: 'achat'|'avoir_fournisseur', id: int, magasin: int, jour: string, ht: int, tva: int, ttc: int, fournisseur: int}>|null */
    public ?array $documentsDAchat = null;

    /** @var array{paiements: list<array{magasin: int, jour: string, mode: string, montant: int}>, credits: list<array{magasin: int, jour: string, montant: int}>}|null */
    public ?array $encaissements = null;

    /** @var array{valeurs: array<int, array<string, int>>, ventes: array<int, array<string, int>>, couts: array<int, array<string, int>>}|null */
    public ?array $historiqueDuStock = null;

    /** @var Collection<int, Facture>|null */
    private ?Collection $facturesAEncaisser = null;

    private readonly string $ceMois;

    /**
     * @param  list<CarteDuTableauDeBord>  $cartes
     */
    public function __construct(
        public readonly Perimetre $perimetre,
        public readonly ReglagesDuTableauDeBord $reglages,
        public readonly array $cartes,
    ) {
        [$premier, $dernier] = $reglages->periode->bornes($perimetre->aujourdhui(), $this->moisDeFinDExercice());
        $this->mois = Mois::du($premier, $dernier);
        $this->moisCompares = $reglages->comparer ? array_map(fn (string $mois): string => Mois::decaler($mois, -12), $this->mois) : null;
        $this->ceMois = Mois::duJour($perimetre->aujourdhui());
    }

    public function montre(Graphique $graphique): bool
    {
        foreach ($this->cartes as $carte) {
            if ($carte->graphique === $graphique) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le mois en cours de l'écran, celui des chiffres clés.
     */
    public function ceMois(): string
    {
        return $this->ceMois;
    }

    /**
     * Les mois de la période et ceux de la comparaison, dans l'ordre.
     *
     * @return list<string>
     */
    public function tousLesMois(): array
    {
        $tous = array_values(array_unique([...$this->moisCompares ?? [], ...$this->mois]));
        sort($tous);

        return $tous;
    }

    /**
     * Le premier jour qu'une source lit : celui du premier mois de la
     * période, de la comparaison, des chiffres clés (le même mois un an
     * plus tôt) et du chiffre d'affaires par an.
     */
    public function premierJour(): string
    {
        $debuts = [$this->mois[0], Mois::decaler($this->ceMois, -12), ...($this->moisCompares === null ? [] : [$this->moisCompares[0]])];

        if ($this->montre(Graphique::ChiffreDAffairesParAn)) {
            $debuts[] = array_values($this->annees())[0][0];
        }

        return min($debuts).'-01';
    }

    /**
     * Le dernier jour qu'une source lit : celui du dernier mois de la
     * période ou du mois en cours.
     */
    public function dernierJour(): string
    {
        return self::dernierJourDu(max($this->mois[count($this->mois) - 1], $this->ceMois));
    }

    public function premierJourDeLaPeriode(): string
    {
        return $this->mois[0].'-01';
    }

    public function dernierJourDeLaPeriode(): string
    {
        return self::dernierJourDu($this->mois[count($this->mois) - 1]);
    }

    /**
     * Les années civiles, ou les exercices quand la période en est un, du
     * chiffre d'affaires par an : les cinq qui finissent avec la période,
     * chacune par son libellé, avec son premier et son dernier mois.
     *
     * @return non-empty-array<string, array{0: string, 1: string}>
     */
    public function annees(): array
    {
        $dernier = $this->mois[count($this->mois) - 1];
        $annees = [];

        for ($rang = self::ANNEES - 1; $rang >= 0; $rang--) {
            if ($this->reglages->periode->estUnExercice()) {
                $fin = Mois::decaler($dernier, -12 * $rang);
                $debut = Mois::decaler($fin, -11);
                $libelle = Mois::annee($debut) === Mois::annee($fin) ? 'Exercice '.Mois::annee($fin) : 'Exercice '.Mois::annee($debut).'-'.Mois::annee($fin);
                $annees[$libelle] = [$debut, $fin];

                continue;
            }

            $annee = Mois::annee($dernier) - $rang;
            $annees[(string) $annee] = [sprintf('%04d-01', $annee), sprintf('%04d-12', $annee)];
        }

        return $annees;
    }

    /**
     * Une série par mois de la période, et, quand la comparaison est
     * cochée, la même un an plus tôt ; un mois encore à venir n'a pas de
     * valeur.
     *
     * @param  Closure(list<string>): array<string, int>  $valeurs
     */
    public function parMois(string $libelle, Closure $valeurs): SerieDuGraphique
    {
        return $this->parMoisMultiple([[$libelle, $valeurs]]);
    }

    /**
     * Plusieurs séries par mois, chacune avec, au besoin, sa forme (une
     * ligne), son unité et son axe ; leurs comparaisons suivent, dans le
     * même ordre.
     *
     * @param  list<array{0: string, 1: Closure(list<string>): array<string, int>, 2?: bool, 3?: 'euros'|'nombre', 4?: bool}>  $series
     */
    public function parMoisMultiple(array $series): SerieDuGraphique
    {
        $jeux = [];
        $comparaisons = [];

        foreach ($series as $serie) {
            [$libelle, $valeurs] = $serie;
            $enLigne = $serie[2] ?? false;
            $unite = $serie[3] ?? SerieDuGraphique::EUROS;
            $axeDeDroite = $serie[4] ?? false;
            $parMois = $valeurs($this->mois);
            $jeux[] = SerieDuGraphique::jeu($libelle, array_map(fn (string $mois): ?int => $mois > $this->ceMois ? null : $parMois[$mois], $this->mois), $unite, $enLigne, $axeDeDroite);

            if ($this->moisCompares !== null) {
                $compares = $valeurs($this->moisCompares);
                $comparaisons[] = SerieDuGraphique::jeu($libelle.' (un an plus tôt)', array_map(fn (string $mois): int => $compares[$mois], $this->moisCompares), $unite, $enLigne, $axeDeDroite);
            }
        }

        return new SerieDuGraphique(array_map(fn (string $mois): string => Mois::libelle($mois), $this->mois), [...$jeux, ...$comparaisons]);
    }

    /**
     * La somme, mois par mois, de ce que $montant donne de chacune de ces
     * lignes de ces magasins ; zéro pour un mois sans ligne.
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @param  list<string>  $mois
     * @param  Closure(T): int  $montant
     * @return array<string, int>
     */
    public function sommeParMois(array $lignes, array $magasins, array $mois, Closure $montant): array
    {
        $sommes = array_fill_keys($mois, 0);
        $permis = array_flip($magasins);

        foreach ($lignes as $ligne) {
            $leMois = substr($ligne['jour'], 0, 7);

            if (isset($permis[$ligne['magasin']]) && array_key_exists($leMois, $sommes)) {
                $sommes[$leMois] += $montant($ligne);
            }
        }

        return $sommes;
    }

    /**
     * La somme, mois par mois, de ces valeurs par magasin de ces magasins.
     *
     * @param  array<int, array<string, int>>  $parMagasin
     * @param  list<int>  $magasins
     * @param  list<string>  $mois
     * @return array<string, int>
     */
    public function sommerParMagasin(array $parMagasin, array $magasins, array $mois): array
    {
        $sommes = array_fill_keys($mois, 0);

        foreach ($magasins as $magasin) {
            foreach ($mois as $unMois) {
                $sommes[$unMois] += $parMagasin[$magasin][$unMois] ?? 0;
            }
        }

        return $sommes;
    }

    /**
     * Ces lignes de ces magasins dont le jour est de la période.
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @return list<T>
     */
    public function dansLaPeriode(array $lignes, array $magasins): array
    {
        $permis = array_flip($magasins);
        $du = $this->premierJourDeLaPeriode();
        $au = $this->dernierJourDeLaPeriode();

        return array_values(array_filter($lignes, fn (array $ligne): bool => isset($permis[$ligne['magasin']]) && $ligne['jour'] >= $du && $ligne['jour'] <= $au));
    }

    /**
     * Un avoir ou un avoir fournisseur se déduit ; une vente ou un achat
     * s'ajoute.
     *
     * @param  array{document: string}  $ligne
     */
    public function signe(array $ligne): int
    {
        return in_array($ligne['document'], ['avoir', 'avoir_fournisseur'], true) ? -1 : 1;
    }

    /**
     * Les factures et les tickets à encaisser des magasins où le compte
     * voit toutes les factures, lus une fois.
     *
     * @return Collection<int, Facture>
     */
    public function facturesAEncaisser(FacturesDuTableauDeBord $factures): Collection
    {
        return $this->facturesAEncaisser ??= $factures->aEncaisser($this->perimetre, $this->perimetre->magasinsAvec(SourceDesGraphiques::Factures));
    }

    /**
     * « 35,2 % » pour 3 520 centièmes de point ; « −4,5 % » en négatif.
     */
    public static function pourcentage(int $centiemes): string
    {
        $dixiemes = self::diviserArrondi($centiemes, 10);
        $absolus = abs($dixiemes);

        return ($dixiemes < 0 ? '−' : '').intdiv($absolus, 10).','.($absolus % 10).' %';
    }

    /**
     * Le libellé d'un taux de TVA en centièmes de point : « 20 % »,
     * « 5,5 % », « 8,5 % », « 2,1 % », « 0 % ».
     */
    public static function taux(int $centiemes): string
    {
        $entier = intdiv($centiemes, 100);
        $decimales = rtrim(sprintf('%02d', $centiemes % 100), '0');

        return $entier.($decimales === '' ? '' : ','.$decimales).' %';
    }

    /**
     * $dividende ÷ $diviseur arrondi à l'entier le plus proche, une moitié
     * exacte s'éloignant de zéro ; $diviseur est positif.
     */
    public static function diviserArrondi(int $dividende, int $diviseur): int
    {
        $quotient = intdiv(abs($dividende) * 2 + $diviseur, 2 * $diviseur);

        return $dividende < 0 ? -$quotient : $quotient;
    }

    /**
     * Le jour de la semaine de ce jour « AAAA-MM-JJ », de 1 (lundi) à 7
     * (dimanche).
     */
    public static function jourDeLaSemaine(string $jour): int
    {
        return (int) Calendrier::afficherJour($jour, 'N');
    }

    private function moisDeFinDExercice(): int
    {
        $mois = $this->perimetre->societe->mois_fin_exercice;

        return is_int($mois) && $mois >= 1 && $mois <= 12 ? $mois : 12;
    }

    private static function dernierJourDu(string $mois): string
    {
        return Calendrier::ajouterJours(Mois::decaler($mois, 1).'-01', -1);
    }
}
