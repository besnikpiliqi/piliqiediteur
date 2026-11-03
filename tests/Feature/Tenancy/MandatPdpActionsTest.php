<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Actions\RevokerMandatPdpAction;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
