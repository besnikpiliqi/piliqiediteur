<?php

namespace Tests\Browser\Support;

/**
 * Le contrôle du contraste des deux modes d'un panneau (décision de
 * l'éditeur du 15/11/2026, lot DATEOP) : depuis une page, il ouvre chaque
 * page que ses liens atteignent dans les préfixes donnés, une seule fiche
 * par sorte d'adresse, comme le balayage (voir Balayeur), puis lit chacune
 * en clair et en sombre (la classe dark sur <html>, comme le sélecteur de
 * thème de Filament) et relève chaque texte visible et chaque option de
 * menu déroulant sous le seuil de WCAG AA (voir contraste.js), avec la
 * page, le mode, l'élément et le rapport trouvé.
 */
final class ContrasteDesPages
{
    public const MODES = ['clair', 'sombre'];

    /**
     * Les pages lues, dans l'ordre.
     *
     * @var list<string>
     */
    private array $pages = [];

    /**
     * Les défauts relevés, un par ligne.
     *
     * @var list<string>
     */
    private array $defauts = [];

    public function __construct(private readonly Navigation $onglet) {}

    /**
     * Lit chaque page atteignable depuis ce chemin dont l'adresse commence
     * par l'un de ces préfixes, dans les deux modes ; rend les pages lues.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    public function lire(string $depart, array $prefixes): array
    {
        $sortesVues = [];
        $aVisiter = [$depart];

        while ($aVisiter !== []) {
            $chemin = array_shift($aVisiter);
            $sorte = self::sorte($chemin, $prefixes);

            if (isset($sortesVues[$sorte])) {
                continue;
            }

            $sortesVues[$sorte] = true;
            $this->onglet->aller($chemin);
            $this->pages[] = $chemin;

            foreach (self::MODES as $mode) {
                $this->relever($chemin, $mode);
            }

            foreach ($this->liens() as $lien) {
                if (self::dansLesPrefixes($lien, $prefixes)) {
                    $aVisiter[] = $lien;
                }
            }
        }

        return $this->pages;
    }

    /**
     * Les défauts relevés : « page (mode) : élément, rapport trouvé pour
     * le seuil ».
     *
     * @return list<string>
     */
    public function defauts(): array
    {
        return $this->defauts;
    }

    /**
     * Met la page ouverte dans ce mode, sans transition ni animation qui
     * fausserait les couleurs lues, et rend ses textes sous le seuil :
     * « élément, rapport trouvé pour le seuil ».
     *
     * @return list<string>
     */
    public function defautsDeLaPage(string $mode): array
    {
        $this->onglet->page()->evaluate(<<<'JS'
            (sombre) => {
                if (! document.getElementById('piliqi-sans-transition')) {
                    const feuille = document.createElement('style');
                    feuille.id = 'piliqi-sans-transition';
                    feuille.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
                    document.head.append(feuille);
                }

                document.documentElement.classList.toggle('dark', sombre);
            }
            JS, $mode === 'sombre');
        $defauts = $this->onglet->page()->evaluate((string) file_get_contents(__DIR__.'/contraste.js'), $mode);

        return array_map(
            static fn (array $defaut): string => $defaut['element'].', '.$defaut['rapport'].':1 pour '.$defaut['seuil'].':1',
            array_values(array_filter(is_array($defauts) ? $defauts : [], is_array(...))),
        );
    }

    /**
     * Relève les textes sous le seuil de la page ouverte, dans ce mode.
     */
    private function relever(string $chemin, string $mode): void
    {
        foreach ($this->defautsDeLaPage($mode) as $defaut) {
            $this->defauts[] = $chemin.' ('.$mode.') : '.$defaut;
        }
    }

    /**
     * Les chemins des liens de la page, visibles ou rangés dans un menu
     * d'actions visible, hors de la barre du haut.
     *
     * @return list<string>
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
                .map((lien) => new URL(lien.href, location.href))
                .filter((adresse) => adresse.origin === location.origin)
                .map((adresse) => adresse.pathname)
            JS);

        return array_values(array_filter(is_array($liens) ? $liens : [], is_string(...)));
    }

    /**
     * La sorte d'une adresse : son chemin, chaque identifiant remplacé par
     * « * » (une seule fiche par sorte, comme le balayage).
     *
     * @param  list<string>  $prefixes
     */
    private static function sorte(string $chemin, array $prefixes): string
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
    private static function dansLesPrefixes(string $chemin, array $prefixes): bool
    {
        foreach ($prefixes as $prefixe) {
            if ($chemin === $prefixe || str_starts_with($chemin, rtrim($prefixe, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
