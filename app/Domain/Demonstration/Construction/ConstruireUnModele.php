<?php

namespace App\Domain\Demonstration\Construction;

use App\Console\Commands\VerifierJournalAudit;
use App\Console\Commands\VerifierLIsolation;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Bases\RolesDeLaDemonstration;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Exceptions\ConstructionEnEchec;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\TachesDesSites;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * La construction d'une base modèle du site de démonstration (lot
 * DEMO-COPIE, C.2), par demo_admin, pour un type :
 *
 * 1. une base de travail vide demo_travail_<type>_<AAAAMMJJHHMM>, depuis
 *    template0, en UTF8 et collation C.UTF-8 ;
 * 2. les migrations communes puis celles du site de démonstration, qui
 *    posent le fuseau UTC et la marque « demonstration », puis les droits
 *    du rôle des pages (demo_app) sur les tables et les séquences, sans
 *    UPDATE, DELETE ni TRUNCATE sur les tables en ajout seul ;
 * 3. le mot de passe de démonstration du modèle, tiré au hasard (choix 29) ;
 * 4. le semis du modèle (SemisDUnModele), par les vraies actions et les
 *    fournisseurs factices, planificateur suspendu, ses fichiers (pièces,
 *    documents) sous le dossier du modèle, que chaque copie recopie (voir
 *    DossiersDeLaDemonstration) ;
 * 5. app:verifier-isolation : zéro problème exigé ;
 * 6. app:verifier-journal-audit (les chaînes du journal d'audit de chaque
 *    société, avec la clé de démonstration) : code 0 exigé ;
 * 7. caisse:verifier --complet, avec la clé publique de démonstration :
 *    code 0 exigé ;
 * 8. le nettoyage des tables techniques (sessions, cache, verrous,
 *    travaux, jetons de mot de passe) ;
 * 9. VACUUM (FREEZE, ANALYZE), pour qu'aucune copie ne refasse ce travail ;
 * 10. la fermeture en modèle (ALLOW_CONNECTIONS false, IS_TEMPLATE true) ;
 * 11. la bascule au registre, sous un verrou consultatif par type : le
 *     nouveau modèle en service, l'ancien « précédent » ;
 * 12. le modèle précédent d'avant est supprimé, sous le même verrou.
 *
 * Les contrôles des étapes 5 et 6 sont ceux que TachesDesSites range
 * « pendant la construction d'un modèle » (C.13).
 *
 * Si une étape échoue, l'ancien modèle reste en service, la base de
 * travail et ses fichiers sont supprimés et la cause s'écrit au registre
 * (ConstructionEnEchec).
 */
final class ConstruireUnModele
{
    /**
     * Les tables techniques vidées avant le gel du modèle (C.2, étape 7) :
     * aucune copie n'hérite d'une session, d'un verrou ou d'un travail.
     */
    public const TABLES_TECHNIQUES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public const MESSAGE_ISOLATION = 'La vérification de l\'isolation du modèle « %s » a trouvé des problèmes : %s';

    public const MESSAGE_JOURNAL_AUDIT = 'La vérification du journal d\'audit du modèle « %s » a trouvé des problèmes : %s';

    /**
     * Le message d'échec de chaque contrôle de la construction, dans
     * l'ordre de TachesDesSites::DE_LA_CONSTRUCTION_D_UN_MODELE.
     */
    public const MESSAGES_DES_CONTROLES = [
        VerifierLIsolation::class => self::MESSAGE_ISOLATION,
        VerifierJournalAudit::class => self::MESSAGE_JOURNAL_AUDIT,
    ];

    public const MESSAGE_CAISSE = 'La vérification du registre fiscal du modèle « %s » a échoué : %s';

    public const MESSAGE_MIGRATIONS = 'Les migrations du modèle « %s » ont échoué : %s';

    public function __construct(
        private readonly BasesDeLaDemonstration $bases,
        private readonly SemisDUnModele $semis,
    ) {}

    /**
     * @throws ConstructionEnEchec quand une étape échoue ; l'ancien modèle reste en service
     */
    public function construire(TypeDActivite $type): Modele
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $debut = CarbonImmutable::now();
        $nom = $this->bases->nomDUneBaseDeTravail($type, $debut);
        $motDePasse = self::tirerUnMotDePasse();
        $modele = Modele::on('demo_admin')->create([
            'type' => $type,
            'nom_de_la_base' => $nom,
            'commence_le' => $debut,
            'version_du_code' => VersionServie::courante(),
            'mot_de_passe' => $motDePasse,
            'etat' => EtatDUnModele::EnConstruction,
        ]);

