<?php

namespace App\Filament;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Services\ChiffresDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\DessinDeLEcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Domain\TableauDeBord\Support\SvgDeLEcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Closure;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Mechanisms\DataStore;
use LogicException;

/**
 * Les chiffres clés et la grille de graphiques du tableau de bord
 * (décision de l'éditeur du 14/11/2026, modèle « G1 + chiffres de G2 »,
 * lot GRAPH) : en haut, les quatre chiffres clés du mois en cours (voir
 * ChiffresDuTableauDeBord) ; puis la période, le magasin (jamais dans le
 * panneau d'un magasin), la comparaison avec l'année précédente et le menu
 * « Ajouter un graphique » ; puis les cartes, chacune avec son menu du
 * type, « Monter », « Descendre », sa croix « Retirer ce graphique », sa
 * ligne grise et « Voir les chiffres », le tableau des mêmes valeurs au
 * centime. Chart.js est celui de Filament (le composant « chart » de
 * filament/widgets), rien ne vient d'un autre site.
 *
 * Le même composant sert les quatre écrans (voir EcranDuTableauDeBord) :
 * l'espace de la société, le panneau d'un magasin (voir
 * GraphiquesDuMagasin), la page d'un client dans l'espace du cabinet (voir
 * GraphiquesDuClient) et la page de l'expert-comptable déclaré (voir
 * TableauDeBordDeLaSociete). L'écran et le lien du client sont figés au
 * montage (#[Locked]) ; tout le reste se relit à chaque requête : le
 * périmètre du compte (voir PerimetreDesGraphiques), qui refuse ce qu'il
 * ne voit pas, et ses préférences en base (voir PreferencesDesGraphiques),
 * que chaque geste vérifie avant de les retenir. Le navigateur, qui peut
 * appeler chaque geste avec les valeurs qu'il veut, n'obtient jamais un
 * graphique, un type, une période ou un magasin hors de ce que le compte
 * peut voir : la valeur est refusée, rien ne change.
 *
 * Chargé en différé (lazy) sur chaque écran, avec un emplacement à la
 * hauteur de la grille en attendant : par le tableau de bord du magasin
 * pour son widget (voir GraphiquesDuMagasin), quand elle paraît à l'écran
 * ; par le paramètre « lazy » de la vue sur les trois autres pages, dès
 * que la page est ouverte (« on-load »). La page s'ouvre sans attendre ses
 * calculs, et un écran qui ne doit pas la montrer ne la monte pas (voir
 * PerimetreDesGraphiques::voitLesGraphiquesDeLaSociete() et
 * voitLesGraphiquesDuClient()).
 *
 * La carte « Écart des prix d'achat » (décision de l'éditeur du 21/11/2026,
 * D76, B6 et B7) n'a pas de canevas : la vue écrit son contenu, avec un
 * `svg` calculé en PHP (voir TextesDeLEcartDesPrixDAchat et
 * SvgDeLEcartDesPrixDAchat). Son menu « Afficher : » (voir
 * changerLAffichage()) se retient comme le type.
 *
 * Les gestes qui ne changent que l'affichage de ce que la page contient déjà
 * ne rappellent ni la base ni le calcul de la grille (décision de l'éditeur
 * du 23/11/2026) : choisir un mois de la carte « Écart des prix d'achat »,
 * ouvrir « Voir les chiffres » ne passent jamais par le serveur ; changer le
 * type d'un graphique, « Monter », « Descendre », la croix, « Classer par »
 * et le menu « Afficher : » se font aussitôt dans la page (voir piliqiGrille()
 * dans graphiques-du-panneau.blade.php), puis le serveur les retient ici, en
 * arrière-plan, sans redessiner la grille : il rend ce qu'il a retenu (voir
 * etat()), ou null quand il refuse, et la page en revient alors à ce qu'il
 * avait retenu en dernier. La page écrit pour cela les deux classements des
 * meilleurs produits, les deux affichages de l'écart des prix d'achat et,
 * par mois, les douze lectures du mois (voir ecartDesPrixDAchat()). Les
 * gestes qui changent ce que la grille calcule (la période, le magasin, la
 * comparaison, un graphique ajouté) redessinent la grille.
 *
 * @phpstan-import-type Lecture from TextesDeLEcartDesPrixDAchat
 * @phpstan-import-type DuMois from TextesDeLEcartDesPrixDAchat
 *
 * @phpstan-type EtatDesCartes array{ordre: list<string>, cartes: array<string, array{type: string, mesure?: string, affichage?: string}>}
 * @phpstan-type Face array{serie: SerieDuGraphique, config: array{titre: string, etiquettes: list<string>, jeux: list<array{libelle: string, valeurs: list<float|int|null>, unite: string, en_ligne: bool, axe_de_droite: bool, pointilles: bool}>, info_bulles: array<int, string>}}
 * @phpstan-type EcartDeLaCarte array{depart: int, parMois: Lecture, surDouzeMois: Lecture, mois: array<int, DuMois>, svg: string, cle: string, titres: array{avant: string, debut: string}}
 */
