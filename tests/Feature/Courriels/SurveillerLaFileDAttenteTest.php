<?php

namespace Tests\Feature\Courriels;

use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * La surveillance de la file d'attente (voir SurveillerLaFileDAttente) :
 * des tâches que personne ne prend, ou laissées réservées par un
 * travailleur arrêté, s'écrivent au journal au niveau critique et
 * préviennent l'éditeur, une fois par incident.
 */
class SurveillerLaFileDAttenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.email' => 'alertes@editeur.test', 'queue.connections.database.retry_after' => 90]);
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
    }

    /**
     * Une tâche réservée depuis plus que le délai de reprise (le
     * travailleur qui l'avait prise s'est arrêté), que Laravel ne compte
     * plus parmi les tâches en attente, signale l'incident.
     */
    public function test_une_tache_reservee_au_dela_du_delai_de_reprise_previent_l_editeur(): void
    {
        Mail::fake();
        Log::spy();
        $this->tache(disponibleIlYA: 200, reserveeIlYA: 100);

        $this->artisan('app:surveiller-la-file-d-attente')
            ->expectsOutputToContain('File « default » : 0 tâche(s) disponible(s) depuis plus de 2 minutes, que personne ne prend, et 1 tâche(s) réservée(s) depuis plus de 90 secondes')
            ->assertFailed();

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_starts_with($message, 'La file d\'attente ne part plus : File « default »'))->once();
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $alerte): bool => $alerte->hasTo('alertes@editeur.test') && $alerte->objet === 'La file d\'attente ne part plus');
    }

    /**
     * Une tâche disponible depuis 30 secondes, ou réservée depuis moins
     * que le délai de reprise, n'est pas un incident.
     */
    public function test_une_tache_recente_ne_declenche_rien(): void
    {
        Mail::fake();
        Log::spy();
        $this->tache(disponibleIlYA: 30);
        $this->tache(disponibleIlYA: 200, reserveeIlYA: 60);

        $this->artisan('app:surveiller-la-file-d-attente')
            ->expectsOutput('La file d\'attente part normalement.')
            ->assertSuccessful();

        Log::shouldNotHaveReceived('critical');
        Mail::assertNothingSent();
    }

    /**
     * Tant que l'incident dure, chaque passage l'écrit au journal, mais
     * l'éditeur n'est prévenu qu'une fois ; une fois la file repartie, un
     * nouvel incident le prévient de nouveau.
     */
    public function test_l_editeur_n_est_prevenu_qu_une_fois_par_incident(): void
    {
        Mail::fake();
        $this->tache(disponibleIlYA: 180);

        $this->artisan('app:surveiller-la-file-d-attente')->assertFailed();
        $this->travel(5)->minutes();
        $this->artisan('app:surveiller-la-file-d-attente')->assertFailed();
        Mail::assertSentCount(1);

        DB::table('jobs')->delete();
        $this->artisan('app:surveiller-la-file-d-attente')->assertSuccessful();
        $this->tache(disponibleIlYA: 180);
        $this->artisan('app:surveiller-la-file-d-attente')->assertFailed();

        Mail::assertSentCount(2);
    }

    /**
     * Le serveur d'envoi en panne n'empêche pas la surveillance : l'alerte
     * ne part pas par e-mail, et le journal le dit au niveau critique.
     */
    public function test_une_alerte_qui_ne_part_pas_par_e_mail_s_ecrit_au_journal(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection to "sandbox.smtp.mailtrap.io:2525" timed out.'));
        $this->tache(disponibleIlYA: 180);

        $this->artisan('app:surveiller-la-file-d-attente')->assertFailed();

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => $message === 'L\'alerte de l\'éditeur « La file d\'attente ne part plus » n\'est pas partie par e-mail : le serveur d\'envoi ne répond pas.')->once();
    }

    private function tache(int $disponibleIlYA, ?int $reserveeIlYA = null): void
    {
        $maintenant = now()->getTimestamp();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => $reserveeIlYA === null ? 0 : 1,
            'reserved_at' => $reserveeIlYA === null ? null : $maintenant - $reserveeIlYA,
            'available_at' => $maintenant - $disponibleIlYA,
            'created_at' => $maintenant - $disponibleIlYA,
        ]);
    }
}
