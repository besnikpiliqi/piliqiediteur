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

/**
 * Les dossiers de fichiers du site de démonstration (lot DEMO-COPIE, C.2,
 * C.3 et C.18), sous storage/demonstration : ceux des modèles (par nom de
 * base), des copies (par référence) et des archives. Chaque nom passe par
 * son motif avant tout usage, et la copie d'un dossier passe par un
 * processus à tableau d'arguments, jamais par une chaîne de shell.
 */
final class DossiersDeLaDemonstration
{
    public const MOTIF_D_UNE_REFERENCE = '/^[0-9a-f]{32}$/';

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
     * Les fichiers du modèle recopiés pour une copie (cp -a, en clone quand
     * le système de fichiers le permet).
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

        $resultat = Process::run(['cp', '-a', '--reflink=auto', $source, $cible]);

        if (! $resultat->successful()) {
            throw new RuntimeException('Les fichiers du modèle n\'ont pas pu être recopiés : '.trim($resultat->errorOutput()));
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
