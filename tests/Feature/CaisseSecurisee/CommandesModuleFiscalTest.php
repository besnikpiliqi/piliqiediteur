<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Calendrier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Commandes de l'éditeur : caisse:generer-cle (paire de clés de signature
 * fiscale) et caisse:diagnostiquer (le serveur peut-il signer et le
 * journal d'audit s'inscrire ?).
 */
class CommandesModuleFiscalTest extends TestCase
{
    /**
     * Le cle_id d'une clé publique PEM, calculé sans le signataire : le
     * SHA-256 du DER SubjectPublicKeyInfo, corps base64 du PEM.
     */
    private function cleIdDe(string $clePubliquePem): string
    {
        return hash('sha256', base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $clePubliquePem)));
    }

    /**
     * Un dossier de clés épinglées propre au test, pas encore créé, effacé
     * à la fin du test.
     */
    private function dossierDeClesTemporaire(): string
    {
        $racine = sys_get_temp_dir().'/caisse-securisee-'.bin2hex(random_bytes(6));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($racine));

        return $racine.'/cles';
    }

    public function test_generer_cle_epingle_la_cle_publique_et_affiche_une_seule_fois_la_cle_privee(): void
    {
        $dossier = $this->dossierDeClesTemporaire();
        config(['caisse_securisee.dossier_cles_publiques' => $dossier]);

        $code = Artisan::call('caisse:generer-cle');

        $sortie = Artisan::output();
        $fichiers = File::files($dossier);
        $clePublique = $fichiers[0]->getContents();
        $cleId = $this->cleIdDe($clePublique);
        $this->assertSame(0, $code);
        $this->assertCount(1, $fichiers);
        $this->assertSame($cleId.'.pem', $fichiers[0]->getFilename());
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $clePublique);
        $this->assertStringNotContainsString('PRIVATE', $clePublique);
        $this->assertStringContainsString("Empreinte (cle_id) : {$cleId}", $sortie);
        $this->assertSame(1, preg_match('/^CAISSE_SECURISEE_CLE_PRIVEE=(\S+)$/m', $sortie, $ligne));

        config([
            'caisse_securisee.cle_privee' => $ligne[1],
            'caisse_securisee.cles_publiques' => [$fichiers[0]->getFilename() => $clePublique],
        ]);
        $signataire = app(SignataireFiscal::class);
        $signature = $signataire->signer('contenu signé');
        $this->assertSame($cleId, $signataire->cleId());
        $this->assertSame(1, openssl_verify('contenu signé', base64_decode(strtr($signature, '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256));
    }

    public function test_diagnostiquer_reussit_quand_la_cle_de_signature_est_epinglee(): void
    {
        $cleId = $this->cleIdDe(Arr::first(config('caisse_securisee.cles_publiques')));

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('clé d\'essai générée et exportée, puis jetée')
            ->expectsOutputToContain("cle_id {$cleId}, signature ES256 vérifiée avec la clé épinglée")
            ->expectsOutputToContain('clé (JOURNAL_AUDIT_CLE) et identifiant (JOURNAL_AUDIT_CLE_ID) configurés, valeurs non affichées')
            ->doesntExpectOutputToContain(config('journal_audit.cle'))
            ->expectsOutputToContain(ModuleFiscal::VERSION)
            ->expectsOutputToContain('Caisse sécurisée opérationnelle.')
            ->assertSuccessful();
    }

    /**
     * L'échec que connaît PHP sous Windows tant que la variable
     * OPENSSL_CONF ne désigne pas son fichier openssl.cnf, reproduit sur
     * tout système : la commande tourne dans un autre processus PHP, dont
     * OPENSSL_CONF désigne un fichier absent. Le contrôle de la génération
     * d'une clé échoue en disant les erreurs d'OpenSSL ; sous Windows, la
     * marche à suivre s'y ajoute (voir ErreursOpenSslTest).
     */
    public function test_diagnostiquer_dit_les_erreurs_d_openssl_quand_il_ne_trouve_pas_son_fichier_de_configuration(): void
    {
        $configurationAbsente = base_path('tests/Fixtures/CaisseSecurisee/aucun-openssl.cnf');
        $this->assertFileDoesNotExist($configurationAbsente);

        $diagnostic = Process::path(base_path())
            ->env(['OPENSSL_CONF' => $configurationAbsente])
            ->timeout(60)
            ->run([PHP_BINARY, 'artisan', 'caisse:diagnostiquer']);

        $this->assertSame(1, $diagnostic->exitCode());
        $this->assertMatchesRegularExpression("/\\| Génération d'une clé P-256 +\\| ÉCHEC +\\| OpenSSL n'a pas pu générer de clé ECDSA P-256\\. Erreurs d'OpenSSL : error:/u", $diagnostic->output());
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function clesDuJournalAuditInutilisables(): array
    {
        return [
            'clé absente' => [null, 'cle-du-serveur-2026'],
            'clé de 16 octets seulement' => [base64_encode('seize-octets-ici'), 'cle-du-serveur-2026'],
            'identifiant absent' => [base64_encode('trente-deux-octets-pour-la-cle!!'), null],
        ];
    }

    /**
     * Sans la clé du journal d'audit ou son identifiant, aucune action
     * auditée ne s'enregistre (confirmation d'une inscription comprise) :
     * le diagnostic le signale et dit comment générer la clé, sans jamais
     * afficher ce qui est configuré.
     */
    #[DataProvider('clesDuJournalAuditInutilisables')]
    public function test_diagnostiquer_signale_une_cle_du_journal_d_audit_inutilisable_sans_afficher_aucune_valeur(?string $cle, ?string $cleId): void
    {
        config(['journal_audit.cle' => $cle, 'journal_audit.cle_id' => $cleId]);

        $diagnostic = $this->artisan('caisse:diagnostiquer');

        foreach (array_filter([$cle, $cleId]) as $valeur) {
            $diagnostic->doesntExpectOutputToContain($valeur);
        }
        $diagnostic->expectsOutputToContain('La clé du journal d\'audit (JOURNAL_AUDIT_CLE, au moins 32 octets aléatoires en base64) ou son identifiant (JOURNAL_AUDIT_CLE_ID) est absent ou invalide : aucune action auditée ne s\'enregistre, confirmation d\'une inscription comprise. Générez une clé par php -r "echo base64_encode(random_bytes(32)), PHP_EOL;", placez-la dans JOURNAL_AUDIT_CLE et donnez-lui un identifiant de votre choix dans JOURNAL_AUDIT_CLE_ID.')
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.')
            ->assertFailed();
    }

    public function test_diagnostiquer_echoue_quand_la_cle_privee_est_absente(): void
    {
        config(['caisse_securisee.cle_privee' => null]);

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente')
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.')
            ->assertFailed();
    }

    public function test_diagnostiquer_echoue_quand_aucune_cle_n_est_epinglee(): void
    {
        config([
            'caisse_securisee.cles_publiques' => [],
            'caisse_securisee.dossier_cles_publiques' => '/srv/piliqi/resources/caisse-securisee/cles',
        ]);

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('Aucune clé publique épinglée dans /srv/piliqi/resources/caisse-securisee/cles.')
            ->expectsOutputToContain("n'est pas épinglée")
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 2 contrôle(s) en échec.')
            ->assertFailed();
    }

    /**
     * Sous PostgreSQL, le fuseau par défaut de la base (celui que reçoit
     * toute session avant que l'application ne fixe le sien) doit être
     * l'UTC : une session dont les options de connexion donnent le fuseau
     * de Paris pour fuseau de départ fait échouer le diagnostic, qui en
     * nomme la cause, alors que SHOW TIMEZONE y rend l'UTC que
     * l'application vient de fixer. Chaque cause et sa commande se
     * vérifient dans FuseauParDefautDeLaBaseTest.
     */
    public function test_diagnostiquer_echoue_quand_le_fuseau_par_defaut_de_la_base_n_est_pas_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Le fuseau par défaut d\'une base n\'existe que sous PostgreSQL.');
        }

        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.fuseau_de_paris' => [...config("database.connections.{$connexionDuTest}"), 'server_options' => ['TimeZone' => Calendrier::deParis()->fuseau()]]]);
        DB::setDefaultConnection('fuseau_de_paris');

        try {
            $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
            $this->artisan('caisse:diagnostiquer')
                ->expectsOutputToContain('est « '.Calendrier::deParis()->fuseau().' », et non UTC : les options de la connexion le fixent')
                ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.')
                ->assertFailed();
        } finally {
            DB::setDefaultConnection($connexionDuTest);
            DB::purge('fuseau_de_paris');
        }
    }

    public function test_diagnostiquer_echoue_quand_le_fuseau_de_l_application_n_est_pas_utc(): void
    {
        config(['app.timezone' => Calendrier::deParis()->fuseau()]);

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain("Le fuseau de l'application doit être UTC")
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.')
            ->assertFailed();
    }
}
