<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\EtatDuMotDePasse;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Domain\Tenancy\Services\LimitationDesLiens;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Filament\App\Resources\FactureResource;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Des essais de connexion simultanés, chacun dans son processus, sur une
 * vraie base PostgreSQL et sur le cache de la base, que partagent les
 * processus comme en production (voir LimitationDesConnexions) : des
 * échecs simultanés sont tous comptés (ceux de la connexion comme les
 * demandes de « Mot de passe oublié »), des connexions réussies
 * simultanées ne font jamais attendre, un mot de passe provisoire ne sert
 * qu'à une seule connexion simultanée, et une connexion faite avec
 * l'ancien mot de passe pendant qu'un accès est redonné ne laisse aucune
 * session valable.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ConnexionsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const ADRESSE_DU_MAGASIN = '203.0.113.7';

    private const PROCESSUS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        self::partagerLeCacheDesEssais();
    }

    /**
     * Six processus échouent au même instant pour le même identifiant,
     * depuis la même adresse : retenus derrière la ligne du compteur, ils
     * l'incrémentent tous ensuite ; il vaut six, et l'essai suivant attend.
     */
    #[Group('concurrence')]
    public function test_des_echecs_simultanes_sur_un_meme_identifiant_sont_tous_comptes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $saisie = (string) $this->membre($magasin, RoleMagasin::Vendeur)->identifiantDeConnexion();
        $limitation = app(LimitationDesConnexions::class);
        $cle = $limitation->cle('paire', $saisie, self::ADRESSE_DU_MAGASIN);
        Cache::store('database')->add($cle, 0, LimitationDesConnexions::FENETRE_PAR_SAISIE_ET_ADRESSE);

        $echouer = static function () use ($saisie): void {
            self::partagerLeCacheDesEssais();
            app(LimitationDesConnexions::class)->echec($saisie, self::ADRESSE_DU_MAGASIN, null, null, false);
        };

        $processus = DB::transaction(function () use ($cle, $echouer): array {
            DB::table('cache')->where('key', config('cache.prefix').$cle)->lockForUpdate()->sole();
            $processus = array_map(fn (): InvokedProcess => $this->demarrerUnProcessusConcurrent($echouer), range(1, self::PROCESSUS));
            $this->attendreQueTantDeProcessusAttendentUnVerrou(self::PROCESSUS);

            return $processus;
        });

        foreach ($processus as $unProcessus) {
            $resultat = $this->attendreLeProcessusConcurrent($unProcessus);
            $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        }
        $this->assertSame(self::PROCESSUS, $limitation->compteDe($cle));
        $this->assertGreaterThan(0, $limitation->attente($saisie, self::ADRESSE_DU_MAGASIN, false));
    }

    /**
     * Six processus tentent au même instant de consommer le même mot de
     * passe provisoire, retenus derrière le verrou de la ligne du compte :
     * un seul y arrive (la mise à jour atomique ne vaut que tant qu'il n'a
     * pas servi), les autres trouvent la ligne déjà prise.
     */
    #[Group('concurrence')]
    public function test_six_consommations_simultanees_d_un_meme_mot_de_passe_provisoire_n_en_servent_qu_une(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->assertSame(EtatDuMotDePasse::ProvisoireValable, $compte->refresh()->etatDuMotDePasse());
        $identifiant = $compte->getKey();

        $consommer = static function () use ($identifiant): void {
            fwrite(STDOUT, app(MotsDePasseProvisoires::class)->consommer(User::query()->findOrFail($identifiant)) ? '1' : '0');
        };

        $processus = DB::transaction(function () use ($identifiant, $consommer): array {
            User::query()->whereKey($identifiant)->lockForUpdate()->sole();
            $processus = array_map(fn (): InvokedProcess => $this->demarrerUnProcessusConcurrent($consommer), range(1, self::PROCESSUS));
            $this->attendreQueTantDeProcessusAttendentUnVerrou(self::PROCESSUS);

            return $processus;
        });

        $reponses = [];
        foreach ($processus as $unProcessus) {
            $resultat = $this->attendreLeProcessusConcurrent($unProcessus);
            $this->assertTrue($resultat->successful(), $resultat->errorOutput());
            $reponses[] = $resultat->output();
        }

        sort($reponses);
        $this->assertSame(['0', '0', '0', '0', '0', '1'], $reponses);
        $this->assertSame(EtatDuMotDePasse::ProvisoireUtilise, $compte->refresh()->etatDuMotDePasse());
        $this->assertFalse(app(MotsDePasseProvisoires::class)->consommer($compte));
    }

    /**
     * Six demandes de « Mot de passe oublié » simultanées pour une même
     * saisie, depuis des adresses différentes : toutes comptées, par heure
     * et par jour, et la suivante attend (le plafond de trois par heure
     * n'est jamais dépassé en silence).
     */
    #[Group('concurrence')]
    public function test_des_demandes_simultanees_de_mot_de_passe_oublie_pour_une_meme_saisie_sont_toutes_comptees(): void
    {
        $saisie = 'proprietaire@exemple.fr';
        $limitation = app(LimitationDesLiens::class);
        $cle = $limitation->cle('demande-heure', $saisie);
        Cache::store('database')->add($cle, 0, LimitationDesLiens::UNE_HEURE);

        $demander = static function (): void {
            self::partagerLeCacheDesEssais();
            app(LimitationDesLiens::class)->demande('proprietaire@exemple.fr', '203.0.113.'.random_int(10, 250));
        };

        $processus = DB::transaction(function () use ($cle, $demander): array {
            DB::table('cache')->where('key', config('cache.prefix').$cle)->lockForUpdate()->sole();
            $processus = array_map(fn (): InvokedProcess => $this->demarrerUnProcessusConcurrent($demander), range(1, self::PROCESSUS));
            $this->attendreQueTantDeProcessusAttendentUnVerrou(self::PROCESSUS);

            return $processus;
        });

        foreach ($processus as $unProcessus) {
            $resultat = $this->attendreLeProcessusConcurrent($unProcessus);
            $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        }

        $this->assertSame(self::PROCESSUS, $limitation->compteDe($cle));
        $this->assertSame(self::PROCESSUS, $limitation->compteDe($limitation->cle('demande-jour', $saisie)));
        $this->assertGreaterThan(0, $limitation->demande($saisie, '198.51.100.250'));
    }

    /**
     * Six vendeurs se connectent au même instant, chacun dix fois, depuis
     * l'adresse commune du magasin : soixante connexions réussies, plus
     * que le seuil des échecs par adresse, et personne n'attend, ni
     * l'adresse, ni aucun identifiant.
     */
    #[Group('concurrence')]
    public function test_des_connexions_reussies_simultanees_depuis_une_meme_adresse_ne_font_jamais_attendre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $saisies = array_map(fn (): string => (string) $this->membre($magasin, RoleMagasin::Vendeur)->identifiantDeConnexion(), range(1, self::PROCESSUS));

        $processus = [];
        foreach ($saisies as $saisie) {
            $processus[] = $this->demarrerUnProcessusConcurrent(static function () use ($saisie): void {
                self::partagerLeCacheDesEssais();
                $limitation = app(LimitationDesConnexions::class);

                foreach (range(1, 10) as $connexion) {
                    if ($limitation->attente($saisie, self::ADRESSE_DU_MAGASIN, false) > 0 || ! Auth::guard('web')->attempt(['login' => $saisie, 'password' => self::MOT_DE_PASSE_CHOISI])) {
                        throw new RuntimeException("La connexion {$connexion} a été refusée ou a dû attendre.");
                    }

                    $limitation->reussite($saisie, self::ADRESSE_DU_MAGASIN, null);
                    Auth::guard('web')->logout();
                }
            });
        }

        foreach ($processus as $unProcessus) {
            $resultat = $this->attendreLeProcessusConcurrent($unProcessus);
            $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        }
        $limitation = app(LimitationDesConnexions::class);
        foreach ([...$saisies, (string) Str::uuid()] as $saisie) {
            $this->assertSame(0, $limitation->attente($saisie, self::ADRESSE_DU_MAGASIN, false), $saisie);
        }
        $this->assertSame(0, $limitation->compteDe($limitation->cle('adresse', self::ADRESSE_DU_MAGASIN)));
    }

    /**
     * Pendant que le responsable redonne l'accès d'un vendeur, celui-ci se
     * connecte ailleurs avec son ancien mot de passe, que la base donne
     * encore tant que le renouvellement n'est pas validé : la session
     * ouverte ainsi est fermée à sa requête suivante (AuthenticateSession
     * compare le mot de passe de la session à celui du compte), et l'ancien
     * mot de passe n'ouvre plus rien.
     */
    #[Group('concurrence')]
    public function test_une_connexion_concurrente_d_un_acces_redonne_ne_laisse_aucune_session_valable_avec_l_ancien_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saisie = (string) $vendeur->identifiantDeConnexion();
        $connexionDuTest = DB::getDefaultConnection();
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $connexionDuTest, $saisie): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'update "users" set "password"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($saisie): void {
                    if (! Auth::guard('web')->attempt(['login' => $saisie, 'password' => self::MOT_DE_PASSE_CHOISI])) {
                        throw new RuntimeException('L\'ancien mot de passe n\'a pas ouvert le compte.');
                    }

                    fwrite(STDOUT, (string) Auth::guard('web')->user()?->getAuthPassword());
                });
                $this->attendreLeProcessusConcurrent($concurrente);
            }
        });

        app(RedonnerLAccesAction::class)->enSaPresence($responsable, $vendeur, 'un-mot-de-passe-solide', (string) Str::uuid());

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $empreinteDeLaSession = $resultat->output();
        $this->assertNotSame($vendeur->refresh()->getAuthPassword(), $empreinteDeLaSession);

        $this->withSession([Auth::guard('web')->getName() => $vendeur->id, 'password_hash_web' => $empreinteDeLaSession])
            ->get(FactureResource::getUrl('index', tenant: $magasin))
            ->assertRedirect();
        $this->assertGuest();
        $this->assertFalse(Auth::guard('web')->validate(['login' => $saisie, 'password' => self::MOT_DE_PASSE_CHOISI]));
        $this->assertTrue(Auth::guard('web')->validate(['login' => $saisie, 'password' => 'un-mot-de-passe-solide']));
    }

    /**
     * Les compteurs des essais sur le cache de la base, que partagent les
     * processus comme en production (les tests gardent sinon un cache en
     * mémoire, propre à chaque processus).
     */
    private static function partagerLeCacheDesEssais(): void
    {
        config(['cache.limiter' => 'database']);
    }

    /**
     * Attend que tant d'autres processus attendent un verrou que tient le
     * test, dix secondes au plus ; l'attente se lit dans pg_stat_activity
     * par une connexion à part.
     */
    private function attendreQueTantDeProcessusAttendentUnVerrou(int $nombre): void
    {
        $observateur = DB::getDefaultConnection().'_observateur';
        config(["database.connections.{$observateur}" => [...DB::connection()->getConfig(), 'name' => $observateur]]);
        $limite = microtime(true) + 10;

        try {
            while (microtime(true) < $limite) {
                if (DB::connection($observateur)->selectOne("select count(*) as nombre from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'")->nombre >= $nombre) {
                    return;
                }

                usleep(20_000);
            }
        } finally {
            DB::purge($observateur);
        }

        $this->fail("Les {$nombre} processus n'attendent pas tous le verrou du compteur.");
    }
}
