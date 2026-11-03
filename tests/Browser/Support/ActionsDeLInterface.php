<?php

namespace Tests\Browser\Support;

use Filament\Pages\Page;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Routes;
use LogicException;
use PhpToken;
use ReflectionClass;

/**
 * Chaque action de l'interface, lue dans le code : ce que l'inventaire des
 * actions (tests/Browser/inventaire-des-actions.php) doit couvrir, rangé par
 * fichier qui la déclare (chemin depuis la racine du dépôt) :
 * - « page » : chaque page d'un panneau (ressources, pages propres, pages
 *   de connexion, d'inscription, de profil...), d'après les routes de
 *   Filament ; « page:Classe » : une page qu'une classe de Filament sert
 *   (le tableau de bord d'un magasin), dans le fournisseur de son panneau ;
 * - « widget » et « composant » : chaque widget et chaque composant
 *   Livewire monté par une vue (app/Filament) ;
 * - « action:nom » : chaque action de Filament (d'en-tête, de ligne,
 *   groupée, de formulaire, de fiche, d'un avis), par le nom qu'elle reçoit
 *   à sa création (Action::make('nom')), ou celui de sa classe (« edit »
 *   pour EditAction), partout dans app/ (les avis des domaines compris) ;
 * - « filtre:nom » et « onglet:nom » : chaque filtre d'un tableau et chaque
 *   onglet d'une liste ou d'un formulaire ;
 * - « champ:nom » : chaque champ d'un formulaire qui déclenche un calcul ou
 *   une recherche (live(), reactive(), afterStateUpdated(), searchable()...),
 *   ou qui ouvre une fenêtre de création (createOptionForm()) ;
 *   « repeteur:nom » : chaque liste de lignes, avec ses boutons d'ajout et
 *   de retrait ;
 * - « recherche » : la recherche d'un tableau qui a une colonne cherchable ;
 * - « lien:nom » : chaque entrée de fiche ou colonne de tableau qui est un
 *   lien (url()) ;
 * - « menu:libellé » : chaque entrée ajoutée au menu du compte ;
 * - dans les vues (hors resources/views/vendor) : « clic:méthode », chaque
 *   bouton qui appelle une méthode de son composant (wire:click, ou un clic
 *   qui appelle $wire) ; « envoi:méthode », chaque formulaire que son
 *   composant reçoit (wire:submit) ; « saisie:propriété », chaque champ qui
 *   envoie sa saisie au serveur (wire:model.live, .blur, .change, .lazy) ;
 *   « changement:méthode », chaque champ qui appelle une méthode quand il
 *   change (wire:change) ; « formulaire:adresse », chaque formulaire envoyé
 *   par le navigateur (method="post"), par l'expression de son adresse ;
 *   « lien:texte », chaque lien (<a href>, x-filament::link, ou
 *   x-filament::button tag="a"), par son texte tel qu'écrit ;
 * - « page publique:adresse » et « formulaire:adresse » : chaque page et
 *   chaque formulaire publics, hors panneaux (routes/web.php).
 */
final class ActionsDeLInterface
{
    /**
     * Le nom que Filament donne à une action créée sans nom.
     *
     * @var array<string, string>
     */
    private const NOMS_PAR_DEFAUT = [
        'CreateAction' => 'create',
        'EditAction' => 'edit',
        'ViewAction' => 'view',
        'DeleteAction' => 'delete',
        'RestoreAction' => 'restore',
        'ForceDeleteAction' => 'forceDelete',
        'DeleteBulkAction' => 'delete',
        'RestoreBulkAction' => 'restore',
        'ForceDeleteBulkAction' => 'forceDelete',
    ];

    /**
     * Les champs d'un formulaire de Filament.
     */
    private const CHAMPS = [
        'TextInput', 'Select', 'Toggle', 'Checkbox', 'CheckboxList', 'Radio', 'DatePicker', 'DateTimePicker',
        'TimePicker', 'Textarea', 'ToggleButtons', 'FileUpload', 'TagsInput', 'KeyValue', 'ColorPicker',
        'RichEditor', 'MarkdownEditor', 'Hidden',
    ];

    /**
     * Les appels qui font d'un champ un déclencheur de calcul ou de
     * recherche.
     */
    private const DECLENCHEURS = ['live', 'reactive', 'lazy', 'afterStateUpdated', 'searchable', 'getSearchResultsUsing', 'createOptionForm', 'editOptionForm', 'createOptionUsing'];

