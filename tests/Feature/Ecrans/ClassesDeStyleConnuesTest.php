<?php

namespace Tests\Feature\Ecrans;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aucun écran n'utilise une classe de style absente de la feuille de
 * Filament ou de nos feuilles de public/css/app (constat de l'éditeur du
 * 15/11/2026 sur la page des charges, lot DATEOP) : les panneaux ne
 * chargent que la feuille déjà compilée de Filament et nos feuilles écrites
 * à la main (aucun outil Node, règle « Outillage ») ; une classe Tailwind
 * que Filament n'utilise pas lui-même n'existe dans aucune de ces feuilles
 * et ne fait rien, sans aucune erreur. Ce test lit toutes les vues Blade des
 * panneaux et les chaînes de classes écrites dans app (HtmlString,
 * extraAttributes, tableaux de couleurs, match), et nomme le fichier et la
 * classe de chaque classe utilitaire qu'aucune feuille ne définit. Une
 * classe nouvelle se vérifie en le lançant.
 */
class ClassesDeStyleConnuesTest extends TestCase
{
    /**
     * Les feuilles que chargent les trois panneaux : celles de Filament,
     * déjà compilées, et nos feuilles écrites à la main, publiées dans
     * public/css/app par « php artisan filament:assets ».
     */
    private const FEUILLES_DE_FILAMENT = [
        'public/css/filament/filament/app.css',
        'public/css/filament/forms/forms.css',
        'public/css/filament/support/support.css',
    ];

    /**
     * Les dossiers des vues rendues dans un panneau.
     */
    private const DOSSIERS_DES_VUES = [
        'resources/views/filament',
        'resources/views/components',
        'resources/views/vendor/filament-notifications',
        'resources/views/vendor/filament-panels',
    ];

    /**
     * Les vues de ces dossiers qui se dessinent seules, par leur propre
     * balise <style> ou leur attribut style, et que ce test ne lit donc pas
     * (liste fermée : chaque entrée doit exister).
     */
    private const VUES_AUTONOMES = [
        'resources/views/filament/requete-refusee.blade.php' => 'avis d\'une requête refusée, dessiné par sa propre balise <style>, qui doit tenir même quand la feuille de Filament n\'est pas chargée',
        'resources/views/components/obligatoire.blade.php' => 'astérisque des pages publiques, qui n\'ont pas la feuille de Filament : dessiné par son attribut style',
    ];

    /**
     * Les préfixes des classes qui ne sont pas des classes utilitaires
     * (liste fermée et motivée).
     */
    private const PREFIXES_HORS_UTILITAIRES = [
        'piliqi-' => 'classes propres à PILIQI : crochets des tests et du script de la page, ou règles de nos feuilles, que nos feuilles et nos tests en navigateur gardent',
        'fi-' => 'classes des composants de Filament, qui servent de crochets à ses scripts et à ses feuilles',
    ];

