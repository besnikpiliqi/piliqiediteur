<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * N13 (lot DEMO-COPIE, B.6 et B.13), côté du site réel : un
 * renouvellement tire une nouvelle empreinte, à la génération suivante,
 * sans changer la fin ; la carte dit « Coupure de l'ancien lien en
 * cours… » tant que l'accusé 200 de cette génération n'est pas venu,
 * jamais « coupé » sur un 409 ; sans accusé en 15 minutes, l'éditeur est
 * prévenu, une fois, et la carte le dit au propriétaire.
 */
class RenouvellementTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $this->reglerLeSiteDeDemonstration();
        $this->travelTo(self::aParis('2026-11-18 10:30:00'));
    }

    public function test_le_renouvellement_change_l_empreinte_et_la_generation_sans_changer_la_fin_et_attend_l_accuse(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $creee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        $this->leSiteDeDemonstrationRepond(409, []);
        app(TenantContext::class)->clear();
        $this->travelTo(self::aParis('2026-11-18 10:42:00'));

        $renouvelee = app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null);

        $relue = Demonstration::query()->withoutGlobalScopes()->findOrFail($creee->demonstration->getKey());
        $this->assertNotSame($creee->demonstration->empreinte_du_lien, $relue->empreinte_du_lien);
        $this->assertSame(Demonstration::empreinteDuLien((string) $renouvelee->lien), $relue->empreinte_du_lien);
        $this->assertSame([2, null], [$relue->generation_du_lien, $relue->generation_confirmee]);
        $this->assertMemeInstant($creee->demonstration->fin_le, $relue->fin_le);
        $this->assertContains(CarteDeLaDemonstration::PHRASE_COUPURE_EN_COURS, $this->notes($proprietaire));

        $this->leSiteDeDemonstrationRepond(200, ['etat' => 'prete', 'generation' => 2]);
        $this->travelTo(self::aParis('2026-11-18 10:43:00'));
        $this->artisan('demonstration:relancer-les-messages')->assertSuccessful();

        $notes = $this->notes($proprietaire);
        $this->assertNotContains(CarteDeLaDemonstration::PHRASE_COUPURE_EN_COURS, $notes);
        $this->assertContains('Ancien lien coupé le 18/11/2026 à 10:43.', $notes);
    }

    public function test_sans_accuse_en_quinze_minutes_l_editeur_est_prevenu_une_fois_et_la_carte_le_dit(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        $this->leSiteDeDemonstrationRepond(409, []);
        app(TenantContext::class)->clear();
        app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null);

        $this->travelTo(self::aParis('2026-11-18 10:44:00'));
        $this->artisan('demonstration:relancer-les-messages')->assertSuccessful();
        Mail::assertNotSent(AlerteEditeurMail::class);
        $this->assertNotContains('Ancien lien coupé le 18/11/2026 à 10:44.', $this->notes($proprietaire));

        $this->travelTo(self::aParis('2026-11-18 10:46:00'));
        $this->artisan('demonstration:relancer-les-messages')->assertSuccessful();
        $this->travelTo(self::aParis('2026-11-18 10:51:00'));
        $this->artisan('demonstration:relancer-les-messages')->assertSuccessful();

        Mail::assertSent(AlerteEditeurMail::class, 1);
        $this->assertContains(CarteDeLaDemonstration::PHRASE_COUPURE_EN_RETARD, $this->notes($proprietaire));
    }

    /**
     * @return list<string>
     */
    private function notes(User $proprietaire): array
    {
        app(TenantContext::class)->clear();

        return app(CarteDeLaDemonstration::class)->pour($proprietaire, $proprietaire->societe)['notes'] ?? [];
    }
}
