<?php

namespace Tests;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

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
     * Chaque application de test signe avec la paire éphémère des tests,
     * seule clé épinglée : ni la clé d'un .env local ni une clé épinglée
     * dans le dépôt n'entrent dans les tests.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        self::$paireFiscaleDesTests ??= SignataireFiscal::genererUnePaire();

        $app['config']->set([
            'caisse_securisee.cle_privee' => base64_encode(self::$paireFiscaleDesTests['cle_privee']),
            'caisse_securisee.phrase_secrete' => null,
            'caisse_securisee.cles_publiques' => ['tests.pem' => self::$paireFiscaleDesTests['cle_publique']],
        ]);

        return $app;
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
