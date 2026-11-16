<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DemandeDeCopie;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\Jobs\ArchiverPuisSupprimerUneCopie;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\MarqueDeLaBase;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\DemonstrationTestCase;

/**
 * La copie d'un modèle (lot DEMO-COPIE, C.3, N17) : CREATE DATABASE …
 * TEMPLATE du modèle de son type, nom tiré au hasard, sans rien de la
 * société ; le modèle reste intact ; les réglages de la base (fuseau UTC,
 * connexion du seul rôle des pages) sont reposés ; une création qui échoue
 * ne laisse aucune base ; le plafond met la demande en attente d'une place,
 * la part réservée aux sociétés validées laisse passer une société validée.
 */
class CopieParModeleTest extends DemonstrationTestCase
{
    public function test_une_copie_se_fait_depuis_le_modele_de_son_type_sous_un_nom_tire_au_hasard_et_le_modele_reste_intact(): void
    {
        $modele = $this->modeleDeTest(TypeDActivite::Commerce);

        Bus::dispatchSync(new CreerUneCopie($this->demande('0123456789abcdef0123456789abcdef', 'K7M2QD')));

        $copie = Copie::on('demo_admin')->where('reference', '0123456789abcdef0123456789abcdef')->sole();
        $this->assertSame(EtatDUneCopie::Prete, $copie->etat);
        $this->assertSame($modele->id, $copie->modele_id);
        $this->assertSame($modele->empreinte_du_schema, $copie->empreinte_du_schema);
        $this->assertMatchesRegularExpression(BasesDeLaDemonstration::MOTIF_D_UNE_COPIE, (string) $copie->nom_de_la_base);
        $this->assertStringNotContainsString('0123456789abcdef', (string) $copie->nom_de_la_base, 'Le nom de la base ne dit rien de la demande.');
        $this->assertSame(1, $copie->liens()->count());

        $base = DB::connection('demo_admin')->table('pg_database')->where('datname', $modele->nom_de_la_base)->first(['datistemplate', 'datallowconn']);
        $this->assertNotNull($base);
        $this->assertTrue((bool) $base->datistemplate, 'Le modèle reste un modèle après la copie.');
        $this->assertFalse((bool) $base->datallowconn);

        $lu = BrancherUneBase::pendant(BrancherUneBase::parLesPages((string) $copie->nom_de_la_base), fn (): array => [
            'marque' => MarqueDeLaBase::lire(DB::connection()),
            'magasins' => Magasin::query()->withoutGlobalScopes()->orderBy('nom')->pluck('nom')->all(),
            'fuseau' => DB::scalar('show timezone'),
        ]);
        $this->assertSame(SiteDePiliqi::Demonstration, $lu['marque']);
        $this->assertSame(['La République', 'Les Halles'], $lu['magasins']);
        $this->assertSame('UTC', $lu['fuseau']);
    }

