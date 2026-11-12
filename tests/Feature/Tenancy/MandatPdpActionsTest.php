<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Actions\RevokerMandatPdpAction;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatMandatPdp;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class MandatPdpActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_mandat_enregistre_la_preuve_de_consentement_et_lactive_via_la_gateway(): void
    {
        $magasin = Magasin::factory()->create();
        $proprietaire = User::factory()->create();

        $mandat = app(CreerMandatPdpAction::class)->handle($magasin, $proprietaire);

        $this->assertSame(StatutMandatPdp::Active, $mandat->statut);
        $this->assertNotNull($mandat->mandat_externe_id);
        $this->assertNotNull($mandat->consentement_a);
        $this->assertSame($proprietaire->id, $mandat->accorde_par);
    }

    public function test_revoquer_un_mandat_change_son_statut_et_horodate_la_revocation(): void
    {
        $magasin = Magasin::factory()->create();
        $proprietaire = User::factory()->create();
        $mandat = app(CreerMandatPdpAction::class)->handle($magasin, $proprietaire);

        $mandat = app(RevokerMandatPdpAction::class)->handle($mandat);

        $this->assertSame(StatutMandatPdp::Revoque, $mandat->statut);
        $this->assertNotNull($mandat->revoque_a);
    }

    /**
     * La preuve du consentement s'enregistre avant l'appel à la plateforme
     * et survit à une panne pendant cet appel : le mandat reste en attente,
     * avec qui l'a accordé et quand (constat relevé avec C33 des audits du
     * 07/11/2026).
     */
    public function test_la_preuve_du_consentement_survit_a_une_panne_de_la_plateforme(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00'));
        $magasin = Magasin::factory()->create();
        $proprietaire = $this->proprietaireDe($magasin);
        $this->app->instance(PdpGatewayInterface::class, new class implements PdpGatewayInterface
        {
            public function creerMandat(Magasin $magasin): ResultatMandatPdp
            {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }

            public function revoquerMandat(MandatPdp $mandat): void {}

            public function soumettreFacture(Facture $facture, string $referenceDepot): ResultatSoumissionPdp
            {
                throw new ConnectionException('inutilisé');
            }

            public function retrouverDepot(TransmissionPdp $transmission): ?ResultatSoumissionPdp
            {
                return null;
            }
        });

        $this->assertThrows(fn () => app(CreerMandatPdpAction::class)->handle($magasin, $proprietaire), ConnectionException::class);

        $mandat = MandatPdp::query()->where('magasin_id', $magasin->id)->sole();
        $this->assertSame(StatutMandatPdp::EnAttente, $mandat->statut);
        $this->assertSame($proprietaire->id, $mandat->accorde_par);
        $this->assertTrue($mandat->consentement_a?->equalTo(self::aParis('2026-10-09 10:00')));
        $this->assertNull($mandat->mandat_externe_id);
    }
}
