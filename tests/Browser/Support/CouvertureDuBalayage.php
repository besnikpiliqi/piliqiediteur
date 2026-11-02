<?php

namespace Tests\Browser\Support;

use Filament\Resources\Pages\Page as PageDeRessource;
use ReflectionClass;

use function Livewire\on;

/**
 * Ce que le balayage d'un test a ouvert, noté côté serveur, dans les termes
 * de l'inventaire des actions (voir ActionsDeLInterface) : chaque page,
 * widget ou composant rendu (Livewire « dehydrate »), chaque action montée
 * (mountAction, mountTableAction, mountTableBulkAction,
 * mountFormComponentAction, mountInfolistAction), chaque filtre, onglet de
 * liste ou recherche d'un tableau changés (Livewire « update »), et chaque
 * onglet de formulaire cliqué, que seul le navigateur connaît. Chaque
 * action se range dans le fichier qui la déclare : la classe du composant,
 * ses parents et ses traits, puis la ressource d'une page de ressource et
 * ses traits. Une action qui est un lien (voir ActionsDeLInterface::liens())
 * ne se monte pas : le balayage la suit comme tout lien de la page, et elle
 * compte comme ouverte quand la page de sa ressource qu'elle ouvre (create,
 * edit, view) a été rendue, ou, pour un autre lien, quand la page qui la
 * déclare l'a été.
 */
final class CouvertureDuBalayage
{
    /**
     * Les actions que Filament ajoute lui-même à une liste de lignes
     * (Repeater) : elles couvrent la liste, pas une action déclarée.
     */
    private const ACTIONS_D_UNE_LISTE = ['add', 'addBetween', 'delete', 'clone', 'reorder', 'moveUp', 'moveDown', 'collapse', 'expand', 'collapseAll', 'expandAll'];

    /**
     * Les actions que Filament ajoute lui-même à une liste déroulante qui
     * crée ou modifie une option : elles couvrent le champ.
     */
    private const ACTIONS_D_UN_CHAMP = ['createOption', 'editOption'];

    /**
     * @var array<class-string, true>
     */
    private array $composants = [];

    /**
     * @var list<array{classe: class-string, element: string}>
     */
    private array $ouverts = [];

    /**
     * @var array<string, list<string>>|null
     */
    private ?array $declarees = null;

    /**
     * Commence à noter ce que les requêtes de ce test ouvrent.
     */
    public function ecouter(): void
    {
        on('dehydrate', function (object $composant): void {
            $this->composants[$composant::class] = true;
        });

        on('call', function (object $composant, string $methode, array $parametres): void {
            $element = match ($methode) {
                'mountAction', 'mountTableAction', 'mountTableBulkAction', 'mountInfolistAction' => 'action:'.($parametres[0] ?? ''),
                'mountFormComponentAction' => match (true) {
                    in_array($parametres[1] ?? '', self::ACTIONS_D_UNE_LISTE, true) => 'repeteur:'.self::dernierSegment((string) ($parametres[0] ?? '')),
                    in_array($parametres[1] ?? '', self::ACTIONS_D_UN_CHAMP, true) => 'champ:'.self::dernierSegment((string) ($parametres[0] ?? '')),
                    default => 'action:'.($parametres[1] ?? ''),
                },
                default => null,
            };

            if ($element !== null) {
                $this->ouverts[] = ['classe' => $composant::class, 'element' => $element];
            }
        });

        on('update', function (object $composant, string $chemin, mixed $valeur): void {
            $element = match (true) {
                str_starts_with($chemin, 'tableFilters.') => 'filtre:'.explode('.', $chemin)[1],
                $chemin === 'activeTab' && is_string($valeur) => 'onglet:'.$valeur,
                $chemin === 'tableSearch' => 'recherche',
                default => null,
            };

            if ($element !== null) {
                $this->ouverts[] = ['classe' => $composant::class, 'element' => $element];
            }
        });
    }

    /**
     * Note un onglet de formulaire cliqué par son libellé, dans la page de
     * ce composant.
     *
     * @param  class-string  $classe
     */
    public function noterUnOnglet(string $classe, string $libelle): void
    {
        $this->ouverts[] = ['classe' => $classe, 'element' => 'onglet:'.$libelle];
    }

