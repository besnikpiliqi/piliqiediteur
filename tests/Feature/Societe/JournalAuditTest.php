<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Exceptions\JournalAuditNonConfigureException;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TeteJournalAudit;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\VerificateurJournalAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Le journal d'audit des sociétés (voir JournalAudit) : en ajout seul,
 * chaîné par magasin, par société et par catégorie, protégé par la base,
 * vérifiable (voir VerificateurJournalAudit), sans aucun secret ; l'adresse
 * IP et le navigateur rangés à part (voir TraceAudit). Les entrées qui
 * doivent expirer sont datées dans un passé réel : la base n'efface une
 * entrée ou une trace qu'une fois expirée à son heure à elle.
 */
class JournalAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_refuse_de_modifier_ou_d_effacer_une_entree_non_expiree(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $entree = $this->inscrire($magasin->societe, $magasin);

        $this->assertThrows(fn () => $entree->forceFill(['action' => ActionAuditee::MagasinCree])->save(), LogicException::class, 'Une entrée du journal d\'audit ne se modifie jamais.');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('journal_audit')->where('id', $entree->id)->update(['justification' => 'falsifié'])), QueryException::class, 'journal_audit : modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('journal_audit')->where('id', $entree->id)->delete()), QueryException::class, 'journal_audit : suppression refusée avant expiration');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('journal_audit')->truncate()), QueryException::class, 'journal_audit : ');

        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($magasin->societe));
    }

    /**
     * Chaque chaîne (un magasin ou la société, dans une catégorie) compte
     * ses propres séquences, chaque entrée signant l'empreinte de la
     * précédente de sa chaîne ; la tête de chaque chaîne retient sa
     * dernière entrée.
     */
    public function test_les_entrees_sont_chainees_par_magasin_par_societe_et_par_categorie(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $societe = $lyon->societe;
        $avant = $this->entrees($societe)->count();

        $premiereDeLyon = $this->inscrire($societe, $lyon);
        $secondeDeLyon = $this->inscrire($societe, $lyon);
        $deVilleurbanne = $this->inscrire($societe, $villeurbanne);
        $deLaSociete = $this->inscrire($societe, null);
        $verificationDeLyon = $this->inscrire($societe, $lyon, ActionAuditee::VerificationEnregistree, ['type' => 'sirene_etablissement', 'resultat' => 'concluante', 'fournisseur' => 'insee', 'siret' => $lyon->siret]);

        $suiteDe = fn (EntreeAudit $entree): int => $this->entrees($societe)
            ->filter(fn (EntreeAudit $autre): bool => $autre->magasin_id === $entree->magasin_id && $autre->categorie === $entree->categorie && $autre->id < $entree->id)
            ->count() + 1;
        $this->assertGreaterThan(0, $avant);
        foreach ([$premiereDeLyon, $secondeDeLyon, $deVilleurbanne, $deLaSociete, $verificationDeLyon] as $entree) {
            $this->assertSame($suiteDe($entree), $entree->sequence);
        }
        $this->assertSame($secondeDeLyon->sequence, $premiereDeLyon->sequence + 1);
        $this->assertSame(CategorieAudit::Verification, $verificationDeLyon->categorie);
        $this->assertSame(
            JournalAudit::empreinte(JournalAudit::cle('tests'), $premiereDeLyon->empreinte, $secondeDeLyon),
            $secondeDeLyon->empreinte,
        );
        $teteDeLyon = TeteJournalAudit::query()->where('magasin_id', $lyon->id)->where('categorie', CategorieAudit::Operations->value)->sole();
        $this->assertSame([$secondeDeLyon->sequence, $secondeDeLyon->empreinte], [$teteDeLyon->sequence, $teteDeLyon->empreinte]);
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($societe));
    }

    /**
     * Aucun mot de passe, jeton, secret ni code n'entre au journal, même
     * si la liste d'une action en portait un ; une valeur qu'une action ne
     * retient pas est refusée.
     */
    public function test_aucun_secret_ni_code_n_entre_jamais_au_journal(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $avant = $this->entrees($magasin->societe)->count();

        foreach (['password', 'mot_de_passe_provisoire', 'remember_token', 'jeton', 'secret_rappel', 'code_confirmation'] as $champ) {
            $this->assertThrows(
                fn () => $this->inscrire($magasin->societe, null, ActionAuditee::IdentiteModifiee, [$champ => 'valeur']),
                InvalidArgumentException::class,
                "Le champ « {$champ} » ne s'inscrit jamais au journal d'audit.",
            );
        }
        $this->assertThrows(
            fn () => $this->inscrire($magasin->societe, null, ActionAuditee::IdentiteModifiee, ['siren' => '100000009']),
            InvalidArgumentException::class,
            'Le champ « siren » n\'est pas retenu par l\'action « identite_modifiee » du journal d\'audit.',
        );

        foreach (ActionAuditee::cases() as $action) {
            foreach ($action->champsAudites() as $champ) {
                foreach (ActionAuditee::CHAMPS_INTERDITS as $interdit) {
                    $this->assertDoesNotMatchRegularExpression('/(^|_)'.preg_quote($interdit, '/').'(_|$)/', $champ, "{$action->value} retient {$champ}");
                }
            }
        }
        $this->assertSame($avant, $this->entrees($magasin->societe)->count());
    }

    /**
     * L'action et son inscription au journal se font ensemble : sans la
     * clé du journal, l'identité de la société ne s'enregistre pas.
     */
    public function test_un_echec_d_ecriture_du_journal_annule_l_action(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $societe = $magasin->societe;
        $raisonSociale = $societe->raison_sociale;
        config(['journal_audit.cle' => null]);
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $societe, ['raison_sociale' => 'Martin et Fils', 'capital_social' => '10000', 'immatriculation' => 'RCS Lyon']),
            JournalAuditNonConfigureException::class,
        );

        $this->assertSame($raisonSociale, $societe->fresh()->raison_sociale);
    }

    /**
     * L'adresse IP et le navigateur d'une requête qui fait inscrire une
     * entrée vont dans sa trace, rangée à part : le contenu chaîné de
     * l'entrée ne les contient pas. Une commande n'en laisse aucune.
     */
    public function test_l_ip_et_le_navigateur_vont_dans_la_trace_separee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $parCommande = $this->inscrire($magasin->societe, null);
        $entree = $this->inscrireParUneRequete($magasin->societe);

        $trace = TraceAudit::withoutGlobalScopes()->findOrFail($entree->id);
        $this->assertSame(['203.0.113.7', 'Navigateur de test'], [$trace->ip, $trace->agent_utilisateur]);
        $this->assertStringNotContainsString('203.0.113.7', JournalAudit::contenuCanonique($entree));
        $this->assertStringNotContainsString('Navigateur de test', JournalAudit::contenuCanonique($entree));
        $this->assertNull(TraceAudit::withoutGlobalScopes()->find($parCommande->id));
    }

    /**
     * Ni le journal d'audit ni ses têtes ne gardent l'adresse IP ou le
     * navigateur : aucune de leurs colonnes ne les porte.
     */
    public function test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->inscrireParUneRequete($magasin->societe);

        foreach (['journal_audit', 'tetes_journal_audit'] as $table) {
            foreach (DB::table($table)->get() as $ligne) {
                $this->assertStringNotContainsString('203.0.113.7', json_encode($ligne, JSON_UNESCAPED_UNICODE));
                $this->assertStringNotContainsString('Navigateur de test', json_encode($ligne, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
     * Les entrées dont la durée de conservation est passée s'effacent par
     * le début de leur chaîne ; la tête retient le point de reprise, d'où
     * la vérification repart sans anomalie.
     */
    public function test_l_effacement_des_entrees_expirees_garde_la_chaine_verifiable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2014-03-01 10:00:00', 'UTC'));
        $societe = Societe::factory()->create();
        $anciennes = [$this->inscrire($societe, null), $this->inscrire($societe, null)];
        $this->travelTo(CarbonImmutable::parse('2016-01-01 10:00:00', 'UTC'));
        $recente = $this->inscrire($societe, null);
        $this->travelTo(CarbonImmutable::parse('2025-01-01 10:00:00', 'UTC'));

        $this->artisan('app:purger-journal-audit')
            ->expectsOutput('2 entrée(s) du journal d\'audit effacée(s).')
            ->assertSuccessful();

        $this->assertSame([$recente->id], $this->entrees($societe)->pluck('id')->all());
        $tete = TeteJournalAudit::query()->where('societe_id', $societe->id)->sole();
        $this->assertSame([$anciennes[1]->sequence, $anciennes[1]->empreinte], [$tete->reprise_sequence, $tete->reprise_empreinte]);
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($societe));
        $this->artisan('app:verifier-journal-audit', ['societe' => $societe->uuid])->assertSuccessful();
    }

    /**
     * Les dernières entrées d'une chaîne effacées par qui contournerait la
     * base, ou une entrée altérée, se voient : la tête retient la dernière
     * séquence, et chaque empreinte se recalcule.
     */
    public function test_la_suppression_des_dernieres_entrees_est_detectee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $societe = $magasin->societe;
        $this->inscrire($societe, null);
        $derniere = $this->inscrire($societe, null);
        $this->retirerLeDeclencheur('journal_audit', 'journal_audit_effacement_apres_expiration_delete');

        DB::table('journal_audit')->where('id', $derniere->id)->delete();

        $anomalies = app(VerificateurJournalAudit::class)->verifier($societe);
        $this->assertSame(["Chaîne de la société (operations) : la tête retient la séquence {$derniere->sequence}, la chaîne s'arrête à ".($derniere->sequence - 1).' ; des dernières entrées manquent ou ont été ajoutées hors du journal.'], $anomalies);
        $this->artisan('app:verifier-journal-audit', ['societe' => (string) $societe->id])
            ->expectsOutput("Société n° {$societe->id} : {$anomalies[0]}")
            ->assertFailed();
    }

    /**
     * La vérification d'une seule société ne la cherche que par son
     * identifiant ou son uuid : une société inconnue, ou un texte qui
     * n'est ni l'un ni l'autre, est refusé, sans rien vérifier d'autre.
     */
    public function test_une_societe_inconnue_ou_un_texte_qui_n_est_ni_un_identifiant_ni_un_uuid_est_refuse(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Lyon']);

        foreach (['999999', (string) Str::uuid(), 'Lyon'] as $designation) {
            $this->artisan('app:verifier-journal-audit', ['societe' => $designation])
                ->expectsOutput("Aucune société « {$designation} ».")
                ->doesntExpectOutputToContain('Journal d\'audit intègre')
                ->assertFailed();
        }
    }

    /**
     * Une clé remplacée reste dans les clés précédentes : les entrées
     * qu'elle a chaînées se vérifient toujours ; oubliée, elles sont
     * signalées.
     */
    public function test_la_rotation_de_la_cle_garde_les_anciennes_entrees_verifiables(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $societe = $magasin->societe;
        $ancienneCle = config('journal_audit.cle');
        $ancienne = $this->inscrire($societe, null);
        config(['journal_audit.cle' => base64_encode(random_bytes(32)), 'journal_audit.cle_id' => 'tests-2', 'journal_audit.cles_precedentes' => ['tests' => $ancienneCle]]);

        $nouvelle = $this->inscrire($societe, null);

        $this->assertSame(['tests', 'tests-2'], [$ancienne->cle_id, $nouvelle->cle_id]);
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($societe));
        config(['journal_audit.cles_precedentes' => []]);
        $this->assertContains("Chaîne de la société (operations) : séquence {$ancienne->sequence} chaînée par une clé inconnue (tests).", app(VerificateurJournalAudit::class)->verifier($societe));
    }

    /**
     * Les traces de plus d'un an s'effacent, les entrées qu'elles
     * accompagnaient restent, et leur chaîne reste intègre.
     */
    public function test_les_traces_de_plus_d_un_an_sont_effacees_sans_toucher_aux_entrees(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-03-01 10:00:00', 'Europe/Paris'));
        $societe = Societe::factory()->create();
        $ancienne = $this->inscrireParUneRequete($societe);
        $this->travelTo(CarbonImmutable::parse('2024-06-01 10:00:00', 'Europe/Paris'));
        $recente = $this->inscrireParUneRequete($societe);
        $this->travelTo(CarbonImmutable::parse('2025-03-02 04:00:00', 'Europe/Paris'));

        $this->artisan('app:purger-traces')
            ->expectsOutput('1 trace(s) technique(s) effacée(s).')
            ->assertSuccessful();

        $this->assertSame([$recente->id], TraceAudit::withoutGlobalScopes()->pluck('entree_audit_id')->all());
        $this->assertSame([$ancienne->id, $recente->id], $this->entrees($societe)->pluck('id')->all());
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($societe));
    }

    /**
     * Une entrée de l'action, dans la chaîne du magasin ou de la société,
     * inscrite hors de toute requête (comme une commande).
     *
     * @param  array<string, mixed>|null  $nouvellesValeurs
     */
    private function inscrire(Societe $societe, ?Magasin $magasin, ActionAuditee $action = ActionAuditee::IdentiteModifiee, ?array $nouvellesValeurs = ['raison_sociale' => 'Martin et Fils']): EntreeAudit
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): EntreeAudit => DB::transaction(fn (): EntreeAudit => app(JournalAudit::class)->inscrire($action, $magasin, null, $nouvellesValeurs)));
    }

    /**
     * Une entrée de la société inscrite par une requête HTTP, depuis
     * l'adresse 203.0.113.7 avec le navigateur « Navigateur de test ».
     */
    private function inscrireParUneRequete(Societe $societe): EntreeAudit
    {
        Route::post("/test/auditer/{$societe->id}", fn (): int => app(TenantContext::class)->runAsSociete(
            $societe,
            fn (): int => DB::transaction(fn (): int => app(JournalAudit::class)->inscrire(ActionAuditee::IdentiteModifiee, null, null, ['raison_sociale' => 'Martin et Fils'])->id),
        ));

        $identifiant = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'Navigateur de test')
            ->post("/test/auditer/{$societe->id}")
            ->getContent();

        return EntreeAudit::withoutGlobalScopes()->findOrFail($identifiant);
    }

    /**
     * @return Collection<int, EntreeAudit>
     */
    private function entrees(Societe $societe): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn () => EntreeAudit::query()->orderBy('id')->get());
    }
}
