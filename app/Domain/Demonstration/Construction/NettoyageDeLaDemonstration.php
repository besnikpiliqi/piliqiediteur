<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Archives\ArchiverUneCopie;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Le nettoyage de chaque nuit du site de démonstration, après la
 * construction des modèles (lot DEMO-COPIE, C.2), par demo_admin. Il ne
 * lit que des noms de bases et ne supprime que ce qui répond au motif
 * exact des bases de la démonstration :
 *
 * - une base de copie absente du registre (le registre n'est pas
 *   sauvegardé) : archive de sa caisse quand sa marque « demonstration »
 *   se lit (une archive qui échoue la garde et le dit), puis suppression ;
 * - une copie du registre supprimée dont la base reste : suppression, après
 *   son archive si elle n'en a aucune ; une copie terminée (sa fin passée)
 *   qui n'est pas encore supprimée : archive puis suppression, comme à 30
 *   jours ;
 * - une base de travail de plus de 6 heures qu'aucun modèle vivant ne
 *   pointe : suppression (elle n'a jamais servi de copie) ;
 * - un dossier de fichiers sans copie ni modèle vivants : effacé ;
 * - une copie servie dont la base n'existe plus : marquée « perdue » et
 *   refaite aussitôt depuis le modèle, même référence, même lien, même
 *   fin, avec le message « Votre démonstration a dû être refaite le … »
 *   (E.7 ; sans base, il n'y a rien à archiver). La visite suivante la
 *   trouve refaite, ou « Votre démonstration se prépare ».
 *
 * Il refuse d'agir, et le dit dans l'état publié (une alerte à l'éditeur),
 * quand le registre ne compte aucune copie alors que des bases de copie
 * existent, ou quand les bases de copie absentes du registre dépassent
 * 10 % des copies vivantes : un registre vide ou mal pointé ne fait jamais
 * tout disparaître.
 */
final class NettoyageDeLaDemonstration
{
    /**
     * La clé, dans le cache du registre, du refus du dernier nettoyage,
     * que l'état publié montre (C.19).
     */
    public const CLE_DU_REFUS = 'demonstration:nettoyage-refuse';

    public const HEURES_D_UNE_BASE_DE_TRAVAIL = 6;

    public const PART_MAXIMALE_D_ORPHELINES = 10;

    public const MESSAGE_REGISTRE_VIDE = 'Le nettoyage a refusé d\'agir : le registre ne compte aucune copie alors que %d base(s) de copie existent sur le serveur.';

    public const MESSAGE_TROP_D_ORPHELINES = 'Le nettoyage a refusé d\'agir : %d base(s) de copie sont absentes du registre, plus de %d %% des %d copies vivantes.';

    public const MESSAGE_ARCHIVE_ORPHELINE = 'Le nettoyage a gardé la base « %s », absente du registre : l\'archive de sa caisse a échoué (%s).';

    public function __construct(
        private readonly BasesDeLaDemonstration $bases,
        private readonly ArchiverUneCopie $archive,
        private readonly FinDesCopies $fin,
    ) {}

    /**
     * @param  list<string>|null  $basesDuServeur  les bases de la démonstration du serveur (par défaut, celles que lit BasesDeLaDemonstration::lister()) ; un test qui partage le serveur avec d'autres processus ne donne que les siennes
     * @return array{refus: string|null, supprimees: list<string>, gardees: list<string>, perdues: list<string>, dossiers: int}
     */
    public function nettoyer(?array $basesDuServeur = null): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $maintenant = CarbonImmutable::now();
        $bases = array_values(array_filter($basesDuServeur ?? $this->bases->lister(), static fn (string $nom): bool => preg_match(BasesDeLaDemonstration::MOTIF, $nom) === 1));
        $basesDeCopie = array_values(array_filter($bases, static fn (string $nom): bool => preg_match(BasesDeLaDemonstration::MOTIF_D_UNE_COPIE, $nom) === 1));
        $copies = Copie::on('demo_admin')->get(['id', 'reference', 'repere', 'type', 'validee', 'nom_de_la_base', 'etat', 'creee_le', 'prete_le', 'fin']);
        $connues = $copies->pluck('nom_de_la_base')->filter()->all();
        $orphelines = array_values(array_diff($basesDeCopie, $connues));
        $vivantes = $copies->filter(static fn (Copie $copie): bool => $copie->etat !== EtatDUneCopie::Supprimee)->count();
        $refus = match (true) {
            $copies->isEmpty() && $basesDeCopie !== [] => sprintf(self::MESSAGE_REGISTRE_VIDE, count($basesDeCopie)),
            count($orphelines) * 100 > self::PART_MAXIMALE_D_ORPHELINES * $vivantes && $orphelines !== [] => sprintf(self::MESSAGE_TROP_D_ORPHELINES, count($orphelines), self::PART_MAXIMALE_D_ORPHELINES, $vivantes),
            default => null,
        };
        $resultat = ['refus' => $refus, 'supprimees' => [], 'gardees' => [], 'perdues' => [], 'dossiers' => 0];

        if ($refus !== null) {
            Cache::store('registre')->put(self::CLE_DU_REFUS, $refus, 26 * 3600);

            return $resultat;
        }

        Cache::store('registre')->forget(self::CLE_DU_REFUS);

        foreach ($orphelines as $nom) {
            $this->nettoyerUneOrpheline($nom, $resultat);
        }

        foreach ($copies as $copie) {
            $base = $copie->nom_de_la_base;
            $existe = $base !== null && in_array($base, $basesDeCopie, true);

            if ($copie->etat === EtatDUneCopie::Supprimee && $existe) {
                $this->supprimerApresSonArchive($copie, $resultat);
            } elseif ($copie->etat !== EtatDUneCopie::Supprimee && $copie->estEchue($maintenant)) {
                $this->terminer($copie, $resultat);
            } elseif ($copie->etat->seSert() && ! $existe) {
                $copie->forceFill(['etat' => EtatDUneCopie::Perdue])->save();
                Bus::dispatch(new RefaireUneCopie($copie->reference, FinDesCopies::MOTIF_PERDUE));
                $resultat['perdues'][] = $copie->reference;
            }
        }

        $this->supprimerLesBasesDeTravail($bases, $maintenant, $resultat);
        $resultat['dossiers'] = $this->effacerLesDossiersOrphelins();

        return $resultat;
    }

    /**
     * Le refus du dernier nettoyage, tant qu'il n'a pas été suivi d'un
     * nettoyage qui a pu agir.
     */
    public static function dernierRefus(): ?string
    {
        $refus = Cache::store('registre')->get(self::CLE_DU_REFUS);

        return is_string($refus) ? $refus : null;
    }

    /**
     * @param  array{refus: string|null, supprimees: list<string>, gardees: list<string>, perdues: list<string>, dossiers: int}  $resultat
     */
    private function nettoyerUneOrpheline(string $nom, array &$resultat): void
    {
        try {
            $marque = BrancherUneBase::pendant(BrancherUneBase::parLAdministration($nom), static fn (): ?SiteDePiliqi => MarqueDeLaBase::lire(DB::connection()));
        } catch (Throwable) {
            $marque = null;
        }

        if ($marque === SiteDePiliqi::Demonstration) {
            $orpheline = (new Copie)->forceFill([
                'reference' => substr(hash('sha256', $nom), 0, 32),
                'repere' => '------',
                'nom_de_la_base' => $nom,
                'creee_le' => CarbonImmutable::now(),
                'fin' => CarbonImmutable::now(),
            ]);

            try {
                $this->archive->archiver($orpheline, 'nettoyage');
            } catch (ArchiveImpossible $erreur) {
                Cache::store('registre')->put(self::CLE_DU_REFUS, sprintf(self::MESSAGE_ARCHIVE_ORPHELINE, $nom, mb_substr($erreur->getMessage(), 0, 500)), 26 * 3600);
                $resultat['gardees'][] = $nom;

                return;
            }
        }

        $this->bases->supprimer($nom);
        $resultat['supprimees'][] = $nom;
    }

    /**
     * @param  array{refus: string|null, supprimees: list<string>, gardees: list<string>, perdues: list<string>, dossiers: int}  $resultat
     */
    private function supprimerApresSonArchive(Copie $copie, array &$resultat): void
    {
        $base = (string) $copie->nom_de_la_base;

        if (! Archive::on('demo_admin')->where('reference', $copie->reference)->exists()) {
            try {
                $this->archive->archiver($copie, 'nettoyage');
            } catch (ArchiveImpossible) {
                $resultat['gardees'][] = $base;

                return;
            }
        }

        $this->bases->supprimer($base);
        DossiersDeLaDemonstration::effacerUneCopie($copie->reference);
        $resultat['supprimees'][] = $base;
    }

    /**
     * @param  array{refus: string|null, supprimees: list<string>, gardees: list<string>, perdues: list<string>, dossiers: int}  $resultat
     */
    private function terminer(Copie $copie, array &$resultat): void
    {
        try {
            $this->fin->supprimer($copie->reference, 'trente_jours');
        } catch (ArchiveImpossible) {
            $resultat['gardees'][] = (string) $copie->nom_de_la_base;

            return;
        }

        if ($copie->nom_de_la_base !== null) {
            $resultat['supprimees'][] = $copie->nom_de_la_base;
        }
    }

    /**
     * Les bases de travail de plus de 6 heures (l'instant de leur
     * construction est dans leur nom, à l'heure de Paris) qu'aucun modèle
     * en service, précédent ou en construction ne pointe.
     *
     * @param  list<string>  $bases
     * @param  array{refus: string|null, supprimees: list<string>, gardees: list<string>, perdues: list<string>, dossiers: int}  $resultat
     */
    private function supprimerLesBasesDeTravail(array $bases, CarbonImmutable $maintenant, array &$resultat): void
    {
        $vivantes = Modele::on('demo_admin')->whereIn('etat', [EtatDUnModele::EnService->value, EtatDUnModele::Precedent->value, EtatDUnModele::EnConstruction->value])->pluck('nom_de_la_base')->all();

        foreach ($bases as $nom) {
            if (preg_match('/^demo_travail_[a-z]+_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/', $nom, $morceaux) !== 1 || in_array($nom, $vivantes, true)) {
                continue;
            }

            try {
                $commencee = Calendrier::deParis()->heureMurale("{$morceaux[1]}-{$morceaux[2]}-{$morceaux[3]} {$morceaux[4]}:{$morceaux[5]}");
            } catch (Throwable) {
                $commencee = null;
            }

            if ($commencee === null || $commencee->addHours(self::HEURES_D_UNE_BASE_DE_TRAVAIL)->lessThanOrEqualTo($maintenant)) {
                $this->bases->supprimer($nom);
                $resultat['supprimees'][] = $nom;
            }
        }
    }

    /**
     * Les dossiers de fichiers des copies et des modèles qui ne sont plus
     * vivants.
     */
    private function effacerLesDossiersOrphelins(): int
    {
        $copies = Copie::on('demo_admin')->where('etat', '!=', EtatDUneCopie::Supprimee)->pluck('reference')->all();
        $modeles = Modele::on('demo_admin')->whereIn('etat', [EtatDUnModele::EnService->value, EtatDUnModele::Precedent->value, EtatDUnModele::EnConstruction->value])->pluck('nom_de_la_base')->all();
        $effaces = 0;

        foreach (['copies' => $copies, 'modeles' => $modeles] as $sorte => $gardes) {
            $parent = DossiersDeLaDemonstration::racine().'/'.$sorte;

            foreach (File::isDirectory($parent) ? File::directories($parent) : [] as $dossier) {
                if (! in_array(basename($dossier), $gardes, true)) {
                    File::deleteDirectory($dossier);
                    $effaces++;
                }
            }
        }

        return $effaces;
    }
}
