<?php

namespace Tests\Browser\Support;

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Models\User;

/**
 * Ce que les tests en navigateur des graphiques par mois, par trimestre ou
 * par année partagent (choix de l'éditeur du 16/11/2026, lot GRAPH-AN) : les
 * cartes à afficher, ce que la page dit de la barre des filtres, d'une carte
 * et de son dessin, et les gestes sur ses barres. Rien ne se calcule ici :
 * chaque lecture est celle du dessin vivant de la page, tel que Livewire et
 * Alpine l'ont écrit.
 */
final class CartesParPeriode
{
    /** La carte que les tests lisent le plus. */
    public const CARTE = 'ca_par_mois';

    /**
     * Les cartes par période, plus « Ventes par vendeur » et la valeur du
     * stock : retenues en base pour ce compte sur cet écran, comme s'il les
     * avait ajoutées.
     */
    public static function afficherLesCartesParPeriode(User $compte, EcranDuTableauDeBord $ecran): void
    {
        app(PreferencesDesGraphiques::class)->enregistrer($compte, $ecran, new ReglagesDuTableauDeBord(
            array_map(
                fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique),
                array_values(array_filter(Graphique::cases(), fn (Graphique $graphique): bool => $graphique->estParPeriode() || $graphique === Graphique::VentesParVendeur)),
            ),
        ));
    }

    public static function carte(string $graphique = self::CARTE): string
    {
        return '[data-graphique="'.$graphique.'"]';
    }

    /**
     * Choisit cette valeur dans un menu de la barre des filtres, puis attend
     * le serveur.
     */
    public static function choisir(Navigation $onglet, string $filtre, string $valeur): void
    {
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="'.$filtre.'"]')->selectOption($valeur), "le choix « {$valeur} » du menu {$filtre}");
    }

    /**
     * Coche ou décoche la case de la barre des filtres ou de la carte
     * (l'attribut `data-filtre` ou `data-geste` la désigne), puis attend le
     * serveur.
     */
    public static function basculer(Navigation $onglet, string $selecteur, string $description): void
    {
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($selecteur)->click(), $description);
    }

    /**
     * Les colonnes du dessin de la carte : leur rang, ce qu'elles disent à
     * un lecteur d'écran, leur rôle (« button » quand la barre se clique,
     * « img » sinon) et si elle porte les gestes.
     *
     * @return list<array{rang: int, libelle: string, role: string, clic: bool}>
     */
    public static function colonnes(Navigation $onglet, string $graphique = self::CARTE): array
    {
        $colonnes = $onglet->page()->evaluate('() => Array.from(document.querySelectorAll(\''.self::carte($graphique).' svg[data-periode="dessin"] g.colonne\')).map((g) => ({ rang: Number(g.dataset.rang), libelle: g.getAttribute("aria-label"), role: g.getAttribute("role"), clic: g.classList.contains("clic") }))');

        return is_array($colonnes) ? array_values($colonnes) : [];
    }

    /**
     * Ce que chaque colonne dit, sans ce qui suit « : » (l'intitulé de la
     * barre).
     *
     * @return list<string>
     */
    public static function intitules(Navigation $onglet, string $graphique = self::CARTE): array
    {
        return array_map(fn (array $colonne): string => trim(explode(' : ', $colonne['libelle'], 2)[0]), self::colonnes($onglet, $graphique));
    }

    /**
     * Les étiquettes du bas du dessin (« janv. », « 2026 », « T1 2026 »),
     * sans les graduations ni les « – » de l'écart.
     *
     * @return list<string>
     */
    public static function etiquettes(Navigation $onglet, string $graphique = self::CARTE): array
    {
        $textes = $onglet->page()->evaluate('() => Array.from(document.querySelectorAll(\''.self::carte($graphique).' svg[data-periode="dessin"] text[text-anchor="middle"]:not(.tiret)\')).map((texte) => texte.textContent.trim())');

        return is_array($textes) ? array_values(array_map(strval(...), $textes)) : [];
    }

    /**
     * La colonne de cette barre : celle dont l'intitulé commence ainsi.
     */
    public static function colonne(string $intitule, string $graphique = self::CARTE): string
    {
        return self::carte($graphique).' svg[data-periode="dessin"] g.colonne[aria-label^="'.$intitule.' :"]';
    }

    /**
     * Clique sur la barre de cet intitulé, puis attend le serveur.
     */
    public static function cliquerLaBarre(Navigation $onglet, string $intitule, string $graphique = self::CARTE): void
    {
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element(self::colonne($intitule, $graphique))->click(), "le clic sur la barre « {$intitule} »");
    }

    /**
     * Découpe la carte par année, ouvre 2026, mars 2026, puis le jeudi 5
     * mars 2026, dont la fenêtre de la liste des documents s'ouvre (voir
     * SemeLesVentesDeTroisAnnees).
     */
    public static function ouvrirLaListeDuCinqMars(Navigation $onglet): void
    {
        $carte = self::carte();

        self::choisir($onglet, 'decoupage', 'annee');
        self::cliquerLaBarre($onglet, 'Année 2026');
        $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 12', 'l\'année 2026 ne se redécoupe pas en douze mois');
        self::cliquerLaBarre($onglet, 'Mars 2026');
        $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 31', 'mars 2026 ne se redécoupe pas en ses 31 jours');
        self::cliquerLaBarre($onglet, 'Jeudi 5 mars 2026');
        $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour][open]") !== null', 'la liste du 5 mars 2026 ne s\'ouvre pas');
    }

    /**
     * Ce que dit la fenêtre de la liste d'un jour : pour chaque magasin, son
     * nom (null quand la fenêtre n'en écrit pas) et ses listes, avec leur
     * nombre et leur total, ainsi que l'adresse de chaque lien.
     *
     * @return list<array{nom: string|null, listes: list<string>, liens: list<string>}>
     */
    public static function listeDuJour(Navigation $onglet): array
    {
        $magasins = $onglet->page()->evaluate(<<<'JS'
            () => Array.from(document.querySelectorAll('[data-liste-du-magasin]')).map((magasin) => ({
                nom: magasin.querySelector('h3') === null ? null : magasin.querySelector('h3').textContent.trim(),
                listes: Array.from(magasin.querySelectorAll('tbody tr')).map((ligne) => ligne.textContent.replace(/\s+/g, ' ').trim()),
                liens: Array.from(magasin.querySelectorAll('a[data-liste-lien]')).map((lien) => lien.getAttribute('href')),
            }))
            JS);

        return is_array($magasins) ? array_values($magasins) : [];
    }

    /**
     * Le nombre d'éléments de la page qui répondent à ce sélecteur.
     */
    public static function nombre(Navigation $onglet, string $selecteur): int
    {
        $nombre = $onglet->page()->evaluate('() => document.querySelectorAll('.json_encode($selecteur, JSON_THROW_ON_ERROR).').length');

        return is_int($nombre) ? $nombre : (int) (is_numeric($nombre) ? $nombre : 0);
    }

    /**
     * Le texte de cet élément de la page, ou null quand il n'y est pas.
     */
    public static function texte(Navigation $onglet, string $selecteur): ?string
    {
        $texte = $onglet->page()->evaluate('() => { const element = document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).'); return element === null ? null : element.textContent.replace(/\s+/g, " ").trim(); }');

        return is_string($texte) ? $texte : null;
    }

    /**
     * La couleur calculée de cet élément (« color », « fill »…), dans le
     * mode où la page est.
     */
    public static function couleur(Navigation $onglet, string $selecteur, string $propriete = 'color'): ?string
    {
        $couleur = $onglet->page()->evaluate('() => { const element = document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).'); return element === null ? null : getComputedStyle(element)['.json_encode($propriete, JSON_THROW_ON_ERROR).']; }');

        return is_string($couleur) ? $couleur : null;
    }

    /**
     * Met la page en mode clair ou sombre (la classe dark sur <html>, comme
     * le sélecteur de thème de Filament), sans transition qui fausserait les
     * couleurs lues.
     */
    public static function mettreEnMode(Navigation $onglet, bool $sombre): void
    {
        $onglet->page()->evaluate(<<<'JS'
            (sombre) => {
                if (! document.getElementById('piliqi-sans-transition')) {
                    const feuille = document.createElement('style');
                    feuille.id = 'piliqi-sans-transition';
                    feuille.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
                    document.head.append(feuille);
                }

                document.documentElement.classList.toggle('dark', sombre);
            }
            JS, $sombre);
    }

    /**
     * Ce que la barre des filtres dit de sa mise en page : pour chaque
     * commande, la position de son libellé et de son menu ou de sa case.
     *
     * @return list<array{nom: string, haut: float, gauche: float, droite: float, libelleHaut: float, libelleGauche: float, libelleDroite: float, commandeHaut: float, commandeGauche: float, commandeDroite: float, menu: bool}>
     */
    public static function barreDesFiltres(Navigation $onglet): array
    {
        $commandes = $onglet->page()->evaluate(<<<'JS'
            () => Array.from(document.querySelectorAll('[data-filtres] > label')).map((etiquette) => {
                const menu = etiquette.querySelector('select') !== null;
                const commande = (menu ? etiquette.querySelector('.fi-input-wrp') : etiquette.querySelector('input')).getBoundingClientRect();
                const libelle = etiquette.querySelector('span').getBoundingClientRect();
                const boite = etiquette.getBoundingClientRect();

                return {
                    nom: etiquette.querySelector('span').textContent.trim(),
                    haut: boite.top, gauche: boite.left, droite: boite.right,
                    libelleHaut: libelle.top, libelleGauche: libelle.left, libelleDroite: libelle.right,
                    commandeHaut: commande.top, commandeGauche: commande.left, commandeDroite: commande.right,
                    menu,
                };
            })
            JS);

        return is_array($commandes) ? array_values($commandes) : [];
    }

    /**
     * Les défauts de débordement de la page et des cartes : « la page
     * défile de côté », « la carte déborde ».
     *
     * @return list<string>
     */
    public static function debordements(Navigation $onglet): array
    {
        $defauts = $onglet->page()->evaluate(<<<'JS'
            () => {
                const defauts = [];

                if (document.documentElement.scrollWidth > window.innerWidth + 1) {
                    defauts.push('la page défile de côté (' + document.documentElement.scrollWidth + ' px pour ' + window.innerWidth + ')');
                }

                for (const carte of document.querySelectorAll('[data-graphique]')) {
                    if (carte.scrollWidth > carte.clientWidth + 1) {
                        defauts.push('la carte ' + carte.dataset.graphique + ' déborde');
                    }
                }

                return defauts;
            }
            JS);

        return array_values(array_filter(is_array($defauts) ? $defauts : [], is_string(...)));
    }
}
