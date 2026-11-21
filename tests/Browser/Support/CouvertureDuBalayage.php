<?php

namespace Tests\Browser\Support;

use Filament\Pages\Page as PageDeFilament;
use Filament\Resources\Pages\Page as PageDeRessource;
use ReflectionClass;

use function Livewire\on;

/**
 * Ce que le balayage d'un test a ouvert, dans les termes de l'inventaire
 * des actions (voir ActionsDeLInterface) : noté côté serveur, chaque page,
 * widget ou composant rendu (Livewire « dehydrate »), une page que sert une
 * classe de Filament (le tableau de bord du magasin) comprise, chaque
 * action montée (mountAction, mountTableAction, mountTableBulkAction,
 * mountFormComponentAction, mountInfolistAction), chaque filtre, onglet de
 * liste ou recherche d'un tableau changés (Livewire « update ») ; noté par
 * le balayage, que seul le navigateur connaît, chaque onglet de formulaire
 * cliqué, chaque entrée du menu du compte ouverte, et chaque action qui
 * est un lien, qui ne se monte pas : elle compte quand le balayage l'a vue
 * sur une page et a ouvert la page de son adresse (voir Balayeur). Chaque
 * élément se range dans le fichier qui le déclare : la classe du
 * composant, ses parents et ses traits, puis la ressource d'une page de
 * ressource et ses traits, puis les classes de l'interface que son fichier
 * importe, puis celles que le fichier de sa ressource importe ou appelle ;
 * à défaut, le seul fichier qui le déclare.
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
     * Les éléments ouverts, chacun avec la classe du composant qui le montre,
     * ou sans classe quand un seul fichier peut le déclarer (une entrée du
     * menu du compte, une page que sert une classe de Filament).
     *
     * @var list<array{classe: class-string|null, element: string}>
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
     * Note une action qui est un lien, vue sur la page de ce composant et
     * dont la page a été ouverte.
     *
     * @param  class-string  $classe
     */
    public function noterUnLienSuivi(string $classe, string $element): void
    {
        $this->ouverts[] = ['classe' => $classe, 'element' => $element];
    }

    /**
     * Note une entrée du menu du compte ouverte, par son libellé.
     */
    public function noterUneEntreeDuMenu(string $libelle): void
    {
        $this->ouverts[] = ['classe' => null, 'element' => 'menu:'.$libelle];
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
        $ouverts = $this->ouverts;

        foreach (array_keys($this->composants) as $classe) {
            if (! str_starts_with($classe, 'App\\')) {
                if (is_subclass_of($classe, PageDeFilament::class)) {
                    $ouverts[] = ['classe' => null, 'element' => 'page:'.class_basename($classe)];
                }

                continue;
            }

            foreach (['page', 'widget', 'composant'] as $element) {
                $fichier = self::fichier($classe);

                if (in_array($element, $this->declarees()[$fichier] ?? [], true)) {
                    $couverts[$fichier][] = $element;
                }
            }
        }

        foreach ($ouverts as $ouvert) {
            $fichier = $this->fichierQuiDeclare($ouvert['classe'], $ouvert['element']);

            if ($fichier === null) {
                $inconnus[] = $ouvert['element'].' ('.($ouvert['classe'] ?? 'sans composant').')';

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
     * à défaut, ou sans composant, le seul fichier de l'application qui le
     * déclare (une action qu'une autre ressource construit pour la page,
     * comme la loupe des clients) ; null s'il n'y en a aucun ou plusieurs.
     *
     * @param  class-string|null  $classe
     */
    private function fichierQuiDeclare(?string $classe, string $element): ?string
    {
        foreach ($classe === null ? [] : $this->candidats($classe) as $candidat) {
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
     * ressource d'une page de ressource ; puis les classes de l'interface
     * que son fichier importe (la caisse reprend les lignes de vente des
     * devis), et enfin celles que le fichier de sa ressource importe ou
     * appelle (le formulaire de création d'une facture reprend les lignes
     * de vente des devis par DevisResource::lignesDeVente()), chacune avec
     * ses parents et ses traits.
     *
     * @param  class-string  $classe
     * @return list<class-string>
     */
    private function candidats(string $classe): array
    {
        $candidats = self::classesDeclarantes($classe);
        $ressource = is_subclass_of($classe, PageDeRessource::class) ? $classe::getResource() : null;

        if ($ressource !== null) {
            $candidats = [...$candidats, ...self::classesDeclarantes($ressource)];
        }

        foreach ([...self::classesImportees($classe), ...($ressource === null ? [] : self::classesImporteesOuAppelees($ressource))] as $nommee) {
            $candidats = [...$candidats, ...self::classesDeclarantes($nommee)];
        }

        return array_values(array_unique($candidats));
    }

    /**
     * Les classes de l'interface (App\Filament) que le fichier de cette
     * classe importe.
     *
     * @param  class-string  $classe
     * @return list<class-string>
     */
    private static function classesImportees(string $classe): array
    {
        preg_match_all('/^use\s+(App\\\\Filament\\\\[\w\\\\]+);/m', self::source($classe), $imports);

        return array_values(array_filter($imports[1], fn (string $importee): bool => class_exists($importee) || trait_exists($importee)));
    }

    /**
     * Les classes de l'interface que le fichier de cette classe importe, ou
     * qu'il appelle sans les importer, parce qu'elles sont de son espace de
     * noms (« DevisResource:: » dans FactureResource).
     *
     * @param  class-string  $classe
     * @return list<class-string>
     */
    private static function classesImporteesOuAppelees(string $classe): array
    {
        preg_match_all('/(?<![\\\\\w])([A-Z]\w*)::/', self::source($classe), $appels);
        $espace = (new ReflectionClass($classe))->getNamespaceName();
        $appelees = array_filter(
            array_map(fn (string $nom): string => $espace.'\\'.$nom, array_unique($appels[1])),
            fn (string $appelee): bool => str_starts_with($appelee, 'App\\Filament\\') && (class_exists($appelee) || trait_exists($appelee)),
        );

        return array_values(array_unique([...self::classesImportees($classe), ...$appelees]));
    }

    /**
     * @param  class-string  $classe
     */
    private static function source(string $classe): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($classe))->getFileName());
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