    public function test_aucun_ecran_n_utilise_une_classe_absente_des_feuilles(): void
    {
        $connues = self::classesConnues();
        $absentes = [];

        foreach (self::classesEcrites() as $fichier => $classes) {
            foreach ($classes as $classe) {
                if (! isset($connues[$classe]) && ! self::horsUtilitaires($classe)) {
                    $absentes[] = $fichier.' : '.$classe;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($absentes)), "Classes de style qu'aucune feuille des panneaux ne définit : remplacez-les par une classe de la feuille de Filament ou écrivez la règle dans une feuille de resources/css (publiée dans public/css/app).");
    }

    public function test_chaque_vue_autonome_exclue_existe_et_porte_son_propre_style(): void
    {
        foreach (array_keys(self::VUES_AUTONOMES) as $vue) {
            $this->assertFileExists(base_path($vue));
            $this->assertMatchesRegularExpression('/<style|\sstyle="/', (string) file_get_contents(base_path($vue)), $vue);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function extraits(): array
    {
        return [
            'attribut class d\'une vue' => ['<div class="flex bg-success-100 {{ $x }}">', ['flex', 'bg-success-100']],
            'parties dynamiques retirées, chaînes gardées' => ['<span class="p-2 {{ $choisie ? \'ring-2 ring-primary-500\' : \'\' }}">', ['p-2', 'ring-2', 'ring-primary-500']],
            'directive @class' => ['<li @class([\'text-sm\', \'text-danger-700\' => $erreur])>', ['text-sm', 'text-danger-700']],
            'attribut :class d\'Alpine' => ['<b :class="{ \'font-bold text-gray-950\': ouvert }">', ['font-bold', 'text-gray-950']],
            'tableau de couleurs d\'un bloc @php' => ["@php \$c = ['succes' => 'bg-success-100 dark:bg-success-500/20']; @endphp", ['bg-success-100', 'dark:bg-success-500/20']],
            'texte français ignoré' => ['<p>{{ \'Toutes les charges\' }}</p>', []],
            'nom de composant ignoré' => ["@livewire('grille-des-graphiques')", []],
        ];
    }

    /**
     * La lecture des vues trouve les classes d'un attribut class, de
     *
     * @class, d'Alpine et des chaînes de classes du code, sans prendre un
     * texte ou un nom de composant pour une classe.
     *
     * @param  list<string>  $attendues
     */
    #[DataProvider('extraits')]
    public function test_la_lecture_d_une_vue_trouve_ses_classes(string $vue, array $attendues): void
    {
        $this->assertSame($attendues, array_values(array_unique(self::classesDUneVue($vue))));
    }

    public function test_la_lecture_du_code_trouve_les_classes_des_chaines(): void
    {
        $code = <<<'PHP'
            <?php
            $a = new HtmlString('<span class="text-success-600 font-medium">x</span>');
            $b = ['class' => 'mt-4 gap-0.5'];
            $c = match ($etat) { 'ok' => 'bg-warning-50 text-warning-700', default => 'danger' };
            $d = 'heroicon-o-check';
            $e = 'Ce choix n\'existe pas';
            PHP;

        $this->assertSame(
            ['text-success-600', 'font-medium', 'mt-4', 'gap-0.5', 'bg-warning-50', 'text-warning-700'],
            self::classesDuCode($code),
        );
    }

    public function test_les_classes_connues_se_lisent_dans_les_selecteurs_echappes(): void
    {
        $connues = self::classesDUneFeuille('.dark\:bg-white\/5:is(.dark *){x:y}.sm\:grid-cols-2{x:y}@media (min-width:40rem){.gap-0\.5,.a>.b:hover{x:y}}.\!p-0{x:y}.\32xl\:mt-2{x:y}');

        $this->assertSame(['dark:bg-white/5', 'dark', 'sm:grid-cols-2', 'gap-0.5', 'a', 'b', '!p-0', '2xl:mt-2'], array_keys($connues));
    }

    /**
     * @return array<string, true>
     */
    private static function classesConnues(): array
    {
        $connues = [];
        $feuilles = array_map(base_path(...), self::FEUILLES_DE_FILAMENT);
        foreach (File::glob(public_path('css/app/*.css')) as $feuille) {
            $feuilles[] = $feuille;
        }
        foreach ($feuilles as $feuille) {
            $connues += self::classesDUneFeuille((string) file_get_contents($feuille));
        }

        return $connues;
    }

    /**
     * Les classes nommées dans les sélecteurs d'une feuille, échappements
     * défaits (« \: », « \/ », « \. », « \32 » d'un chiffre en tête).
     *
     * @return array<string, true>
     */
    private static function classesDUneFeuille(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/([^{}]+)\{/', $css, $preludes);
        $classes = [];
        foreach ($preludes[1] as $prelude) {
            if (str_starts_with(trim($prelude), '@')) {
                continue;
            }
            preg_match_all('/\.((?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[A-Za-z0-9_-]|[^\x00-\x7F])+)/', $prelude, $noms);
            foreach ($noms[1] as $nom) {
                $classes[self::sansEchappement($nom)] = true;
            }
        }

        return $classes;
    }

    private static function sansEchappement(string $nom): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? mb_chr((int) hexdec($m[1])) : $m[2],
            $nom,
        );
    }

    /**
     * Les classes écrites dans chaque vue d'un panneau et chaque fichier
     * PHP de app, par fichier.
     *
     * @return array<string, list<string>>
     */
    private static function classesEcrites(): array
    {
        $ecrites = [];
        foreach (self::DOSSIERS_DES_VUES as $dossier) {
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $chemin = Str::after($fichier->getPathname(), base_path().DIRECTORY_SEPARATOR);
                if (str_ends_with($chemin, '.blade.php') && ! isset(self::VUES_AUTONOMES[$chemin])) {
                    $ecrites[$chemin] = self::classesDUneVue($fichier->getContents());
                }
            }
        }
        foreach (File::allFiles(app_path()) as $fichier) {
            if ($fichier->getExtension() === 'php') {
                $ecrites[Str::after($fichier->getPathname(), base_path().DIRECTORY_SEPARATOR)] = self::classesDuCode($fichier->getContents());
            }
        }

        return array_filter($ecrites);
    }

    /**
     * Les classes d'une vue Blade : chaque mot d'un attribut class (ou
     * wire:loading.class, x-transition:…), ses parties dynamiques retirées,
     * puis les chaînes de classes de ces parties dynamiques, de @class,
     * des attributs :class et x-bind:class et des blocs @php.
     *
     * @return list<string>
     */
    private static function classesDUneVue(string $vue): array
    {
        $vue = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $vue);
        $classes = [];
        preg_match_all('/(?<![\w:.-])(?:class|wire:[a-z.]*\.class(?:\.remove)?|x-transition:[a-z-]+)\s*=\s*"([^"]*)"/', $vue, $attributs);
        foreach ($attributs[1] as $valeur) {
            $statique = (string) preg_replace(['/\{\{.*?\}\}|\{!!.*?!!\}/s', '/@[a-z]+(\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/'], ' ', $valeur);
            foreach (preg_split('/\s+/', trim($statique), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
                $classes[] = $mot;
            }
        }
        preg_match_all('/\{\{.*?\}\}|\{!!.*?!!\}|@class\s*\((?:[^()]|\([^()]*\))*\)|(?:x-bind)?:class\s*=\s*"[^"]*"|@php.*?@endphp|@php\s*\((?:[^()]|\([^()]*\))*\)/s', $vue, $dynamiques);
        foreach ($dynamiques[0] as $dynamique) {
            if (preg_match('/^(?:x-bind)?:class\s*=\s*"(.*)"$/s', $dynamique, $alpine) === 1) {
                $dynamique = $alpine[1];
            }
            foreach (self::chainesDeClasses($dynamique) as $mot) {
                $classes[] = $mot;
            }
        }

        return $classes;
    }

    /**
     * Les classes d'un fichier PHP : celles d'un attribut class écrit dans
     * une chaîne (HtmlString, sprintf…), puis chaque chaîne qui n'est faite
     * que de classes utilitaires (extraAttributes, tableaux de couleurs,
     * match).
     *
     * @return list<string>
     */
    private static function classesDuCode(string $code): array
    {
        $classes = [];
        foreach (token_get_all($code) as $jeton) {
            if (! is_array($jeton) || ! in_array($jeton[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $chaine = $jeton[0] === T_CONSTANT_ENCAPSED_STRING ? stripcslashes(substr($jeton[1], 1, -1)) : $jeton[1];
            if (preg_match_all('/(?<![\w:.-])class\s*=\s*\\\\?["\']([^"\'\\\\]*)/', $chaine, $attributs) > 0) {
                foreach ($attributs[1] as $valeur) {
                    foreach (preg_split('/\s+/', trim($valeur), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
                        $classes[] = $mot;
                    }
                }

                continue;
            }
            foreach (self::motsSiClasses($chaine) as $mot) {
                $classes[] = $mot;
            }
        }

        return $classes;
    }

    /**
     * Les classes des chaînes littérales d'un bout de code (PHP d'une vue,
     * expression d'Alpine).
     *
     * @return list<string>
     */
    private static function chainesDeClasses(string $code): array
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $code, $chaines, PREG_SET_ORDER);
        $classes = [];
        foreach ($chaines as $chaine) {
            foreach (self::motsSiClasses(stripcslashes(($chaine[2] ?? '') !== '' ? $chaine[2] : $chaine[1])) as $mot) {
                $classes[] = $mot;
            }
        }

        return $classes;
    }

    /**
     * Les mots d'une chaîne qui n'est faite que de classes utilitaires,
     * dont au moins une se reconnaît à coup sûr (couleur, marge, taille,
     * grille…) ; aucun mot pour un texte, un nom d'icône, de route ou de
     * composant.
     *
     * @return list<string>
     */
    private static function motsSiClasses(string $chaine): array
    {
        $mots = preg_split('/\s+/', trim($chaine), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($mots === []) {
            return [];
        }
        $variantes = '(?:[a-z0-9-]+:)*!?-?';
        $unMot = '/^'.$variantes.'[a-z][a-z0-9-]*(?:\.[0-9]+)?(?:\/[0-9]+)?(?:-\[[^\]\s]+\])?$/';
        $sur = '/^'.$variantes.'(?:bg|text|border|ring|divide|p[xytrbse]?|m[xytrbse]?|gap|space-[xy]|w|h|size|min-w|max-w|min-h|max-h|font|rounded|grid-cols|col-span|row-span|items|justify|shadow|leading|tracking|whitespace|overflow|line-clamp|underline-offset|translate-[xy])-[a-z0-9]/';
        $aucunSur = true;
        foreach ($mots as $mot) {
            if (preg_match($unMot, $mot) !== 1) {
                return [];
            }
            if (preg_match($sur, $mot) === 1) {
                $aucunSur = false;
            }
        }

        return $aucunSur ? [] : $mots;
    }

    private static function horsUtilitaires(string $classe): bool
    {
        foreach (array_keys(self::PREFIXES_HORS_UTILITAIRES) as $prefixe) {
            if (str_starts_with($classe, $prefixe)) {
                return true;
            }
        }

        return false;
    }
}
