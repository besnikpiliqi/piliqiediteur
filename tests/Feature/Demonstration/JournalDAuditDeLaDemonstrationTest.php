<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RecommencerLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Actions\TerminerLaDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * N11 (lot DEMO-COPIE, B.12) : chaque geste de la démonstration s'inscrit
 * au journal d'audit de la société, catégorie « gestion des accès », avec
 * son libellé ; jamais le lien, son empreinte ni la référence.
 */
class JournalDAuditDeLaDemonstrationTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    public function test_chaque_geste_s_inscrit_en_gestion_des_acces_sans_jamais_le_lien_son_empreinte_ni_la_reference(): void
    {
        Mail::fake();
        $this->reglerLeSiteDeDemonstration();
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $creee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        app(TenantContext::class)->clear();
        app(OuvrirLaDemonstrationAction::class)->handle($proprietaire);
        app(TenantContext::class)->clear();
        $renouvelee = app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null);
        app(TenantContext::class)->clear();
        app(RecommencerLaDemonstrationAction::class)->handle($proprietaire, null);
        app(TenantContext::class)->clear();
        app(TerminerLaDemonstrationAction::class)->handle($proprietaire->societe, CauseDeLaFin::TrenteJours);

        $entrees = DB::table('journal_audit')->where('societe_id', $proprietaire->societe_id)->where('action', 'like', '%demonstration%')->orderBy('id')->get(['action', 'categorie', 'nouvelles_valeurs', 'auteur_id']);

        $this->assertSame([
            ActionAuditee::DemonstrationCreee->value,
            ActionAuditee::DemonstrationOuverteParLeBouton->value,
            ActionAuditee::LienDeDemonstrationRenouvele->value,
            ActionAuditee::DemonstrationRecommencee->value,
            ActionAuditee::DemonstrationTerminee->value,
        ], $entrees->pluck('action')->all());
        $this->assertSame([CategorieAudit::Acces->value], $entrees->pluck('categorie')->unique()->values()->all());
        $this->assertSame('Démonstration ouverte depuis le compte', ActionAuditee::DemonstrationOuverteParLeBouton->getLabel());
        $this->assertEquals(['repere' => $creee->demonstration->repere, 'cause' => '30 jours'], json_decode((string) $entrees->last()?->nouvelles_valeurs, true));

        $demonstration = Demonstration::query()->withoutGlobalScopes()->findOrFail($creee->demonstration->getKey());
        $tout = DB::table('journal_audit')->where('societe_id', $proprietaire->societe_id)->get()->toJson();

        foreach ([(string) $creee->lien, (string) $renouvelee->lien, $demonstration->reference, $demonstration->empreinte_du_lien, Demonstration::empreinteDuLien((string) $creee->lien)] as $secret) {
            $this->assertStringNotContainsString($secret, $tout);
        }
    }
}