class GrilleDesGraphiques extends Widget
{
    use GereLesExceptionsMetier;

    /**
     * Le nom du composant, enregistré par AppServiceProvider.
     */
    public const NOM = 'grille-des-graphiques';

    public const REFUS_DE_LA_VALEUR = 'Ce choix n\'existe pas dans le tableau de bord : rien n\'a changé.';

    protected static string $view = 'filament.grille-des-graphiques';

    protected int|string|array $columnSpan = 'full';

    protected ?string $placeholderHeight = '24rem';

    #[Locked]
    public string $ecran = 'magasin';

    #[Locked]
    public ?string $lien = null;

    /**
     * Un geste de cette requête a demandé que la grille soit redessinée : un
     * geste de préférence qui vient après (ou avant) ne l'en empêche pas
     * (voir sansRedessin()). Jamais envoyé à la page.
     */
    private bool $redessine = false;

    /**
     * Le périmètre du compte, relu une seule fois par requête : un geste qui
     * enregistre puis redessine la grille le lisait deux fois (les magasins
     * et la société du compte, deux requêtes de plus), alors que les droits
     * ne changent pas pendant une requête. Jamais envoyé à la page.
     */
    private ?Perimetre $perimetreDeLaRequete = null;

    public function mount(string $ecran = 'magasin', ?string $lien = null): void
    {
        $this->ecran = (EcranDuTableauDeBord::tryFrom($ecran) ?? throw new AuthorizationException(PerimetreDesGraphiques::REFUS))->value;
        $this->lien = $lien;
        $this->perimetre();
    }

    /**
     * Une période du menu de la société (voir
     * PeriodeDuTableauDeBord::duMenu()) : un exercice, que le menu d'une
     * société dont l'exercice finit en décembre ne propose pas, est refusé.
     */
    public function choisirLaPeriode(string $periode): void
    {
        $this->avecRedessin();
        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($periode): ReglagesDuTableauDeBord {
            $choisie = PeriodeDuTableauDeBord::tryFrom($periode);

            if ($choisie === null || ! in_array($choisie, PeriodeDuTableauDeBord::duMenu($perimetre->moisDeFinDExercice()), true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaPeriode($choisie);
        });
    }

    /**
     * Le magasin du filtre, par son uuid, ou « » pour tous les magasins du
     * périmètre ; un magasin hors du périmètre est refusé (voir
     * Perimetre::restreintA()).
     */
    public function choisirLeMagasin(string $magasin): void
    {
        $this->avecRedessin();
        $choisi = $magasin === '' ? null : $magasin;

        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($choisi): ReglagesDuTableauDeBord {
            if (! $perimetre->ecran->montrePlusieursMagasins()) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            $perimetre->restreintA($choisi);

            return $reglages->avecLeMagasin($choisi);
        });
    }

    public function comparer(bool $comparer): void
    {
        $this->avecRedessin();
        $this->modifier(fn (ReglagesDuTableauDeBord $reglages): ReglagesDuTableauDeBord => $reglages->avecLaComparaison($comparer));
    }

    /**
     * Le graphique choisi dans le menu « Ajouter un graphique ». « —
     * Choisir — » n'est jamais une valeur envoyée par le menu (il revient
     * de lui-même sur ce choix et n'envoie rien quand on le choisit) : une
     * valeur vide ne fait rien, sans message ; une valeur inconnue est
     * refusée.
     */
    public function ajouterUnGraphique(string $graphique): void
    {
        $this->avecRedessin();

        if ($graphique === '') {
            return;
        }

        $this->modifier(fn (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre): ReglagesDuTableauDeBord => $reglages->avecLeGraphique($this->graphiqueVisible($graphique, $perimetre)));
    }

