<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Isolation\ControlesDeLaBase;
use App\Domain\Tenancy\Isolation\ControlesDeLApplication;
use App\Domain\Tenancy\Isolation\ProblemeDIsolation;
use App\Domain\Tenancy\Isolation\ReglesDIsolation;
use App\Domain\Tenancy\Isolation\SchemaDeLaBase;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * N12 (lot DEMO-COPIE, B.1 et B.8) : les démonstrations et leur boîte
 * d'envoi sont des données de la société (BelongsToSociete), que le
 * propriétaire d'une autre société ne voit ni ne touche ; la vérification
 * de l'isolation les contrôle par la base et par l'application ; les
 * commandes du site réel, qui tournent hors de toute société, n'écrivent
 * qu'au journal de la société de chaque ligne.
 */
class DemandesDeLaSocieteTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration();
    }

    public function test_le_proprietaire_d_une_autre_societe_ne_voit_ni_ne_touche_la_demonstration(): void
    {
        [$premier, $second] = $this->deuxProprietairesAvecLeurDemonstration();
        $avant = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $premier->societe_id)->firstOrFail();

        app(TenantContext::class)->clear();
        app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($second, 'password', null);

        $apres = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $premier->societe_id)->firstOrFail();
        $this->assertSame([$avant->empreinte_du_lien, 1], [$apres->empreinte_du_lien, $apres->generation_du_lien]);
        $this->assertSame(1, app(TenantContext::class)->runAsSociete($second->societe, fn (): int => Demonstration::query()->count()));
        $this->assertSame($second->societe_id, app(TenantContext::class)->runAsSociete($second->societe, fn (): ?int => Demonstration::query()->value('societe_id')));
        $this->assertNull(app(CarteDeLaDemonstration::class)->pour($second, $premier->societe));
        $this->assertStringNotContainsString($avant->repere, (string) json_encode(app(CarteDeLaDemonstration::class)->pour($second, $second->societe)));
    }

    public function test_les_deux_tables_sont_des_donnees_de_la_societe_controlees_par_l_application(): void
    {
        $this->assertSame([ReglesDIsolation::SOCIETE, ReglesDIsolation::SOCIETE], [ReglesDIsolation::porteeDe('demonstrations'), ReglesDIsolation::porteeDe('messages_vers_la_demonstration')]);
        $controles = new ControlesDeLApplication(DB::connection(), app(TenantContext::class));
        $this->assertSame([], $controles->porteeDesModeles([Demonstration::class, MessageVersLaDemonstration::class])->problemes());

        $sansScope = new class extends Model
        {
            protected $table = 'demonstrations';
        };
        $this->assertSame([['Laravel', 'demonstrations', $sansScope::class, 'un modèle sans le scope de sa portée']], $this->problemesDe($controles->porteeDesModeles([$sansScope::class])->problemes()));
    }

    public function test_une_demande_dont_le_compte_est_d_une_autre_societe_est_signalee_par_la_base(): void
    {
        [$premier, $second] = $this->deuxProprietairesAvecLeurDemonstration();
        $demonstration = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $premier->societe_id)->firstOrFail();
        $this->remplacerLaCleComposee('demonstrations', 'demandee_par', 'users');
        DB::table('demonstrations')->where('id', $demonstration->getKey())->update(['demandee_par' => $second->getKey()]);

        $problemes = $this->problemesDe($this->controlesDeLaBase()->comptesCites()->problemes());

        $this->assertContains(['SQL', 'demonstrations', (string) $demonstration->getKey(), 'un compte de la société n° '.$second->societe_id], $problemes);
    }

    public function test_un_message_dont_la_demande_est_d_une_autre_societe_est_signale_par_la_base(): void
    {
        [$premier, $second] = $this->deuxProprietairesAvecLeurDemonstration();
        $deLAutre = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $second->societe_id)->firstOrFail();
        $this->remplacerLaCleComposee('messages_vers_la_demonstration', 'demonstration_id', 'demonstrations');
        $identifiant = DB::table('messages_vers_la_demonstration')->insertGetId(['societe_id' => $premier->societe_id, 'demonstration_id' => $deLAutre->getKey(), 'quoi' => QuoiDuMessage::Etat->value, 'contenu' => '{}', 'cree_le' => CarbonImmutable::now()]);

        $problemes = $this->problemesDe($this->controlesDeLaBase()->referencesEntreLignes()->problemes());

        $this->assertContains(['SQL', 'messages_vers_la_demonstration', (string) $identifiant, 'société n° '.$second->societe_id.' (ligne de demonstrations)'], $problemes);
    }

    public function test_la_tache_des_fins_n_ecrit_qu_au_journal_de_la_societe_de_chaque_ligne(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        [$premier, $second] = $this->deuxProprietairesAvecLeurDemonstration();
        $this->travelTo(self::aParis('2026-12-17 10:00:00'));

        $this->artisan('demonstration:marquer-les-fins')->assertSuccessful();

        foreach ([$premier, $second] as $proprietaire) {
            $demonstration = Demonstration::query()->withoutGlobalScopes()->where('societe_id', $proprietaire->societe_id)->firstOrFail();
            $entrees = DB::table('journal_audit')->where('action', ActionAuditee::DemonstrationTerminee->value)->where('cible_id', $demonstration->getKey())->get(['societe_id']);
            $this->assertSame([$proprietaire->societe_id], $entrees->pluck('societe_id')->map(fn (mixed $id): int => (int) $id)->all());
        }
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function deuxProprietairesAvecLeurDemonstration(): array
    {
        $premier = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Premier']));
        $second = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Second']));

        foreach ([$premier, $second] as $proprietaire) {
            app(TenantContext::class)->clear();
            app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
        }

        app(TenantContext::class)->clear();

        return [$premier, $second];
    }

    /**
     * Une faute de schéma, dans la transaction du test : la clé composée
     * (societe_id, colonne) devient une clé simple vers la table visée,
     * comme une migration qui l'aurait oubliée ; seule une faute introduite
     * ainsi montre que la vérification trouve ce que la clé empêche.
     */
    private function remplacerLaCleComposee(string $table, string $colonne, string $visee): void
    {
        $nom = DB::scalar("select c.conname from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey) where c.conrelid = ?::regclass and c.contype = 'f' and a.attname = ? and array_length(c.conkey, 1) = 2", [$table, $colonne]);
        DB::statement('alter table '.$table.' drop constraint "'.$nom.'"');
        DB::statement('alter table '.$table.' add foreign key ('.$colonne.') references '.$visee.' (id)');
    }

    private function controlesDeLaBase(): ControlesDeLaBase
    {
        return new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection()));
    }

    /**
     * @param  list<ProblemeDIsolation>  $problemes
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function problemesDe(array $problemes): array
    {
        return array_map(static fn (ProblemeDIsolation $probleme): array => [$probleme->couche, $probleme->table, $probleme->identifiant, $probleme->trouve], $problemes);
    }
}
