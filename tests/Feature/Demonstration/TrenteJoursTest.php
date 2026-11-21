<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * N9 (lot DEMO-COPIE, B.8) : la démonstration vit 30 jours au calendrier
 * de Paris, à la seconde, à travers les changements d'heure ; la tâche de
 * chaque heure la termine à sa fin tout juste, inscrit l'audit au nom du
 * système, la carte revient à « À créer », et une nouvelle copie repart de
 * zéro avec un nouveau lien. La validation de la société ne la touche pas.
 */
class TrenteJoursTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration();
    }

    /**
     * @return array<string, array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}>
     */
    public static function passagesALHeure(): array
    {
        return [
            'à travers le passage à l\'heure d\'hiver' => [self::aParis('2026-10-20 10:00:00'), self::aParis('2026-11-19 10:00:00'), '19/11/2026'],
            'à travers le passage à l\'heure d\'été' => [self::aParis('2027-03-10 10:00:00'), self::aParis('2027-04-09 10:00:00'), '09/04/2027'],
        ];
    }

    #[DataProvider('passagesALHeure')]
    public function test_la_copie_vit_trente_jours_au_calendrier_de_paris_puis_se_termine_tout_juste(CarbonImmutable $creation, CarbonImmutable $fin, string $jourDeFin): void
    {
        $this->travelTo($creation);
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $premiere = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        $this->assertMemeInstant($fin, $premiere->demonstration->fin_le);

        $this->travelTo($fin->subSecond());
        $this->artisan('demonstration:marquer-les-fins')->assertSuccessful();
        $this->assertSame(EtatDeLaDemonstration::Prete, Demonstration::query()->withoutGlobalScopes()->findOrFail($premiere->demonstration->getKey())->etat);

        $this->travelTo($fin);
        $this->artisan('demonstration:marquer-les-fins')->assertSuccessful();
        $terminee = Demonstration::query()->withoutGlobalScopes()->findOrFail($premiere->demonstration->getKey());
        $this->assertSame([EtatDeLaDemonstration::Terminee, CauseDeLaFin::TrenteJours], [$terminee->etat, $terminee->cause_de_la_fin]);
        $entree = EntreeAudit::query()->withoutGlobalScopes()->where('societe_id', $proprietaire->societe_id)->where('action', ActionAuditee::DemonstrationTerminee->value)->firstOrFail();
        $this->assertSame([null, 'systeme'], [$entree->auteur_id, $entree->origine]);
        $this->assertSame('supprimer', collect($this->messagesRecus())->last()['quoi'] ?? null);

        app(TenantContext::class)->clear();
        $carte = app(CarteDeLaDemonstration::class)->pour($proprietaire, $proprietaire->societe);
        $this->assertSame(CarteDeLaDemonstration::A_CREER, $carte['etat'] ?? null);
        $this->assertContains('Votre démonstration précédente s\'est terminée le '.$jourDeFin.'.', $carte['notes'] ?? []);

        $seconde = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        $this->assertNotSame($premiere->demonstration->getKey(), $seconde->demonstration->getKey());
        $this->assertNotSame($premiere->lien, $seconde->lien);
        $this->assertNotSame($premiere->demonstration->reference, $seconde->demonstration->reference);
    }

    public function test_la_validation_de_la_societe_ne_touche_pas_la_copie(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($societe->proprietaire()->firstOrFail(), null, null)->demonstration;
        app(TenantContext::class)->clear();

        $this->saisirLeCodeDuCourrier($societe);

        $relue = Demonstration::query()->withoutGlobalScopes()->findOrFail($demonstration->getKey());
        $this->assertSame([EtatDeLaDemonstration::Prete, $demonstration->empreinte_du_lien, 1], [$relue->etat, $relue->empreinte_du_lien, $relue->generation_du_lien]);
        $this->assertMemeInstant($demonstration->fin_le, $relue->fin_le);
    }
}