    /**
     * Ce qui a été ouvert, par fichier de l'inventaire ; et ce qui a été
     * ouvert sans qu'aucun fichier ne le déclare (une action que la lecture
     * du code n'a pas trouvée).
     *
     * @return array{couverts: array<string, list<string>>, inconnus: list<string>}
     */
    public function bilan(): array
    {
        $couverts = [];
        $inconnus = [];

        foreach (array_keys($this->composants) as $classe) {
            foreach (['page', 'widget', 'composant'] as $element) {
                $fichier = self::fichier($classe);

                if (in_array($element, $this->declarees()[$fichier] ?? [], true)) {
                    $couverts[$fichier][] = $element;
                }
            }
        }

        $liens = ActionsDeLInterface::liens();

        foreach (array_keys($this->composants) as $classe) {
            foreach ($this->candidats($classe, avecLesImports: false) as $candidat) {
                $fichier = self::fichier($candidat);

                foreach ($liens[$fichier] ?? [] as $lien) {
                    if ($this->lienSuivi($classe, $lien)) {
                        $couverts[$fichier][] = $lien;
                    }
                }
            }
        }

        foreach ($this->ouverts as $ouvert) {
            $fichier = $this->fichierQuiDeclare($ouvert['classe'], $ouvert['element']);

            if ($fichier === null) {
                $inconnus[] = $ouvert['element'].' ('.$ouvert['classe'].')';

                continue;
            }

            $couverts[$fichier][] = $ouvert['element'];
        }

        ksort($couverts);

        return [
            'couverts' => array_map(fn (array $elements): array => array_values(array_unique($elements)), $couverts),
            'inconnus' => array_values(array_unique($inconnus)),
        ];
    }

    /**
     * Le fichier qui déclare cet élément pour ce composant : la classe, ses
     * parents et leurs traits, puis la ressource de sa page et ses traits ;
     * à défaut, le seul fichier de l'application qui le déclare (une action
     * qu'une autre ressource construit pour la page, comme la loupe des
     * clients) ; null s'il n'y en a aucun ou plusieurs.
     *
     * @param  class-string  $classe
     */
    private function fichierQuiDeclare(string $classe, string $element): ?string
    {
        foreach ($this->candidats($classe) as $candidat) {
            $fichier = self::fichier($candidat);

            if (in_array($element, $this->declarees()[$fichier] ?? [], true)) {
                return $fichier;
            }
        }

        $declarants = array_keys(array_filter($this->declarees(), fn (array $elements): bool => in_array($element, $elements, true)));

        return count($declarants) === 1 ? $declarants[0] : null;
    }

    /**
     * Les classes qui peuvent déclarer une action de ce composant, dans
     * l'ordre où les chercher : la classe, ses parents et leurs traits ; la
     * ressource d'une page de ressource ; puis, avec les imports, les
     * classes de l'interface que son fichier importe (la caisse reprend les
     * lignes de vente des devis), avec leurs parents et leurs traits. Les
     * liens d'une page ne se cherchent que sans les imports : un lien d'une
     * classe importée ne s'affiche pas sur la page.
     *
     * @param  class-string  $classe
     * @return list<class-string>
     */
    private function candidats(string $classe, bool $avecLesImports = true): array
    {
        $candidats = self::classesDeclarantes($classe);

        if (is_subclass_of($classe, PageDeRessource::class)) {
            $candidats = [...$candidats, ...self::classesDeclarantes($classe::getResource())];
        }

        if (! $avecLesImports) {
            return array_values(array_unique($candidats));
        }

        preg_match_all('/^use\s+(App\\\\Filament\\\\[\w\\\\]+);/m', (string) file_get_contents((string) (new ReflectionClass($classe))->getFileName()), $imports);

        foreach ($imports[1] as $importee) {
            if (class_exists($importee) || trait_exists($importee)) {
                $candidats = [...$candidats, ...self::classesDeclarantes($importee)];
            }
        }

        return array_values(array_unique($candidats));
    }

    /**
     * Ce lien, déclaré pour la page de ce composant, a-t-il mené à une page
     * rendue : la page create, edit ou view de la ressource pour les
     * actions de ce nom, sinon la page qui le déclare.
     *
     * @param  class-string  $composant
     */
    private function lienSuivi(string $composant, string $lien): bool
    {
        $page = substr($lien, strlen('action:'));

        if (! in_array($page, ['create', 'edit', 'view'], true) || ! is_subclass_of($composant, PageDeRessource::class)) {
            return true;
        }

        $cible = $composant::getResource()::getPages()[$page] ?? null;

        return $cible !== null && isset($this->composants[$cible->getPage()]);
    }

    /**
     * Cette classe, ses parents et tous leurs traits, de l'application.
     *
     * @param  class-string  $classe
     * @return list<class-string>
     */
    private static function classesDeclarantes(string $classe): array
    {
        $classes = [];

        for ($courante = $classe; $courante !== false; $courante = get_parent_class($courante)) {
            $classes[] = $courante;
            $classes = [...$classes, ...array_values(class_uses_recursive($courante))];
        }

        return array_values(array_filter(array_unique($classes), fn (string $classe): bool => str_starts_with($classe, 'App\\')));
    }

    /**
     * @return array<string, list<string>>
     */
    private function declarees(): array
    {
        return $this->declarees ??= ActionsDeLInterface::lire();
    }

    /**
     * @param  class-string  $classe
     */
    private static function fichier(string $classe): string
    {
        return ltrim(str_replace([base_path(), '\\'], ['', '/'], (string) (new ReflectionClass($classe))->getFileName()), '/');
    }

    private static function dernierSegment(string $chemin): string
    {
        $segments = explode('.', $chemin);

        return (string) end($segments);
    }
}
