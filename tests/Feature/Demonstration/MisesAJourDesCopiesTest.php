<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\EmpreinteDuSchema;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Construction\MigrationDesCopies;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\DemonstrationTestCase;

/**
 * Les mises à jour du logiciel et les copies (lot DEMO-COPIE, partie E,
 * N26). Avant la mise en production : demonstration:refaire-les-copies
 * archive puis refait chaque copie depuis le nouveau modèle, avec la même
 * référence, le même lien et la même fin, efface le compte choisi en
 * dernier et montre une fois le message de la mise à jour ;
 * demonstration:tout-effacer vide le registre et les archives, garde les
 * modèles, et refuse en production. Après : demonstration:migrer-les-copies,
 * avec un dossier de migrations de test, migre chaque copie après
 * l'archive de sa caisse, efface l'archive d'une migration réussie, refait
 * celle dont la migration échoue, sans toucher les autres, et écrit son
 * tableau dans l'état publié ; une copie pas encore à jour n'est jamais
 * servie (« Mise à jour en cours »).
 */
class MisesAJourDesCopiesTest extends DemonstrationTestCase
{
    public function test_refaire_les_copies_garde_la_reference_le_lien_et_la_fin_et_montre_le_message_une_fois(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        Temoin::on('demo_admin')->where('copie_id', $copie->id)->update(['compte_choisi' => 7]);
        $lien = Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->sole();
        $ancienneBase = (string) $copie->nom_de_la_base;

        $this->artisan('demonstration:refaire-les-copies')->assertSuccessful();

        $refaite = Copie::on('demo_admin')->whereKey($copie->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $refaite->etat);
        $this->assertSame($copie->reference, $refaite->reference);
        $this->assertNotSame($ancienneBase, $refaite->nom_de_la_base, 'La copie repart du nouveau modèle, dans une base neuve.');
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($ancienneBase));
        $this->assertSame($copie->fin->getTimestamp(), $refaite->fin->getTimestamp(), 'Même fin.');
        $this->assertSame($lien->id, Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->sole()->id, 'Même lien.');
        $this->assertNull(Temoin::on('demo_admin')->where('copie_id', $copie->id)->sole()->compte_choisi, 'Le compte choisi en dernier est effacé.');
        $this->assertSame('mise_a_jour', Archive::on('demo_admin')->where('reference', $copie->reference)->sole()->motif, 'La caisse est archivée avant la suppression de l\'ancienne base.');

