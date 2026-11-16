<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Semis\MotsDePasseDuSemis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Les garde-fous du semis d'un poste de développement
 * (lot SEED, demande de l'éditeur du 13/11/2026) : php artisan db:seed
 * refuse, sans rien écrire, la production, les fournisseurs réels, une
 * base déjà remplie et un mot de passe commun hors de la règle des mots
 * de passe ; les mots de passe de la liste des comptes sont communs ou
 * tirés au hasard. Le semis lui-même, qui prend plusieurs minutes, se
 * vérifie dans SemisCompletTest (groupe « semis »).
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_semis_est_refuse_en_production_sans_rien_ecrire(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(DatabaseSeeder::MESSAGE_HORS_DU_DEVELOPPEMENT, $this->refusDuSemis(['--force' => true]));
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    /**
     * Chaque fournisseur externe avec son pilote réel, ou sans pilote
     * quand il n'en a pas encore d'autre que le factice (le courrier
     * recommandé et la plateforme agréée).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function fournisseursReels(): array
    {
        return [
            'répertoire Sirene de l\'INSEE' => ['services.sirene.pilote', 'insee'],
            'registre national des entreprises de l\'INPI' => ['services.rne.pilote', 'inpi'],
            'courrier recommandé sans pilote' => ['services.recommande.pilote', null],
            'annuaire de l\'Ordre tenu par l\'éditeur' => ['services.ordre.pilote', 'editeur'],
            'plateforme agréée sans pilote' => ['services.pdp.pilote', null],
            'antivirus ClamAV' => ['services.antivirus.pilote', 'clamav'],
        ];
    }

    #[DataProvider('fournisseursReels')]
    public function test_le_semis_est_refuse_avec_un_fournisseur_reel_sans_rien_ecrire(string $reglage, ?string $pilote): void
    {
        config([$reglage => $pilote]);

        $this->assertSame(DatabaseSeeder::MESSAGE_FOURNISSEURS_REELS, $this->refusDuSemis());
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    public function test_le_semis_est_refuse_dans_une_base_qui_a_deja_des_societes(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->assertSame(DatabaseSeeder::MESSAGE_BASE_DEJA_REMPLIE, $this->refusDuSemis());
        $this->assertSame([(int) $societe->getKey()], DB::table('societes')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function motsDePasseHorsDeLaRegle(): array
    {
        return [
            'trop court' => ['Fraise7', RegleDuMotDePasse::MESSAGE_TROP_COURT],
            'un seul caractère répété' => ['aaaaaaaa', RegleDuMotDePasse::MESSAGE_REPETE],
            'une suite de chiffres' => ['12345678', RegleDuMotDePasse::MESSAGE_SUITE],
        ];
    }

    #[DataProvider('motsDePasseHorsDeLaRegle')]
    public function test_un_mot_de_passe_commun_hors_de_la_regle_est_refuse_sans_rien_ecrire(string $motDePasse, string $reproche): void
    {
        config(['piliqi.semis_mot_de_passe' => $motDePasse]);

        $this->assertSame(sprintf(MotsDePasseDuSemis::MESSAGE_REFUSE, $reproche), $this->refusDuSemis());
        $this->assertDatabaseEmpty('societes');
        $this->assertDatabaseEmpty('users');
    }

    public function test_le_mot_de_passe_commun_va_a_chaque_compte_sauf_s_il_contient_son_identifiant(): void
    {
        config(['piliqi.semis_mot_de_passe' => 'Clef-48151623']);
        $motsDePasse = MotsDePasseDuSemis::depuisLaConfiguration();

        $this->assertTrue($motsDePasse->sontCommuns());
        $this->assertSame('Clef-48151623', $motsDePasse->nouveau());
        $this->assertSame('Clef-48151623', $motsDePasse->pour('27182818'));

        try {
            $motsDePasse->pour('48151623');
            $this->fail('Un mot de passe commun qui contient l\'identifiant d\'un compte doit être refusé.');
        } catch (RuntimeException $refus) {
            $this->assertSame(sprintf(MotsDePasseDuSemis::MESSAGE_IDENTIFIANT, '48151623'), $refus->getMessage());
        }
    }

    public function test_sans_mot_de_passe_commun_chaque_compte_recoit_un_mot_de_passe_tire_au_hasard(): void
    {
        config(['piliqi.semis_mot_de_passe' => '']);
        Str::createRandomStringsUsingSequence(['K7QP', '2MZR', 'XA4D', 'B3NW', 'Q9TE', 'LM2C']);
        $motsDePasse = MotsDePasseDuSemis::depuisLaConfiguration();

        $this->assertFalse($motsDePasse->sontCommuns());
        $this->assertSame('k7qp-2mzr-xa4d', $motsDePasse->pour('48151623'));
        $this->assertSame('b3nw-q9te-lm2c', $motsDePasse->nouveau());
    }

    /**
     * Le message du refus du semis, lancé avec ces options.
     *
     * @param  array<string, mixed>  $options
     */
    private function refusDuSemis(array $options = []): ?string
    {
        try {
            $this->artisan('db:seed', $options)->run();
        } catch (RuntimeException $refus) {
            return $refus->getMessage();
        }

        return null;
    }
}