        try {
            $this->bases->creerUneBaseVide($nom);
            BrancherUneBase::pendant(BrancherUneBase::parLAdministration($nom), function () use ($type, $motDePasse, $nom): void {
                $this->migrer($nom);
                RolesDeLaDemonstration::accorderLesDroitsDUneBase(DB::connection());
                DossiersDeLaDemonstration::pendantLeGesteDans(DossiersDeLaDemonstration::duModele($nom), fn () => $this->semis->semer($type, $motDePasse));
                foreach (TachesDesSites::DE_LA_CONSTRUCTION_D_UN_MODELE as $controle) {
                    $this->exigerZero($controle, [], self::MESSAGES_DES_CONTROLES[$controle], $nom);
                }

                $this->exigerZero('caisse:verifier', ['--complet' => true], self::MESSAGE_CAISSE, $nom);

                foreach (self::TABLES_TECHNIQUES as $table) {
                    DB::table($table)->delete();
                }

                DB::unprepared('vacuum (freeze, analyze)');
            });
            $this->bases->fermerEnModele($nom);
            $taille = $this->bases->taille($nom);
        } catch (Throwable $erreur) {
            $this->bases->supprimer($nom);
            DossiersDeLaDemonstration::effacerUnModele($nom);
            $modele->forceFill([
                'etat' => EtatDUnModele::EnEchec,
                'cause_de_l_echec' => mb_substr($erreur->getMessage(), 0, 2000),
                'duree_en_secondes' => (int) $debut->diffInSeconds(CarbonImmutable::now()),
            ])->save();

            throw new ConstructionEnEchec('La construction du modèle « '.$type->value.' » a échoué : '.$erreur->getMessage(), 0, $erreur);
        }

        $this->basculer($modele, $taille, $debut);

        return $modele;
    }

    /**
     * Un mot de passe de démonstration : lisible, jamais un identifiant
     * (huit chiffres), jamais un seul caractère répété ni une suite de
     * chiffres.
     */
    public static function tirerUnMotDePasse(): string
    {
        return 'Demo-'.Str::lower(Str::random(6)).'-'.random_int(10, 99);
    }

    private function migrer(string $nom): void
    {
        foreach (EmpreinteDuSchema::DOSSIERS as $dossier) {
            if (Artisan::call('migrate', ['--path' => $dossier, '--force' => true]) !== 0) {
                throw new ConstructionEnEchec(sprintf(self::MESSAGE_MIGRATIONS, $nom, trim(Artisan::output())));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function exigerZero(string $commande, array $options, string $message, string $nom): void
    {
        if (Artisan::call($commande, $options) !== 0) {
            throw new ConstructionEnEchec(sprintf($message, $nom, mb_substr(trim(Artisan::output()), 0, 1500)));
        }
    }

    /**
     * Le nouveau modèle en service, l'ancien « précédent », celui d'avant
     * supprimé, sous un verrou consultatif par type, que prend aussi la
     * copie d'un modèle (CopierUnModele) : la bascule attend la fin des
     * copies en cours, jamais une copie ne part d'un modèle en suppression.
     */
    private function basculer(Modele $modele, int $taille, CarbonImmutable $debut): void
    {
        $aSupprimer = DB::connection('demo_admin')->transaction(function () use ($modele, $taille, $debut): ?string {
            self::verrouillerLeType($modele->type);
            $precedent = Modele::on('demo_admin')->where('type', $modele->type)->where('etat', EtatDUnModele::Precedent)->lockForUpdate()->first();
            $precedent?->forceFill(['etat' => EtatDUnModele::Supprime])->save();
            Modele::on('demo_admin')->where('type', $modele->type)->where('etat', EtatDUnModele::EnService)->update(['etat' => EtatDUnModele::Precedent->value]);
            $modele->forceFill([
                'etat' => EtatDUnModele::EnService,
                'construit_le' => CarbonImmutable::now(),
                'empreinte_du_schema' => EmpreinteDuSchema::duCode(),
                'taille' => $taille,
                'duree_en_secondes' => (int) $debut->diffInSeconds(CarbonImmutable::now()),
            ])->save();

            return $precedent?->nom_de_la_base;
        });

        if ($aSupprimer !== null) {
            $this->bases->supprimer($aSupprimer);
            DossiersDeLaDemonstration::effacerUnModele($aSupprimer);
        }
    }

    /**
     * Le verrou consultatif du type, pour la durée de la transaction
     * courante de demo_admin.
     */
    public static function verrouillerLeType(TypeDActivite $type): void
    {
        DB::connection('demo_admin')->select('select pg_advisory_xact_lock(hashtext(?))', ['demonstration-modele-'.$type->value]);
    }
}
