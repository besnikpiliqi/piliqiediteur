<?php

namespace Tests\Browser\Support;

use Filament\Actions\MountableAction;
use Filament\Support\Components\ComponentManager;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Assert;

/**
 * Le balayage d'un panneau par un compte connecté : depuis une page, il
 * ouvre chaque page que ses liens atteignent dans les préfixes donnés (une
 * seule fiche par sorte d'adresse), et, sur chacune, chaque action visible,
 * comme une personne : il ouvre chaque menu d'actions, clique sur chaque
 * action (une seule fois par composant et par action, la première ligne
 * d'un tableau pour ses actions de ligne, la première ligne cochée pour
 * ses actions groupées), cherche dans le tableau que montre la fenêtre
 * ouverte, s'il y en a un, puis la ferme sans la valider ; il clique sur
 * chaque onglet, change chaque filtre puis remet la page dans son état,
 * et cherche dans chaque tableau. Les liens d'une page se relèvent avant
 * ces gestes. Après chaque geste, l'onglet vérifie qu'aucune erreur n'est
 * apparue (voir Navigation::verifier()).
 *
 * Rien de ce que le balayage ouvre n'écrit : pendant le balayage, chaque
 * action de Filament demande une confirmation (requiresConfirmation()),
 * si bien qu'une action qui s'exécuterait d'un clic ouvre elle aussi une
 * fenêtre, que le balayage ferme ; un lien qui est une action se suit
 * comme tout lien.
 */
final class Balayeur
{
    /**
     * Les adresses qui ne s'ouvrent pas comme une page : la déconnexion
     * (un formulaire), et un téléchargement.
     */
    private const ADRESSES_EXCLUES = ['/logout', '/pdf', '/telecharger', '/export'];

    /**
     * Les sortes d'adresses déjà balayées.
     *
     * @var array<string, true>
     */
    private array $sortesVues = [];

    /**
     * Les actions déjà ouvertes, par composant.
     *
     * @var array<string, true>
     */
    private array $actionsVues = [];

    /**
     * Les pages balayées, dans l'ordre.
     *
     * @var list<string>
     */
    private array $pages = [];

    public function __construct(
        private readonly Navigation $onglet,
        private readonly CouvertureDuBalayage $couverture,
    ) {
        app(ComponentManager::class)->configureUsing(MountableAction::class, fn (MountableAction $action): MountableAction => $action->requiresConfirmation(), isImportant: true);
    }

