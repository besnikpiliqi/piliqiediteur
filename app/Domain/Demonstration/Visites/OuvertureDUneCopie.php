<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Exceptions\NomDeBaseHorsDuMotif;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ce qui fait lire et écrire une requête, ou un travail, dans sa copie (lot
 * DEMO-COPIE, C.6.4) : la connexion par défaut sur la base de la copie,
 * par le rôle des pages ; le cache par défaut (celui du registre hors
 * d'une copie), ses verrous, le limiteur de débit et la session sur cette
 * connexion, refaits ; la racine de chaque disque
 * local de config/filesystems.php sous le dossier de la copie. Ainsi les
 * limites d'envoi et d'essais et les verrous des travaux uniques restent
 * propres à chaque copie. Le nom de la base vient du registre, jamais de la
 * requête, et passe par son motif avant tout usage ; la base doit porter la
 * marque « demonstration ».
 *
 * Un travail du site de démonstration (l'archive d'une copie, C.18) l'ouvre
 * le temps d'un geste (pendant()) : la connexion et les disques reviennent
 * ensuite à ce qu'ils étaient, même après un échec, pour que le travail
 * suivant du même processus ne lise jamais cette copie.
 */
final class OuvertureDUneCopie
{
    private const COPIE_OUVERTE = 'demonstration.copie-ouverte';

    /**
     * Le geste, dans cette copie ; la connexion par défaut et la racine
     * des disques locaux reviennent ensuite à ce qu'elles étaient.
     *
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    public function pendant(Copie $copie, Closure $geste): mixed
    {
        $fermer = $this->ouvrirJusquA($copie);

        try {
            return $geste();
        } finally {
            $fermer();
        }
    }

    /**
     * Ouvre cette copie, et rend ce qui la referme : la connexion par
     * défaut, le cache, la racine des disques locaux et la copie ouverte
     * reviennent à ce qu'ils étaient avant elle (un travail de la file,
     * voir TravauxDesCopies). Rien ne reste ouvert quand l'ouverture
     * échoue.
     *
     * @return Closure(): void
     *
     * @throws NomDeBaseHorsDuMotif
     */
    public function ouvrirJusquA(Copie $copie): Closure
    {
        $acces = [
            'database' => (string) config('database.connections.pgsql.database'),
            'username' => (string) config('database.connections.pgsql.username'),
            'password' => (string) config('database.connections.pgsql.password'),
        ];
        $disques = (array) config('filesystems.disks');
        $cache = config('cache.default');
        $avant = app()->bound(self::COPIE_OUVERTE) ? app(self::COPIE_OUVERTE) : null;
        $fermer = static function () use ($acces, $disques, $cache, $avant): void {
            config(['cache.default' => $cache]);
            BrancherUneBase::brancher($acces);
            config(['filesystems.disks' => $disques]);
            app()->instance(self::COPIE_OUVERTE, $avant);

            foreach (array_keys($disques) as $nom) {
                Storage::forgetDisk((string) $nom);
            }
        };

        try {
            $this->ouvrir($copie);
        } catch (Throwable $erreur) {
            $fermer();

            throw $erreur;
        }

        return $fermer;
    }

    /**
     * La copie ouverte dans ce processus (requête, travail, tâche) : sa
     * référence, le nom de sa base et son état, ou null hors d'une copie.
     * Un travail envoyé depuis une copie emporte sa référence et sa base
     * (voir TravauxDesCopies), et une copie en lecture seule refuse toute
     * écriture (C.16).
     *
     * @return array{reference: string, base: string, en_lecture_seule: bool}|null
     */
    public static function courante(): ?array
    {
        $ouverte = app()->bound(self::COPIE_OUVERTE) ? app(self::COPIE_OUVERTE) : null;

        return is_array($ouverte) && is_string($ouverte['reference'] ?? null) && is_string($ouverte['base'] ?? null)
            ? ['reference' => $ouverte['reference'], 'base' => $ouverte['base'], 'en_lecture_seule' => ($ouverte['en_lecture_seule'] ?? false) === true]
            : null;
    }

    /**
     * @throws NomDeBaseHorsDuMotif quand le nom de base lu au registre n'est pas du motif d'une copie
     */
    public function ouvrir(Copie $copie): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $base = (string) $copie->nom_de_la_base;

        if (preg_match('/^demo_copie_[a-z0-9]{26}$/', $base) !== 1) {
            throw new NomDeBaseHorsDuMotif('Le nom de base d\'une copie au registre n\'est pas du motif d\'une copie : la requête est arrêtée.');
        }

        config(['cache.default' => 'database']);
        BrancherUneBase::brancher(BrancherUneBase::parLesPages($base));
        DossiersDeLaDemonstration::enracinerLesDisques(DossiersDeLaDemonstration::dUneCopie($copie->reference));

        MarqueDeLaBase::exigerPour(SiteDePiliqi::Demonstration, DB::connection());
        app()->instance(self::COPIE_OUVERTE, ['reference' => $copie->reference, 'base' => $base, 'en_lecture_seule' => $copie->etat === EtatDUneCopie::EnLectureSeule]);
    }
}
