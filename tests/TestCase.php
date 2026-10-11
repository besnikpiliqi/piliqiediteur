<?php

namespace Tests;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\DemarrerVerificationIdentiteAction;
use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\FausseVerificationIdentite;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Laravel\SerializableClosure\SerializableClosure;

abstract class TestCase extends BaseTestCase
{
    /**
     * La paire de clés de signature fiscale des tests, générée une seule
     * fois par processus : aucune clé n'est jamais commitée.
     *
     * @var array{cle_id: string, cle_privee: string, cle_publique: string}|null
     */
    private static ?array $paireFiscaleDesTests = null;

    /**
     * La clé du journal d'audit des tests, tirée une seule fois par
     * processus : aucune clé n'est jamais commitée.
     */
    private static ?string $cleDuJournalAuditDesTests = null;

    /**
     * Chaque application de test signe avec la paire éphémère des tests,
     * seule clé épinglée, et chaîne son journal d'audit avec la clé
     * éphémère des tests : ni la clé d'un .env local ni une clé épinglée
     * dans le dépôt n'entrent dans les tests.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        self::$paireFiscaleDesTests ??= SignataireFiscal::genererUnePaire();
        self::$cleDuJournalAuditDesTests ??= base64_encode(random_bytes(32));

        $app['config']->set([
            'caisse_securisee.cle_privee' => base64_encode(self::$paireFiscaleDesTests['cle_privee']),
            'caisse_securisee.phrase_secrete' => null,
            'caisse_securisee.cles_publiques' => ['tests.pem' => self::$paireFiscaleDesTests['cle_publique']],
            'journal_audit.cle' => self::$cleDuJournalAuditDesTests,
            'journal_audit.cle_id' => 'tests',
            'journal_audit.cles_precedentes' => [],
        ]);

        return $app;
    }

    /**
     * Aucun test n'interroge un service réel : une requête HTTP sortante
     * sans réponse simulée (Http::fake()) échoue au lieu de partir.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Une société et son premier magasin, comme après son inscription (voir
     * CreerSocieteAction) : l'identité que le répertoire Sirene préremplit
     * (voir SocieteFactory::commeApresLInscription()), sans capital ni
     * immatriculation ; le magasin, créé par CreerEtablissementAction, son
     * établissement vérifié. La société est ensuite validée, et son SIRET
     * réservé, comme à sa validation ; le magasin est en activité depuis
     * MagasinFactory::DEBUT_D_ACTIVITE, avant toute journée que visitent
     * les tests. Avec ['a_verifier' => true], la société reste en cours de
     * vérification. Le magasin devient le magasin actif.
     *
     * Les options nom, code, nic et email sont celles du magasin ; toute
     * autre, sauf a_verifier, se règle sur la société avant la création du
     * magasin (regime_tva...). Avec $proprietaire, le propriétaire d'une
     * société sans magasin (voir UserFactory), le magasin s'ouvre dans la
     * société de ce compte.
     *
     * @param  array<string, mixed>  $options
     */
    protected function ouvrirUneSociete(array $options = [], ?User $proprietaire = null): Magasin
    {
        app(TenantContext::class)->clear();
        $proprietaire ??= User::factory()->create();
        $societe = $proprietaire->societe;
        $reglages = Arr::except($options, ['nom', 'code', 'nic', 'email', 'a_verifier']);

        if ($reglages !== []) {
            app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill($reglages)->save());
        }

        $magasin = app(CreerEtablissementAction::class)->handle($proprietaire, $societe, [
            'nom' => $options['nom'] ?? 'Ma Boutique',
            'code' => $options['code'] ?? null,
            'email' => $options['email'] ?? null,
            'nic' => $options['nic'] ?? MagasinFactory::nicLibre($societe),
        ]);

        if (! ($options['a_verifier'] ?? false)) {
            $societe->forceFill(['validee_le' => now()])->save();
            app(SiretsDesMagasins::class)->reserver($magasin);
            MagasinFactory::ouvrirSaPremierePeriode($magasin, $proprietaire);
        }

        $magasin = $magasin->fresh();
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Une société inscrite comme par la confirmation de son lien (voir
     * CreerSocieteAction), en cours de vérification : son entreprise et son
     * premier magasin, son siège, contrôlés au répertoire Sirene factice, son
     * propriétaire Jeanne Dupont, la présidente que donnent le registre
     * national factice et la vérification d'identité factice. Aucun
     * magasin n'est le magasin actif.
     */
    protected function inscrireUneSociete(?string $siren = null, string $nomDuProprietaire = 'Jeanne Dupont'): Societe
    {
        app(TenantContext::class)->clear();
        $siren ??= SocieteFactory::sirenFictif();

        return app(CreerSocieteAction::class)->handle(
            ['siren' => $siren],
            ['nom' => 'Ma Boutique', 'nic' => substr(FauxRepertoireSirene::siretDuSiege($siren), 9)],
            ['name' => $nomDuProprietaire, 'email' => fake()->unique()->safeEmail(), 'password' => 'mot-de-passe-sur'],
        );
    }