    public function test_une_copie_a_les_reglages_de_la_base_que_la_copie_par_template_ne_reprend_pas(): void
    {
        $this->modeleDeTest(TypeDActivite::Services);
        Bus::dispatchSync(new CreerUneCopie($this->demande('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'ACDEFG', TypeDActivite::Services)));
        $nom = (string) Copie::on('demo_admin')->sole()->nom_de_la_base;

        $reglages = DB::connection('demo_admin')->table('pg_db_role_setting')
            ->join('pg_database', 'pg_database.oid', '=', 'pg_db_role_setting.setdatabase')
            ->where('pg_database.datname', $nom)->where('pg_db_role_setting.setrole', 0)
            ->value('setconfig');
        $this->assertStringContainsString('TimeZone=UTC', (string) $reglages);
        $droits = (string) DB::connection('demo_admin')->table('pg_database')->where('datname', $nom)->value('datacl');
        $this->assertStringContainsString('demo_app=c/', $droits, 'Le rôle des pages se connecte à la copie.');
        $this->assertDoesNotMatchRegularExpression('/(^|[{,])=/', $droits, 'PUBLIC ne se connecte plus à la copie.');

        $privileges = BrancherUneBase::pendant(BrancherUneBase::parLesPages($nom), fn (): array => [
            'insertion' => DB::scalar("select has_table_privilege('enregistrements_fiscaux', 'INSERT')"),
            'modification' => DB::scalar("select has_table_privilege('enregistrements_fiscaux', 'UPDATE')"),
            'suppression' => DB::scalar("select has_table_privilege('enregistrements_fiscaux', 'DELETE')"),
            'clients' => DB::scalar("select has_table_privilege('clients', 'UPDATE')"),
        ]);
        $this->assertSame(['insertion' => true, 'modification' => false, 'suppression' => false, 'clients' => true], $privileges, 'Le rôle des pages n\'a ni UPDATE ni DELETE sur une table en ajout seul.');
    }

    public function test_une_demande_repetee_ne_cree_qu_une_copie(): void
    {
        $this->modeleDeTest(TypeDActivite::Services);
        $demande = $this->demande('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'CDEFGH', TypeDActivite::Services);

        Bus::dispatchSync(new CreerUneCopie($demande));
        $nom = Copie::on('demo_admin')->sole()->nom_de_la_base;
        Bus::dispatchSync(new CreerUneCopie($demande));

        $this->assertSame($nom, Copie::on('demo_admin')->sole()->nom_de_la_base, 'La demande répétée rend la copie déjà connue, sans en créer une seconde.');
    }

    public function test_sans_modele_en_service_la_creation_echoue_sans_laisser_de_base_et_compte_l_essai(): void
    {
        $copies = app(CopiesDeLaDemonstration::class);
        $copies->inscrire(DemandeDeCopie::lire($this->demande('cccccccccccccccccccccccccccccccc', 'DEFGHJ', TypeDActivite::Cabinet)));

        try {
            $copies->preparer('cccccccccccccccccccccccccccccccc');
            $this->fail('La copie devait être refusée sans modèle.');
        } catch (CopieImpossible $refus) {
            $this->assertSame(CopiesDeLaDemonstration::CAUSE_SANS_MODELE, $refus->getMessage());
        }

        $copie = Copie::on('demo_admin')->sole();
        $this->assertSame(1, $copie->essais);
        $this->assertSame(EtatDUneCopie::EnPreparation, $copie->etat);
        $this->assertNull($copie->nom_de_la_base, 'Aucune base n\'est née sans modèle.');

        (new CreerUneCopie($this->demande('cccccccccccccccccccccccccccccccc', 'DEFGHJ', TypeDActivite::Cabinet)))->failed(new CopieImpossible(CopiesDeLaDemonstration::CAUSE_SANS_MODELE));
        $this->assertSame(EtatDUneCopie::EnEchec, $copie->fresh()?->etat);
        $this->assertSame(CopiesDeLaDemonstration::CAUSE_SANS_MODELE, $copie->fresh()?->cause_de_l_echec);
    }

    public function test_le_plafond_met_la_demande_en_attente_et_la_part_reservee_laisse_passer_une_societe_validee(): void
    {
        config(['demonstration.copies_max' => 2, 'demonstration.part_des_validees' => 50]);
        $this->modeleDeTest(TypeDActivite::Services);

        $etats = [];

        foreach ([['d', false, 0], ['e', false, 1], ['f', true, 2], ['a', true, 3]] as [$lettre, $validee, $minutes]) {
            $this->travelTo(self::aParis('2026-11-16 10:00:00')->addMinutes($minutes));
            $reference = str_repeat($lettre, 32);
            Bus::dispatchSync(new CreerUneCopie([...$this->demande($reference, 'EFGHJK', TypeDActivite::Services), 'validee' => $validee]));
            $etats[$lettre] = Copie::on('demo_admin')->where('reference', $reference)->sole()->etat->value;
        }

        $this->assertSame([
            'd' => 'prete',
            'e' => 'en_attente_d_une_place',
            'f' => 'prete',
            'a' => 'en_attente_d_une_place',
        ], $etats, 'Une seule place pour les sociétés non validées sur deux ; la société validée passe ; le plafond met la suivante en attente.');
        $this->assertSame([str_repeat('a', 32), str_repeat('e', 32)], app(CopiesDeLaDemonstration::class)->enAttenteDUnePlace(), 'La place libérée va d\'abord à la plus ancienne demande d\'une société validée.');
    }

    public function test_la_place_liberee_par_une_suppression_va_a_la_demande_qui_l_attend_en_premier(): void
    {
        config(['demonstration.copies_max' => 1]);
        $this->modeleDeTest(TypeDActivite::Services);
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        Bus::dispatchSync(new CreerUneCopie($this->demande(str_repeat('d', 32), 'EFGHJK', TypeDActivite::Services)));
        $this->travelTo(self::aParis('2026-11-16 10:01:00'));
        Bus::dispatchSync(new CreerUneCopie($this->demande(str_repeat('e', 32), 'EFGHJK', TypeDActivite::Services)));
        $this->assertSame(EtatDUneCopie::EnAttenteDUnePlace, Copie::on('demo_admin')->where('reference', str_repeat('e', 32))->sole()->etat);

        Bus::dispatchSync(new ArchiverPuisSupprimerUneCopie(str_repeat('d', 32), 'supprimer'));

        $this->assertSame(EtatDUneCopie::Supprimee, Copie::on('demo_admin')->where('reference', str_repeat('d', 32))->sole()->etat);
        $this->assertSame(EtatDUneCopie::Prete, Copie::on('demo_admin')->where('reference', str_repeat('e', 32))->sole()->etat, 'La demande qui attendait passe prête dès que la place se libère.');
    }

    public function test_une_copie_en_echec_reprend_une_heure_apres_son_dernier_essai(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $reference = str_repeat('c', 32);

        try {
            Bus::dispatchSync(new CreerUneCopie($this->demande($reference, 'EFGHJK', TypeDActivite::Services)));
        } catch (CopieImpossible) {
        }

        app(CopiesDeLaDemonstration::class)->marquerEnEchec($reference, CopiesDeLaDemonstration::CAUSE_SANS_MODELE);
        $this->modeleDeTest(TypeDActivite::Services);

        $this->travelTo(self::aParis('2026-11-16 10:59:00'));
        $this->assertSame(0, Artisan::call('demonstration:reprendre-les-copies'));
        $this->assertSame(EtatDUneCopie::EnEchec, Copie::on('demo_admin')->where('reference', $reference)->sole()->etat, 'Pas avant l\'heure.');

        $this->travelTo(self::aParis('2026-11-16 11:01:00'));
        $this->assertSame(0, Artisan::call('demonstration:reprendre-les-copies'));
        $this->assertSame(EtatDUneCopie::Prete, Copie::on('demo_admin')->where('reference', $reference)->sole()->etat);
    }

    public function test_au_dela_de_cinquante_visites_la_plus_ancienne_est_retiree(): void
    {
        $this->travelTo(self::aParis('2026-11-16 11:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $premier = $this->entrerParLeLien($secret);

        for ($visite = 2; $visite <= TemoinsDesVisites::MAXIMUM_PAR_COPIE + 1; $visite++) {
            $this->travelTo(self::aParis('2026-11-16 12:00:00')->addSeconds($visite));
            app(TemoinsDesVisites::class)->creer($copie, 'lien');
        }

        $this->assertSame(TemoinsDesVisites::MAXIMUM_PAR_COPIE, Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->count());
        $this->assertNull(app(TemoinsDesVisites::class)->retrouver($premier), 'La plus ancienne visite est retirée.');
    }

    public function test_le_role_des_pages_ne_cree_aucune_base_et_n_ecrit_au_registre_que_les_temoins_les_nonces_et_les_travaux(): void
    {
        $registre = DB::connection('registre');

        $this->assertFalse((bool) $registre->scalar('select rolcreatedb from pg_roles where rolname = current_user'));
        $this->assertSame([
            'archives' => false, 'cache' => true, 'copies' => false, 'jobs' => true, 'liens' => false,
            'messages_recus' => true, 'modeles' => false, 'temoins' => true,
        ], collect(['archives', 'cache', 'copies', 'jobs', 'liens', 'messages_recus', 'modeles', 'temoins'])
            ->mapWithKeys(fn (string $table): array => [$table => (bool) $registre->scalar('select has_table_privilege(?, \'INSERT\')', [$table])])
            ->all());
        $this->assertTrue((bool) DB::connection('demo_admin')->scalar('select rolcreatedb from pg_roles where rolname = current_user'));
        $this->assertTrue((bool) DB::connection('demo_admin')->scalar("select pg_has_role(current_user, 'pg_signal_backend', 'member')"));
    }

    /**
     * @return array{reference: string, repere: string, type: string, validee: bool, empreinte_du_lien: string, generation: int, fin: string}
     */
    private function demande(string $reference, string $repere, TypeDActivite $type = TypeDActivite::Commerce): array
    {
        return [
            'reference' => $reference,
            'repere' => $repere,
            'type' => $type->value,
            'validee' => true,
            'empreinte_du_lien' => hash('sha256', 'secret-'.$reference),
            'generation' => 1,
            'fin' => self::aParis('2026-12-16 10:00:00')->utc()->toIso8601ZuluString(),
        ];
    }
}
