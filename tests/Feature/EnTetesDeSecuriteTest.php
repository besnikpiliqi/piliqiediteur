<?php

namespace Tests\Feature;

use App\Http\Middleware\AjouterLesEnTetesDeSecurite;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Chaque réponse de PILIQI porte les en-têtes de sécurité (décision de
 * l'éditeur du 07/11/2026, D54) : page, redirection, réponse JSON, erreur ;
 * un fichier téléchargé, voir PiecesDuFournisseurResourceTest.
 */
class EnTetesDeSecuriteTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function reponses(): array
    {
        return [
            'page de connexion du panneau des magasins' => ['GET', '/app/login', 200],
            'page de connexion de l\'espace de la société' => ['GET', '/societe/login', 200],
            'page de connexion de l\'espace du cabinet' => ['GET', '/cabinet/login', 200],
            'redirection de la racine' => ['GET', '/', 302],
            'page introuvable' => ['GET', '/page-qui-n-existe-pas', 404],
            'erreur JSON' => ['JSON', '/page-qui-n-existe-pas', 404],
            'état du service' => ['GET', '/up', 200],
        ];
    }

    #[DataProvider('reponses')]
    public function test_chaque_reponse_porte_les_en_tetes_de_securite(string $methode, string $adresse, int $statut): void
    {
        $reponse = $methode === 'JSON' ? $this->getJson($adresse) : $this->get($adresse);

        $reponse->assertStatus($statut);
        self::verifierLesEnTetes($reponse);
        $reponse->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_la_politique_de_contenu_ne_permet_aucune_source_tierce_ni_objet_ni_cadre(): void
    {
        $directives = [];

        foreach (explode(';', AjouterLesEnTetesDeSecurite::POLITIQUE_DE_CONTENU) as $directive) {
            $mots = preg_split('/\s+/', trim($directive));
            $directives[array_shift($mots)] = $mots;
        }

        $this->assertSame(["'self'"], $directives['default-src']);
        $this->assertSame(["'none'"], $directives['object-src']);
        $this->assertSame(["'self'"], $directives['base-uri']);
        $this->assertSame(["'self'"], $directives['form-action']);
        $this->assertSame(["'none'"], $directives['frame-ancestors']);
        $this->assertSame(["'self'"], $directives['connect-src']);
        $this->assertSame(["'self'"], $directives['font-src']);

        foreach ($directives as $nom => $sources) {
            foreach ($sources as $source) {
                $this->assertContains($source, ["'self'", "'none'", "'unsafe-inline'", "'unsafe-eval'", 'data:', 'blob:'], "{$nom} ne nomme que PILIQI : {$source}");
            }
        }
    }

    public function test_en_production_le_transport_strict_s_ajoute(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        // En production, le middleware TrustHosts pose les hôtes admis sur
        // la classe Request elle-même : ils sont retirés après le test, pour
        // que les tests suivants du même processus (ceux du navigateur
        // compris, servis sur 127.0.0.1) ne reçoivent pas une erreur 400.
        $this->beforeApplicationDestroyed(fn () => Request::setTrustedHosts([]));

        $reponse = $this->get('/app/login');

        $reponse->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        self::verifierLesEnTetes($reponse);
    }

    public function test_un_en_tete_deja_pose_par_la_reponse_est_garde(): void
    {
        $reponse = $this->get('/inscription/'.str_repeat('a', 64));

        $reponse->assertHeader('Referrer-Policy', 'no-referrer');
        $reponse->assertHeader('Content-Security-Policy', AjouterLesEnTetesDeSecurite::POLITIQUE_DE_CONTENU);
    }

    /**
     * @param  TestResponse<Response>  $reponse
     */
    public static function verifierLesEnTetes(TestResponse $reponse): void
    {
        $reponse->assertHeader('Content-Security-Policy', AjouterLesEnTetesDeSecurite::POLITIQUE_DE_CONTENU);
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('X-Frame-Options', 'DENY');
        $reponse->assertHeader('Permissions-Policy', AjouterLesEnTetesDeSecurite::POLITIQUE_DES_FONCTIONS);
        $reponse->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        if ($reponse->headers->get('Referrer-Policy') !== 'no-referrer') {
            $reponse->assertHeader('Referrer-Policy', 'same-origin');
        }
    }
}