    /**
     * Le propriétaire de la société passe la vérification d'identité
     * factice, avec la réponse par défaut du prestataire (Jeanne DUPONT,
     * présidente au registre factice) ou celle donnée, de son démarrage à
     * la réception de son résultat (voir DemarrerVerificationIdentiteAction
     * et RecevoirResultatIdentiteAction) : la société est validée si elle
     * peut l'être. Rend la ligne du résultat.
     *
     * @param  array<mixed>|null  $reponse  la réponse brute du prestataire factice
     */
    protected function verifierLIdentiteDuProprietaire(Societe $societe, ?array $reponse = null): ?VerificationSociete
    {
        $identite = app(VerificationIdentite::class);
        $identite->repondreAvec($reponse ?? FausseVerificationIdentite::REPONSE_PAR_DEFAUT);

        $demarree = app(DemarrerVerificationIdentiteAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe, true, 'http://localhost/verification/identite/retour');

        return app(RecevoirResultatIdentiteAction::class)->handle($demarree['session']->reference, $identite->resultat($demarree['session']->reference, $demarree['state']));
    }

    /**
     * Un autre magasin de la société de $magasin, créé par son propriétaire
     * (voir CreerEtablissementAction) : dans une société validée, son SIRET
     * se réserve, sa période d'activité s'ouvre et les droits implicites du
     * propriétaire s'inscrivent à son journal des événements. Il devient le
     * magasin actif.
     *
     * @param  array{nom?: string, code?: string, nic?: string, email?: string}  $donnees
     */
    protected function ouvrirUnAutreMagasin(Magasin $magasin, array $donnees = []): Magasin
    {
        $societe = $magasin->societe;
        $autre = app(CreerEtablissementAction::class)->handle($this->proprietaireDe($magasin), $societe, [
            'nom' => $donnees['nom'] ?? 'Autre boutique',
            'code' => $donnees['code'] ?? null,
            'email' => $donnees['email'] ?? null,
            'nic' => $donnees['nic'] ?? MagasinFactory::nicLibre($societe),
        ]);
        app(TenantContext::class)->set($autre);

        return $autre;
    }

    /**
     * Règle la société du magasin, comme son propriétaire dans les
     * paramètres de la société : un réglage de conformité (régime de TVA,
     * option pour les débits...) s'inscrit au journal des événements de
     * chacun de ses magasins (voir JournaliseSesParametresDeConformite).
     *
     * @param  array<string, mixed>  $reglages
     */
    protected function reglerLaSociete(Magasin $magasin, array $reglages): Societe
    {
        $societe = $magasin->societe()->firstOrFail();
        $societe->forceFill($reglages)->save();
        $magasin->setRelation('societe', $societe);

        return $societe;
    }