    /**
     * Les gestes de préférence : la page a déjà changé l'écran (voir
     * piliqiGrille()), le serveur retient le choix sans redessiner la grille
     * et rend ce qu'il a retenu (voir etat()), ou null quand il refuse (une
     * alerte dit pourquoi) ; la page revient alors à ce qu'il avait retenu.
     *
     * @return EtatDesCartes|null
     */
    public function retirerLeGraphique(string $graphique): ?array
    {
        $this->sansRedessin();

        return $this->modifier(fn (ReglagesDuTableauDeBord $reglages): ReglagesDuTableauDeBord => $reglages->sansLeGraphique(Graphique::tryFrom($graphique) ?? throw new LogicException(self::REFUS_DE_LA_VALEUR)));
    }

    /**
     * @return EtatDesCartes|null
     */
    public function changerLeType(string $graphique, string $type): ?array
    {
        $this->sansRedessin();

        return $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $type): ReglagesDuTableauDeBord {
            $carte = $this->carteAffichee($reglages, $this->graphiqueVisible($graphique, $perimetre));
            $nouveau = TypeDeGraphique::tryFrom($type);

            if ($nouveau === null || ! in_array($nouveau, $carte->graphique->types(), true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaCarte(new CarteDuTableauDeBord($carte->graphique, $nouveau, $carte->mesure, $carte->affichage));
        });
    }

    /**
     * Ce qui classe les meilleurs produits : leur chiffre d'affaires HT ou
     * leur quantité ; les deux classements sont dans la page.
     *
     * @return EtatDesCartes|null
     */
    public function changerLaMesure(string $graphique, string $mesure): ?array
    {
        $this->sansRedessin();

        return $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $mesure): ReglagesDuTableauDeBord {
            $carte = $this->carteAffichee($reglages, $this->graphiqueVisible($graphique, $perimetre));

            if ($carte->graphique !== Graphique::MeilleursProduits || ! in_array($mesure, [CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES, CarteDuTableauDeBord::PAR_QUANTITE], true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaCarte(new CarteDuTableauDeBord($carte->graphique, $carte->type, $mesure, $carte->affichage));
        });
    }

    /**
     * L'affichage de la carte « Écart des prix d'achat » : un total par
     * mois ou un seul total pour les douze mois, retenu comme le type ; les
     * deux affichages sont dans la page, et le mois choisi, qui vit dans la
     * page, est gardé. Un autre graphique, ou une autre valeur, est refusé.
     *
     * @return EtatDesCartes|null
     */
    public function changerLAffichage(string $graphique, string $valeur): ?array
    {
        $this->sansRedessin();

        return $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $valeur): ReglagesDuTableauDeBord {
            $carte = $this->carteAffichee($reglages, $this->graphiqueVisible($graphique, $perimetre));

            if ($carte->graphique !== Graphique::EcartDesPrixDAchat || ! in_array($valeur, [CarteDuTableauDeBord::AFFICHAGE_PAR_MOIS, CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS], true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaCarte(new CarteDuTableauDeBord($carte->graphique, $carte->type, $carte->mesure, $valeur));
        });
    }

    /**
     * @return EtatDesCartes|null
     */
    public function monter(string $graphique): ?array
    {
        return $this->deplacer($graphique, -1);
    }

    /**
     * @return EtatDesCartes|null
     */
    public function descendre(string $graphique): ?array
    {
        return $this->deplacer($graphique, 1);
    }

