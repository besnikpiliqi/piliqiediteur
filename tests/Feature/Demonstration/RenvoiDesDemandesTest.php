<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\TerminerLaDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * demonstration:renvoyer-les-demandes, sur le site réel (lot DEMO-COPIE,
 * E.9, N26) : le message « creer » de chaque démonstration en cours repart,
 * le même que la demande (référence, repère, type, empreinte du lien,
 * génération, fin), chacun déposé dans le contexte de sa société ; une
 * démonstration terminée ne repart pas ; la commande est idempotente, sans
 * jamais créer une seconde démonstration.
 */
class RenvoiDesDemandesTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration(200, ['etat' => 'prete', 'generation' => 1]);
    }

    public function test_chaque_demande_en_cours_repart_identique_et_une_demonstration_terminee_non(): void
    {
        $premiere = $this->proprietaireDe($this->ouvrirUneSociete());
        $seconde = $this->proprietaireDe($this->ouvrirUneSociete());
        $terminee = $this->proprietaireDe($this->ouvrirUneSociete());

        foreach ([$premiere, $seconde, $terminee] as $proprietaire) {
            app(TenantContext::class)->clear();
            app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
            app(TenantContext::class)->clear();
        }

        app(TerminerLaDemonstrationAction::class)->handle($terminee->societe()->firstOrFail(), CauseDeLaFin::TrenteJours);
        app(TenantContext::class)->clear();
        $creations = $this->messagesRecus();

        $this->artisan('demonstration:renvoyer-les-demandes')->assertSuccessful()->expectsOutputToContain('2 demande(s) renvoyée(s)');
        $this->artisan('demonstration:renvoyer-les-demandes')->assertSuccessful();

        $renvoyes = array_slice($this->messagesRecus(), count($creations));
        $this->assertCount(4, $renvoyes, 'Deux démonstrations en cours, deux passages : quatre « creer ».');
        $sansEnvoi = static fn (array $message): array => array_diff_key($message, array_flip(['emis_le', 'nonce']));
        $attendus = array_map($sansEnvoi, array_values(array_filter($creations, static fn (array $message): bool => $message['quoi'] === 'creer')));

        foreach ($renvoyes as $renvoye) {
            $this->assertContains($sansEnvoi($renvoye), $attendus, 'Le « creer » renvoyé est celui de la demande : même référence, même repère, même lien, même fin.');
        }

        $termineeReference = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $terminee->societe_id)->value('reference');
        $this->assertNotContains($termineeReference, array_column($renvoyes, 'reference'), 'Une démonstration terminée ne repart pas.');
        $this->assertSame(3, Demonstration::query()->withoutGlobalScopes()->count(), 'Aucune démonstration nouvelle.');
    }
}