        $message = 'PILIQI a été mis à jour le 20/11/2026. Votre démonstration repart de la société fictive de départ, avec les nouveautés. Votre lien reste le même.';
        $this->assertSame($message, $refaite->message);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertOk()->assertSee($message, false);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertOk()->assertDontSee($message, false);
    }

    public function test_tout_effacer_vide_le_registre_et_les_archives_garde_les_modeles_et_refuse_en_production(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $this->entrerParLeLien($secret);
        $this->artisan('demonstration:refaire-les-copies')->assertSuccessful();
        $copie = Copie::on('demo_admin')->whereKey($copie->id)->sole();
        $modeles = Modele::on('demo_admin')->count();

        $this->app['env'] = 'production';
        $this->artisan('demonstration:tout-effacer')->assertFailed()->expectsOutputToContain('Rien n\'a été effacé');
        $this->assertSame(1, Copie::on('demo_admin')->count());

        $this->app['env'] = 'testing';
        $this->artisan('demonstration:tout-effacer')->assertSuccessful();

        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base), 'La base de la copie est supprimée.');

        foreach (['copies', 'liens', 'temoins', 'archives', 'messages_recus'] as $table) {
            $this->assertSame(0, DB::connection('demo_admin')->table($table)->count(), "La table {$table} du registre est vide.");
        }

        $this->assertDirectoryDoesNotExist(DossiersDeLaDemonstration::racine().'/archives');
        $this->assertSame($modeles, Modele::on('demo_admin')->count(), 'Les bases modèles restent.');
    }

    public function test_migrer_les_copies_migre_chacune_apres_son_archive_et_refait_celle_qui_echoue_sans_toucher_les_autres(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $migree] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $ratee] = $this->copieDeTest(TypeDActivite::Services);
        $dossier = $this->dossierDeMigrationsDeTest((string) $ratee->nom_de_la_base);

        try {
            $this->artisan('demonstration:migrer-les-copies', ['--dossier' => [$dossier]])->assertSuccessful();
        } finally {
            File::deleteDirectory($dossier);
        }

        $migree = Copie::on('demo_admin')->whereKey($migree->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $migree->etat);
        $this->assertTrue(app(OuvertureDUneCopie::class)->pendant($migree, fn (): bool => Schema::hasTable('essai_de_mise_a_jour')), 'La copie a reçu la migration, en gardant ses données.');
        $this->assertSame(EmpreinteDuSchema::duCode(), $migree->empreinte_du_schema);
        $this->assertSame(FinDesCopies::MOTIF_MIGRATION_REUSSIE, $migree->motif_du_message);
        $this->assertStringContainsString('vos essais sont gardés', (string) $migree->message);
        $this->assertSame(0, Archive::on('demo_admin')->where('reference', $migree->reference)->count(), 'L\'archive d\'avant une migration réussie est effacée.');

        $refaite = Copie::on('demo_admin')->whereKey($ratee->id)->sole();
        $this->assertSame(EtatDUneCopie::Prete, $refaite->etat, 'La copie dont la migration échoue est refaite depuis le nouveau modèle.');
        $this->assertNotSame($ratee->nom_de_la_base, $refaite->nom_de_la_base);
        $this->assertSame($ratee->fin->getTimestamp(), $refaite->fin->getTimestamp());
        $this->assertSame(FinDesCopies::MOTIF_MIGRATION_RATEE, $refaite->motif_du_message);
        $this->assertSame('PILIQI a été mis à jour le 20/11/2026. Votre démonstration a dû être refaite : vos essais n\'ont pas pu être gardés. Votre lien reste le même.', $refaite->message);
        $this->assertSame([MigrationDesCopies::MOTIF_DE_L_ARCHIVE], Archive::on('demo_admin')->where('reference', $ratee->reference)->pluck('motif')->all(), 'L\'archive faite avant la migration couvre la suppression : une seule archive.');

        $this->assertSame(['migrees' => 1, 'refaites' => 1, 'laissees' => 0], array_intersect_key((array) MigrationDesCopies::dernierTableau(), array_flip(['migrees', 'refaites', 'laissees'])));
        $this->assertNotEmpty(array_filter(app(EtatDuSite::class)->construire()['problemes'], static fn (string $ligne): bool => str_starts_with($ligne, 'Dernière mise à jour des copies : 1 migrée(s), 1 refaite(s)')), 'Le tableau final paraît dans l\'état publié, sans aucune référence.');
        Cache::store('registre')->forget(MigrationDesCopies::CLE_DU_TABLEAU);
    }

    public function test_une_copie_pas_encore_a_jour_n_est_jamais_servie(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        Copie::on('demo_admin')->whereKey($copie->id)->update(['empreinte_du_schema' => str_repeat('0', 64)]);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertStatus(503)
            ->assertSee('Mise à jour en cours, une minute');

        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnMigration->value, 'empreinte_du_schema' => EmpreinteDuSchema::duCode()]);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertStatus(503)
            ->assertSee('Mise à jour en cours, une minute');
    }

    /**
     * Un dossier de migrations de test : une table ajoutée, puis une
     * migration qui échoue sur la base nommée.
     */
    private function dossierDeMigrationsDeTest(string $baseQuiEchoue): string
    {
        $dossier = sys_get_temp_dir().'/migrations-de-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dossier);
        File::put($dossier.'/2099_01_01_000001_create_essai_de_mise_a_jour_table.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('essai_de_mise_a_jour', function (Blueprint $table) {
                        $table->id();
                        $table->string('nom');
                    });
                }
            };
            PHP);
        File::put($dossier.'/2099_01_01_000002_echouer_sur_une_copie.php', str_replace('__BASE__', $baseQuiEchoue, <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration
            {
                public function up(): void
                {
                    if (DB::connection()->getDatabaseName() === '__BASE__') {
                        throw new RuntimeException('Migration de test qui échoue sur cette copie.');
                    }
                }
            };
            PHP));

        return $dossier;
    }
}
