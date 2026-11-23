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
use App\Domain\Demonstration\Taches\TravauxDesCopies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
 * - refaire() (message « recommencer », C.7 ; mise à jour avant la mise en
 *   production, E.4 ; migration ratée, E.5 ; copie perdue, E.7) :
 *   « à refaire », archive quand la base existe, base supprimée, nouvelle
 *   copie du modèle du jour sous un nouveau nom, avec la même référence,
 *   le même lien et la même fin (choix 11) ; les travaux envoyés depuis
 *   l'ancienne base sont effacés des files (et un travail qui y reviendrait
 *   est abandonné, sa base n'étant plus celle de la copie, voir
 *   TravauxDesCopies), le compte choisi en dernier de chaque témoin est
 *   effacé (la visite suivante choisit de nouveau : l'identifiant d'un
 *   compte ou d'une fiche de l'ancienne base ne désigne jamais une autre
 *   personne ni une autre fiche de la nouvelle), et la copie porte le
 *   message de son motif, montré une fois à chaque témoin. Une visite pendant ce temps
 *   voit « Votre démonstration se prépare ».
 *
 * Une archive qui échoue arrête tout : la base reste, la cause s'écrit au
 * registre, l'état publié la montre, et le travail réessaie.
 */
final class FinDesCopies
{
    public const MOTIF_REMISE_A_ZERO = 'remise_a_zero';

    public const MOTIF_MISE_A_JOUR = 'mise_a_jour';

    public const MOTIF_MIGRATION_REUSSIE = 'migration_reussie';

    public const MOTIF_MIGRATION_RATEE = 'migration_ratee';

    public const MOTIF_PERDUE = 'perdue';

    /**
     * Les motifs d'une réfection, avec ce que l'archive de la caisse en dit
     * (ArchiverUneCopie).
     */
    public const MOTIFS_DE_REFECTION = [
        self::MOTIF_REMISE_A_ZERO => 'recommencer',
        self::MOTIF_MISE_A_JOUR => 'mise_a_jour',
        self::MOTIF_MIGRATION_RATEE => 'migration_ratee',
        self::MOTIF_PERDUE => 'perdue',
    ];

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
     * La copie refaite depuis le modèle du jour pour ce motif (une clé de
     * MOTIFS_DE_REFECTION), après l'archive relue de sa caisse quand sa
     * base existe encore, sauf quand elle est déjà faite (une migration
     * ratée, archivée juste avant, voir MigrationDesCopies).
     *
     * @throws ArchiveImpossible
     */
    public function refaire(string $reference, string $motif, bool $archiveFaite = false): void
    {
        $archive = self::MOTIFS_DE_REFECTION[$motif] ?? throw new InvalidArgumentException("Motif de réfection inconnu : {$motif}.");
        $copie = $this->passer($reference, EtatDUneCopie::ARefaire, [EtatDUneCopie::Supprimee, EtatDUneCopie::EnSuppression, EtatDUneCopie::EnArchivage], ['motif_du_message' => $motif]);

        if ($copie === null) {
            return;
        }

        if (! $archiveFaite) {
            $this->archiverSiElleALieu($copie, $archive);
        }

        $this->effacerLaBase($copie);

        foreach (['jobs', 'failed_jobs'] as $table) {
            DB::connection('demo_admin')->table($table)->where('payload', 'like', TravauxDesCopies::motifDesTravaux($copie->reference))->delete();
        }

        $maintenant = CarbonImmutable::now();
        DB::connection('demo_admin')->transaction(function () use ($copie, $motif, $maintenant): void {
            Temoin::on('demo_admin')->where('copie_id', $copie->id)->update(['compte_choisi' => null]);
            $copie->forceFill([
                'nom_de_la_base' => null,
                'cause_de_l_echec' => null,
                'message' => self::message($motif, $maintenant),
                'motif_du_message' => $motif,
            ])->save();
        });
        $this->copies->preparer($reference);
    }

    /**
     * Le message que la copie montre une fois à chaque témoin après cet
     * événement (C.7, E.4, E.5, E.7) : jamais par e-mail, le site de
     * démonstration ne connaissant aucune adresse de la société (E.8).
     */
    public static function message(string $motif, CarbonImmutable $le): string
    {
        $paris = Calendrier::deParis();
        $jour = $paris->afficher($le, 'd/m/Y');

        return match ($motif) {
            self::MOTIF_REMISE_A_ZERO => 'Votre démonstration a été remise à zéro le '.$paris->afficher($le, 'd/m/Y à H:i').'.',
            self::MOTIF_MISE_A_JOUR => "PILIQI a été mis à jour le {$jour}. Votre démonstration repart de la société fictive de départ, avec les nouveautés. Votre lien reste le même.",
            self::MOTIF_MIGRATION_REUSSIE => "PILIQI a été mis à jour le {$jour}. Votre démonstration a reçu les nouveautés ; vos essais sont gardés. Pour voir les nouveautés avec leurs données, choisissez « Recommencer à zéro » dans votre compte.",
            self::MOTIF_MIGRATION_RATEE => "PILIQI a été mis à jour le {$jour}. Votre démonstration a dû être refaite : vos essais n'ont pas pu être gardés. Votre lien reste le même.",
            self::MOTIF_PERDUE => "Votre démonstration a dû être refaite le {$jour} ; votre lien reste le même.",
            default => throw new InvalidArgumentException("Motif de message inconnu : {$motif}."),
        };
    }

    /**
     * La copie passée à cet état sous son verrou, ou null quand elle
     * n'existe pas ou est déjà dans un état qui ne revient pas.
     *
     * @param  list<EtatDUneCopie>  $etatsFinals
     * @param  array<string, string>  $champs  écrits avec l'état
     */
    private function passer(string $reference, EtatDUneCopie $etat, array $etatsFinals, array $champs = []): ?Copie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        return DB::connection('demo_admin')->transaction(function () use ($reference, $etat, $etatsFinals, $champs): ?Copie {
            $copie = Copie::on('demo_admin')->where('reference', $reference)->lockForUpdate()->first();

            if ($copie === null || in_array($copie->etat, $etatsFinals, true)) {
                return null;
            }

            $copie->forceFill(['etat' => $etat, ...$champs])->save();

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
