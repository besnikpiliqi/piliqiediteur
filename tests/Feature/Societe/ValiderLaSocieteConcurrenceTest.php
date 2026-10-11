<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\LeverUnSignalAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ValiderLaSocieteAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EtatDeVerification;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deux sociétés du même SIREN, toutes deux validables au même instant,
 * validées chacune dans son processus, sur une vraie base PostgreSQL
 * (voir ValiderLaSocieteAction) : la seconde attend, sur l'index unique
 * des SIREN validés, la transaction de la première, puis échoue. Une seule
 * est validée ; l'autre, qui a prouvé sa qualité, devient une réclamation
 * que l'éditeur examine, sans rien réserver ni rien ouvrir.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ValiderLaSocieteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_validations_simultanees_du_meme_siren_n_en_retiennent_qu_une_et_l_autre_devient_une_reclamation(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $siren = SocieteFactory::sirenFictif();
        $premiere = $this->inscrireUneSociete($siren);
        $seconde = $this->inscrireUneSociete($siren);
        $this->rendreValidable($premiere, $seconde);
        $this->assertSame([true, true], [$this->evaluer($premiere)->estValidable(), $this->evaluer($seconde)->estValidable()]);
        $secondeId = $seconde->id;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $secondeId): void {
            if ($concurrente === null && str_starts_with($requete->sql, 'update "societes" set "validee_le"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($secondeId): void {
                    app(ValiderLaSocieteAction::class)->siPossible(Societe::query()->findOrFail($secondeId));
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        $this->assertTrue(app(ValiderLaSocieteAction::class)->siPossible($premiere));

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame([true, false], [$premiere->fresh()->estValidee(), $seconde->fresh()->estValidee()]);
        $this->assertTrue($this->evaluer($seconde)->reclamationPossible());
        $magasinDeLaPremiere = $premiere->magasinsReels()->sole()->id;
        $this->assertSame([$magasinDeLaPremiere], SiretMagasin::query()->pluck('magasin_id')->all());
        $this->assertSame([$magasinDeLaPremiere], PeriodeActivite::query()->pluck('magasin_id')->all());
        $this->assertNull($seconde->proprietaire->fresh()->qualite_verifiee_le);
    }

    /**
     * Chaque société prouve la qualité de sa propriétaire et reçoit le
     * code du courrier, sous le doute de l'éditeur qui la retient ; puis
     * l'éditeur lève, chez chacune, son doute et le signal des inscriptions
     * concurrentes : les lignes de ses deux levées s'inscrivent sans la
     * validation qui les suit, comme si elles arrivaient au même instant.
     */
    private function rendreValidable(Societe ...$societes): void
    {
        $editeur = new InterventionDeLEditeur('Examen des deux inscriptions du même SIREN', 'DOSSIER-2026-0002', ['alice']);

        foreach ($societes as $societe) {
            app(LeverUnSignalAction::class)->signalerUnDoute($societe, $editeur);
            $this->verifierLIdentiteDuProprietaire($societe);
            app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, editeur: $editeur);
            $this->assertTrue(app(SaisirCodeDeConfirmationAction::class)->handle($societe->proprietaire, $societe, (string) app(ServiceEnvoiRecommande::class)->codeDuDernierPli()));
        }

        foreach ($societes as $societe) {
            foreach ([SignalDeRisque::DecisionEditeur, SignalDeRisque::InscriptionsConcurrentes] as $signal) {
                app(RegistreDesVerifications::class)->noter($societe, TypeVerification::Manuelle, ResultatVerification::Concluante, ['decision' => 'signal_leve'], signal: $signal, editeur: $editeur);
            }
        }
    }

    private function evaluer(Societe $societe): EtatDeVerification
    {
        return app(EvaluateurDeVerification::class)->evaluer($societe->fresh());
    }
}
