<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RecommencerLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Jobs\EnvoyerLeLienDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\MaDemonstration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * N7 (lot DEMO-COPIE, B.2, B.3, B.9 et choix 12) : le lien ne part qu'à
 * une adresse de connexion confirmée ; une société non validée, même sans
 * magasin, demande sa copie ; l'inscription close, la révocation et un
 * nouveau propriétaire par le code du courrier suppriment la copie ; une
 * société suspendue ou résiliée la garde, l'ouvre et en renouvelle le
 * lien, sans en créer ni la remettre à zéro ; une transmission de la
 * propriété la garde, et le nouveau propriétaire coupe l'ancien en
 * renouvelant le lien.
 */
class AdresseConfirmeeTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration();
    }

    public function test_sans_adresse_confirmee_le_bouton_est_inactif_et_l_action_forgee_est_refusee_sans_rien_envoyer(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['email' => null, 'email_verified_at' => null])->save();
        Bus::fake([EnvoyerLeLienDeLaDemonstration::class]);

        $carte = app(CarteDeLaDemonstration::class)->pour($proprietaire->fresh(), $magasin->societe);
        $this->assertSame(CarteDeLaDemonstration::ADRESSE_A_CONFIRMER, $carte['etat'] ?? null);
        $this->assertFalse($carte['peut_creer'] ?? true);

        $this->actingAs($proprietaire);
        Livewire::test(MaDemonstration::class, ['societe' => $magasin->societe])
            ->assertSee(ConditionsDeLaDemonstration::MESSAGE_ADRESSE_A_CONFIRMER)
            ->assertSee('Renvoyer le lien de confirmation')
            ->assertDontSeeHtml("mountAction('creer')");

        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(DemanderUneDemonstrationAction::class)->handle($proprietaire->fresh() ?? $proprietaire, null, null), LogicException::class, ConditionsDeLaDemonstration::MESSAGE_ADRESSE_A_CONFIRMER);
        $this->assertSame(0, Demonstration::query()->withoutGlobalScopes()->count());
        Bus::assertNotDispatched(EnvoyerLeLienDeLaDemonstration::class);
    }

    public function test_une_societe_non_validee_et_sans_magasin_demande_sa_copie(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->assertFalse($societe->estValidee());
        $this->assertSame(0, $societe->magasins()->count());

        $resultat = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertNotNull($resultat->lien);
        $this->assertFalse($this->messagesRecus()[0]['validee']);
    }

    /**
     * @return array<string, array{0: string, 1: CauseDeLaFin}>
     */
    public static function finsAussitot(): array
    {
        return [
            'inscription close' => ['cloture', CauseDeLaFin::Cloture],
            'société révoquée' => ['revocation', CauseDeLaFin::Revocation],
            'nouveau propriétaire par le code du courrier' => ['nouveau-proprietaire', CauseDeLaFin::NouveauProprietaire],
        ];
    }

    #[DataProvider('finsAussitot')]
    public function test_la_cloture_la_revocation_et_un_nouveau_proprietaire_suppriment_la_copie(string $fin, CauseDeLaFin $cause): void
    {
        $societe = $fin === 'revocation' ? $this->ouvrirUneSociete()->societe : $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null)->demonstration;
        app(TenantContext::class)->clear();

        match ($fin) {
            'cloture' => $this->assertTrue(app(CloreLInscriptionAction::class)->cloreSansCode($societe, CarbonImmutable::now()->addMinute())),
            'revocation' => $this->revoquerLaSociete($societe),
            default => $this->saisirLeCodeCommeUneAutrePersonne($societe, 'repreneuse@exemple-de-test.fr'),
        };

        $relue = Demonstration::query()->withoutGlobalScopes()->findOrFail($demonstration->getKey());
        $this->assertSame(EtatDeLaDemonstration::Supprimee, $relue->etat);
        $this->assertSame($cause, $relue->cause_de_la_fin);
        $this->assertSame('supprimer', collect($this->messagesRecus())->last()['quoi'] ?? null);
        $this->assertNull(app(CarteDeLaDemonstration::class)->pour($proprietaire->fresh(), $societe->fresh() ?? $societe));
    }

    /**
     * @return array<string, array{0: StatutSociete}>
     */
    public static function statutsQuiGardentLaCopie(): array
    {
        return [
            'suspendue' => [StatutSociete::Suspendue],
            'résiliée' => [StatutSociete::Resiliee],
        ];
    }

    #[DataProvider('statutsQuiGardentLaCopie')]
    public function test_une_societe_suspendue_ou_resiliee_garde_sa_copie_l_ouvre_et_en_renouvelle_le_lien_sans_en_creer_ni_la_remettre_a_zero(StatutSociete $statut): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null)->demonstration;
        $magasin->societe->forceFill(['statut' => $statut])->save();
        app(TenantContext::class)->clear();

        $this->assertNotEmpty(app(OuvrirLaDemonstrationAction::class)->handle($proprietaire)['signature']);
        app(TenantContext::class)->clear();
        $this->assertNotNull(app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null)->lien);
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(RecommencerLaDemonstrationAction::class)->handle($proprietaire, null), LogicException::class, ConditionsDeLaDemonstration::MESSAGE_SOCIETE_SUSPENDUE);
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null), LogicException::class, ConditionsDeLaDemonstration::MESSAGE_SOCIETE_SUSPENDUE);
        $this->assertSame(EtatDeLaDemonstration::Prete, Demonstration::query()->withoutGlobalScopes()->findOrFail($demonstration->getKey())->etat);

        $carte = app(CarteDeLaDemonstration::class)->pour($proprietaire, Societe::query()->findOrFail($magasin->societe_id));
        $this->assertSame([true, true, false], [$carte['peut_ouvrir'] ?? null, $carte['peut_renouveler'] ?? null, $carte['peut_recommencer'] ?? null]);
    }

    public function test_une_transmission_de_la_propriete_garde_la_copie_et_le_renouvellement_du_nouveau_proprietaire_coupe_l_ancien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $ancien = $this->proprietaireDe($magasin);
        app(DemanderUneDemonstrationAction::class)->handle($ancien, null, null);
        app(TenantContext::class)->clear();
        $administrateur = $this->administrateurPretAReprendre($magasin, 'repreneur@exemple-de-test.fr');
        app(TenantContext::class)->clear();
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $this->demanderLaTransmission($ancien, $administrateur), self::MOT_DE_PASSE_CHOISI);
        $nouveau = User::query()->findOrFail($administrateur->getKey());
        $societe = Societe::query()->findOrFail($magasin->societe_id);
        app(TenantContext::class)->clear();

        $carte = app(CarteDeLaDemonstration::class)->pour($nouveau, $societe);
        $this->assertSame(CarteDeLaDemonstration::PRETE, $carte['etat'] ?? null);
        $this->assertContains(CarteDeLaDemonstration::PHRASE_ANCIEN_PROPRIETAIRE, $carte['notes'] ?? []);
        $this->assertNull(app(CarteDeLaDemonstration::class)->pour($ancien->fresh(), $societe));

        app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($nouveau, self::MOT_DE_PASSE_CHOISI, null);
        app(TenantContext::class)->clear();

        $this->assertNotContains(CarteDeLaDemonstration::PHRASE_ANCIEN_PROPRIETAIRE, app(CarteDeLaDemonstration::class)->pour($nouveau, $societe)['notes'] ?? []);
        $this->assertSame(2, collect($this->messagesRecus())->firstWhere('quoi', 'renouveler_le_lien')['generation'] ?? null);
    }
}
