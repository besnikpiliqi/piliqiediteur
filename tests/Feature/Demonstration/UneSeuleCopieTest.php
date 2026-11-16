<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * N10 (lot DEMO-COPIE, B.1, B.3 et B.13) : une seule copie à la fois ; une
 * demande répétée après une réponse perdue ne rend jamais le lien ; une
 * démonstration en échec n'empêche, après son troisième échec, aucune
 * nouvelle demande ; au plus 3 créations par heure et par adresse IP.
 */
class UneSeuleCopieTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration(202, ['etat' => 'en_preparation']);
    }

    public function test_une_seconde_demande_montre_la_copie_en_cours_sans_second_message(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $premiere = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        app(TenantContext::class)->clear();

        $seconde = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertSame($premiere->demonstration->getKey(), $seconde->demonstration->getKey());
        $this->assertNull($seconde->lien);
        $this->assertSame(['creer'], array_column($this->messagesRecus(), 'quoi'));
    }

    public function test_le_meme_jeton_apres_une_reponse_perdue_rend_la_meme_copie_sans_le_lien(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $jeton = (string) Str::uuid();
        $premiere = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, $jeton, null);
        app(TenantContext::class)->clear();

        $repetee = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, $jeton, null);

        $this->assertNotNull($premiere->lien);
        $this->assertSame($premiere->demonstration->getKey(), $repetee->demonstration->getKey());
        $this->assertNull($repetee->lien);
        $this->assertSame(1, Demonstration::query()->withoutGlobalScopes()->count());
        Mail::assertSentCount(1);
    }

    public function test_la_base_refuse_une_seconde_demonstration_en_cours_pour_la_meme_societe(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null)->demonstration;
        $ligne = (array) DB::table('demonstrations')->where('id', $demonstration->getKey())->first();
        unset($ligne['id']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('demonstrations')->insert([...$ligne, 'uuid' => (string) Str::uuid(), 'reference' => str_repeat('a', 32)]);
    }

    public function test_apres_trois_echecs_a_une_heure_d_ecart_la_copie_se_termine_et_une_nouvelle_demande_est_permise(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        config(['editeur.email' => 'editeur@piliqi.test']);
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $this->leSiteDeDemonstrationRepond(202, ['etat' => 'en_echec', 'cause' => 'Le modèle est occupé.']);
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null)->demonstration;

        foreach (['10:30:00', '11:00:00', '12:00:00'] as $heure) {
            $this->travelTo(self::aParis('2026-11-16 '.$heure));
            $this->artisan('demonstration:relancer-les-messages')->assertSuccessful();
        }

        $relue = Demonstration::query()->withoutGlobalScopes()->findOrFail($demonstration->getKey());
        $this->assertSame([EtatDeLaDemonstration::Terminee, CauseDeLaFin::Echec, 3], [$relue->etat, $relue->cause_de_la_fin, $relue->essais_en_echec]);
        Mail::assertSent(AlerteEditeurMail::class);
        app(TenantContext::class)->clear();
        $carte = app(CarteDeLaDemonstration::class)->pour($proprietaire, $proprietaire->societe);
        $this->assertSame(CarteDeLaDemonstration::A_CREER, $carte['etat'] ?? null);
        $this->assertContains('Votre démonstration n\'a pas pu être créée le 16/11/2026. Vous pouvez la redemander.', $carte['notes'] ?? []);

        $this->leSiteDeDemonstrationRepond(202, ['etat' => 'en_preparation']);
        $nouvelle = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        $this->assertNotSame($demonstration->getKey(), $nouvelle->demonstration->getKey());
    }

    public function test_au_plus_trois_creations_par_heure_depuis_la_meme_adresse_ip_toutes_societes_confondues(): void
    {
        for ($i = 1; $i <= DemanderUneDemonstrationAction::CREATIONS_PAR_HEURE; $i++) {
            app(DemanderUneDemonstrationAction::class)->handle($this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Société '.$i])), null, '203.0.113.7');
            app(TenantContext::class)->clear();
        }

        $quatrieme = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Société 4']));
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(DemanderUneDemonstrationAction::class)->handle($quatrieme, null, '203.0.113.7'), LogicException::class, DemanderUneDemonstrationAction::MESSAGE_TROP_DE_CREATIONS);
        app(TenantContext::class)->clear();
        $this->assertNotNull(app(DemanderUneDemonstrationAction::class)->handle($quatrieme, null, '198.51.100.9')->lien);
    }
}
