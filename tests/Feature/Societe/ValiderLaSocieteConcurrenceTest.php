<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CreerEtablissementAction;
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
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Database\Factories\MagasinFactory;
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
     * Un magasin se crée dans le processus du test pendant que la société,
     * validable, se valide dans un autre : la validation attend le verrou
     * de la société que tient la création, puis reprend avec la nouvelle
     * liste de ses magasins. Les deux magasins ont leur SIRET réservé et
     * leur période d'activité ouverte : le nouveau vend, et produira ses
     * tickets Z.
     */
    public function test_un_magasin_cree_pendant_la_validation_de_la_societe_est_mis_en_activite(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $editeur = new InterventionDeLEditeur('Doute levé après examen du dossier', 'DOSSIER-2026-0003', ['alice']);
        app(LeverUnSignalAction::class)->signalerUnDoute($societe, $editeur);
        $this->verifierLIdentiteDuProprietaire($societe);
        app(RegistreDesVerifications::class)->noter($societe, TypeVerification::Manuelle, ResultatVerification::Concluante, ['siren' => $societe->siren, 'decision' => 'signal_leve', 'justification' => $editeur->justification], signal: SignalDeRisque::DecisionEditeur, editeur: $editeur);
        $this->assertTrue($this->evaluer($societe)->estValidable());
        $societeId = $societe->id;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $societeId): void {
            if ($concurrente === null && str_starts_with($requete->sql, 'insert into "magasins"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($societeId): void {
                    app(ValiderLaSocieteAction::class)->siPossible(Societe::query()->findOrFail($societeId));
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
        app(TenantContext::class)->clear();

        $nouveau = app(CreerEtablissementAction::class)->handle($societe->proprietaire, $societe, ['nom' => 'Nouveau magasin', 'nic' => MagasinFactory::nicLibre($societe)]);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue($societe->fresh()->estValidee());
        $magasins = $societe->magasinsReels()->orderBy('id')->pluck('id')->all();
        $this->assertSame($magasins, SiretMagasin::query()->orderBy('magasin_id')->pluck('magasin_id')->all());
        $this->assertSame($magasins, PeriodeActivite::query()->whereNull('fin')->orderBy('magasin_id')->pluck('magasin_id')->all());
        $this->assertTrue($nouveau->fresh()->peutVendre());
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
                app(RegistreDesVerifications::class)->noter($societe, TypeVerification::Manuelle, ResultatVerification::Concluante, ['siren' => $societe->siren, 'decision' => 'signal_leve', 'justification' => $editeur->justification], signal: $signal, editeur: $editeur);
            }
        }
    }

    private function evaluer(Societe $societe): EtatDeVerification
    {
        return app(EvaluateurDeVerification::class)->evaluer($societe->fresh());
    }
}
