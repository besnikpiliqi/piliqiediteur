<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Demonstration\Archives\ArchiverUneCopie;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Bases\RolesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La mise à jour des copies après la mise en production (lot DEMO-COPIE,
 * E.5), par demo_admin : chaque copie servie, l'une après l'autre, passe
 * « en migration » (ses visiteurs voient « Mise à jour en cours, une
 * minute ») ; l'archive de sa caisse est faite d'abord ; puis les
 * migrations (communes et du site de démonstration) passent sur sa base,
 * chacune dans sa transaction, chaque requête bornée à DELAI_EN_SECONDES.
 *
 * - Réussite : la copie rentre dans le mode d'entraînement (JET 100 dans
 *   chaque magasin, après le JET 105 que l'archive a inscrit : chaque
 *   période d'entraînement reste encadrée, et la copie n'enregistre jamais
 *   une vente après une sortie du mode d'entraînement sans nouvelle
 *   entrée) ; l'archive d'avant la migration est effacée (la copie garde
 *   ses données et refera son archive à sa fin) ; nouvelle empreinte du
 *   schéma et nouvelle version au registre, « prête », avec le message
 *   « … vos essais sont gardés… ».
 * - Échec ou délai dépassé : la base reste cohérente mais ancienne ;
 *   l'archive faite avant la migration couvre sa suppression ; la copie est
 *   refaite depuis le nouveau modèle (même référence, même lien, même fin),
 *   avec le message « … a dû être refaite… » ; les autres copies ne sont
 *   pas touchées.
 * - Une archive qui échoue laisse la copie telle qu'elle était, avec sa
 *   cause : aucune migration ne passe sans archive.
 *
 * Le tableau final (copies migrées, refaites, laissées, durée) s'écrit au
 * journal du site et dans l'état publié (voir EtatDuSite), sans aucune
 * référence. Les copies sont un banc d'essai de plus pour les migrations :
 * ce sont les mêmes que sur la base réelle.
 */
final class MigrationDesCopies
{
    public const CLE_DU_TABLEAU = 'demonstration:migration-des-copies';

    public const DELAI_EN_SECONDES = 600;

    public const MOTIF_DE_L_ARCHIVE = 'avant_migration';

    public const MOTIF_DE_LA_REPRISE = 'reprise_apres_migration';

    public function __construct(
        private readonly ArchiverUneCopie $archive,
        private readonly BasesDeLaDemonstration $bases,
        private readonly OuvertureDUneCopie $ouverture,
    ) {}

    /**
     * @param  list<string>  $dossiers  les dossiers de migrations, chacun relatif au projet ou absolu
     * @return array{migrees: int, refaites: int, laissees: int, duree_en_secondes: int}
     */
    public function migrer(array $dossiers): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $debut = CarbonImmutable::now();
        $tableau = ['migrees' => 0, 'refaites' => 0, 'laissees' => 0, 'duree_en_secondes' => 0];
        $copies = Copie::on('demo_admin')
            ->whereIn('etat', [EtatDUneCopie::Prete->value, EtatDUneCopie::EnLectureSeule->value])
            ->orderBy('id')
            ->get();

        foreach ($copies as $copie) {
            $tableau[$this->migrerUneCopie($copie, $dossiers)]++;
        }

        $tableau['duree_en_secondes'] = (int) $debut->diffInSeconds(CarbonImmutable::now());
        Cache::store('registre')->put(self::CLE_DU_TABLEAU, $tableau, 7 * 86400);
        Log::info(sprintf(
            'Mise à jour des copies de démonstration : %d migrée(s), %d refaite(s), %d laissée(s) faute d\'archive, en %d s.',
            $tableau['migrees'],
            $tableau['refaites'],
            $tableau['laissees'],
            $tableau['duree_en_secondes'],
        ));

