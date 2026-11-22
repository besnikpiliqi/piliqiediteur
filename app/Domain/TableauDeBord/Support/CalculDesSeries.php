<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChargesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\FacturesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PaiementsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le calcul d'une page du tableau de bord (voir SeriesDesGraphiques et
 * ChiffresDuTableauDeBord) : sa fenêtre (les mois de la période, ou les dix
 * dernières années quand le découpage est l'année), les barres de chaque
 * carte par période (voir barresDe()), le détail qu'une carte ouvre (voir
 * chemin()), et ce que chaque source a déjà lu, pour qu'elle ne se lise
 * qu'une fois quel que soit le nombre de cartes. Les sommes se font ici, en
 * centimes, sur les montants lus (voir SommesParJour).
 *
 * Les réglages de l'écran (voir ReglagesDuTableauDeBord) et ceux de chaque
 * carte (voir CarteDuTableauDeBord) se composent ainsi, par carte :
 * - en détail (une barre ouverte en mois ou en jours), les montants
 *   seulement, sans cumul, comparaison, écart ni tendance ;
 * - l'écart en % sur un an met de côté le cumul, la comparaison et la
 *   tendance ;
 * - le cumul ne s'applique ni par année ni à une valeur de stock ; avec lui,
 *   pas de tendance ;
 * - la comparaison ne s'applique pas par année, où chaque barre est déjà une
 *   année.
 * Le cumul, la comparaison, l'écart et la tendance portent sur la série
 * principale d'une carte (voir DefinitionDeSerie), sauf le cumul, qui porte
 * sur toutes ses séries.
 *
 * @phpstan-type Ligne array{magasin: int, jour: string}
 *
 * @phpstan-import-type DocumentDeVente from VentesDuTableauDeBord
 * @phpstan-import-type LigneDeVente from VentesDuTableauDeBord
 * @phpstan-import-type VenteDUnCompte from VentesDuTableauDeBord
 * @phpstan-import-type DocumentDAchat from AchatsDuTableauDeBord
 * @phpstan-import-type LigneDAchatDuTableau from AchatsDuTableauDeBord
 * @phpstan-import-type Encaissement from PaiementsDuTableauDeBord
 * @phpstan-import-type CreditImpute from PaiementsDuTableauDeBord
 * @phpstan-import-type ChargeDeLaTva from ChargesDuTableauDeBord
 */
final class CalculDesSeries
{
    /** @var list<string> les mois de la fenêtre : la période, ou les dix dernières années */
    public readonly array $mois;

    /** @var list<string> les douze mois de la carte « Écart des prix d'achat » : ceux de la période, ou les douze derniers mois quand le découpage est l'année */
    public readonly array $moisDeLEcart;

    /** @var list<DocumentDeVente>|null */
    public ?array $documentsDeVente = null;

    /** @var list<LigneDeVente>|null */
    public ?array $lignesDeVente = null;

    /** @var list<DocumentDeVente>|null */
    public ?array $avoirs = null;

    /** @var list<VenteDUnCompte>|null les ventes de chaque compte, que lit la carte « Ventes par vendeur » */
    public ?array $ventesParCompte = null;

    /** @var list<DocumentDAchat>|null */
    public ?array $documentsDAchat = null;

    /** @var list<LigneDAchatDuTableau>|null les lignes des factures d'achat validées, que lit la carte « Écart des prix d'achat » */
    public ?array $lignesDAchat = null;

    /** @var array{paiements: list<Encaissement>, credits: list<CreditImpute>}|null */
    public ?array $encaissements = null;

    public ?HistoriqueDuStock $historiqueDuStock = null;

    /** @var list<ChargeDeLaTva>|null les charges que compte la TVA, null sans les charges (voir chargesDeLaTvaLues) */
    public ?array $chargesDeLaTva = null;

    public bool $chargesDeLaTvaLues = false;

    /** @var Collection<int, Facture>|null */
    private ?Collection $facturesAEncaisser = null;

    /** @var array<string, list<Barre>> le détail ouvert de chaque carte, validé : la barre de chaque niveau, de la plus large à la plus fine */
    private array $chemins = [];

    private readonly string $ceMois;

    private readonly string $aujourdhui;

    private readonly int $moisDeFinDExercice;

    /**
     * @param  list<CarteDuTableauDeBord>  $cartes
     * @param  array<string, list<string>>  $details  par graphique, les jetons des barres ouvertes (voir Barre::jeton()), de la plus large à la plus fine ; un détail qui n'est pas celui d'une barre de la carte est ignoré
     */
    public function __construct(
        public readonly Perimetre $perimetre,
        public readonly ReglagesDuTableauDeBord $reglages,
        public readonly array $cartes,
        public readonly array $details = [],
    ) {
        $this->aujourdhui = $perimetre->aujourdhui();
        $this->moisDeFinDExercice = $perimetre->moisDeFinDExercice();
        $this->ceMois = Mois::duJour($this->aujourdhui);
        [$premier, $dernier] = DecoupageDesPeriodes::fenetre($reglages->decoupage, $reglages->periode, $this->aujourdhui, $this->moisDeFinDExercice);
        $this->mois = Mois::du($premier, $dernier);
        $this->moisDeLEcart = $reglages->decoupage === DecoupageDuTableauDeBord::Annee ? Mois::du(Mois::decaler($this->ceMois, -11), $this->ceMois) : $this->mois;
    }

