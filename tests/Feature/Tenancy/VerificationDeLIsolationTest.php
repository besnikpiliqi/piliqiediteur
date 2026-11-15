<?php

namespace Tests\Feature\Tenancy;

use App\Console\Commands\VerifierLIsolation;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Isolation\ControlesDeLaBase;
use App\Domain\Tenancy\Isolation\ControlesDeLApplication;
use App\Domain\Tenancy\Isolation\ProblemeDIsolation;
use App\Domain\Tenancy\Isolation\RapportDIsolation;
use App\Domain\Tenancy\Isolation\SchemaDeLaBase;
use App\Domain\Tenancy\Isolation\VerificationDeLIsolation;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La vérification de l'isolation de toute la base (protocole de rigueur,
 * point 6, demande de l'éditeur du 15/11/2026) : juste sur des données
 * justes, elle trouve chaque faute introduite volontairement, par la base
 * (SQL) comme par l'application (Laravel), n'écrit rien et ne montre aucune
 * donnée personnelle.
 */
class VerificationDeLIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deux_societes_justes_ne_donnent_aucun_probleme_et_le_code_0(): void
    {
        $this->deuxSocietes();

        $code = Artisan::call('app:verifier-isolation');
        $sortie = Artisan::output();

        $this->assertSame(0, $code, $sortie);
        $this->assertStringContainsString('Isolation vérifiée : aucun problème', $sortie);
    }

    public function test_la_commande_n_ecrit_rien_et_ne_montre_aucune_donnee_personnelle(): void
    {
        [$premier] = $this->deuxSocietes();
        $ecritures = [];
        DB::listen(function ($requete) use (&$ecritures): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $requete->sql) === 1) {
                $ecritures[] = $requete->sql;
            }
        });
        DB::statement('alter table paiements disable trigger paiements_ajout_seul_update');

        Artisan::call('app:verifier-isolation');
        $sortie = Artisan::output();

        $this->assertSame(['alter table paiements disable trigger paiements_ajout_seul_update'], $ecritures);
        $this->assertStringContainsString('un déclencheur désactivé', $sortie);
        $proprietaire = $premier->societe->proprietaire()->firstOrFail();
        foreach ([(string) $proprietaire->name, (string) $proprietaire->email, (string) $proprietaire->identifiantDeConnexion(), (string) $premier->nom, (string) $premier->societe->raison_sociale] as $donneePersonnelle) {
            $this->assertStringNotContainsString($donneePersonnelle, $sortie);
        }
    }

    public function test_un_declencheur_retire_ou_desactive_est_signale_par_la_base(): void
    {
        DB::statement('drop trigger mouvements_stock_justifies on mouvements_stock');
        DB::statement('alter table factures disable trigger garde_vente_factures');

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->declencheurs()->problemes());

        $this->assertContains(['SQL', 'mouvements_stock', 'mouvements_stock_justifies', 'aucun déclencheur'], $problemes);
        $this->assertContains(['SQL', 'factures', 'garde_vente_factures', 'un déclencheur désactivé'], $problemes);
    }

    public function test_une_fonction_de_garde_sans_chemin_de_recherche_fixe_est_signalee(): void
    {
        DB::statement('alter function garde_vente_factures() reset search_path');

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->declencheurs()->problemes());

        $this->assertContains(['SQL', 'factures', 'garde_vente_factures', 'aucun search_path fixé'], $problemes);
    }

    public function test_une_cle_simple_entre_deux_tables_d_un_magasin_est_signalee(): void
    {
        DB::statement('alter table lignes_facture drop constraint lignes_facture_magasin_id_facture_id_foreign');
        DB::statement('alter table lignes_facture add constraint lignes_facture_facture_simple foreign key (facture_id) references factures (id)');

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->clesComposees()->problemes());

        $this->assertContains(['SQL', 'lignes_facture', 'lignes_facture_facture_simple', '(facture_id) vers factures (id)'], $problemes);
    }

    public function test_une_table_sans_regle_d_isolation_est_signalee(): void
    {
        DB::statement('create table essai_sans_regle (id bigint primary key, magasin_id bigint)');

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->tablesConnues()->problemes());

        $this->assertContains(['SQL', 'essai_sans_regle', '—', 'aucune règle'], $problemes);
    }

    public function test_une_reference_vers_une_ligne_d_une_autre_societe_est_signalee_par_la_base(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        $entree = (int) DB::table('journal_audit')->where('societe_id', $second->societe_id)->min('id');
        DB::table('traces_audit')->insert(['societe_id' => $premier->societe_id, 'entree_audit_id' => $entree, 'ip' => '127.0.0.1', 'agent_utilisateur' => 'essai', 'expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::year()), 'created_at' => now()]);

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->referencesEntreLignes()->problemes());

        $this->assertContains(['SQL', 'traces_audit', 'entree_audit_id='.$entree.', societe_id='.$premier->societe_id, 'société n° '.$second->societe_id.' (ligne de journal_audit)'], $problemes);
    }

    public function test_un_compte_cite_d_une_autre_societe_est_signale_par_la_base(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        $intrus = $second->societe->proprietaire()->firstOrFail();
        $client = app(TenantContext::class)->runAs($premier, fn (): Client => Client::factory()->create());
        DB::table('consultations_vies')->insert(['magasin_id' => $premier->id, 'client_id' => $client->id, 'numero_tva' => 'FR00000000000', 'valide' => true, 'consultee_le' => now(), 'consultee_par' => $intrus->id, 'reponse' => '{}']);

        $problemes = $this->problemesDe((new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection())))->comptesCites()->problemes());

        $this->assertContains(['SQL', 'consultations_vies', (string) DB::table('consultations_vies')->max('id'), 'un compte de la société n° '.$second->societe_id], $problemes);
    }

    public function test_un_modele_d_une_table_d_un_magasin_sans_son_scope_est_signale_par_laravel(): void
    {
        $sansScope = new class extends Model
        {
            protected $table = 'clients';
        };

        $problemes = $this->problemesDe((new ControlesDeLApplication(DB::connection(), app(TenantContext::class)))->porteeDesModeles([$sansScope::class])->problemes());

        $this->assertSame([['Laravel', 'clients', $sansScope::class, 'un modèle sans le scope de sa portée']], $problemes);
    }

    public function test_un_compte_qui_verrait_les_clients_d_un_autre_magasin_est_une_fuite_signalee(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        app(TenantContext::class)->runAs($second, fn (): Client => Client::factory()->create());
        $contexteFautif = new class($second) extends TenantContext
        {
            public function __construct(private readonly Magasin $autre) {}

            public function runAs(Magasin $magasin, Closure $callback): mixed
            {
                return parent::runAs($this->autre, $callback);
            }
        };

        app()->instance(TenantContext::class, $contexteFautif);

        $rapport = new RapportDIsolation([(new ControlesDeLApplication(DB::connection(), $contexteFautif))->vueDeChaqueCompte()]);
        $fuites = array_filter($rapport->problemes(), fn (ProblemeDIsolation $probleme): bool => $probleme->table === 'clients' && str_starts_with($probleme->identifiant, 'compte n° '.$premier->societe->proprietaire()->firstOrFail()->id.', magasin n° '.$premier->id));

        $this->assertNotSame([], $fuites);
        $this->assertSame('Laravel et SQL', array_values($fuites)[0]->couche);
        $this->assertStringContainsString('désaccord entre Laravel et la base', array_values($fuites)[0]->trouve);
        $this->assertFalse($rapport->estJuste());
    }

    public function test_un_magasin_d_une_autre_societe_dans_une_ligne_d_une_societe_est_signale_par_la_base(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        DB::statement('alter table ecarts_sirene drop constraint ecarts_sirene_societe_id_magasin_id_foreign');
        $ecart = (int) DB::table('ecarts_sirene')->insertGetId(['societe_id' => $premier->societe_id, 'magasin_id' => $second->id, 'motif' => 'essai', 'negatif' => false, 'signale_le' => now()]);

        $problemes = $this->problemesDe($this->controlesDeLaBase()->magasinsDansLeurSociete()->problemes());

        $this->assertSame([['SQL', 'ecarts_sirene', (string) $ecart, 'un magasin de la société n° '.$second->societe_id]], $problemes);
    }

    public function test_une_vente_au_nom_du_proprietaire_dans_un_magasin_reel_est_signalee_par_la_base(): void
    {
        [$premier] = $this->deuxSocietes();
        $ticket = $this->ticketEmis($premier);
        $this->assertSame([], $this->controlesDeLaBase()->ventesEtEncaissements()->problemes());
        $this->sansSesDeclencheurs('factures', fn () => DB::table('factures')->where('id', $ticket->id)->update(['cree_par' => $premier->societe->proprietaire()->firstOrFail()->id]));

        $problemes = $this->problemesDe($this->controlesDeLaBase()->ventesEtEncaissements()->problemes());

        $this->assertSame([['SQL', 'factures', (string) $ticket->id, 'un compte de rôle « proprietaire »']], $problemes);
    }

    public function test_un_maillon_du_registre_qui_ne_suit_pas_le_precedent_de_son_magasin_est_signale_par_la_base(): void
    {
        [$premier] = $this->deuxSocietes();
        $this->ticketEmis($premier);
        $this->ticketEmis($premier);
        $this->assertSame([], $this->controlesDeLaBase()->chaines()->problemes());
        $dernier = DB::table('enregistrements_fiscaux')->where('magasin_id', $premier->id)->orderByDesc('sequence')->firstOrFail(['id', 'chaine']);
        $this->sansSesDeclencheurs('enregistrements_fiscaux', fn () => DB::table('enregistrements_fiscaux')->where('id', $dernier->id)->update(['signature_precedente' => 'une signature qui n\'est pas celle du maillon précédent']));

        $problemes = $this->problemesDe($this->controlesDeLaBase()->chaines()->problemes());

        $this->assertSame([['SQL', 'enregistrements_fiscaux', (string) $dernier->id, 'aucun maillon précédent de ce magasin']], $problemes);
    }

    public function test_un_enregistrement_fiscal_qui_designe_un_document_d_une_autre_societe_est_signale_par_la_base(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        $ticket = $this->ticketEmis($premier);
        $autre = $this->ticketEmis($second);
        $this->assertSame([], $this->controlesDeLaBase()->referencesPolymorphes()->problemes());
        $enregistrement = (int) DB::table('enregistrements_fiscaux')->where('document_type', $ticket->getMorphClass())->where('document_id', $ticket->id)->value('id');
        $this->sansSesDeclencheurs('enregistrements_fiscaux', fn () => DB::table('enregistrements_fiscaux')->where('id', $enregistrement)->update(['document_id' => $autre->id]));

        $problemes = $this->problemesDe($this->controlesDeLaBase()->referencesPolymorphes()->problemes());

        $this->assertSame([['SQL', 'enregistrements_fiscaux', (string) $enregistrement, 'une ligne de la société n° '.$second->societe_id]], $problemes);
    }

    public function test_une_notification_qui_parle_d_une_autre_societe_est_signalee_par_la_base(): void
    {
        [$premier, $second] = $this->deuxSocietes();
        $proprietaire = $premier->societe->proprietaire()->firstOrFail();
        $notification = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $notification, 'type' => 'essai', 'notifiable_type' => $proprietaire->getMorphClass(), 'notifiable_id' => $proprietaire->id, 'data' => json_encode(['societe_id' => $second->societe_id, 'titre' => 'essai'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        $problemes = $this->problemesDe($this->controlesDeLaBase()->referencesPolymorphes()->problemes());

        $this->assertSame([['SQL', 'notifications', $notification, 'la société n° '.$second->societe_id.' (ou un magasin d\'une autre société)']], $problemes);
    }

    /**
     * Chaque nuit à 4 h 40, heure de Paris, en développement comme en
     * production, sur un seul serveur, jamais deux passages à la fois, en
     * prévenant l'éditeur.
     */
    public function test_la_verification_est_planifiee_chaque_nuit_a_4_h_40_heure_de_paris_sur_un_seul_serveur(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $taches = app(Schedule::class)->events();
        $verification = array_values(array_filter($taches, static fn (Event $tache): bool => str_contains((string) $tache->command, 'app:verifier-isolation')));

        $this->assertCount(1, $verification);
        $this->assertSame('40 4 * * *', $verification[0]->expression);
        $this->assertSame(Calendrier::deParis()->fuseau(), $verification[0]->timezone);
        $this->assertTrue($verification[0]->onOneServer);
        $this->assertTrue($verification[0]->withoutOverlapping);
        $this->assertStringContainsString('--prevenir-l-editeur', (string) $verification[0]->command);
    }

    public function test_une_nuit_juste_ne_previent_personne_et_retient_sa_reussite(): void
    {
        Mail::fake();
        config(['editeur.email' => 'alertes@editeur.test']);
        $this->travelTo(self::aParis('2026-11-15 04:40:00'));
        $this->deuxSocietes();
        Log::spy();

        $this->assertSame(0, Artisan::call('app:verifier-isolation', ['--prevenir-l-editeur' => true]));

        Mail::assertNothingSent();
        $this->assertSame(self::aParis('2026-11-15 04:40:00')->getTimestamp(), Cache::get(VerifierLIsolation::CLE_DE_LA_DERNIERE_REUSSITE));
        Log::shouldHaveReceived('info')->withArgs(static fn (string $message): bool => (bool) preg_match('/^Vérification de l\'isolation : \d+ lignes vérifiées en \d+,\d s, 0 problème\(s\)\.$/u', $message))->once();
    }

    public function test_un_probleme_previent_l_editeur_une_seule_fois_par_nuit_sans_aucune_donnee_personnelle(): void
    {
        Mail::fake();
        config(['editeur.email' => 'alertes@editeur.test']);
        $this->travelTo(self::aParis('2026-11-15 04:40:00'));
        [$premier] = $this->deuxSocietes();
        DB::statement('create table essai_sans_regle (id bigint primary key)');
        Log::spy();

        $this->assertSame(1, Artisan::call('app:verifier-isolation', ['--prevenir-l-editeur' => true]));
        $this->assertSame(1, Artisan::call('app:verifier-isolation', ['--prevenir-l-editeur' => true]));

        Mail::assertSentCount(1);
        Mail::assertSent(AlerteEditeurMail::class, function (AlerteEditeurMail $alerte) use ($premier): bool {
            $texte = implode("\n", $alerte->lignes);
            $proprietaire = $premier->societe->proprietaire()->firstOrFail();

            foreach ([(string) $proprietaire->name, (string) $proprietaire->email, (string) $proprietaire->identifiantDeConnexion(), (string) $premier->nom, (string) $premier->societe->raison_sociale, (string) $premier->societe->siren] as $donneePersonnelle) {
                $this->assertStringNotContainsString($donneePersonnelle, $texte);
            }

            return $alerte->hasTo('alertes@editeur.test')
                && $alerte->objet === VerifierLIsolation::OBJET_DES_PROBLEMES
                && str_contains($texte, 'a trouvé 1 problème(s)')
                && str_contains($texte, 'Tables connues : 1 problème(s).')
                && str_contains($texte, 'table essai_sans_regle, ligne —, colonne — ; attendu : une règle d\'isolation ; trouvé : aucune règle.');
        });
        $this->assertNull(Cache::get(VerifierLIsolation::CLE_DE_LA_DERNIERE_REUSSITE));
        Log::shouldHaveReceived('critical')->withArgs(static fn (string $message): bool => $message === 'Isolation : 1 problème(s). Tables connues : 1 problème(s).')->twice();

        $this->travelTo(self::aParis('2026-11-16 04:40:00'));
        $this->assertSame(1, Artisan::call('app:verifier-isolation', ['--prevenir-l-editeur' => true]));
        Mail::assertSentCount(2);
    }

    public function test_une_verification_qui_ne_finit_pas_previent_l_editeur(): void
    {
        Mail::fake();
        config(['editeur.email' => 'alertes@editeur.test']);
        $this->travelTo(self::aParis('2026-11-15 04:40:00'));
        Cache::forever(VerifierLIsolation::CLE_DE_LA_DERNIERE_REUSSITE, self::aParis('2026-11-14 04:41:00')->getTimestamp());
        DB::statement('alter table liens_cabinet rename to liens_cabinet_disparus');

        $this->assertSame(1, Artisan::call('app:verifier-isolation', ['--prevenir-l-editeur' => true]));

        Mail::assertSent(AlerteEditeurMail::class, static fn (AlerteEditeurMail $alerte): bool => $alerte->objet === 'Contrôle de l\'isolation non terminé'
            && str_contains(implode(' ', $alerte->lignes), 's\'est arrêtée avant la fin (QueryException) : l\'isolation des sociétés et des magasins n\'a pas été contrôlée cette nuit.'));
        $this->assertSame(self::aParis('2026-11-14 04:41:00')->getTimestamp(), Cache::get(VerifierLIsolation::CLE_DE_LA_DERNIERE_REUSSITE));
    }

    public function test_la_verification_entiere_sort_avec_le_code_1_au_premier_probleme(): void
    {
        $this->deuxSocietes();
        DB::statement('create table essai_sans_regle (id bigint primary key)');

        $code = Artisan::call('app:verifier-isolation');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Isolation : 1 problème(s).', Artisan::output());
        $this->assertFalse(app(VerificationDeLIsolation::class)->verifier()->estJuste());
    }

    /**
     * Les contrôles de la base, sur la connexion des tests.
     */
    private function controlesDeLaBase(): ControlesDeLaBase
    {
        return new ControlesDeLaBase(DB::connection(), new SchemaDeLaBase(DB::connection()));
    }

    /**
     * Un ticket émis dans ce magasin par sa vraie action, au nom du
     * responsable de test du magasin (qui vend).
     */
    private function ticketEmis(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function (): Facture {
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        });
    }

    /**
     * Une écriture fautive faite en coupant les déclencheurs de la table,
     * dans la transaction du test, puis remis : seule une faute introduite
     * ainsi montre que la vérification trouve ce que les gardes empêchent.
     */
    private function sansSesDeclencheurs(string $table, Closure $ecriture): void
    {
        DB::statement("alter table {$table} disable trigger user");
        $ecriture();
        DB::statement("alter table {$table} enable trigger user");
    }

    /**
     * Deux sociétés, chacune avec son propriétaire et un magasin.
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function deuxSocietes(): array
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Premier']);
        $second = $this->ouvrirUneSociete(['nom' => 'Second']);
        app(TenantContext::class)->clear();

        return [$premier, $second];
    }

    /**
     * Chaque problème en [couche, table, identifiant, trouvé].
     *
     * @param  list<ProblemeDIsolation>  $problemes
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function problemesDe(array $problemes): array
    {
        return array_map(static fn (ProblemeDIsolation $probleme): array => [$probleme->couche, $probleme->table, $probleme->identifiant, $probleme->trouve], $problemes);
    }
}
