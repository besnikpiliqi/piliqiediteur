<?php

namespace App\Domain\Demonstration\Taches;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * La place des copies (lot DEMO-COPIE, C.16) : chaque heure, la taille de
 * la base de chaque copie servie (pg_database_size) et de tout son dossier
 * (pièces déposées, ZIP et PDF de « Télécharger les documents », envois
 * temporaires). Au-delà de DEMONSTRATION_FACTEUR_DE_PLACE fois la taille
 * de son modèle (base et dossier), la copie passe en lecture seule : la
 * consultation reste, chaque écriture est refusée avec MESSAGE ; revenue
 * sous la limite, elle se sert de nouveau. Une pièce déposée qui ferait
 * dépasser la place est refusée avec le même message (voir
 * ControleDesFichiers), et la consultation reste toujours.
 */
final class PlaceDesCopies
{
    public const MESSAGE = 'Votre démonstration a atteint sa place. « Recommencer à zéro », dans votre compte, la libère.';

    private static bool $gestePermis = false;

    /**
     * Ce qui reste permis dans une copie en lecture seule : le changement
     * de compte du bandeau (sa fin de session, sa connexion et leur trace),
     * pour y consulter encore sous chaque compte, et les tâches du
     * calendrier des magasins (les clôtures du jour suivent la règle de la
     * caisse).
     *
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    public static function pendantUnGestePermis(Closure $geste): mixed
    {
        self::$gestePermis = true;

        try {
            return $geste();
        } finally {
            self::$gestePermis = false;
        }
    }

    /**
     * Une écriture d'un modèle est-elle refusée maintenant : la copie
     * ouverte est en lecture seule, et la requête en cours est un geste
     * (POST, PUT, PATCH, DELETE), hors d'un geste permis ? Une page qui ne
     * fait que s'afficher (GET), avec les traces que sa consultation laisse
     * (ouverture de session au journal des événements, JET 80), et les
     * travaux et les tâches de la copie restent permis : la consultation
     * reste.
     */
    public static function refuseLesEcritures(): bool
    {
        return ! self::$gestePermis
            && (OuvertureDUneCopie::courante()['en_lecture_seule'] ?? false) === true
            && ! request()->isMethodSafe();
    }

    /**
     * Ce dépôt de tant d'octets ferait-il dépasser sa place à la copie
     * ouverte (sa base et son dossier, mesurés maintenant) ? Toujours non
     * hors d'une copie ; toujours oui dans une copie en lecture seule.
     */
    public static function depasseraitLaPlace(int $octets): bool
    {
        $ouverte = OuvertureDUneCopie::courante();

        if ($ouverte === null) {
            return false;
        }

        if ($ouverte['en_lecture_seule']) {
            return true;
        }

        $modele = Copie::query()->with('modele')->where('reference', $ouverte['reference'])->first()?->modele;

        if ($modele === null) {
            return false;
        }

        $occupee = (int) DB::connection()->scalar('select pg_database_size(current_database())') + self::tailleDuDossier(DossiersDeLaDemonstration::dUneCopie($ouverte['reference']));

        return $occupee + $octets > self::limiteDe($modele);
    }

    /**
     * La place d'une copie de ce modèle : DEMONSTRATION_FACTEUR_DE_PLACE
     * fois la taille du modèle, sa base (mesurée à sa construction) et son
     * dossier de fichiers.
     */
    public static function limiteDe(Modele $modele): int
    {
        $base = $modele->taille ?? self::tailleDeLaBase($modele->nom_de_la_base);

        return max(1, (int) config('demonstration.facteur_de_place')) * ($base + self::tailleDuDossier(DossiersDeLaDemonstration::duModele($modele->nom_de_la_base)));
    }

    /**
     * @return array{mesurees: int, en_lecture_seule: list<string>}
     */
    public function mesurer(): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $resultat = ['mesurees' => 0, 'en_lecture_seule' => []];
        $modeles = Modele::on('demo_admin')->get()->keyBy('id');

        foreach (Copie::on('demo_admin')->whereIn('etat', [EtatDUneCopie::Prete->value, EtatDUneCopie::EnLectureSeule->value])->orderBy('id')->get() as $copie) {
            $modele = $modeles->get($copie->modele_id);

            if ($modele === null || $copie->nom_de_la_base === null) {
                continue;
            }

            $taille = self::tailleDeLaBase($copie->nom_de_la_base) + self::tailleDuDossier(DossiersDeLaDemonstration::dUneCopie($copie->reference));
            $etat = $taille > self::limiteDe($modele) ? EtatDUneCopie::EnLectureSeule : EtatDUneCopie::Prete;
            $copie->forceFill(['taille_mesuree' => $taille, 'etat' => $etat])->save();
            $resultat['mesurees']++;

            if ($etat === EtatDUneCopie::EnLectureSeule) {
                $resultat['en_lecture_seule'][] = $copie->reference;
            }
        }

        return $resultat;
    }

    public static function tailleDeLaBase(string $base): int
    {
        return (int) DB::connection('demo_admin')->scalar('select coalesce(pg_database_size(datname), 0) from pg_database where datname = ?', [$base]);
    }

    public static function tailleDuDossier(string $dossier): int
    {
        if (! File::isDirectory($dossier)) {
            return 0;
        }

        return array_sum(array_map(static fn (SplFileInfo $fichier): int => (int) $fichier->getSize(), File::allFiles($dossier, true)));
    }
}
