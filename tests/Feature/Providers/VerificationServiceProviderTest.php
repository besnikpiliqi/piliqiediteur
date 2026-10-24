<?php

namespace Tests\Feature\Providers;

use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use App\Providers\VerificationServiceProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Le câblage des fournisseurs externes de la vérification (voir
 * VerificationServiceProvider) : chacun selon son pilote, jamais le pilote
 * factice en production.
 */
class VerificationServiceProviderTest extends TestCase
{
    private const SERVICES = ['sirene', 'rne', 'recommande'];

    public function test_aucun_pilote_factice_n_est_accepte_en_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        foreach (self::SERVICES as $service) {
            config([
                'services.sirene.pilote' => 'insee',
                'services.rne.pilote' => 'inpi',
                'services.recommande.pilote' => null,
                "services.{$service}.pilote" => 'faux',
            ]);

            $this->assertThrows(fn () => (new VerificationServiceProvider($this->app))->boot(), RuntimeException::class, "Le fournisseur factice de « {$service} » (pilote faux) ne sert jamais en production.");
        }

        config(['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi', 'services.recommande.pilote' => null]);
        (new VerificationServiceProvider($this->app))->boot();
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(array_fill_keys(array_map(fn (string $service): string => "services.{$service}.pilote", self::SERVICES), 'faux'));
        (new VerificationServiceProvider($this->app))->boot();
    }

    /**
     * Chaque contrat se résout selon son pilote ; un pilote inconnu fait
     * échouer sa résolution au lieu de choisir un fournisseur au hasard.
     */
    public function test_chaque_fournisseur_suit_son_pilote_et_un_pilote_inconnu_est_refuse(): void
    {
        config(['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi']);
        $this->app->forgetInstance(RepertoireSirene::class);
        $this->app->forgetInstance(RegistreNationalDesEntreprises::class);

        $this->assertInstanceOf(InseeSirene::class, $this->app->make(RepertoireSirene::class));
        $this->assertInstanceOf(InpiRne::class, $this->app->make(RegistreNationalDesEntreprises::class));

        config(['services.sirene.pilote' => 'annuaire']);
        $this->app->forgetInstance(RepertoireSirene::class);
        $this->assertThrows(fn () => $this->app->make(RepertoireSirene::class), RuntimeException::class, 'Pilote inconnu pour sirene : « annuaire » (insee ou faux).');
    }
}
