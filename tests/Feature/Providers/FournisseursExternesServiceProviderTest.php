<?php

namespace Tests\Feature\Providers;

use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use App\Providers\FournisseursExternesServiceProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Le câblage des fournisseurs externes de la vérification (voir
 * FournisseursExternesServiceProvider) : chacun selon son pilote, jamais le pilote
 * factice en production.
 */
class FournisseursExternesServiceProviderTest extends TestCase
{
    private const SERVICES = ['sirene', 'rne', 'recommande', 'pdp'];

    public function test_aucun_pilote_factice_n_est_accepte_en_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        foreach (self::SERVICES as $service) {
            config([
                'services.sirene.pilote' => 'insee',
                'services.rne.pilote' => 'inpi',
                'services.recommande.pilote' => null,
                'services.ordre.pilote' => 'editeur',
                'services.pdp.pilote' => null,
                "services.{$service}.pilote" => 'faux',
            ]);

            $this->assertThrows(fn () => (new FournisseursExternesServiceProvider($this->app))->boot(), RuntimeException::class, "Le fournisseur factice de « {$service} » (pilote faux) ne sert jamais en production.");
        }

        config(['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi', 'services.recommande.pilote' => null, 'services.ordre.pilote' => 'editeur', 'services.pdp.pilote' => null]);
        (new FournisseursExternesServiceProvider($this->app))->boot();
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(array_fill_keys(array_map(fn (string $service): string => "services.{$service}.pilote", self::SERVICES), 'faux'));
        (new FournisseursExternesServiceProvider($this->app))->boot();
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

    /**
     * La plateforme agréée se câble comme les autres fournisseurs : sa
     * passerelle factice en test, jamais en production, et un pilote
     * inconnu refusé (constat C34 des audits du 07/11/2026).
     */
    public function test_la_plateforme_agreee_suit_son_pilote(): void
    {
        $this->app->forgetInstance(PdpGatewayInterface::class);
        $this->assertInstanceOf(FakePdpGateway::class, $this->app->make(PdpGatewayInterface::class));
        $this->assertSame($this->app->make(PdpGatewayInterface::class), $this->app->make(PdpGatewayInterface::class));

        config(['services.pdp.pilote' => 'superpdp']);
        $this->app->forgetInstance(PdpGatewayInterface::class);
        $this->assertThrows(fn () => $this->app->make(PdpGatewayInterface::class), RuntimeException::class, 'Pilote inconnu pour pdp : « superpdp » (faux).');
    }
}
