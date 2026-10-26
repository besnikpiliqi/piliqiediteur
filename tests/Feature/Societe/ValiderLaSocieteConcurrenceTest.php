<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La saisie du code du courrier pendant une autre opération sur la même
 * société, chacune dans son processus, sur une vraie base PostgreSQL (voir
 * SaisirCodeDeConfirmationAction) : le code sert une seule fois, et la
 * validation met en activité tous les magasins réels actifs, même celui
 * qui se crée pendant qu'elle attend.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ValiderLaSocieteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * La personne inscrite saisit le bon code pendant qu'une autre le
     * saisit aussi, avec son adresse et son mot de passe : la seconde
     * saisie attend le verrou que tient la première, relit le code, déjà
     * servi, et ne fait rien. La société est validée une fois, par la
     * personne inscrite, qui reste propriétaire ; aucun compte n'est créé
     * ni retiré.
     */
    public function test_deux_saisies_simultanees_du_bon_code_ne_valident_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $siren = (string) $societe->siren;
        $code = $this->codeDuCourrier($societe);
        $inscrit = $societe->proprietaire()->sole();
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $siren, $code): void {
            if ($concurrente === null && str_starts_with($requete->sql, 'update "codes_confirmation" set "utilise_le"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($siren, $code): void {
                    app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, 'autre@exemple.fr', 'un-mot-de-passe-sur', 'un-mot-de-passe-sur', '192.0.2.20');
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
        app(TenantContext::class)->clear();

        $proprietaire = app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, (string) $inscrit->email, self::MOT_DE_PASSE_CHOISI, '', '192.0.2.10');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX, $resultat->errorOutput());
        $this->assertTrue($proprietaire->is($inscrit));
        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame([[$inscrit->id, RoleSociete::Proprietaire->value, 'actif']], User::query()->where('societe_id', $societe->id)->orderBy('id')->get()->map(fn (User $compte): array => [$compte->id, $compte->role_societe->value, $compte->statut->value])->all());
        $this->assertSame(1, app(TenantContext::class)->runAsSociete($societe, fn (): int => EntreeAudit::query()->where('action', ActionAuditee::SocieteValidee->value)->count()));
    }

    /**
     * Un magasin se crée dans le processus du test pendant que la société
     * se valide, par la saisie de son code, dans un autre : la validation
     * attend le verrou de la société que tient la création, voit la
     * nouvelle liste de ses magasins et reprend avec elle. Les deux
     * magasins ont leur SIRET réservé et leur période d'activité ouverte :
     * le nouveau vend, et produira ses tickets Z.
     */
    public function test_un_magasin_cree_pendant_la_validation_de_la_societe_est_mis_en_activite(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $siren = (string) $societe->siren;
        $code = $this->codeDuCourrier($societe);
        $email = (string) $societe->proprietaire()->sole()->email;
        $motDePasse = self::MOT_DE_PASSE_CHOISI;
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $siren, $code, $email, $motDePasse): void {
            if ($concurrente === null && str_starts_with($requete->sql, 'insert into "magasins"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($siren, $code, $email, $motDePasse): void {
                    app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, $email, $motDePasse, '', '192.0.2.10');
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
        $this->assertCount(2, $magasins);
        $this->assertSame($magasins, SiretMagasin::query()->orderBy('magasin_id')->pluck('magasin_id')->all());
        $this->assertSame($magasins, PeriodeActivite::query()->whereNull('fin')->orderBy('magasin_id')->pluck('magasin_id')->all());
        $this->assertTrue($nouveau->fresh()->peutVendre());
    }
}