    /**
     * Les routes qui ne sont pas des pages de l'interface : les rappels
     * signés du prestataire d'envoi recommandé, appelés par son serveur.
     */
    private const ROUTES_HORS_INTERFACE = ['rappels.recommande'];

    /**
     * @return array<string, list<string>>
     */
    public static function lire(): array
    {
        $actions = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            if ($fichier->getExtension() === 'php') {
                self::ajouter($actions, $fichier->getPathname(), self::declarees($fichier->getContents()));
            }
        }

        foreach (File::allFiles(resource_path('views')) as $vue) {
            if (! str_starts_with($vue->getPathname(), resource_path('views/vendor'))) {
                self::ajouter($actions, $vue->getPathname(), self::declareesDansLaVue($vue->getContents()));
            }
        }

        foreach (Routes::getRoutes()->getRoutes() as $route) {
            self::ajouterLaRoute($actions, $route);
        }

        ksort($actions);

        return array_map(function (array $elements): array {
            $elements = array_values(array_unique($elements));
            sort($elements);

            return $elements;
        }, $actions);
    }

    /**
     * Les éléments que cette vue Blade déclare.
     *
     * @return list<string>
     */
    public static function declareesDansLaVue(string $vue): array
    {
        $attributs = '((?:[^>"\']|"[^"]*"|\'[^\']*\')*)';
        $texte = fn (string $contenu): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($contenu)));
        $elements = [];

        foreach ([
            'clic' => '/(?:wire:click(?:\.[a-z]+)*="|(?:x-on:click|@click)(?:\.[a-z]+)*="[^"]*\$wire\.)([A-Za-z_]\w*)/',
            'envoi' => '/wire:submit(?:\.[a-z]+)*="([A-Za-z_]\w*)/',
            'saisie' => '/wire:model\.(?:live|blur|change|lazy)(?:\.[a-z0-9]+)*="([A-Za-z_][\w.]*)/',
            'changement' => '/wire:change(?:\.[a-z]+)*="([A-Za-z_]\w*)/',
        ] as $genre => $motif) {
            preg_match_all($motif, $vue, $trouves);
            array_push($elements, ...array_map(fn (string $nom): string => $genre.':'.$nom, $trouves[1]));
        }

        preg_match_all('/<form\b'.$attributs.'>/i', $vue, $formulaires);

        foreach ($formulaires[1] as $attributsDuFormulaire) {
            if (preg_match('/\bmethod="post"/i', $attributsDuFormulaire) === 1 && preg_match('/\baction="([^"]*)"/i', $attributsDuFormulaire, $adresse) === 1) {
                $elements[] = 'formulaire:'.$adresse[1];
            }
        }

        preg_match_all('/<a\b'.$attributs.'>(.*?)<\/a>|<x-filament::link\b'.$attributs.'>(.*?)<\/x-filament::link>|<x-filament::button\b'.$attributs.'>(.*?)<\/x-filament::button>/s', $vue, $liens, PREG_SET_ORDER);

        foreach ($liens as $lien) {
            $estUnLien = match (true) {
                ($lien[1] ?? '') !== '' || ($lien[2] ?? '') !== '' => preg_match('/\bhref=/', $lien[1]) === 1,
                ($lien[3] ?? '') !== '' || ($lien[4] ?? '') !== '' => preg_match('/\btag="(?!a")/', $lien[3]) !== 1,
                default => preg_match('/\btag="a"/', $lien[5] ?? '') === 1,
            };

            if ($estUnLien) {
                $elements[] = 'lien:'.$texte($lien[2] ?? '').$texte($lien[4] ?? '').$texte($lien[6] ?? '');
            }
        }

        return $elements;
    }

    /**
     * Les éléments que ce code PHP déclare.
     *
     * @return list<string>
     */
    public static function declarees(string $code): array
    {
        $jetons = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $jeton): bool => ! $jeton->isIgnorable()));
        $alias = self::alias($code);
        $elements = [];

        $parent = preg_match('/^(?:final\s+|abstract\s+)?class\s+\w+\s+extends\s+([\w\\\\]+)/m', $code, $extends) === 1 ? ($alias[self::nomCourt($extends[1])] ?? self::nomCourt($extends[1])) : null;

        if ($parent !== null && str_ends_with($parent, 'Widget')) {
            $elements[] = 'widget';
        }

        if ($parent === 'Component' && preg_match('/\bimplements\b[^{]*\bHas(?:Forms|Table|Actions)\b/', $code) === 1) {
            $elements[] = 'composant';
        }

        if (preg_match('/function\s+table\s*\(/', $code) === 1 && preg_match('/->(?:searchable|rechercheSansAccents)\s*\(/', $code) === 1) {
            $elements[] = 'recherche';
        }

        foreach ($jetons as $indice => $jeton) {
            if (! $jeton->is(T_DOUBLE_COLON) || ! ($jetons[$indice + 1] ?? null)?->is(T_STRING) || $jetons[$indice + 1]->text !== 'make' || ($jetons[$indice + 2] ?? null)?->text !== '(') {
                continue;
            }

            $classe = $alias[self::nomCourt($jetons[$indice - 1]->text)] ?? self::nomCourt($jetons[$indice - 1]->text);
            $nom = self::nomEcrit($jetons, $indice + 3, $code);
            $chaine = self::chaine($jetons, $indice + 2);
            $element = self::element($classe, $nom, $chaine, $jetons[$indice - 1]->line);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Le nom écrit en premier argument de make(), à cet indice : une chaîne,
     * ou une constante de la classe du fichier (self::NOM, static::NOM) ;
     * null sans nom écrit (ni l'appel d'une méthode).
     *
     * @param  list<PhpToken>  $jetons
     */
    private static function nomEcrit(array $jetons, int $indice, string $code): ?string
    {
        $jeton = $jetons[$indice] ?? null;

        if ($jeton?->is(T_CONSTANT_ENCAPSED_STRING)) {
            return stripcslashes(substr($jeton->text, 1, -1));
        }

        if ($jeton !== null && in_array($jeton->text, ['self', 'static'], true) && ($jetons[$indice + 1] ?? null)?->is(T_DOUBLE_COLON) && ($jetons[$indice + 2] ?? null)?->is(T_STRING) && ($jetons[$indice + 3] ?? null)?->text !== '(') {
            preg_match('/^namespace\s+([^;]+);/m', $code, $espace);
            preg_match('/^(?:final\s+|abstract\s+)?(?:class|trait)\s+(\w+)/m', $code, $classe);

            return (string) constant(($espace[1] ?? '').'\\'.$classe[1].'::'.$jetons[$indice + 2]->text);
        }

        return null;
    }

    /**
     * L'élément que crée cet appel à make(), ou null s'il n'est pas une
     * action de l'interface (une colonne, une section, un groupe...).
     *
     * @param  list<string>  $chaine
     */
    private static function element(string $classe, ?string $nom, array $chaine, int $ligne): ?string
    {
        if (str_ends_with($classe, 'ActionGroup')) {
            return null;
        }

        if (str_ends_with($classe, 'Action')) {
            $nom ??= self::NOMS_PAR_DEFAUT[$classe] ?? throw new LogicException("Le nom par défaut de l'action {$classe} (ligne {$ligne}) est inconnu : ajoutez-le à ActionsDeLInterface::NOMS_PAR_DEFAUT.");

            return 'action:'.$nom;
        }

        if (str_ends_with($classe, 'Filter')) {
            return 'filtre:'.($nom ?? ($classe === 'TrashedFilter' ? 'trashed' : throw new LogicException("Le filtre {$classe} de la ligne {$ligne} n'a pas de nom.")));
        }

        if ($classe === 'Tab' && $nom !== null) {
            return 'onglet:'.$nom;
        }

        if ($classe === 'Repeater' && $nom !== null) {
            return 'repeteur:'.$nom;
        }

        if (in_array($classe, self::CHAMPS, true) && $nom !== null && array_intersect($chaine, self::DECLENCHEURS) !== []) {
            return 'champ:'.$nom;
        }

        if ((str_ends_with($classe, 'Entry') || str_ends_with($classe, 'Column')) && $nom !== null && in_array('url', $chaine, true)) {
            return 'lien:'.$nom;
        }

        if ($classe === 'MenuItem') {
            $libelle = $chaine['label'] ?? null;

            return $libelle === null ? throw new LogicException("L'entrée du menu de la ligne {$ligne} n'a pas de libellé écrit.") : 'menu:'.$libelle;
        }

        return null;
    }

    /**
     * Les méthodes appelées en chaîne sur le résultat de make(), dont la
     * parenthèse ouvrante est à cet indice ; le libellé écrit d'un appel à
     * label() se lit sous la clé « label ».
     *
     * @param  list<PhpToken>  $jetons
     * @return array<int|string, string>
     */
    private static function chaine(array $jetons, int $ouverture): array
    {
        $profondeur = 0;
        $methodes = [];

        for ($indice = $ouverture, $fin = count($jetons); $indice < $fin; $indice++) {
            $texte = $jetons[$indice]->text;

            if (in_array($texte, ['(', '[', '{'], true)) {
                $profondeur++;
            } elseif (in_array($texte, [')', ']', '}'], true)) {
                $profondeur--;

                if ($profondeur < 0) {
                    break;
                }
            } elseif ($profondeur === 0 && in_array($texte, [',', ';'], true)) {
                break;
            } elseif ($profondeur === 0 && ($jetons[$indice]->is(T_OBJECT_OPERATOR) || $jetons[$indice]->is(T_NULLSAFE_OBJECT_OPERATOR)) && ($jetons[$indice + 1] ?? null)?->is(T_STRING)) {
                $methode = $jetons[$indice + 1]->text;
                $methodes[] = $methode;

                if ($methode === 'label' && ($jetons[$indice + 3] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $methodes['label'] = stripcslashes(substr($jetons[$indice + 3]->text, 1, -1));
                }
            }
        }

        return $methodes;
    }

    /**
     * Ajoute la page ou le formulaire de cette route à l'inventaire : une
     * page d'un panneau dans le fichier de son composant, ou, servie par une
     * classe de Filament, dans le fournisseur de son panneau ; une page ou
     * un formulaire publics (un contrôleur de l'application) dans
     * routes/web.php ; la redirection de la racine vers le panneau n'est
     * pas une page.
     *
     * @param  array<string, list<string>>  $actions
     */
    private static function ajouterLaRoute(array &$actions, Route $route): void
    {
        $nom = (string) $route->getName();

        if (in_array($nom, self::ROUTES_HORS_INTERFACE, true)) {
            return;
        }

        if (str_starts_with($nom, 'filament.') && in_array('GET', $route->methods(), true)) {
            $classe = (string) $route->getControllerClass();

            if (str_starts_with($classe, 'App\\')) {
                self::ajouter($actions, (string) (new ReflectionClass($classe))->getFileName(), ['page']);
            } elseif (is_subclass_of($classe, Page::class)) {
                self::ajouter($actions, self::fournisseurDuPanneau(explode('.', $nom)[1]), ['page:'.class_basename($classe)]);
            }

            return;
        }

        if (str_starts_with((string) $route->getControllerClass(), 'App\\Http\\Controllers\\')) {
            $genre = in_array('GET', $route->methods(), true) ? 'page publique:' : 'formulaire:';
            self::ajouter($actions, base_path('routes/web.php'), [$genre.'/'.ltrim($route->uri(), '/')]);
        }
    }

    /**
     * Le fichier du fournisseur du panneau de cet identifiant
     * (app/Providers/Filament).
     */
    private static function fournisseurDuPanneau(string $panneau): string
    {
        foreach (File::files(app_path('Providers/Filament')) as $fichier) {
            if (str_contains($fichier->getContents(), "->id('{$panneau}')")) {
                return $fichier->getPathname();
            }
        }

        throw new LogicException("Aucun fournisseur de app/Providers/Filament ne déclare le panneau {$panneau}.");
    }

    /**
     * @param  array<string, list<string>>  $actions
     * @param  list<string>  $elements
     */
    private static function ajouter(array &$actions, string $chemin, array $elements): void
    {
        if ($elements === []) {
            return;
        }

        $relatif = ltrim(str_replace([base_path(), '\\'], ['', '/'], $chemin), '/');
        $actions[$relatif] = [...($actions[$relatif] ?? []), ...$elements];
    }

    /**
     * Les alias que ce code donne à une classe (use A\B\Action as
     * ActionDeChamp), vers le nom court de la classe.
     *
     * @return array<string, string>
     */
    private static function alias(string $code): array
    {
        preg_match_all('/^use\s+([\w\\\\]+)\s+as\s+(\w+)\s*;/m', $code, $uses, PREG_SET_ORDER);

        return array_combine(array_column($uses, 2), array_map(fn (array $use): string => self::nomCourt($use[1]), $uses));
    }

    private static function nomCourt(string $classe): string
    {
        $morceaux = explode('\\', $classe);

        return (string) end($morceaux);
    }
}
