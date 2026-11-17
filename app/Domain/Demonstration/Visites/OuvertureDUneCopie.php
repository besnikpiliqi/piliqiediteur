<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Exceptions\NomDeBaseHorsDuMotif;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        $acces = [
            'database' => (string) config('database.connections.pgsql.database'),
            'username' => (string) config('database.connections.pgsql.username'),
            'password' => (string) config('database.connections.pgsql.password'),
        ];
        $disques = (array) config('filesystems.disks');
        $cache = config('cache.default');

        try {
            $this->ouvrir($copie);

            return $geste();
        } finally {
            config(['cache.default' => $cache]);
            BrancherUneBase::brancher($acces);
            config(['filesystems.disks' => $disques]);

            foreach (array_keys($disques) as $nom) {
                Storage::forgetDisk((string) $nom);
            }
        }
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
    }
}
