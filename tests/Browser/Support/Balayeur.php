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
 * et cherche dans chaque tableau. Les liens d'une page, visibles ou rangés
 * dans un menu d'actions visible, se relèvent avant ces gestes. Sur la
 * page de départ, il ouvre aussi le menu du compte : le profil se balaye
 * comme les autres pages, et chaque autre entrée s'ouvre puis la page de
 * départ revient. Après chaque geste, l'onglet vérifie qu'aucune erreur
 * n'est apparue (voir Navigation::verifier()).
 *
 * Rien de ce que le balayage ouvre n'écrit : pendant le balayage, chaque
 * action de Filament demande une confirmation (requiresConfirmation()),
 * si bien qu'une action qui s'exécuterait d'un clic ouvre elle aussi une
 * fenêtre, que le balayage ferme ; seules les actions qui ne font que
 * lire (TELECHARGEMENTS) s'exécutent d'un clic, comme pour une personne,
 * et le balayage vérifie le fichier que le navigateur reçoit. Chaque action
 * porte son nom dans la page (data-balayage-action) : une action qui est un
 * lien compte comme ouverte quand le balayage l'a vue sur une page, et
 * qu'il a ouvert la page de son adresse (voir CouvertureDuBalayage).
 */
final class Balayeur
{
    /**
     * Les actions qui ne font que lire, exécutées d'un clic pendant le
     * balayage, et le fichier que chacune fait télécharger : la fin de son
     * nom et ses premiers caractères.
     *
     * @var array<string, array{extension: string, debut: string}>
     */
    private const TELECHARGEMENTS = [
        'telecharger_pdf' => ['extension' => '.pdf', 'debut' => '%PDF-'],
    ];

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
     * Les actions qui sont des liens, vues sur une page balayée : le
     * composant qui les montre, leur nom et la sorte de leur adresse.
     *
     * @var list<array{composant: string, action: string, sorte: string}>
     */
    private array $liensVus = [];

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
        app(ComponentManager::class)->configureUsing(MountableAction::class, function (MountableAction $action): MountableAction {
            $action->extraAttributes(['data-balayage-action' => $action->getName()], merge: true);

            return isset(self::TELECHARGEMENTS[$action->getName()]) ? $action : $action->requiresConfirmation();
        }, isImportant: true);
    }

    /**
     * Balaye chaque page atteignable depuis ce chemin dont l'adresse
     * commence par l'un de ces préfixes, puis le profil et les entrées du
     * menu du compte de la page de départ ; rend les pages balayées.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    public function balayer(string $depart, array $prefixes): array
    {
        $this->onglet->aller($depart);
        $profil = $this->ouvrirLeMenuDuCompte($depart);
        $prefixes = [...$prefixes, ...$profil];
        $aVisiter = [$depart, ...$profil];

        while ($aVisiter !== []) {
            $chemin = array_shift($aVisiter);
            $sorte = $this->sorte($chemin, $prefixes);

            if (isset($this->sortesVues[$sorte])) {
                continue;
            }

            $this->sortesVues[$sorte] = true;
            $this->onglet->aller($chemin);
            $this->pages[] = $chemin;

            foreach ($this->liens() as $lien) {
                if (! $this->dansLesPrefixes($lien['chemin'], $prefixes)) {
                    continue;
                }

                if ($lien['action'] !== null) {
                    $this->liensVus[] = ['composant' => $lien['composant'], 'action' => $lien['action'], 'sorte' => $this->sorte($lien['chemin'], $prefixes)];
                }

                if (! isset($this->sortesVues[$this->sorte($lien['chemin'], $prefixes)])) {
                    $aVisiter[] = $lien['chemin'];
                }
            }

            $this->ouvrirLesActions($chemin);
        }

        foreach ($this->liensVus as $lien) {
            if (isset($this->sortesVues[$lien['sorte']])) {
                $this->couverture->noterUnLienSuivi($this->classeDuComposant($lien['composant']), 'action:'.$lien['action']);
            }
        }

        return $this->pages;
    }

    /**
     * Ouvre le menu du compte de cette page : rend le chemin du profil,
     * balayé ensuite comme une page du panneau ; chaque autre entrée
     * s'ouvre, sa page se vérifie, puis cette page revient.
     *
     * @return list<string>
     */
    private function ouvrirLeMenuDuCompte(string $depart): array
    {
        $this->onglet->cliquer('.fi-user-menu .fi-dropdown-trigger');
        $entrees = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('.fi-user-menu .fi-dropdown-panel a[href]')]
                .filter((lien) => lien.offsetParent !== null)
                .map((lien) => ({ chemin: new URL(lien.href, location.href).pathname, libelle: lien.innerText.trim() }))
        JS);
        $profil = [];

        foreach (is_array($entrees) ? $entrees : [] as $entree) {
            if (str_ends_with($entree['chemin'], '/profile')) {
                $profil[] = $entree['chemin'];

                continue;
            }

            $this->onglet->aller($entree['chemin']);
            $this->couverture->noterUneEntreeDuMenu($entree['libelle']);
        }

        $this->onglet->aller($depart);

        return $profil;
    }

    /**
     * Les liens de la page vers l'application, sans requête ni ancre,
     * visibles ou rangés dans un menu d'actions visible, hors menus du
     * panneau : leur chemin, et, pour une action qui est un lien, son nom et
     * le composant Livewire qui la montre.
     *
     * @return list<array{chemin: string, action: string|null, composant: string}>
     */
    private function liens(): array
    {
        $liens = $this->onglet->page()->evaluate(<<<'JS'
            () => [...document.querySelectorAll('a[href]')]
                .filter((lien) => ! lien.closest('.fi-topbar, .fi-user-menu, .fi-tenant-menu'))
                .filter((lien) => {
                    if (lien.offsetParent !== null) {
                        return true;
                    }

                    const menu = lien.closest('.fi-dropdown');
                    const declencheur = menu ? menu.querySelector('.fi-dropdown-trigger') : null;

                    return declencheur !== null && declencheur.offsetParent !== null;
                })
                .map((lien) => ({ adresse: new URL(lien.href, location.href), lien }))
                .filter(({ adresse }) => adresse.origin === location.origin)
                .map(({ adresse, lien }) => {
                    const composant = lien.closest('[wire\\:snapshot]');

                    return {
                        chemin: adresse.pathname,
                        action: lien.getAttribute('data-balayage-action'),
                        composant: composant ? JSON.parse(composant.getAttribute('wire:snapshot')).memo.name : '',
                    };
                })
        JS);

        return array_values(array_unique(is_array($liens) ? $liens : [], SORT_REGULAR));
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
            $this->verifierLeTelechargement($action['nom']);

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
     * Une action qui ne fait que lire a fait télécharger au navigateur un
     * seul fichier, du nom et du contenu attendus ; une autre action n'a
     * rien fait télécharger.
     */
    private function verifierLeTelechargement(string $action): void
    {
        $fichiers = $this->onglet->telechargements();
        $attendu = self::TELECHARGEMENTS[$action] ?? null;

        if ($attendu === null) {
            Assert::assertSame([], $fichiers, "L'action {$action}, ouverte sans être validée, a fait télécharger un fichier.");

            return;
        }

        Assert::assertCount(1, $fichiers, "L'action {$action} n'a pas fait télécharger un seul fichier.");
        Assert::assertStringEndsWith($attendu['extension'], $fichiers[0]['nom'], "L'action {$action} a fait télécharger un fichier d'un autre genre.");
        Assert::assertStringStartsWith($attendu['debut'], $fichiers[0]['debut'], "L'action {$action} a fait télécharger un fichier illisible : {$fichiers[0]['nom']}.");
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
     * magasin, notifications) : son appel (wire:click), son nom et sa clé,
     * sans l'enregistrement d'une action de ligne ; null quand toutes ont
     * été ouvertes.
     *
     * @return array{clic: string, nom: string, cle: string}|null
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
            preg_match("/^mountFormComponentAction\\('[^']*', ?'([^']*)'|^mount\\w*Action\\('([^']*)'/", $clic, $nom);

            if (! isset($this->actionsVues[$composant.'|'.$cle])) {
                return ['clic' => $clic, 'nom' => ($nom[1] ?? '') !== '' ? $nom[1] : ($nom[2] ?? ''), 'cle' => $cle];
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

        $this->onglet->gesteQuiAppelleLeServeur(fn () => $this->onglet->element('[data-balayage="action"]')->click(), 'le clic sur l\'action '.$clic);
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
            $this->onglet->geste(fn () => $champ['valeur'] === null ? $element->check() : $element->selectOption((string) $champ['valeur']), 'le filtre '.$champ['id']);
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
