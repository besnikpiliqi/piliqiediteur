<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Tenancy\Support\ValeurSimple;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Throwable;

/**
 * Les dossiers de fichiers du site de démonstration (lot DEMO-COPIE, C.2,
 * C.3 et C.18), sous storage/demonstration : ceux des modèles (par nom de
 * base), des copies (par référence) et des archives. Chaque nom passe par
 * son motif avant tout usage. La copie d'un dossier est faite par PHP seul,
 * qui tourne partout (le poste de l'éditeur est sous Windows, où `cp`
 * n'existe pas) ; sous Linux, `cp --reflink=auto` ne sert que d'accélération,
 * par un processus à tableau d'arguments, jamais par une chaîne de shell,
 * avec repli sur PHP s'il est absent ou échoue.
 */
final class DossiersDeLaDemonstration
{
    public const MOTIF_D_UNE_REFERENCE = '/^[0-9a-f]{32}$/';

    public const MESSAGE_LIEN_SYMBOLIQUE = 'Le modèle porte un lien symbolique (%s) : aucune copie n\'en est faite.';

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
     * Les fichiers du modèle recopiés pour une copie, avec leurs droits et
     * leur date de modification. Un lien symbolique dans le modèle est
     * refusé, avant toute copie : il ferait lire ou écrire hors du dossier
     * du modèle. Sous Linux, le système de fichiers clone les fichiers
     * quand il le permet (`cp -a --reflink=auto`) ; partout ailleurs, ou si
     * `cp` manque ou échoue, PHP copie lui-même, fichier par fichier.
     *
     * @throws RuntimeException quand le modèle porte un lien symbolique ou qu'un fichier ne se copie pas
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

        self::refuserUnLienSymbolique($source);

        if (self::cloner($source, $cible)) {
            return;
        }

        File::deleteDirectory($cible);

        try {
            self::copierEnPhp($source, $cible);
        } catch (Throwable $erreur) {
            File::deleteDirectory($cible);

            throw new RuntimeException('Les fichiers du modèle n\'ont pas pu être recopiés : '.$erreur->getMessage(), 0, $erreur);
        }
    }

    /**
     * Le dossier du modèle ne porte, ni lui-même ni dedans, aucun lien
     * symbolique.
     *
     * @throws RuntimeException au premier lien trouvé
     */
    private static function refuserUnLienSymbolique(string $source): void
    {
        if (is_link($source)) {
            throw new RuntimeException(sprintf(self::MESSAGE_LIEN_SYMBOLIQUE, $source));
        }

        foreach (Finder::create()->in($source)->ignoreDotFiles(false)->ignoreVCS(false) as $element) {
            if ($element->isLink()) {
                throw new RuntimeException(sprintf(self::MESSAGE_LIEN_SYMBOLIQUE, $element->getPathname()));
            }
        }
    }

    /**
     * Le clonage du système de fichiers, sous Linux seulement : vrai quand
     * `cp` a recopié le dossier, faux quand il n'y en a pas, qu'il manque,
     * ou qu'il a échoué (le dossier copié à moitié est alors à effacer).
     */
    private static function cloner(string $source, string $cible): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        try {
            return Process::run(['cp', '-a', '--reflink=auto', $source, $cible])->successful() && File::isDirectory($cible);
        } catch (ProcessExceptionInterface) {
            return false;
        }
    }

    private static function copierEnPhp(string $source, string $cible): void
    {
        File::ensureDirectoryExists($cible);

        foreach (Finder::create()->in($source)->ignoreDotFiles(false)->ignoreVCS(false) as $element) {
            $destination = $cible.'/'.str_replace('\\', '/', $element->getRelativePathname());

            if ($element->isDir()) {
                File::ensureDirectoryExists($destination);

                continue;
            }

            if (! copy($element->getPathname(), $destination)) {
                throw new RuntimeException(sprintf('« %s » ne se copie pas.', $element->getRelativePathname()));
            }

            chmod($destination, $element->getPerms() & 0777);
            touch($destination, $element->getMTime());
        }
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