    /**
     * Ce que la vue montre, calculé à chaque affichage depuis le périmètre
     * et les préférences du compte : les chiffres clés, les filtres, le
     * menu des graphiques à ajouter et chaque carte avec sa série, prête
     * pour Chart.js (en euros) et pour le tableau « Voir les chiffres »
     * (au centime) ; pour les meilleurs produits, les deux classements ; la
     * carte « Écart des prix d'achat », qui n'a pas de canevas, avec ses
     * deux affichages et les douze lectures de ses mois (voir
     * ecartDesPrixDAchat()).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $perimetre = $this->perimetre();
        $reglages = $this->reglages($perimetre);
        $filtre = $perimetre->restreintA($this->magasinDuFiltre($reglages, $perimetre));
        $cartes = $reglages->cartesVisibles($filtre);
        $calcul = new CalculDesSeries($filtre, $reglages, $cartes);
        $series = app(SeriesDesGraphiques::class)->pour($calcul);
        $euros = app(CalculateurLigne::class)->enEuros(...);
        $moisDeFin = $perimetre->moisDeFinDExercice();
        $periodes = PeriodeDuTableauDeBord::duMenu($moisDeFin);
        $aujourdhui = $perimetre->aujourdhui();

        return [
            'chiffres' => app(ChiffresDuTableauDeBord::class)->pour($calcul),
            'euros' => $euros,
            'reglages' => $reglages,
            'magasinChoisi' => $this->magasinDuFiltre($reglages, $perimetre),
            'periodes' => array_combine(
                array_map(fn (PeriodeDuTableauDeBord $periode): string => $periode->value, $periodes),
                array_map(fn (PeriodeDuTableauDeBord $periode): string => $periode->libelle($aujourdhui, $moisDeFin), $periodes),
            ),
            'magasins' => $perimetre->ecran->montrePlusieursMagasins() && $perimetre->magasins()->count() > 1
                ? $perimetre->magasins()->mapWithKeys(fn (Magasin $magasin): array => [(string) $magasin->uuid => Perimetre::nomDuMagasin($magasin)])->all()
                : [],
            'disponibles' => array_map(fn (Graphique $graphique): array => ['valeur' => $graphique->value, 'libelle' => $graphique->getLabel()], $filtre->graphiquesVisibles()),
            'affiches' => array_map(fn (CarteDuTableauDeBord $carte): string => $carte->graphique->value, $cartes),
            'cartes' => array_map(fn (CarteDuTableauDeBord $carte): array => [
                'carte' => $carte,
                'serie' => $series[$carte->graphique->value],
                'faces' => $carte->graphique === Graphique::EcartDesPrixDAchat ? [] : $this->faces($carte, $series[$carte->graphique->value]),
                'ecart' => $carte->graphique === Graphique::EcartDesPrixDAchat ? $this->ecartDesPrixDAchat($series[$carte->graphique->value], $perimetre, $reglages, $calcul->mois) : null,
            ], $cartes),
        ];
    }

    /**
     * Ce que le serveur a retenu des cartes que la grille montre : leur
     * ordre et, pour chacune, son type, sa mesure (les meilleurs produits)
     * et son affichage (l'écart des prix d'achat), tels que la page les relit
     * (voir piliqiGrille()).
     *
     * @return EtatDesCartes
     */
    private function etat(ReglagesDuTableauDeBord $reglages, Perimetre $filtre): array
    {
        $etat = ['ordre' => [], 'cartes' => []];

        foreach ($reglages->cartesVisibles($filtre) as $carte) {
            $etat['ordre'][] = $carte->graphique->value;
            $etat['cartes'][$carte->graphique->value] = ['type' => $carte->type->value]
                + ($carte->graphique === Graphique::MeilleursProduits ? ['mesure' => $carte->mesure] : [])
                + ($carte->graphique === Graphique::EcartDesPrixDAchat ? ['affichage' => $carte->affichage] : []);
        }

        return $etat;
    }

    /**
     * Les faces d'une carte à canevas : une seule, ou, pour les meilleurs
     * produits, leurs deux classements (le chiffre d'affaires d'abord), que
     * la page montre l'un ou l'autre sans rappeler le serveur.
     *
     * @return array<string, Face>
     */
    private function faces(CarteDuTableauDeBord $carte, SerieDuGraphique $serie): array
    {
        if ($carte->graphique !== Graphique::MeilleursProduits) {
            return ['defaut' => ['serie' => $serie, 'config' => self::configuration($carte->graphique, $serie)]];
        }

        $autre = $serie->autreClassement ?? throw new LogicException('Les meilleurs produits portent leurs deux classements.');
        $classements = $carte->parQuantite()
            ? [CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES => $autre, CarteDuTableauDeBord::PAR_QUANTITE => $serie]
            : [CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES => $serie, CarteDuTableauDeBord::PAR_QUANTITE => $autre];

        return array_map(fn (SerieDuGraphique $classement): array => ['serie' => $classement, 'config' => self::configuration($carte->graphique, $classement)], $classements);
    }

