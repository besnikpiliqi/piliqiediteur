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
 * changerLAffichage()) se retient comme le type ; le mois qu'on y choisit
 * (voir choisirLeMois()) vit dans l'écran, le temps de la page, sans rien
 * écrire en base, et revient au dernier mois de la période qui n'est pas à
 * venir quand la période ou le magasin change. Seuls le calcul de
 * l'affichage choisi et celui du mois choisi sont écrits dans la page.
 *
 * @phpstan-import-type Lecture from TextesDeLEcartDesPrixDAchat
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
     * Le mois (de 0 à 11) choisi dans la carte « Écart des prix d'achat »,
     * par graphique ; vide tant que personne n'a cliqué sur un mois. Une
     * valeur hors de 0 à 11, que le navigateur peut forger, est ignorée
     * (voir moisDeLaCarte()).
     *
     * @var array<string, int>
     */
    public array $moisChoisis = [];

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
        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($periode): ReglagesDuTableauDeBord {
            $choisie = PeriodeDuTableauDeBord::tryFrom($periode);

            if ($choisie === null || ! in_array($choisie, PeriodeDuTableauDeBord::duMenu($perimetre->moisDeFinDExercice()), true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            $this->moisChoisis = [];

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
        $choisi = $magasin === '' ? null : $magasin;

        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($choisi): ReglagesDuTableauDeBord {
            if (! $perimetre->ecran->montrePlusieursMagasins()) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            $perimetre->restreintA($choisi);
            $this->moisChoisis = [];

            return $reglages->avecLeMagasin($choisi);
        });
    }

    public function comparer(bool $comparer): void
    {
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
        if ($graphique === '') {
            return;
        }

        $this->modifier(fn (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre): ReglagesDuTableauDeBord => $reglages->avecLeGraphique($this->graphiqueVisible($graphique, $perimetre)));
    }

    public function retirerLeGraphique(string $graphique): void
    {
        $this->modifier(fn (ReglagesDuTableauDeBord $reglages): ReglagesDuTableauDeBord => $reglages->sansLeGraphique(Graphique::tryFrom($graphique) ?? throw new LogicException(self::REFUS_DE_LA_VALEUR)));
    }

    public function changerLeType(string $graphique, string $type): void
    {
        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $type): ReglagesDuTableauDeBord {
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
     * leur quantité.
     */
    public function changerLaMesure(string $graphique, string $mesure): void
    {
        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $mesure): ReglagesDuTableauDeBord {
            $carte = $this->carteAffichee($reglages, $this->graphiqueVisible($graphique, $perimetre));

            if ($carte->graphique !== Graphique::MeilleursProduits || ! in_array($mesure, [CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES, CarteDuTableauDeBord::PAR_QUANTITE], true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaCarte(new CarteDuTableauDeBord($carte->graphique, $carte->type, $mesure, $carte->affichage));
        });
    }

    /**
     * L'affichage de la carte « Écart des prix d'achat » : un total par
     * mois ou un seul total pour les douze mois, retenu comme le type ; le
     * mois choisi est gardé. Un autre graphique, ou une autre valeur, est
     * refusé.
     */
    public function changerLAffichage(string $graphique, string $valeur): void
    {
        $this->modifier(function (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre) use ($graphique, $valeur): ReglagesDuTableauDeBord {
            $carte = $this->carteAffichee($reglages, $this->graphiqueVisible($graphique, $perimetre));

            if ($carte->graphique !== Graphique::EcartDesPrixDAchat || ! in_array($valeur, [CarteDuTableauDeBord::AFFICHAGE_PAR_MOIS, CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS], true)) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            return $reglages->avecLaCarte(new CarteDuTableauDeBord($carte->graphique, $carte->type, $carte->mesure, $valeur));
        });
    }

    /**
     * Le mois (de 0 à 11, 0 étant le premier de la période) que la carte
     * « Écart des prix d'achat » montre, par un clic, Entrée ou Espace sur
     * sa colonne : un geste de l'écran, jamais retenu en base. Un autre
     * graphique, un graphique que le compte ne voit pas ou qui n'est pas
     * affiché, un mois hors de 0 à 11 sont refusés, rien ne change.
     */
    public function choisirLeMois(string $graphique, int $mois): void
    {
        static::executerSansCasser(function () use ($graphique, $mois): void {
            $perimetre = $this->perimetre();
            $carte = $this->carteAffichee($this->reglages($perimetre), $this->graphiqueVisible($graphique, $perimetre));

            if ($carte->graphique !== Graphique::EcartDesPrixDAchat || $mois < 0 || $mois >= EcartDesPrixDAchat::NOMBRE_DE_MOIS) {
                throw new LogicException(self::REFUS_DE_LA_VALEUR);
            }

            $this->moisChoisis[$carte->graphique->value] = $mois;
        });
    }

    public function monter(string $graphique): void
    {
        $this->deplacer($graphique, -1);
    }

    public function descendre(string $graphique): void
    {
        $this->deplacer($graphique, 1);
    }

    /**
     * Ce que la vue montre, calculé à chaque affichage depuis le périmètre
     * et les préférences du compte : les chiffres clés, les filtres, le
     * menu des graphiques à ajouter et chaque carte avec sa série, prête
     * pour Chart.js (en euros) et pour le tableau « Voir les chiffres »
     * (au centime) ; la carte « Écart des prix d'achat », qui n'a pas de
     * canevas, avec sa lecture, son `svg` par mois et son mois choisi
     * (voir ecartDesPrixDAchat()).
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
        $affiches = array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, $cartes);
        $aujourdhui = $perimetre->aujourdhui();
        $moisDeFin = $perimetre->moisDeFinDExercice();
        $periodes = PeriodeDuTableauDeBord::duMenu($moisDeFin);

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
            'aAjouter' => array_values(array_filter($filtre->graphiquesVisibles(), fn (Graphique $graphique): bool => ! in_array($graphique, $affiches, true))),
            'cartes' => array_map(fn (CarteDuTableauDeBord $carte): array => [
                'carte' => $carte,
                'serie' => $series[$carte->graphique->value],
                'config' => $carte->graphique === Graphique::EcartDesPrixDAchat ? null : self::configuration($carte, $series[$carte->graphique->value]),
                'ecart' => $carte->graphique === Graphique::EcartDesPrixDAchat ? $this->ecartDesPrixDAchat($carte, $series[$carte->graphique->value], $perimetre, $calcul->mois) : null,
            ], $cartes),
        ];
    }

    /**
     * Ce que la carte « Écart des prix d'achat » écrit : sa lecture (voir
     * TextesDeLEcartDesPrixDAchat::lecture()) pour l'affichage retenu et le
     * mois choisi, et, par mois seulement, son `svg`. Le dessin de
     * l'affichage caché n'est pas écrit dans la page.
     *
     * @param  list<string>  $moisDeLaPeriode  les douze mois « AAAA-MM » de la période
     * @return array{lecture: Lecture, svg: string|null, mois: int}
     */
    private function ecartDesPrixDAchat(CarteDuTableauDeBord $carte, SerieDuGraphique $serie, Perimetre $perimetre, array $moisDeLaPeriode): array
    {
        $calcul = $serie->ecartDesPrixDAchat ?? throw new LogicException('La série de l\'écart des prix d\'achat porte son calcul.');
        $mois = $this->moisDeLaCarte($carte->graphique, $moisDeLaPeriode, $perimetre);
        $lecture = TextesDeLEcartDesPrixDAchat::lecture($calcul, $carte->affichage, $mois);

        return [
            'lecture' => $lecture,
            'svg' => $carte->surDouzeMois() ? null : SvgDeLEcartDesPrixDAchat::ecrire($lecture['dessin']['enfants'], $lecture['dessin']['viewBox'], $lecture['dessin']['ariaLabel'], $carte->graphique->value),
            'mois' => $mois,
        ];
    }

    /**
     * Le mois choisi de la carte : celui qu'on a cliqué s'il est de 0 à 11,
     * sinon le dernier mois de la période qui n'est pas à venir (le dernier
     * de la période quand elle est passée, le premier quand elle est tout
     * entière à venir).
     *
     * @param  list<string>  $mois  les douze mois « AAAA-MM » de la période
     */
    private function moisDeLaCarte(Graphique $graphique, array $mois, Perimetre $perimetre): int
    {
        $choisi = $this->moisChoisis[$graphique->value] ?? null;

        if (is_int($choisi) && $choisi >= 0 && $choisi < EcartDesPrixDAchat::NOMBRE_DE_MOIS) {
            return $choisi;
        }

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
     * Ce que le script des graphiques reçoit pour une carte (voir
     * graphiques-du-tableau-de-bord.blade.php) : le type, les étiquettes,
     * les séries en euros (un nombre reste un nombre) et les précisions des
     * info-bulles.
     *
     * @return array{type: string, titre: string, etiquettes: list<string>, jeux: list<array{libelle: string, valeurs: list<float|int|null>, unite: string, en_ligne: bool, axe_de_droite: bool, pointilles: bool}>, info_bulles: array<int, string>}
     */
    public static function configuration(CarteDuTableauDeBord $carte, SerieDuGraphique $serie): array
    {
        return [
            'type' => $carte->type->value,
            'titre' => $carte->graphique->getLabel(),
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
     * requête ; refusé quand il n'y voit aucun graphique.
     *
     * @throws AuthorizationException
     */
    protected function perimetre(): Perimetre
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
     */
    private function deplacer(string $graphique, int $sens): void
    {
        $this->modifier(fn (ReglagesDuTableauDeBord $reglages, Perimetre $perimetre): ReglagesDuTableauDeBord => $reglages->avecLaCarteDeplacee(
            Graphique::tryFrom($graphique) ?? throw new LogicException(self::REFUS_DE_LA_VALEUR),
            $sens,
            $perimetre->restreintA($this->magasinDuFiltre($reglages, $perimetre)),
        ));
    }

    /**
     * Relit le périmètre et les préférences, applique le geste et retient
     * le résultat ; une valeur refusée s'affiche en alerte, rien ne change.
     *
     * @param  Closure(ReglagesDuTableauDeBord, Perimetre): ReglagesDuTableauDeBord  $geste
     */
    private function modifier(Closure $geste): void
    {
        static::executerSansCasser(function () use ($geste): void {
            $perimetre = $this->perimetre();
            app(PreferencesDesGraphiques::class)->enregistrer(CompteConnecte::exiger(), $perimetre->ecran, $geste($this->reglages($perimetre), $perimetre));
        });
    }
}