        return $tableau;
    }

    /**
     * Le tableau de la dernière mise à jour des copies, pour l'état publié.
     *
     * @return array{migrees: int, refaites: int, laissees: int, duree_en_secondes: int}|null
     */
    public static function dernierTableau(): ?array
    {
        $tableau = Cache::store('registre')->get(self::CLE_DU_TABLEAU);

        return is_array($tableau) ? [
            'migrees' => ValeurSimple::enEntier($tableau['migrees'] ?? 0),
            'refaites' => ValeurSimple::enEntier($tableau['refaites'] ?? 0),
            'laissees' => ValeurSimple::enEntier($tableau['laissees'] ?? 0),
            'duree_en_secondes' => ValeurSimple::enEntier($tableau['duree_en_secondes'] ?? 0),
        ] : null;
    }

    /**
     * @param  list<string>  $dossiers
     * @return 'migrees'|'refaites'|'laissees'
     */
    private function migrerUneCopie(Copie $copie, array $dossiers): string
    {
        $avant = $copie->etat;
        $copie->forceFill(['etat' => EtatDUneCopie::EnMigration])->save();

        try {
            $archive = $this->archive->archiver($copie, self::MOTIF_DE_L_ARCHIVE);
        } catch (ArchiveImpossible $erreur) {
            $copie->forceFill(['etat' => $avant, 'cause_de_l_echec' => mb_substr($erreur->getMessage(), 0, 2000)])->save();

            return 'laissees';
        }

        $cause = $this->executerLesMigrations($copie, $dossiers) ?? $this->reprendreLeModeDEntrainement($copie);

        if ($cause !== null) {
            $copie->forceFill(['cause_de_l_echec' => mb_substr($cause, 0, 2000)])->save();
            Bus::dispatch(new RefaireUneCopie($copie->reference, FinDesCopies::MOTIF_MIGRATION_RATEE, archiveFaite: true));

            return 'refaites';
        }

        File::delete($archive->chemin);
        Archive::on('demo_admin')->whereKey($archive->id)->delete();
        $copie->forceFill([
            'etat' => EtatDUneCopie::Prete,
            'empreinte_du_schema' => EmpreinteDuSchema::duCode(),
            'version' => VersionServie::courante(),
            'message' => FinDesCopies::message(FinDesCopies::MOTIF_MIGRATION_REUSSIE, CarbonImmutable::now()),
            'motif_du_message' => FinDesCopies::MOTIF_MIGRATION_REUSSIE,
            'cause_de_l_echec' => null,
        ])->save();

        return 'migrees';
    }

    /**
     * Le JET 100 de chaque magasin de la copie migrée, par le nouveau code,
     * dans la copie, après le JET 105 de l'archive d'avant la migration
     * (chaque magasin a déjà son registre : l'archive y a écrit). La cause
     * de l'échec, ou null : une copie qui ne rentre pas dans le mode
     * d'entraînement n'est pas resservie, elle est refaite.
     */
    private function reprendreLeModeDEntrainement(Copie $copie): ?string
    {
        try {
            $this->ouverture->pendant($copie, function (): void {
                $contexte = app(TenantContext::class);
                $registre = app(RegistreFiscal::class);

                foreach (Magasin::query()->withoutGlobalScopes()->orderBy('id')->get() as $magasin) {
                    $contexte->runAs($magasin, fn () => $registre->sousVerrou($magasin, fn () => $registre->journaliser(CodeEvenement::EntreeEnModeDemonstration, ['motif' => self::MOTIF_DE_LA_REPRISE])));
                }

                $contexte->clear();
            });

            return null;
        } catch (Throwable $erreur) {
            return 'La copie migrée n\'a pas pu rentrer dans le mode d\'entraînement : '.$erreur->getMessage();
        }
    }

    /**
     * Les migrations de ces dossiers sur la base de la copie, par
     * demo_admin, son propriétaire ; puis les droits de demo_app sur les
     * tables nouvelles. La cause de l'échec, ou null.
     *
     * @param  list<string>  $dossiers
     */
    private function executerLesMigrations(Copie $copie, array $dossiers): ?string
    {
        try {
            $connexion = $this->bases->connexionDAdministration((string) $copie->nom_de_la_base);
            $connexion->statement('set statement_timeout = '.(self::DELAI_EN_SECONDES * 1000));

            foreach ($dossiers as $dossier) {
                $chemin = str_starts_with($dossier, '/') ? $dossier : base_path($dossier);

                if (Artisan::call('migrate', ['--database' => BasesDeLaDemonstration::CONNEXION_D_ADMINISTRATION, '--path' => $chemin, '--realpath' => true, '--force' => true]) !== 0) {
                    return 'Les migrations de la copie ont échoué : '.trim(Artisan::output());
                }
            }

            RolesDeLaDemonstration::accorderLesDroitsDUneBase($connexion);

            return null;
        } catch (Throwable $erreur) {
            return 'Les migrations de la copie ont échoué : '.$erreur->getMessage();
        } finally {
            $this->bases->fermerLaConnexion();
        }
    }
}
