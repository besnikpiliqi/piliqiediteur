<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Support\HorlogeDuSysteme;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\FuseauParDefautDeLaBase;
use App\Domain\Fichiers\AnalyseurDePdf;
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

        config([
            'caisse_securisee.cle_privee' => $this->clePriveeAffichee($sortie),
            'caisse_securisee.cles_publiques' => [$fichiers[0]->getFilename() => $clePublique],
        ]);
        $signataire = app(SignataireFiscal::class);
        $signature = $signataire->signer('contenu signé');
        $this->assertSame($cleId, $signataire->cleId());
        $this->assertSame(1, openssl_verify('contenu signé', base64_decode(strtr($signature, '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256));
    }

    /**
     * La clé privée affichée par caisse:generer-cle, lue dans sa sortie
     * avec ses fins de ligne, de Linux comme de Windows (« \r\n »).
     */
    private function clePriveeAffichee(string $sortie): string
    {
        $this->assertSame(1, preg_match('/^CAISSE_SECURISEE_CLE_PRIVEE=(\S+)\r?$/m', $sortie, $ligne), 'La sortie doit afficher la clé privée sur sa propre ligne.');

        return $ligne[1];
    }

    /**
     * Sous Windows, la sortie finit ses lignes par « \r\n » : la clé
     * privée s'y lit de même, et elle signe un contenu que la clé publique
     * épinglée vérifie (constat C43 des audits du 07/11/2026 : portabilité
     * du test, exigence inchangée).
     */
    public function test_la_cle_privee_se_lit_dans_une_sortie_aux_fins_de_ligne_de_windows(): void
    {
        config(['caisse_securisee.dossier_cles_publiques' => $this->dossierDeClesTemporaire()]);
        $this->assertSame(0, Artisan::call('caisse:generer-cle'));
        $sortieWindows = str_replace("\n", "\r\n", Artisan::output());
        $clePublique = File::files(config('caisse_securisee.dossier_cles_publiques'))[0];

        config([
            'caisse_securisee.cle_privee' => $this->clePriveeAffichee($sortieWindows),
            'caisse_securisee.cles_publiques' => [$clePublique->getFilename() => $clePublique->getContents()],
        ]);
        $signataire = app(SignataireFiscal::class);

        $this->assertTrue($signataire->verifier('contenu signé', $signataire->signer('contenu signé'), $this->cleIdDe($clePublique->getContents())));
    }

    /**
     * Le diagnostic compare l'horloge de la machine à celle du serveur de
     * base, corrigée de la moitié de l'aller-retour : alignée, la ligne est
     * OK ; décalée de 2 secondes, elle échoue et dit comment la remettre à
     * l'heure (constat C93 des audits du 07/11/2026). La mesure se fait à
     * quelques millisecondes près, de part et d'autre, l'aller et le retour
     * d'une requête n'étant jamais tout à fait égaux sur une machine chargée
     * (la CI a lu 1,999 s pour 2 s) : l'écart lu est celui du décalage à
     * moins d'un dixième de seconde, dans les deux cas.
     */
    public function test_le_diagnostic_controle_l_horloge_du_serveur_sur_celle_du_serveur_de_base(): void
    {
        $this->poserLHorlogeDuSysteme(0.0);
        $this->assertSame(0, Artisan::call('caisse:diagnostiquer'));
        $this->assertMatchesRegularExpression('/\| Horloge du serveur +\| OK +\| écart de -?0,0\d\d s avec le serveur de base/u', Artisan::output());

        $this->poserLHorlogeDuSysteme(2.0);
        $this->assertSame(1, Artisan::call('caisse:diagnostiquer'));
        $sortie = Artisan::output();
        $this->assertMatchesRegularExpression("/\\| Horloge du serveur +\\| ÉCHEC +\\| L'horloge de ce serveur s'écarte de (1,9\\d\\d|2,0\\d\\d) s de celle du serveur de base/u", $sortie);
        $this->assertStringContainsString("Synchronisez l'horloge de chaque machine qui écrit au registre fiscal (serveur web, travailleurs des files, planificateur) par NTP", $sortie);
        $this->assertStringContainsString('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.', $sortie);
    }

    /**
     * L'horloge du système, décalée de ce nombre de secondes sur celle du
     * serveur de base, que le test lit à chaque appel.
     */
    private function poserLHorlogeDuSysteme(float $decalage): void
    {
        $this->app->instance(HorlogeDuSysteme::class, new class($decalage) extends HorlogeDuSysteme
        {
            public function __construct(private readonly float $decalage) {}

            public function secondes(): float
            {
                return (float) DB::scalar("select date_part('epoch', clock_timestamp())") + $this->decalage;
            }
        });
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
     * Le diagnostic contrôle aussi ce qui contrôle les fichiers reçus (lot
     * FI) : l'extension gd, qpdf, et l'antivirus, qui doit reconnaître le
     * fichier de test EICAR.
     */
    public function test_le_diagnostic_controle_gd_qpdf_et_l_antivirus(): void
    {
        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('version '.phpversion('gd'))
            ->expectsOutputToContain('qpdf version')
            ->expectsOutputToContain('antivirus factice, qui ne reconnaît que le fichier de test EICAR, fichier de test EICAR reconnu (Eicar-Test-Signature)')
            ->assertSuccessful();
    }

    /**
     * En production, l'antivirus factice fait échouer le diagnostic ; un
     * qpdf introuvable aussi, en tout lieu : sans lui, tout PDF reçu est
     * refusé.
     */
    public function test_le_diagnostic_echoue_avec_l_antivirus_factice_en_production_ou_sans_qpdf(): void
    {
        config(['piliqi.qpdf' => '/chemin/qui/n/existe/pas/qpdf']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('L\'antivirus factice (ANTIVIRUS_PILOTE=factice) ne sert jamais en production')
            ->expectsOutputToContain('qpdf ne se lance pas (/chemin/qui/n/existe/pas/qpdf)')
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle')
            ->assertFailed();
    }

    /**
     * qpdf 10, qu'ont encore Debian 11 et Ubuntu 22.04, ne donne pas la
     * description JSON version 2 que lit l'analyse des PDF : le diagnostic
     * exige qpdf 11 ou plus, et dit quoi faire ; un programme qui ne dit
     * pas une version de qpdf (ici PHP lui-même) échoue de même.
     */
    public function test_le_diagnostic_exige_qpdf_11_ou_plus(): void
    {
        $this->assertSame(
            [false, false, true, true, false],
            array_map(AnalyseurDePdf::versionAdmise(...), ['qpdf version 10.1.0', 'qpdf version 9.1.1', 'qpdf version 11.0.0', "qpdf version 12.2.0\nRun qpdf --copyright", 'PHP 8.3.6 (cli)']),
        );

        config(['piliqi.qpdf' => PHP_BINARY]);

        $this->artisan('caisse:diagnostiquer')
            ->expectsOutputToContain('l\'analyse des PDF demande qpdf 11 ou plus')
            ->expectsOutputToContain('Caisse sécurisée non opérationnelle : 1 contrôle(s) en échec.')
            ->assertFailed();
    }

    /**
     * Constat de l'éditeur du 12/11/2026 : sous Windows, « qpdf --version »
     * finit ses lignes par « \r\n » ; le retour chariot, imprimé dans la
     * cellule, effaçait la ligne de qpdf du diagnostic. La première ligne
     * se lit sans fin de ligne : le diagnostic dit la version, sans aucun
     * « \r ». Le programme simulé écrit exactement ce qu'écrit qpdf sous
     * Windows.
     */
    public function test_le_diagnostic_lit_la_version_de_qpdf_sans_le_retour_chariot_de_windows(): void
    {
        $sortieDeWindows = "qpdf version 12.2.0\r\nRun qpdf --copyright to see copyright and license information.\r\n";
        $this->assertSame('qpdf version 12.2.0', AnalyseurDePdf::premiereLigne($sortieDeWindows));
        $this->assertTrue(AnalyseurDePdf::versionAdmise($sortieDeWindows));
        $qpdfDeWindows = tempnam(sys_get_temp_dir(), 'qpdf-windows-');
        $this->assertIsString($qpdfDeWindows);
        file_put_contents($qpdfDeWindows, "#!/bin/sh\nprintf 'qpdf version 12.2.0\\r\\nRun qpdf --copyright to see copyright and license information.\\r\\n'\n");
        chmod($qpdfDeWindows, 0755);
        config(['piliqi.qpdf' => $qpdfDeWindows]);

        try {
            $code = Artisan::call('caisse:diagnostiquer');
            $sortie = Artisan::output();
        } finally {
            unlink($qpdfDeWindows);
        }

        $this->assertSame(0, $code, $sortie);
        $this->assertMatchesRegularExpression('/\| Analyseur des PDF \(qpdf\) +\| OK +\| qpdf version 12\.2\.0 +\|/u', $sortie);
        $this->assertStringNotContainsString("\r", $sortie);
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
     * Le fuseau par défaut de la base (celui que reçoit toute session avant que l'application ne fixe le sien) doit être
     * l'UTC : une session dont les options de connexion donnent le fuseau
     * de Paris pour fuseau de départ fait échouer le diagnostic, qui en
     * nomme la cause, alors que SHOW TIMEZONE y rend l'UTC que
     * l'application vient de fixer. Chaque cause et sa commande se
     * vérifient dans FuseauParDefautDeLaBaseTest. La base reçoit d'abord,
     * par une connexion à part, le fuseau UTC que lui donnent les
     * migrations : cette classe ne les passe pas, et un test qui les
     * défait (DatabaseMigrations) retire ce réglage, si bien que l'ordre
     * des tests d'un processus ne le garantit pas.
     */
    public function test_diagnostiquer_echoue_quand_le_fuseau_par_defaut_de_la_base_n_est_pas_utc(): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        config(['database.connections.reglage_de_la_base' => config("database.connections.{$connexionDuTest}")]);
        FuseauParDefautDeLaBase::fixerAUtc(DB::connection('reglage_de_la_base'));
        DB::purge('reglage_de_la_base');
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
