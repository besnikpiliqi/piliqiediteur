<?php

namespace Database\Seeders;

use App\Console\SemisEnCours;
use App\Domain\Demonstration\ConstructionDUnModele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Fichiers\AnalyseurDePdf;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\TenantContext;
use App\Providers\FournisseursExternesServiceProvider;
use Database\Seeders\Semis\CodesLusDansLesCourriers;
use Database\Seeders\Semis\ComptesSemes;
use Database\Seeders\Semis\CourriersDuSemis;
use Database\Seeders\Semis\FileDAttenteDuSemis;
use Database\Seeders\Semis\HorlogeDuSemis;
use Database\Seeders\Semis\MotsDePasseDuSemis;
use Database\Seeders\Semis\SemisComplet;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Le semis d'un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026) : php artisan migrate:fresh --seed
 * remplit la base par les vraies actions et les fournisseurs factices
 * (voir SemisComplet), puis affiche la liste des comptes pour se
 * connecter et l'écrit dans FICHIER_DES_COMPTES, sur le disque privé, que
 * git ne suit pas. Le semeur refuse de s'exécuter hors du développement
 * local et des tests (sur le site de démonstration, hors d'une base modèle
 * en construction, voir ConstructionDUnModele), sans les fournisseurs
 * factices (voir FournisseursExternesServiceProvider::PILOTES_FACTICES),
 * sans qpdf, qui lit les pièces en PDF qu'il dépose (voir
 * AnalyseurDePdf::verifierQueQpdfSeLance()), et dans une base qui a déjà
 * des sociétés, avant d'y rien écrire. Aucun e-mail ne part pendant le semis : les liens qu'il
 * suit (invitation du cabinet, mot de passe de l'expert-comptable,
 * réponse au devis) se lisent dans les e-mails retenus, aucune requête HTTP
 * ne sort (VIES est le faux, voir FauxVies), et ses tâches
 * s'exécutent aussitôt, sans file d'attente, sauf celles que quelques
 * gestes laissent en file, comme en production, dans des files à eux
 * qu'aucun travailleur du poste n'écoute (voir FileDAttenteDuSemis :
 * préparation des téléchargements, un envoi qui attend sa tentative
 * suivante, une transmission en panne) ; le planificateur ne lance
 * aucune tâche tant qu'il dure (voir SemisEnCours). Le semis se termine
 * par la vérification de l'isolation de toute la base (voir
 * VerifierLIsolation), dont il affiche le tableau : au moindre problème, il
 * n'est pas accepté (code de sortie 1) et la liste des comptes ne s'affiche
 * pas. Le même semis remplit les bases modèles du site de démonstration
 * (lot DEMO-COPIE, F.1), qui se met en ligne à l'étape 15 de la feuille de
 * route : chacune ne reçoit que les sociétés de son modèle (voir
 * SemisComplet::SOCIETES_DES_MODELES), tous ses comptes ont le mot de
 * passe de démonstration du modèle (choix 29), et aucune liste des comptes
 * ne s'écrit : le bandeau de la copie la donne (voir
 * ComptesDuBandeauDuModele).
 */
class DatabaseSeeder extends Seeder
{
    public const FICHIER_DES_COMPTES = 'comptes-du-semis.txt';

    /**
     * Les derniers jours de l'histoire d'un modèle du site de démonstration
     * dont les e-mails passent aussi par son vrai transport, qui les écrit
     * dans « E-mails qui seraient partis » (lot DEMO-COPIE, F.4) ; tous
     * restent retenus, pour que le semis y lise ses liens, et aucun ne part
     * (voir CourriersDuSemis).
     */
    public const JOURS_DES_E_MAILS_DES_MODELES = 7;

    public const MESSAGE_HORS_D_UNE_CONSTRUCTION = 'Sur le site de démonstration, le semis ne remplit qu\'une base modèle en construction (demo_travail_…), par php artisan demonstration:construire-les-modeles : php artisan db:seed y est refusé. Rien n\'a été écrit.';

    public const MESSAGE_HORS_DU_DEVELOPPEMENT = 'Le semis ne se fait qu\'en développement local ou dans les tests (APP_ENV=local ou testing), jamais en production : rien n\'a été écrit.';

    public const MESSAGE_FOURNISSEURS_REELS = 'Le semis exige les fournisseurs factices, qui seuls connaissent les entreprises fictives semées et n\'envoient rien hors de PILIQI : réglez SIRENE_PILOTE=faux, RNE_PILOTE=faux, RECOMMANDE_PILOTE=faux, ORDRE_PILOTE=faux, PDP_PILOTE=faux, VIES_PILOTE=faux et ANTIVIRUS_PILOTE=factice dans le .env, comme dans .env.example, puis php artisan optimize:clear. Rien n\'a été écrit.';

    public const MESSAGE_SANS_QPDF = 'Le semis dépose des pièces en PDF (achats, charges) que qpdf doit lire avant de les accepter : rien n\'a été écrit. ';

    public const MESSAGE_ISOLATION_EN_DEFAUT = 'Le semis n\'est pas accepté : la vérification de l\'isolation a trouvé des problèmes (tableau ci-dessus). Corrigez le semis ou le code qui a écrit ces lignes, puis relancez php artisan migrate:fresh --seed.';

