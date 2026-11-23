<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * N10, groupe concurrence (lot DEMO-COPIE, B.3) : deux demandes de la même
 * société au même instant, chacune dans son processus, sur une vraie base
 * PostgreSQL, donnent une seule démonstration et un seul message « creer »
 * : la seconde attend le verrou de la société, puis trouve la première.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class UneSeuleCopieConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_deux_demandes_au_meme_instant_donnent_une_seule_copie(): void
    {
        self::reglerLeFauxSite();
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $identifiant = $proprietaire->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('demonstrations', 'for update', static function () use ($identifiant): void {
            self::reglerLeFauxSite();
            app(DemanderUneDemonstrationAction::class)->handle(User::query()->findOrFail($identifiant), null, null);
        }, $concurrente);

        $premiere = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente, 'La seconde demande est partie pendant la première.');
        $resultat = $this->attendreLeProcessusConcurrent($concurrente);
        $this->assertTrue($resultat->successful(), 'La seconde demande finit sans erreur : '.$resultat->output().$resultat->errorOutput());
        $this->assertSame([$premiere->demonstration->getKey()], Demonstration::query()->withoutGlobalScopes()->pluck('id')->all(), 'Une seule démonstration pour la société.');
        $this->assertSame(['creer'], MessageVersLaDemonstration::query()->withoutGlobalScopes()->pluck('quoi')->map(fn ($quoi): string => $quoi->value)->all(), 'Un seul message « creer ».');
    }

    /**
     * Un site de démonstration réglé, qui répond « en préparation » sans
     * jamais être joint, et les e-mails retenus, dans ce processus.
     */
    private static function reglerLeFauxSite(): void
    {
        $paire = MessagesSignes::genererUnePaire();
        config([
            'demonstration.url' => 'https://piliqi-demo.test',
            'demonstration.nom_du_site' => 'piliqi-demonstration',
            'demonstration.messages.cle_privee' => $paire['cle_privee'],
            'demonstration.messages.cle_id' => $paire['cle_id'],
        ]);
        Http::fake(['https://piliqi-demo.test/interne/messages' => Http::response(['etat' => 'en_preparation'], 202)]);
        Mail::fake();
    }
}