    /**
     * Balaye chaque page atteignable depuis ce chemin dont l'adresse
     * commence par l'un de ces préfixes ; rend les pages balayées.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    public function balayer(string $depart, array $prefixes): array
    {
        $aVisiter = [$depart];

        while ($aVisiter !== []) {
            $chemin = array_shift($aVisiter);
            $sorte = $this->sorte($chemin, $prefixes);

            if (isset($this->sortesVues[$sorte])) {
                continue;
            }

            $this->sortesVues[$sorte] = true;
            $this->onglet->aller($chemin);
            $this->pages[] = $chemin;

            foreach ($this->liens($prefixes) as $lien) {
                if (! isset($this->sortesVues[$this->sorte($lien, $prefixes)])) {
                    $aVisiter[] = $lien;
                }
            }

            $this->ouvrirLesActions($chemin);
        }

        return $this->pages;
    }

    /**
     * Les liens visibles de la page vers les préfixes donnés, sans requête
     * ni ancre, hors adresses exclues.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function liens(array $prefixes): array
    {
        $liens = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('a[href]')]
                .filter((lien) => lien.offsetParent !== null && lien.target !== '_blank' && ! lien.hasAttribute('download'))
                .map((lien) => new URL(lien.href, location.href))
                .filter((adresse) => adresse.origin === location.origin)
                .map((adresse) => adresse.pathname)
        JS);

        return array_values(array_unique(array_filter(
            is_array($liens) ? $liens : [],
            fn (string $lien): bool => $this->dansLesPrefixes($lien, $prefixes) && ! $this->exclue($lien),
        )));
    }

    /**
     * La sorte d'une adresse : son préfixe gardé tel quel, puis chaque
     * identifiant (un nombre, un uuid) remplacé par une étoile ; une seule
     * fiche de chaque sorte se balaye.
     *
     * @param  list<string>  $prefixes
     */
    private function sorte(string $chemin, array $prefixes): string
    {
        foreach ($prefixes as $prefixe) {
            if (str_starts_with($chemin, $prefixe)) {
                return $prefixe.preg_replace('#/(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?=/|$)#i', '/*', substr($chemin, strlen($prefixe)));
            }
        }

        return $chemin;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function dansLesPrefixes(string $chemin, array $prefixes): bool
    {
        foreach ($prefixes as $prefixe) {
            if ($chemin === $prefixe || str_starts_with($chemin, rtrim($prefixe, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function exclue(string $chemin): bool
    {
        foreach (self::ADRESSES_EXCLUES as $exclue) {
            if (str_contains($chemin, $exclue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ouvre chaque onglet, chaque action, chaque filtre et la recherche de
     * cette page, puis la remet dans son état.
     */
    private function ouvrirLesActions(string $chemin): void
    {
        $composant = $this->composantDeLaPage();
        $this->cliquerSurLesOnglets($composant);
        $this->cocherLaPremiereLigne();

        while (($action = $this->prochaineAction($composant)) !== null) {
            $this->actionsVues[$composant.'|'.$action['cle']] = true;
            $this->ouvrir($action['clic']);

            if ($this->onglet->fenetreOuverte()) {
                $this->chercherDansLaFenetre();
                $this->onglet->fermerLaFenetre();
            }

            if ($this->onglet->chemin() !== $chemin) {
                $this->onglet->aller($chemin);
            }

            $this->fermerLesMenus();
            $this->cocherLaPremiereLigne();
        }

        $this->changerLesFiltres($chemin);
        $this->chercher();
    }

    /**
     * Le nom Livewire du composant principal de la page.
     */
    private function composantDeLaPage(): string
    {
        return (string) $this->onglet->page()->evaluate(<<<'JS'
            () => {
                const racine = document.querySelector('.fi-main [wire\\:snapshot], main [wire\\:snapshot], [wire\\:snapshot]');

                return racine ? JSON.parse(racine.getAttribute('wire:snapshot')).memo.name : '';
            }
        JS);
    }

    /**
     * La prochaine action de la page à ouvrir, visible ou dans un menu
     * d'actions visible, hors fenêtre et hors menus du panneau (compte,
     * magasin, notifications) : son appel (wire:click) et sa clé, sans
     * l'enregistrement d'une action de ligne ; null quand toutes ont été
     * ouvertes.
     *
     * @return array{clic: string, cle: string}|null
     */
    private function prochaineAction(string $composant): ?array
    {
        $actions = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('[wire\\:click]')]
                .filter((element) => /^mount(Table|TableBulk|FormComponent|Infolist)?Action\(/.test(element.getAttribute('wire:click')))
                .filter((element) => ! element.closest('.fi-modal, .fi-topbar, .fi-sidebar, .fi-user-menu, .fi-tenant-menu'))
                .filter((element) => {
                    if (element.offsetParent !== null) {
                        return ! element.disabled;
                    }

                    const menu = element.closest('.fi-dropdown');
                    const declencheur = menu ? menu.querySelector('.fi-dropdown-trigger') : null;

                    return declencheur !== null && declencheur.offsetParent !== null && ! element.disabled;
                })
                .map((element) => element.getAttribute('wire:click'))
        JS);

        foreach (is_array($actions) ? $actions : [] as $clic) {
            $cle = (string) preg_replace("/^(mountTableAction\\('[^']*')(?:, ?'[^']*')?/", '$1', $clic);

            if (! isset($this->actionsVues[$composant.'|'.$cle])) {
                return ['clic' => $clic, 'cle' => $cle];
            }
        }

        return null;
    }

    /**
     * Clique sur l'élément de cet appel, en ouvrant d'abord son menu
     * d'actions s'il y est rangé.
     */
    private function ouvrir(string $clic): void
    {
        $repere = $this->onglet->page()->evaluate(<<<'JS'
            (clic) => {
                document.querySelectorAll('[data-balayage]').forEach((element) => element.removeAttribute('data-balayage'));
                const element = [...document.querySelectorAll('[wire\\:click]')].find((candidat) => candidat.getAttribute('wire:click') === clic && ! candidat.closest('.fi-modal'));

                if (! element) {
                    return 'absent';
                }

                element.setAttribute('data-balayage', 'action');

                if (element.offsetParent !== null) {
                    return 'visible';
                }

                element.closest('.fi-dropdown').querySelector('.fi-dropdown-trigger').setAttribute('data-balayage', 'menu');

                return 'menu';
            }
        JS, $clic);

        Assert::assertNotSame('absent', $repere, "L'action {$clic} a disparu de la page avant le clic.");

        if ($repere === 'menu') {
            $this->onglet->cliquer('[data-balayage="menu"]');
        }

        $this->onglet->cliquer('[data-balayage="action"]');
    }

    /**
     * Coche la première ligne du tableau de la page, pour faire paraître
     * ses actions groupées.
     */
    private function cocherLaPremiereLigne(): void
    {
        $case = $this->onglet->page()->locator('.fi-ta-record-checkbox');

        if ($case->count() > 0 && ! $case->first()->isChecked()) {
            $this->onglet->geste(fn () => $case->first()->check());
        }
    }

    /**
     * Ferme un menu d'actions resté ouvert.
     */
    private function fermerLesMenus(): void
    {
        if ($this->onglet->page()->evaluate('() => [...document.querySelectorAll(".fi-dropdown-panel")].some((panneau) => panneau.offsetParent !== null)') === true) {
            $this->onglet->geste(fn () => $this->onglet->page()->locator('body')->press('Escape'));
        }
    }

    /**
     * Clique sur chaque onglet de la page (d'une liste ou d'un formulaire)
     * qui n'est pas déjà ouvert.
     */
    private function cliquerSurLesOnglets(string $composant): void
    {
        $onglets = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('.fi-tabs-item')]
                .filter((onglet) => onglet.offsetParent !== null && ! onglet.closest('.fi-modal'))
                .map((onglet) => onglet.innerText.trim())
        JS);

        foreach (is_array($onglets) ? $onglets : [] as $indice => $libelle) {
            $this->onglet->cliquer('.fi-tabs-item >> nth='.$indice);
            $this->couverture->noterUnOnglet($this->classeDuComposant($composant), $libelle);
        }
    }

    /**
     * Change chaque filtre du tableau de la page (la seconde option d'une
     * liste, une case cochée), puis remet les filtres à zéro.
     */
    private function changerLesFiltres(string $chemin): void
    {
        $declencheur = $this->onglet->page()->locator('.fi-ta-filters-dropdown .fi-dropdown-trigger');

        if ($declencheur->count() === 0) {
            return;
        }

        $this->onglet->cliquer('.fi-ta-filters-dropdown .fi-dropdown-trigger');
        $champs = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('.fi-ta-filters-dropdown .fi-dropdown-panel select, .fi-ta-filters-dropdown .fi-dropdown-panel input[type=checkbox]')]
                .filter((champ) => champ.id !== '' && (champ.tagName !== 'SELECT' || champ.options.length > 1))
                .map((champ) => ({ id: champ.id, valeur: champ.tagName === 'SELECT' ? champ.options[1].value : null }))
        JS);

        foreach (is_array($champs) ? $champs : [] as $champ) {
            $element = $this->onglet->element('[id="'.$champ['id'].'"]');
            $this->onglet->geste(fn () => $champ['valeur'] === null ? $element->check() : $element->selectOption((string) $champ['valeur']));
        }

        $this->onglet->aller($chemin);
    }

    /**
     * Cherche « a » dans le tableau que montre la fenêtre ouverte, s'il en
     * a un (la loupe des clients, « Ajouter un produit ») : le composant
     * qu'elle monte répond ainsi à une requête à lui, comme au premier clic
     * d'une personne.
     */
    private function chercherDansLaFenetre(): void
    {
        $recherche = $this->onglet->page()->locator(Navigation::FENETRE.' .fi-ta-search-field input');

        if ($recherche->count() > 0) {
            $this->onglet->gesteQuiAppelleLeServeur(fn () => $recherche->first()->fill('a'), 'la recherche dans la fenêtre');
        }
    }

    /**
     * Cherche « a » dans le tableau de la page, s'il a une recherche.
     */
    private function chercher(): void
    {
        $recherche = $this->onglet->page()->locator('.fi-ta-search-field input');

        if ($recherche->count() > 0) {
            $this->onglet->gesteQuiAppelleLeServeur(fn () => $recherche->first()->fill('a'), 'la recherche dans le tableau');
        }
    }

    /**
     * La classe d'un composant Livewire d'après son nom.
     *
     * @return class-string
     */
    private function classeDuComposant(string $nom): string
    {
        return ltrim(app(ComponentRegistry::class)->getClass($nom), '\\');
    }
}