    /**
     * Ce que la carte « Écart des prix d'achat » écrit : la lecture de chaque
     * affichage au mois de départ (le dessin, les légendes, la méthode, le
     * tableau « Voir les chiffres », que la page n'écrit qu'une fois), les
     * douze lectures du mois (voir TextesDeLEcartDesPrixDAchat::duMois()),
     * que la donnée Alpine `mois` de la carte montre l'une après l'autre sans
     * appeler le serveur, et le `svg`, écrit une seule fois avec ce que le
     * choix d'un mois change dans chaque colonne ; le menu « Afficher : »
     * montre l'un ou l'autre affichage de la même façon.
     *
     * La clé de la vue de la carte change avec la période, le magasin et le
     * premier mois de la période : la page remet alors le mois choisi au mois
     * de départ.
     *
     * @param  list<string>  $moisDeLaPeriode  les douze mois « AAAA-MM » de la période
     * @return EcartDeLaCarte
     */
    private function ecartDesPrixDAchat(SerieDuGraphique $serie, Perimetre $perimetre, ReglagesDuTableauDeBord $reglages, array $moisDeLaPeriode): array
    {
        $calcul = $serie->ecartDesPrixDAchat ?? throw new LogicException('La série de l\'écart des prix d\'achat porte son calcul.');
        $depart = $this->moisDeDepart($moisDeLaPeriode, $perimetre);
        $parMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, CarteDuTableauDeBord::AFFICHAGE_PAR_MOIS, $depart);
        $surDouzeMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS, $depart);
        $mois = [];

        foreach (range(0, EcartDesPrixDAchat::NOMBRE_DE_MOIS - 1) as $rang) {
            $mois[$rang] = TextesDeLEcartDesPrixDAchat::duMois($calcul, $rang);
        }

        return [
            'depart' => $depart,
            'parMois' => $parMois,
            'surDouzeMois' => $surDouzeMois,
            'mois' => $mois,
            'svg' => SvgDeLEcartDesPrixDAchat::ecrire($parMois['dessin']['enfants'], DessinDeLEcartDesPrixDAchat::ceQueLeChoixChange($calcul), $parMois['dessin']['viewBox'], $parMois['dessin']['ariaLabel']),
            'cle' => implode('|', [$reglages->periode->value, $this->magasinDuFiltre($reglages, $perimetre) ?? 'tous', $moisDeLaPeriode[0]]),
            'titres' => ['avant' => $parMois['titreCarte'], 'debut' => $surDouzeMois['titreCarte']],
        ];
    }

    /**
     * Le mois de la carte au départ : le dernier mois de la période qui n'est
     * pas à venir (le dernier de la période quand elle est passée, le premier
     * quand elle est tout entière à venir).
     *
     * @param  list<string>  $mois  les douze mois « AAAA-MM » de la période
     */
    private function moisDeDepart(array $mois, Perimetre $perimetre): int
    {
        $courant = Mois::duJour($perimetre->aujourdhui());
        $dernier = 0;

        foreach ($mois as $rang => $unMois) {
            if ($unMois <= $courant) {
                $dernier = $rang;
            }
        }

        return $dernier;
    }

    /**
     * Ce que le script des graphiques reçoit pour une face (voir
     * graphiques-du-panneau.blade.php) : les étiquettes, les séries en
     * euros (un nombre reste un nombre) et les précisions des info-bulles ;
     * le type vient de la carte, que la page change sans le serveur.
     *
     * @return array{titre: string, etiquettes: list<string>, jeux: list<array{libelle: string, valeurs: list<float|int|null>, unite: string, en_ligne: bool, axe_de_droite: bool, pointilles: bool}>, info_bulles: array<int, string>}
     */
    public static function configuration(Graphique $graphique, SerieDuGraphique $serie): array
    {
        return [
            'titre' => $graphique->getLabel(),
            'etiquettes' => $serie->etiquettes,
            'jeux' => array_map(fn (array $jeu): array => [
                ...$jeu,
                'valeurs' => array_map(fn (?int $valeur): float|int|null => $valeur === null || $jeu['unite'] === SerieDuGraphique::NOMBRE ? $valeur : $valeur / 100, $jeu['valeurs']),
            ], $serie->jeux),
            'info_bulles' => $serie->infoBulles,
        ];
    }

    /**
     * Le périmètre de l'écran pour le compte connecté, relu à chaque
     * requête (voir $perimetreDeLaRequete) ; refusé quand il n'y voit aucun
     * graphique.
     *
     * @throws AuthorizationException
     */
    protected function perimetre(): Perimetre
    {
        return $this->perimetreDeLaRequete ??= $this->lirePerimetre();
    }

    /**
     * @throws AuthorizationException
     */
    private function lirePerimetre(): Perimetre
    {
        $compte = CompteConnecte::exiger();
        $graphiques = app(PerimetreDesGraphiques::class);
        $tenant = Filament::getTenant();

        return match (EcranDuTableauDeBord::from($this->ecran)) {
            EcranDuTableauDeBord::Societe => $tenant instanceof Societe ? $graphiques->deLaSociete($compte, $tenant) : throw new AuthorizationException(PerimetreDesGraphiques::REFUS),
            EcranDuTableauDeBord::Magasin => $tenant instanceof Magasin ? $graphiques->duMagasin($compte, $tenant) : throw new AuthorizationException(PerimetreDesGraphiques::REFUS),
            EcranDuTableauDeBord::Cabinet => $graphiques->duClient($compte, $this->lienDuClient()),
            EcranDuTableauDeBord::ExpertComptable => $graphiques->delExpertComptable($compte),
        };
    }

    private function lienDuClient(): LienCabinet
    {
        return ($this->lien === null ? null : app(AccesDesCabinets::class)->liensOuverts(CompteConnecte::exiger())->parUuid($this->lien)->first())
            ?? throw new AuthorizationException(PerimetreDesGraphiques::REFUS);
    }

    private function reglages(Perimetre $perimetre): ReglagesDuTableauDeBord
    {
        return app(PreferencesDesGraphiques::class)->lire(CompteConnecte::exiger(), $perimetre);
    }

    /**
     * Le magasin du filtre retenu, s'il est encore du périmètre ; sinon
     * tous ses magasins. Jamais dans le panneau d'un magasin.
     */
    private function magasinDuFiltre(ReglagesDuTableauDeBord $reglages, Perimetre $perimetre): ?string
    {
        if (! $perimetre->ecran->montrePlusieursMagasins() || $reglages->magasin === null) {
            return null;
        }

        return $perimetre->magasins()->contains(fn (Magasin $magasin): bool => (string) $magasin->uuid === $reglages->magasin) ? $reglages->magasin : null;
    }

    private function graphiqueVisible(string $graphique, Perimetre $perimetre): Graphique
    {
        $choisi = Graphique::tryFrom($graphique);

        if ($choisi === null || ! $perimetre->voit($choisi)) {
            throw new LogicException(self::REFUS_DE_LA_VALEUR);
        }

        return $choisi;
    }

    private function carteAffichee(ReglagesDuTableauDeBord $reglages, Graphique $graphique): CarteDuTableauDeBord
    {
        return $reglages->carte($graphique) ?? throw new LogicException(self::REFUS_DE_LA_VALEUR);
    }

    /**
     * « Monter » ou « Descendre » : la carte change de rang parmi celles
     * que la grille montre, sur le magasin du filtre (voir
     * ReglagesDuTableauDeBord::avecLaCarteDeplacee()).
     *
     * @return EtatDesCartes|null
     */
    private function deplacer(string $graphique, int $sens): ?array
    {
        $this->sansRedessin();

        return $this->modifier(fn (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre): ReglagesDuTableauDeBord => $reglages->avecLaCarteDeplacee(
            Graphique::tryFrom($graphique) ?? throw new LogicException(self::REFUS_DE_LA_VALEUR),
            $sens,
            $perimetre->restreintA($this->magasinDuFiltre($reglages, $perimetre)),
        ));
    }

    /**
     * Ce geste change ce que la grille calcule : elle est redessinée, même si
     * un geste de préférence de la même requête, avant ou après, ne la
     * redessine pas (Livewire saute le dessin de toute la requête dès qu'un
     * appel le demande : sansRedessin() ne le demande donc que si aucun
     * geste ne veut le dessin).
     */
    private function avecRedessin(): void
    {
        $this->redessine = true;
        app(DataStore::class)->set($this, 'skipRender', false);
    }

    /**
     * Ce geste de préférence n'a pas besoin du dessin de la grille : la page
     * a déjà changé l'écran (voir piliqiGrille()).
     */
    private function sansRedessin(): void
    {
        if (! $this->redessine) {
            $this->skipRender();
        }
    }

    /**
     * Relit le périmètre et les préférences, applique le geste et retient
     * le résultat ; une valeur refusée s'affiche en alerte, rien ne change.
     * Rend ce que le serveur a retenu des cartes, ou null quand le geste est
     * refusé.
     *
     * @param  Closure(ReglagesDuTableauDeBord, Perimetre): ReglagesDuTableauDeBord  $geste
     * @return EtatDesCartes|null
     */
    private function modifier(Closure $geste): ?array
    {
        $etat = null;

        static::executerSansCasser(function () use ($geste, &$etat): void {
            $perimetre = $this->perimetre();
            $reglages = $geste($this->reglages($perimetre), $perimetre);
            app(PreferencesDesGraphiques::class)->enregistrer(CompteConnecte::exiger(), $perimetre->ecran, $reglages);
            $etat = $this->etat($reglages, $perimetre->restreintA($this->magasinDuFiltre($reglages, $perimetre)));
        });

        return $etat;
    }
}
