<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Tenancy\Support\ValeurSimple;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Les dossiers de fichiers du site de démonstration (lot DEMO-COPIE, C.2,
 * C.3 et C.18), sous storage/demonstration : ceux des modèles (par nom de
 * base), des copies (par référence) et des archives. Chaque nom passe par
 * son motif avant tout usage. Le dossier d'un modèle se recopie en PHP, sur
 * tout système (le poste de l'éditeur est sous Windows, la production sous
 * Linux), sans aucun programme extérieur : le clone de fichiers de Linux
 * (cp --reflink) n'est qu'une optimisation, essayée seulement sous Linux
 * quand cp existe et réussit, jamais nécessaire.
 */
final class DossiersDeLaDemonstration
{
    public const MOTIF_D_UNE_REFERENCE = '/^[0-9a-f]{32}$/';

    public const MESSAGE_LIEN_DANS_LE_MODELE = 'Le dossier du modèle contient un lien symbolique (« %s ») : un modèle ne porte que des fichiers et des dossiers, rien n\'a été recopié.';

    public static function racine(): string
    {
        return ValeurSimple::enTexte(config('demonstration.dossier'));
    }

    public static function duModele(string $base): string
    {
        if (preg_match(BasesDeLaDemonstration::MOTIF_D_UNE_BASE_DE_TRAVAIL, $base) !== 1) {
            throw new InvalidArgumentException(sprintf(BasesDeLaDemonstration::MESSAGE_NOM_REFUSE, $base));
        }

        return self::racine().'/modeles/'.$base;
    }

    public static function dUneCopie(string $reference): string
    {
        return self::racine().'/copies/'.self::reference($reference);
    }

    public static function desArchives(string $reference): string
    {
        return self::racine().'/archives/'.self::reference($reference);
    }

    /**
     * Les fichiers du modèle recopiés pour une copie : en clone quand le
     * système de fichiers le permet (voir cloner()), sinon en PHP (voir
     * recopierEnPhp()), avec leurs droits. Un lien symbolique dans le
     * modèle le fait refuser avant toute copie : une copie ne suivrait
     * jamais un lien hors de son dossier.
     *
     * @throws RuntimeException quand le modèle contient un lien symbolique ou qu'un fichier ne se recopie pas
     */
    public static function copierLesFichiersDuModele(string $base, string $reference): void
    {
        $source = self::duModele($base);
        $cible = self::dUneCopie($reference);
        File::deleteDirectory($cible);
        File::ensureDirectoryExists(dirname($cible));

        if (! File::isDirectory($source)) {
            File::ensureDirectoryExists($cible);

            return;
        }

        self::refuserUnLienDans($source);

        if (! self::cloner($source, $cible)) {
            File::deleteDirectory($cible);
            self::recopierEnPhp($source, $cible);
        }
    }

    /**
     * Le clone de fichiers de Linux (cp -a --reflink=auto : un clone quand
     * le système de fichiers le permet, une copie sinon), seulement sous
     * Linux et quand cp existe : vrai quand il a réussi, faux sinon (un
     * système autre, cp introuvable, un échec), et la copie se fait alors en
     * PHP. Un processus à tableau d'arguments, jamais une chaîne de shell.
     */
    private static function cloner(string $source, string $cible): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        try {
            return Process::run(['cp', '-a', '--reflink=auto', $source, $cible])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Recopie le dossier en PHP : chaque dossier et chaque fichier garde ses
     * droits (les bits de lecture, d'écriture et d'exécution) et sa date de
     * modification, sous tout système.
     *
     * @throws RuntimeException quand un dossier ne se crée pas ou un fichier ne se recopie pas
     */
    private static function recopierEnPhp(string $source, string $cible): void
    {
        File::ensureDirectoryExists($cible, self::droitsDe($source));
        $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($elements as $element) {
            if (! $element instanceof SplFileInfo) {
                continue;
            }

            $destination = $cible.DIRECTORY_SEPARATOR.substr($element->getPathname(), strlen($source) + 1);

            if ($element->isDir()) {
                File::ensureDirectoryExists($destination, self::droitsDe($element->getPathname()));

                continue;
            }

            if (! copy($element->getPathname(), $destination)) {
                throw new RuntimeException('Les fichiers du modèle n\'ont pas pu être recopiés : « '.$element->getFilename().' » ne se copie pas.');
            }

            @chmod($destination, self::droitsDe($element->getPathname()));
            @touch($destination, (int) $element->getMTime());
        }
    }

    /**
     * @throws RuntimeException quand le dossier est un lien symbolique ou en contient un, à quelque profondeur que ce soit
     */
    private static function refuserUnLienDans(string $dossier): void
    {
        if (is_link($dossier)) {
            throw new RuntimeException(sprintf(self::MESSAGE_LIEN_DANS_LE_MODELE, basename($dossier)));
        }

        $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($elements as $element) {
            if ($element instanceof SplFileInfo && $element->isLink()) {
                throw new RuntimeException(sprintf(self::MESSAGE_LIEN_DANS_LE_MODELE, substr($element->getPathname(), strlen($dossier) + 1)));
            }
        }
    }

    /**
     * Les droits d'un fichier ou d'un dossier (ses neuf bits).
     */
    private static function droitsDe(string $chemin): int
    {
        return fileperms($chemin) & 0777;
    }

    /**
     * Le geste, avec la racine de chaque disque local de
     * config/filesystems.php sous ce dossier (« <dossier>/<disque> ») :
     * les fichiers qu'il écrit (pièces, documents) restent à la base qu'il
     * remplit ; les disques reviennent ensuite à ce qu'ils étaient.
     *
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    public static function pendantLeGesteDans(string $dossier, Closure $geste): mixed
    {
        $disques = (array) config('filesystems.disks');

        try {
            self::enracinerLesDisques($dossier);

            return $geste();
        } finally {
            config(['filesystems.disks' => $disques]);

            foreach (array_keys($disques) as $nom) {
                Storage::forgetDisk((string) $nom);
            }
        }
    }

    /**
     * La racine de chaque disque local de config/filesystems.php sous ce
     * dossier (« <dossier>/<disque> »).
     */
    public static function enracinerLesDisques(string $dossier): void
    {
        foreach ((array) config('filesystems.disks') as $nom => $disque) {
            if (is_array($disque) && ($disque['driver'] ?? null) === 'local') {
                config(["filesystems.disks.{$nom}.root" => $dossier.'/'.$nom]);
                Storage::forgetDisk((string) $nom);
            }
        }
    }

    public static function effacerUneCopie(string $reference): void
    {
        File::deleteDirectory(self::dUneCopie($reference));
    }

    public static function effacerUnModele(string $base): void
    {
        File::deleteDirectory(self::duModele($base));
    }

    private static function reference(string $reference): string
    {
        if (preg_match(self::MOTIF_D_UNE_REFERENCE, $reference) !== 1) {
            throw new InvalidArgumentException('La référence « '.$reference.' » n\'est pas une référence de copie : rien n\'a été fait.');
        }

        return $reference;
    }
}
