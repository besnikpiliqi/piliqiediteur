<?php

namespace Tests;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
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
