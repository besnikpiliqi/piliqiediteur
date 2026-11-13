<?php

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Seeders\Semis\CodesLusDansLesCourriers;
use Database\Seeders\Semis\ComptesSemes;
use Database\Seeders\Semis\HorlogeDuSemis;
use Database\Seeders\Semis\MotsDePasseDuSemis;
use Database\Seeders\Semis\SemisDeDemonstration;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Les données de démonstration d'un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026) : php artisan migrate:fresh --seed
 * remplit la base par les vraies actions et les fournisseurs factices
 * (voir SemisDeDemonstration), puis affiche la liste des comptes pour se
 * connecter et l'écrit dans FICHIER_DES_COMPTES, sur le disque privé, que
 * git ne suit pas. Le semeur refuse de s'exécuter hors du développement
 * local et des tests, sans les fournisseurs factices, qui seuls
 * connaissent les entreprises fictives semées, et dans une base qui a déjà
 * des sociétés, avant d'y rien écrire. Aucun e-mail ne part pendant le
 * semis : les liens qu'il suit (invitation du cabinet, mot de passe de
 * l'expert-comptable, réponse au devis) se lisent dans les e-mails
 * retenus, et ses tâches s'exécutent aussitôt, sans file d'attente.
 * Les données de démonstration de la production restent à l'étape 15 de
 * la feuille de route.
 */
class DatabaseSeeder extends Seeder
{
    public const FICHIER_DES_COMPTES = 'comptes-de-demonstration.txt';

    public const MESSAGE_HORS_DU_DEVELOPPEMENT = 'Les données de démonstration ne se sèment qu\'en développement local ou dans les tests (APP_ENV=local ou testing), jamais en production : rien n\'a été écrit.';

    public const MESSAGE_FOURNISSEURS_REELS = 'Les données de démonstration exigent les fournisseurs factices, qui seuls connaissent les entreprises fictives semées : réglez SIRENE_PILOTE=faux, RNE_PILOTE=faux et ORDRE_PILOTE=faux dans le .env, puis php artisan optimize:clear. Rien n\'a été écrit.';

    public const MESSAGE_BASE_DEJA_REMPLIE = 'La base contient déjà des sociétés : les données de démonstration se sèment dans une base vide, par php artisan migrate:fresh --seed, qui efface toute la base. Rien n\'a été écrit.';

    /**
     * Les fournisseurs dont le semis exige le pilote factice (voir
     * FournisseursExternesServiceProvider) ; l'envoi recommandé et la
     * plateforme agréée n'en ont pas d'autre, et l'antivirus peut être
     * ClamAV.
     */
    private const FOURNISSEURS_FACTICES = ['sirene', 'rne', 'ordre'];

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
     * @throws RuntimeException quand l'environnement, les fournisseurs, la base ou le mot de passe commun ne permettent pas le semis
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(self::MESSAGE_HORS_DU_DEVELOPPEMENT);
        }

        foreach (self::FOURNISSEURS_FACTICES as $fournisseur) {
            if (config("services.{$fournisseur}.pilote") !== 'faux') {
                throw new RuntimeException(self::MESSAGE_FOURNISSEURS_REELS);
            }
        }

        if (Societe::query()->exists()) {
            throw new RuntimeException(self::MESSAGE_BASE_DEJA_REMPLIE);
        }

        $motsDePasse = MotsDePasseDuSemis::depuisLaConfiguration();
        $comptes = new ComptesSemes;
        $codes = new CodesLusDansLesCourriers;
        app()->instance(CodesDeConfirmation::class, $codes);
        config(['queue.default' => 'sync']);
        Mail::fake();
        $horloge = new HorlogeDuSemis(SemisDeDemonstration::JOURS);

        try {
            (new SemisDeDemonstration($horloge, $motsDePasse, $codes, $comptes))->semer();
            $horloge->terminer();
        } catch (Throwable $erreur) {
            $horloge->rendre();

            throw $erreur;
        } finally {
            app(TenantContext::class)->clear();
        }

        Storage::disk('local')->put(self::FICHIER_DES_COMPTES, $this->texteDuFichier($comptes, $motsDePasse));
        $this->commande?->info('Données de démonstration semées. Connexion : '.url('/app/login'));
        $this->commande?->table(ComptesSemes::ENTETES, $comptes->lignes());
        $this->commande?->info('Cette liste est aussi dans '.Storage::disk('local')->path(self::FICHIER_DES_COMPTES).' (fichier local, que git ne suit pas, réécrit à chaque semis).');
    }

    private function texteDuFichier(ComptesSemes $comptes, MotsDePasseDuSemis $motsDePasse): string
    {
        return implode(PHP_EOL, [
            'Comptes de démonstration de PILIQI (php artisan migrate:fresh --seed)',
            'Fichier local, que git ne suit pas, réécrit à chaque semis.',
            'Connexion : '.url('/app/login').' ; un propriétaire se connecte avec son adresse ou son identifiant, tout autre compte avec son identifiant.',
            $motsDePasse->sontCommuns()
                ? 'Mots de passe : celui de PILIQI_DEMO_MOT_DE_PASSE, pour tous les comptes qui ont choisi le leur.'
                : 'Mots de passe : tirés au hasard à ce semis (PILIQI_DEMO_MOT_DE_PASSE, dans le .env, en donne un seul, stable).',
            'Un compte dont le mot de passe est encore son identifiant ne s\'ouvre que depuis un poste reconnu du magasin (reconnu par le propriétaire ou un responsable).',
            '',
            $comptes->enTexte(),
        ]);
    }
}
