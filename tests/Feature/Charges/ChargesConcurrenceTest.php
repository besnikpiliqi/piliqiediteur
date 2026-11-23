<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Actions\SupprimerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Deux gestes sur la même charge en même temps, chacun dans son processus,
 * sur une vraie base PostgreSQL (lot CHARGES) : chaque geste relit la
 * charge sous le verrou de sa ligne (voir
 * GestesSurLesCharges::chargeVerrouillee()), si bien que le second voit ce
 * que le premier a fait. Deux validations n'en font qu'une, avec une seule
 * entrée au journal d'audit ; une suppression qui arrive pendant la
 * validation est refusée, et la charge reste validée.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ChargesConcurrenceTest extends TestCase
{
    use DatabaseMigrations;
    use PrepareDesCharges;

    #[Group('concurrence')]
    public function test_deux_validations_en_meme_temps_n_en_font_qu_une(): void
    {
        $this->disqueDesCharges();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $charge = $this->deposer($proprietaire, $societe);
        [$societeId, $compteId, $uuid] = [$societe->getKey(), $proprietaire->getKey(), (string) $charge->uuid];
        $autreValidation = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('charges', 'for update', static function () use ($societeId, $compteId, $uuid): void {
            try {
                app(ValiderUneChargeAction::class)->handle(User::query()->findOrFail($compteId), Societe::query()->withoutGlobalScopes()->findOrFail($societeId), $uuid);
            } catch (LogicException $refus) {
                if ($refus->getMessage() === ValiderUneChargeAction::REFUS_DEJA_VALIDEE) {
                    return;
                }

                throw $refus;
            }

            throw new RuntimeException('La charge a été validée deux fois.');
        }, $autreValidation);

        app(ValiderUneChargeAction::class)->handle($proprietaire, $societe, $uuid);

        $this->assertInstanceOf(InvokedProcess::class, $autreValidation);
        $resultat = $this->attendreLeProcessusConcurrent($autreValidation);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $relue = Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey());
        $this->assertSame([EtatDeLaCharge::Validee, ValideePar::Proprietaire], [$relue->etat, $relue->validee_par]);
        $this->assertCount(1, $this->entreesDAudit($societe, ActionAuditee::ChargeValidee));
    }

    #[Group('concurrence')]
    public function test_une_suppression_pendant_la_validation_est_refusee_et_la_charge_reste_validee(): void
    {
        $this->disqueDesCharges();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $charge = $this->deposer($proprietaire, $societe);
        [$societeId, $compteId, $uuid] = [$societe->getKey(), $proprietaire->getKey(), (string) $charge->uuid];
        $suppression = null;
        app(TenantContext::class)->clear();
        $this->concurrencerDesQueLaLigneEstVerrouillee('charges', 'for update', static function () use ($societeId, $compteId, $uuid): void {
            try {
                app(SupprimerUneChargeAction::class)->handle(User::query()->findOrFail($compteId), Societe::query()->withoutGlobalScopes()->findOrFail($societeId), $uuid);
            } catch (LogicException $refus) {
                if ($refus->getMessage() === SupprimerUneChargeAction::REFUS_VALIDEE) {
                    return;
                }

                throw $refus;
            }

            throw new RuntimeException('La charge validée a été supprimée.');
        }, $suppression);

        app(ValiderUneChargeAction::class)->handle($collaborateur, $societe, $uuid);

        $this->assertInstanceOf(InvokedProcess::class, $suppression);
        $resultat = $this->attendreLeProcessusConcurrent($suppression);
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $relue = Charge::query()->withoutGlobalScopes()->findOrFail($charge->getKey());
        $this->assertSame([EtatDeLaCharge::Validee, ValideePar::Cabinet], [$relue->etat, $relue->validee_par]);
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::ChargeSupprimee));
    }
}