    /**
     * Un nouveau membre de l'équipe du magasin, créé par son propriétaire
     * (voir CreerMembreMagasinAction) : avec les autorisations données,
     * ou, sans liste, celles du modèle de son rôle. Le magasin reste
     * ensuite le magasin actif.
     *
     * @param  list<string>|null  $permissions
     */
    protected function membre(Magasin $magasin, RoleMagasin $role, ?array $permissions = null): User
    {
        return app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'mot-de-passe-sur',
        ], $role, $permissions);
    }

    /**
     * Le propriétaire de la société du magasin, qui a tous les droits dans
     * chacun de ses magasins.
     */
    protected function proprietaireDe(Magasin $magasin): User
    {
        return $magasin->societe()->firstOrFail()->proprietaire()->firstOrFail();
    }

    /**
     * Complète l'identité légale de la société du magasin, comme son
     * propriétaire dans Administration de la société > Identité : il peut
     * alors émettre des factures à des professionnels (voir
     * Magasin::mentionsLegalesManquantes()).
     */
    protected function completerIdentiteLegale(Magasin $magasin): Magasin
    {
        $societe = $magasin->societe()->firstOrFail();
        $societe->forceFill([
            'raison_sociale' => 'Ma Boutique',
            'forme_juridique' => FormeJuridique::Sas,
            'capital_social' => '10000.00',
            'immatriculation' => 'RCS Lyon',
            'siege_adresse' => '1 place Bellecour',
            'siege_code_postal' => '69001',
            'siege_ville' => 'Lyon',
        ])->save();
        $magasin->setRelation('societe', $societe);

        return $magasin;
    }

    /**
     * L'opération, exécutée dans un point de sauvegarde (une transaction
     * imbriquée dans celle du test) : sous PostgreSQL, une erreur SQL
     * annule toute la transaction en cours, et le test ne pourrait plus
     * rien lire ensuite ; le point de sauvegarde n'annule que l'opération
     * refusée, sur les deux moteurs.
     */
    protected function dansUnPointDeSauvegarde(Closure $operation): Closure
    {
        return static fn (): mixed => DB::transaction($operation);
    }

    /**
     * Retire un déclencheur de protection (voir DeclencheursDeProtection),
     * comme le ferait un administrateur de la base, pour falsifier une
     * table en ajout seul le temps d'un test. Le DDL de PostgreSQL et de
     * SQLite est transactionnel : le retour en arrière de la transaction
     * du test le rétablit.
     */
    protected function retirerLeDeclencheur(string $table, string $declencheur): void
    {
        DB::unprepared(DB::connection()->getDriverName() === 'pgsql'
            ? "DROP TRIGGER {$declencheur} ON {$table}"
            : "DROP TRIGGER {$declencheur}");
    }

    /**
     * Deux processus ne partagent une base qu'avec un serveur de base de
     * données : les tests de concurrence ne tournent que sous PostgreSQL,
     * la base SQLite des tests vivant dans la mémoire de leur processus.
     */
    protected function exigerUnServeurDeBaseDeDonnees(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deux processus ne partagent une base qu\'avec un serveur : ce test ne tourne que sous PostgreSQL.');
        }
    }

    /**
     * Lance l'opération dans un autre processus PHP, sur la base du test
     * (voir tests/Fixtures/processus-concurrent.php), sans attendre sa
     * fin : une seconde caisse qui agit au même instant. L'opération ne
     * voit que ce que le test a validé ; elle est statique et ne capture
     * que des valeurs simples (des identifiants).
     */
    protected function demarrerUnProcessusConcurrent(Closure $operation): InvokedProcess
    {
        $demande = serialize([
            'base' => DB::connection()->getDatabaseName(),
            'caisse_securisee' => config('caisse_securisee'),
            'journal_audit' => config('journal_audit'),
            'operation' => new SerializableClosure($operation),
        ]);

        return Process::path(base_path())
            ->timeout(60)
            ->input(base64_encode($demande))
            ->start([PHP_BINARY, base_path('tests/Fixtures/processus-concurrent.php')]);
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient le
     * verrou du registre fiscal, et attend qu'elle attende ce verrou : deux
     * caisses qui agissent au même instant, la seconde passant après la
     * première. $concurrente reçoit le processus démarré.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    protected function concurrencerDesQueLeRegistreEstVerrouille(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $operationConcurrente): void {
            if ($concurrente === null && str_contains($requete->sql, 'from "tetes_registre_fiscal"') && str_contains($requete->sql, 'for update')) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Attend que l'autre processus attende un verrou que tient le test, ou
     * qu'il ait fini, dix secondes au plus. L'attente se lit dans
     * pg_stat_activity par une connexion à part, hors de la transaction du
     * test, fermée ensuite. Elle porte son propre nom : fermée sous celui
     * de la connexion du test, elle effacerait du gestionnaire de
     * transactions celles que l'opération du test a ouvertes.
     */
    protected function attendreQueLeProcessusAttendeUnVerrou(InvokedProcess $processus): void
    {
        $observateur = DB::getDefaultConnection().'_observateur';
        config(["database.connections.{$observateur}" => [...DB::connection()->getConfig(), 'name' => $observateur]]);
        $limite = microtime(true) + 10;

        try {
            while ($processus->running() && microtime(true) < $limite) {
                $attentes = DB::connection($observateur)->selectOne("select count(*) as nombre from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'")->nombre;

                if ($attentes > 0) {
                    return;
                }

                usleep(20_000);
            }
        } finally {
            DB::purge($observateur);
        }
    }

    /**
     * Une vraie requête HTTP démarre sans magasin actif : seul le
     * middleware du panneau (BindTenantContextFromFilamentTenant) le
     * positionne, depuis l'URL. Une requête de test repart donc d'un
     * TenantContext neuf, au lieu d'hériter de celui que la préparation du
     * test a positionné ; ce dernier est rendu au test une fois la réponse
     * reçue, pour ses vérifications.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $contexteDuTest = $this->app->make(TenantContext::class);
        $this->app->forgetInstance(TenantContext::class);

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app->instance(TenantContext::class, $contexteDuTest);
        }
    }
}