    public function montre(Graphique $graphique): bool
    {
        return $this->carte($graphique) !== null;
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
     * Le mois en cours de l'écran, celui des chiffres clés.
     */
    public function ceMois(): string
    {
        return $this->ceMois;
    }

    /**
     * Le jour de l'écran, « AAAA-MM-JJ ».
     */
    public function aujourdhui(): string
    {
        return $this->aujourdhui;
    }

    /**
     * Les années sont-elles celles d'un exercice décalé, que la société
     * clôt un autre mois que décembre ?
     */
    public function exercicesDecales(): bool
    {
        return $this->moisDeFinDExercice !== 12;
    }

    /**
     * Les barres de la carte d'un graphique par période : celles du
     * découpage, ou, quand un détail est ouvert, les barres plus fines de la
     * dernière barre ouverte.
     *
     * @return list<Barre>
     */
    public function barresDe(CarteDuTableauDeBord $carte): array
    {
        $chemin = $this->chemin($carte->graphique);

        return $chemin === []
            ? $this->barresDeBase($carte)
            : DecoupageDesPeriodes::sousBarres($chemin[count($chemin) - 1], $this->aujourdhui, $this->moisDeFinDExercice);
    }

    /**
     * Le détail ouvert de cette carte, validé : la barre de chaque niveau
     * ouvert, de la plus large à la plus fine, chacune étant une barre à
     * détailler, qui n'est pas à venir, de la carte à ce niveau. Vide quand
     * rien n'est ouvert, ou quand un jeton ne désigne aucune barre de la
     * carte : un détail forgé, ou resté ouvert après un autre réglage,
     * n'ouvre rien.
     *
     * @return list<Barre>
     */
    public function chemin(Graphique $graphique): array
    {
        return $this->chemins[$graphique->value] ??= $this->lireLeChemin($graphique);
    }

    /**
     * La dernière barre du détail ouvert de cette carte, celle dont la
     * carte montre les barres plus fines ; null sans détail.
     */
    public function detailDe(Graphique $graphique): ?Barre
    {
        $chemin = $this->chemin($graphique);

        return $chemin === [] ? null : $chemin[count($chemin) - 1];
    }

    /**
     * Cette carte montre-t-elle le cumul depuis le début de l'année ou de
     * l'exercice ? Pas par année, pas pour un stock, pas en écart en %, pas
     * en détail.
     */
    public function cumulActif(CarteDuTableauDeBord $carte): bool
    {
        return $this->chemin($carte->graphique) === [] && $this->cumulDeBase($carte);
    }

    /**
     * Cette carte montre-t-elle l'année précédente à côté de ses barres ?
     * Pas par année, pas en écart en %, pas en détail.
     */
    public function comparaisonActive(CarteDuTableauDeBord $carte): bool
    {
        return $this->reglages->comparer && $this->reglages->comparaisonPossible() && ! $carte->ecart && $this->chemin($carte->graphique) === [];
    }

    /**
     * Cette carte montre-t-elle l'écart en % sur un an à la place des
     * montants ? Pas en détail.
     */
    public function ecartActif(CarteDuTableauDeBord $carte): bool
    {
        return $carte->ecart && $this->chemin($carte->graphique) === [];
    }

    /**
     * Cette carte montre-t-elle sa tendance ? Pas en écart en %, pas avec le
     * cumul, pas en détail.
     */
    public function tendanceActive(CarteDuTableauDeBord $carte): bool
    {
        return $carte->tendance && ! $carte->ecart && $this->chemin($carte->graphique) === [] && ! $this->cumulDeBase($carte);
    }

    /**
     * Le premier jour qu'une source lit : celui du premier mois de la
     * fenêtre, des chiffres clés (le même mois un an plus tôt) et, quand la
     * comparaison est cochée ou qu'une carte est en écart en % sur un an,
     * de la fenêtre un an plus tôt.
     */
    public function premierJour(): string
    {
        $debuts = [Mois::premierJour($this->mois[0]), Mois::premierJour(Mois::decaler($this->ceMois, -12))];

        if ($this->reglages->comparer || $this->uneCarteEstEnEcart()) {
            $debuts[] = Mois::premierJour(Mois::decaler($this->mois[0], -12));
        }

        return min($debuts);
    }

    /**
     * Le dernier jour qu'une source lit : celui du dernier mois de la
     * fenêtre ou du mois en cours.
     */
    public function dernierJour(): string
    {
        return Mois::dernierJour(max($this->mois[count($this->mois) - 1], $this->ceMois));
    }

    public function premierJourDeLaPeriode(): string
    {
        return Mois::premierJour($this->mois[0]);
    }

    public function dernierJourDeLaPeriode(): string
    {
        return Mois::dernierJour($this->mois[count($this->mois) - 1]);
    }

    public function premierJourDeLEcart(): string
    {
        return Mois::premierJour($this->moisDeLEcart[0]);
    }

    public function dernierJourDeLEcart(): string
    {
        return Mois::dernierJour($this->moisDeLEcart[count($this->moisDeLEcart) - 1]);
    }

    /**
     * Les montants de ces lignes de ces magasins, rangés par jour (voir
     * SommesParJour).
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @param  Closure(T): int  $montant
     */
    public function sommesParJour(array $lignes, array $magasins, Closure $montant): SommesParJour
    {
        return SommesParJour::depuis($lignes, $magasins, $montant);
    }

    /**
     * Ces lignes de ces magasins dont le jour est de la fenêtre.
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @return list<T>
     */
    public function dansLaPeriode(array $lignes, array $magasins): array
    {
        return self::entre($lignes, $magasins, $this->premierJourDeLaPeriode(), $this->dernierJourDeLaPeriode());
    }

    /**
     * Ces lignes de ces magasins dont le jour est de l'un des douze mois de
     * la carte « Écart des prix d'achat ».
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @return list<T>
     */
    public function dansLesMoisDeLEcart(array $lignes, array $magasins): array
    {
        return self::entre($lignes, $magasins, $this->premierJourDeLEcart(), $this->dernierJourDeLEcart());
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
     * L'écart de ce montant à celui d'il y a un an, en centièmes de point
     * (« +12,5 % » pour 1 250), sur la valeur absolue de celui d'il y a un
     * an ; null quand il n'y avait rien il y a un an : l'écart ne se dit
     * pas.
     */
    public static function ecartSurUnAn(int $valeur, int $ilYAUnAn): ?int
    {
        return $ilYAUnAn === 0 ? null : self::diviserArrondi(($valeur - $ilYAUnAn) * 10000, abs($ilYAUnAn));
    }

    /**
     * Le jour de la semaine de ce jour « AAAA-MM-JJ », de 1 (lundi) à 7
     * (dimanche).
     */
    public static function jourDeLaSemaine(string $jour): int
    {
        return (int) Calendrier::afficherJour($jour, 'N');
    }

    /**
     * Ces lignes de ces magasins du $du au $au compris.
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @return list<T>
     */
    private static function entre(array $lignes, array $magasins, string $du, string $au): array
    {
        $permis = array_flip($magasins);

        return array_values(array_filter($lignes, fn (array $ligne): bool => isset($permis[$ligne['magasin']]) && $ligne['jour'] >= $du && $ligne['jour'] <= $au));
    }

    /**
     * Le cumul est réglé pour cette carte et s'y applique, avant tout
     * détail : l'écran le permet (pas par année), le graphique l'accepte
     * (pas un stock), et la carte n'est pas en écart en %.
     */
    private function cumulDeBase(CarteDuTableauDeBord $carte): bool
    {
        return $this->reglages->cumul && $this->reglages->cumulPossible() && $carte->graphique->accepteLeCumul() && ! $carte->ecart;
    }

    /**
     * Les barres de la carte avant tout détail.
     *
     * @return list<Barre>
     */
    private function barresDeBase(CarteDuTableauDeBord $carte): array
    {
        return DecoupageDesPeriodes::barres($this->reglages->decoupage, $this->reglages->periode, $this->cumulDeBase($carte), $this->aujourdhui, $this->moisDeFinDExercice);
    }

    /**
     * @return list<Barre>
     */
    private function lireLeChemin(Graphique $graphique): array
    {
        $carte = $this->carte($graphique);
        $jetons = $this->details[$graphique->value] ?? [];

        if ($carte === null || ! $graphique->estParPeriode() || $jetons === []) {
            return [];
        }

        $barres = $this->barresDeBase($carte);
        $chemin = [];

        foreach ($jetons as $jeton) {
            $ouverte = null;

            foreach ($barres as $barre) {
                if ($barre->jeton() === $jeton && $barre->peutSeDetailler() && ! $barre->aVenir) {
                    $ouverte = $barre;

                    break;
                }
            }

            if ($ouverte === null) {
                return [];
            }

            $chemin[] = $ouverte;
            $barres = DecoupageDesPeriodes::sousBarres($ouverte, $this->aujourdhui, $this->moisDeFinDExercice);
        }

        return $chemin;
    }

    private function uneCarteEstEnEcart(): bool
    {
        foreach ($this->cartes as $carte) {
            if ($carte->ecart && $carte->graphique->estParPeriode()) {
                return true;
            }
        }

        return false;
    }
}