    public const MESSAGE_BASE_DEJA_REMPLIE = 'La base contient déjà des sociétés : le semis se fait dans une base vide, par php artisan migrate:fresh --seed, qui efface toute la base. Rien n\'a été écrit.';

    /**
     * La commande de la console qui lance le semis (db:seed), qui la donne
     * par setCommand() ; aucune quand le semeur est lancé autrement.
     * Seeder::$command se dit toujours présente, mais reste vide jusque-là.
     */
    private ?Command $commande = null;

    public function setCommand(Command $command): static
    {
        $this->commande = $command;

        return parent::setCommand($command);
    }

    /**
     * @throws RuntimeException quand l'environnement, les fournisseurs, la base ou le mot de passe commun ne permettent pas le semis, ou que l'isolation des données semées n'est pas juste
     */
    public function run(): void
    {
        if (SiteDePiliqi::estLaDemonstration()) {
            if (ConstructionDUnModele::enCours() === null || preg_match(ConstructionDUnModele::MOTIF_DE_LA_BASE_DE_TRAVAIL, DB::connection()->getDatabaseName()) !== 1) {
                throw new RuntimeException(self::MESSAGE_HORS_D_UNE_CONSTRUCTION);
            }
        } elseif (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(self::MESSAGE_HORS_DU_DEVELOPPEMENT);
        }

        foreach (FournisseursExternesServiceProvider::PILOTES_FACTICES as $reglage => $pilote) {
            if (config($reglage) !== $pilote) {
                throw new RuntimeException(self::MESSAGE_FOURNISSEURS_REELS);
            }
        }

        try {
            AnalyseurDePdf::verifierQueQpdfSeLance();
        } catch (RuntimeException $absent) {
            throw new RuntimeException(self::MESSAGE_SANS_QPDF.$absent->getMessage(), 0, $absent);
        }

        if (Societe::query()->exists()) {
            throw new RuntimeException(self::MESSAGE_BASE_DEJA_REMPLIE);
        }

        $construction = ConstructionDUnModele::enCours();
        $motsDePasse = $construction === null ? MotsDePasseDuSemis::depuisLaConfiguration() : MotsDePasseDuSemis::duModele($construction->motDePasse);
        $comptes = new ComptesSemes;
        $codes = new CodesLusDansLesCourriers;
        app()->instance(CodesDeConfirmation::class, $codes);
        $courriers = CourriersDuSemis::poser();
        Http::preventStrayRequests();

        SemisEnCours::pendant(fn () => FileDAttenteDuSemis::pendantLeSemis(function () use ($motsDePasse, $codes, $comptes, $construction, $courriers): void {
            $horloge = new HorlogeDuSemis(SemisComplet::JOURS);

            if ($construction !== null) {
                $horloge->auxDerniersJours(self::JOURS_DES_E_MAILS_DES_MODELES, $courriers->envoyerAussiParLeTransport(...));
            }

            try {
                $semis = new SemisComplet($horloge, $motsDePasse, $codes, $comptes, $construction?->type);
                $semis->semer();
                $horloge->terminer();
                $semis->laisserUneVenteEnCours();
            } catch (Throwable $erreur) {
                $horloge->rendre();

                throw $erreur;
            } finally {
                app(TenantContext::class)->clear();
            }
        }));

        $isolation = Artisan::call('app:verifier-isolation');
        $this->commande?->getOutput()->write(Artisan::output());

        if ($isolation !== 0) {
            throw new RuntimeException(self::MESSAGE_ISOLATION_EN_DEFAUT);
        }

        if ($construction !== null) {
            return;
        }

        Storage::disk('local')->put(self::FICHIER_DES_COMPTES, $this->texteDuFichier($comptes, $motsDePasse));
        $this->commande?->info('Semis terminé. Connexion : '.url('/app/login'));
        $this->commande?->table(ComptesSemes::ENTETES, $comptes->lignes());
        $this->commande?->info('Cette liste est aussi dans '.Storage::disk('local')->path(self::FICHIER_DES_COMPTES).' (fichier local, que git ne suit pas, réécrit à chaque semis).');
    }

    private function texteDuFichier(ComptesSemes $comptes, MotsDePasseDuSemis $motsDePasse): string
    {
        return implode(PHP_EOL, [
            'Comptes du semis de PILIQI (php artisan migrate:fresh --seed)',
            'Fichier local, que git ne suit pas, réécrit à chaque semis.',
            'Connexion : '.url('/app/login').' ; un propriétaire se connecte avec son adresse ou son identifiant, tout autre compte avec son identifiant.',
            $motsDePasse->sontCommuns()
                ? 'Mots de passe : celui de PILIQI_SEMIS_MOT_DE_PASSE, pour tous les comptes qui ont choisi le leur.'
                : 'Mots de passe : tirés au hasard à ce semis (PILIQI_SEMIS_MOT_DE_PASSE, dans le .env, en donne un seul, stable).',
            'Un compte dont le mot de passe est encore son identifiant ne s\'ouvre que depuis un poste reconnu du magasin (reconnu par le propriétaire ou un responsable).',
            '',
            $comptes->enTexte(),
        ]);
    }
}
