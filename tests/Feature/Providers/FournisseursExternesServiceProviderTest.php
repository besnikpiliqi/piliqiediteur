<?php

namespace Tests\Feature\Providers;

use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\InpiRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\InseeSirene;
use App\Integrations\Vies\ClientVies;
use App\Integrations\Vies\Contracts\ServiceVies;
use App\Integrations\Vies\FauxVies;
use App\Providers\FournisseursExternesServiceProvider;
use App\Providers\SiteDePiliqiServiceProvider;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Le câblage des fournisseurs externes de la vérification (voir
 * FournisseursExternesServiceProvider) : chacun selon son pilote, jamais le pilote
 * factice en production.
 */
class FournisseursExternesServiceProviderTest extends TestCase
{
    /**
     * La garde vit désormais avec celles du site servi (lot DEMO-COPIE,
     * A.4) : elle ne vaut que sur le site réel, où chaque fournisseur
     * factice de la liste unique, VIES et l'antivirus compris, est refusé
     * en production (voir SiteDePiliqiServiceProvider).
     */
    public function test_aucun_pilote_factice_n_est_accepte_en_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $reels = ['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi', 'services.recommande.pilote' => null, 'services.ordre.pilote' => 'editeur', 'services.pdp.pilote' => null, 'services.vies.pilote' => 'reel', 'services.antivirus.pilote' => 'clamav', 'session.cookie' => '__Host-piliqi-session', 'session.secure' => true, 'session.domain' => null, 'session.path' => '/'];

        foreach (FournisseursExternesServiceProvider::PILOTES_FACTICES as $reglage => $pilote) {
            config($reels);
            config([$reglage => $pilote]);
            $service = explode('.', $reglage)[1];

            $this->assertThrows(fn () => (new SiteDePiliqiServiceProvider($this->app))->boot(), RuntimeException::class, "Le fournisseur factice de « {$service} » (pilote {$pilote}) ne sert jamais en production.");
        }

        config($reels);
        (new SiteDePiliqiServiceProvider($this->app))->boot();
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(FournisseursExternesServiceProvider::PILOTES_FACTICES);
        (new SiteDePiliqiServiceProvider($this->app))->boot();
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

    /**
     * VIES suit son pilote (lot DEMO-COPIE, A.6) : le vrai service, que les
     * tests simulent, ou le faux du semis et du site de démonstration, qui
     * dit valide un n° de l'Union sans aucune requête et refuse, comme le
     * vrai, un n° hors de l'Union.
     */
    public function test_vies_suit_son_pilote_et_le_faux_ne_joint_jamais_l_exterieur(): void
    {
        config(['services.vies.pilote' => 'reel']);
        $this->app->forgetInstance(ServiceVies::class);
        $this->assertInstanceOf(ClientVies::class, $this->app->make(ServiceVies::class));

        config(['services.vies.pilote' => 'faux']);
        $this->app->forgetInstance(ServiceVies::class);
        $vies = $this->app->make(ServiceVies::class);
        $this->assertInstanceOf(FauxVies::class, $vies);

        $resultat = $vies->verifier('DE 123456789', 'FR40303265045');

        $this->assertTrue($resultat->valide);
        $this->assertNull($resultat->nom);
        $this->assertSame(FauxVies::IDENTIFIANT_DE_CONSULTATION, $resultat->identifiantConsultation);
        Http::assertNothingSent();
        $this->assertThrows(fn () => $vies->verifier('CH123456789', null), InvalidArgumentException::class, 'VIES ne vérifie que les n° de TVA de l\'Union européenne et d\'Irlande du Nord.');

        config(['services.vies.pilote' => 'ec']);
        $this->app->forgetInstance(ServiceVies::class);
        $this->assertThrows(fn () => $this->app->make(ServiceVies::class), RuntimeException::class, 'Pilote inconnu pour vies : « ec » (reel ou faux).');
    }
}
