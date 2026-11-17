<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
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
 * celui de la démonstration telle qu'elle est (référence, repère, type,
 * empreinte et génération du lien en cours, jamais d'un lien que le
 * renouvellement a coupé, fin), chacun déposé dans le contexte de sa
 * société ; une
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

    public function test_apres_un_renouvellement_le_creer_renvoye_porte_le_lien_en_cours_jamais_l_ancien(): void
    {
        $this->travelTo(self::aParis('2026-11-18 10:30:00'));
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $creee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        app(TenantContext::class)->clear();
        $this->leSiteDeDemonstrationRepond(200, ['etat' => 'prete', 'generation' => 2]);
        $this->travelTo(self::aParis('2026-11-18 10:42:00'));
        $renouvelee = app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null);
        app(TenantContext::class)->clear();
        $avant = count($this->messagesRecus());

        $this->artisan('demonstration:renvoyer-les-demandes')->assertSuccessful();

        $renvoyes = array_slice($this->messagesRecus(), $avant);
        $this->assertCount(1, $renvoyes);
        $this->assertSame('creer', $renvoyes[0]['quoi']);
        $this->assertSame($creee->demonstration->reference, $renvoyes[0]['reference']);
        $this->assertSame(Demonstration::empreinteDuLien((string) $renouvelee->lien), $renvoyes[0]['empreinte_du_lien'], 'Le lien en cours, jamais celui que le renouvellement a coupé.');
        $this->assertNotSame($creee->demonstration->empreinte_du_lien, $renvoyes[0]['empreinte_du_lien']);
        $this->assertSame(2, $renvoyes[0]['generation']);
        $this->assertSame($creee->demonstration->repere, $renvoyes[0]['repere'], 'Même repère.');
    }
}
