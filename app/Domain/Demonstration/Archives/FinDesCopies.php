<?php

namespace App\Domain\Demonstration\Archives;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Jobs\PreparerUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * La fin d'une base de copie, par demo_admin seul (travailleur de la file
 * demonstration-admin) : jamais de DROP DATABASE sans l'archive de sa
 * caisse, faite et relue (C.18, voir ArchiverUneCopie).
 *
 * - supprimer() (message « supprimer » du site réel, C.7 ; fin des 30
 *   jours, C.14) : « en archivage », archive, « en suppression », base
 *   supprimée, dossier de fichiers effacé, travaux de la copie effacés des
 *   files, liens et témoins retirés, « supprimée ». La place libérée va à
 *   la demande qui l'attend en premier.
 * - recommencer() (message « recommencer », C.7) : « à refaire », archive,
 *   base supprimée, nouvelle copie du modèle du jour sous un nouveau nom,
 *   avec la même référence, le même lien et la même fin (choix 11) ; le
 *   compte choisi en dernier de chaque témoin est effacé, et la copie
 *   porte le message de sa remise à zéro. Une visite pendant ce temps voit
 *   « Votre démonstration se prépare ».
 *
 * Une archive qui échoue arrête tout : la base reste, la cause s'écrit au
 * registre, l'état publié la montre, et le travail réessaie.
 */
final class FinDesCopies
{
    public const MOTIF_REMISE_A_ZERO = 'remise_a_zero';

    public function __construct(
        private readonly ArchiverUneCopie $archive,
        private readonly BasesDeLaDemonstration $bases,
        private readonly CopiesDeLaDemonstration $copies,
    ) {}

    /**
     * @throws ArchiveImpossible
     */
    public function supprimer(string $reference, string $motif): void
    {
        $copie = $this->passer($reference, EtatDUneCopie::EnArchivage, [EtatDUneCopie::Supprimee, EtatDUneCopie::EnSuppression]);

        if ($copie === null) {
            return;
        }

        $this->archiverSiElleALieu($copie, $motif);
        $copie->forceFill(['etat' => EtatDUneCopie::EnSuppression, 'cause_de_l_echec' => null])->save();
        $this->effacerLaBase($copie);
        DB::connection('demo_admin')->transaction(function () use ($copie): void {
            $maintenant = CarbonImmutable::now();
            Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->update(['retire_le' => $maintenant]);
            Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->update(['retire_le' => $maintenant]);
            $copie->forceFill(['etat' => EtatDUneCopie::Supprimee, 'supprimee_le' => $maintenant])->save();
        });

        foreach (['jobs', 'failed_jobs'] as $table) {
            DB::connection('demo_admin')->table($table)->where('payload', 'like', '%'.$copie->reference.'%')->delete();
        }

        $suivante = $this->copies->enAttenteDUnePlace()[0] ?? null;

        if ($suivante !== null) {
            Bus::dispatch(new PreparerUneCopie($suivante));
        }
    }

    /**
     * @throws ArchiveImpossible
     */
    public function recommencer(string $reference): void
    {
        $copie = $this->passer($reference, EtatDUneCopie::ARefaire, [EtatDUneCopie::Supprimee, EtatDUneCopie::EnSuppression, EtatDUneCopie::EnArchivage]);

        if ($copie === null) {
            return;
        }

        $this->archiverSiElleALieu($copie, 'recommencer');
        $this->effacerLaBase($copie);
        $maintenant = CarbonImmutable::now();
        DB::connection('demo_admin')->transaction(function () use ($copie, $maintenant): void {
            Temoin::on('demo_admin')->where('copie_id', $copie->id)->update(['compte_choisi' => null]);
            $copie->forceFill([
                'nom_de_la_base' => null,
                'cause_de_l_echec' => null,
                'message' => 'Votre démonstration a été remise à zéro le '.Calendrier::deParis()->afficher($maintenant, 'd/m/Y à H:i').'.',
                'motif_du_message' => self::MOTIF_REMISE_A_ZERO,
            ])->save();
        });
        $this->copies->preparer($reference);
    }

    /**
     * La copie passée à cet état sous son verrou, ou null quand elle
     * n'existe pas ou est déjà dans un état qui ne revient pas.
     *
     * @param  list<EtatDUneCopie>  $etatsFinals
     */
    private function passer(string $reference, EtatDUneCopie $etat, array $etatsFinals): ?Copie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        return DB::connection('demo_admin')->transaction(function () use ($reference, $etat, $etatsFinals): ?Copie {
            $copie = Copie::on('demo_admin')->where('reference', $reference)->lockForUpdate()->first();

            if ($copie === null || in_array($copie->etat, $etatsFinals, true)) {
                return null;
            }

            $copie->forceFill(['etat' => $etat])->save();

            return $copie;
        });
    }

    /**
     * L'archive de la caisse quand la copie a une base ; son échec s'écrit
     * au registre et arrête tout.
     *
     * @throws ArchiveImpossible
     */
    private function archiverSiElleALieu(Copie $copie, string $motif): void
    {
        if ($copie->nom_de_la_base === null || ! $this->bases->existe($copie->nom_de_la_base)) {
            return;
        }

        try {
            $this->archive->archiver($copie, $motif);
        } catch (ArchiveImpossible $erreur) {
            $copie->forceFill(['cause_de_l_echec' => mb_substr($erreur->getMessage(), 0, 2000)])->save();

            throw $erreur;
        }
    }

    private function effacerLaBase(Copie $copie): void
    {
        if ($copie->nom_de_la_base !== null) {
            $this->bases->supprimer($copie->nom_de_la_base);
        }

        DossiersDeLaDemonstration::effacerUneCopie($copie->reference);
    }
}
