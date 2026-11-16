<?php

namespace Tests\Feature\Demonstration;

use App\Console\Commands\EpinglerLaCleDuJournalDAudit;
use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\DemonstrationTestCase;

/**
 * Les copies signent avec la clé de démonstration et marquent leur caisse
 * (lot DEMO-COPIE, C.10, C.12, choix 18 et 19, N19) : l'en-tête signé de
 * chaque enregistrement et l'émetteur d'une copie portent
 * « demonstration » à vrai, et chaque magasin entre en mode d'entraînement
 * (JET 100) dès son premier enregistrement ; caisse:verifier d'une copie
 * passe avec la clé publique de démonstration et échoue avec une autre.
 */
class ClesDeLaDemonstrationTest extends DemonstrationTestCase
{
    public function test_une_copie_marque_son_registre_et_son_emetteur_et_entre_en_mode_d_entrainement(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        $lu = BrancherUneBase::pendant(BrancherUneBase::parLesPages((string) $copie->nom_de_la_base), function (): array {
            $magasin = Magasin::query()->withoutGlobalScopes()->sole();

            return app(TenantContext::class)->runAs($magasin, function () use ($magasin): array {
                DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => 'essai']));
                $enregistrements = EnregistrementFiscal::query()->orderBy('sequence')->get(['type', 'contenu']);

                return [
                    'types' => $enregistrements->pluck('type')->all(),
                    'marques' => $enregistrements->map(fn (EnregistrementFiscal $enregistrement): mixed => json_decode($enregistrement->contenu, true)['demonstration'] ?? null)->all(),
                    'emetteur' => Emetteur::duMagasin($magasin)->enTableau()['demonstration'] ?? null,
                    'filigrane' => Emetteur::duMagasin($magasin)->filigrane(),
                    'verification' => app(VerifierIntegriteAction::class)->parLeSysteme($magasin, true)->estConforme(),
                ];
            });
        });

        $this->assertSame(['260', '100', '110'], array_slice($lu['types'], 0, 3), 'Le registre d\'un magasin de la copie s\'ouvre en mode d\'entraînement (JET 100).');
        $this->assertSame([true, true, true], array_slice($lu['marques'], 0, 3), 'Chaque en-tête signé d\'une copie porte « demonstration ».');
        $this->assertTrue($lu['emetteur']);
        $this->assertSame('DÉMONSTRATION — SANS VALEUR', $lu['filigrane']);
        $this->assertTrue($lu['verification'], 'caisse:verifier d\'une copie passe avec la clé publique de démonstration.');
    }

    public function test_le_registre_d_une_copie_ne_se_verifie_pas_avec_une_autre_cle_que_celle_de_la_demonstration(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $autre = SignataireFiscal::genererUnePaire();

        $anomalies = BrancherUneBase::pendant(BrancherUneBase::parLesPages((string) $copie->nom_de_la_base), function () use ($autre): array {
            $magasin = Magasin::query()->withoutGlobalScopes()->sole();

            return app(TenantContext::class)->runAs($magasin, function () use ($magasin, $autre): array {
                DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => 'essai']));
                config(['caisse_securisee.cles_publiques' => ['reel.pem' => $autre['cle_publique']]]);
                app()->forgetInstance(SignataireFiscal::class);
                app()->forgetInstance(VerificateurIntegrite::class);

                return array_map(fn (AnomalieFiscale $anomalie): string => $anomalie->controle, app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
            });
        });

        $this->assertContains('cle', $anomalies, 'Signé par la clé de démonstration, le registre d\'une copie ne se vérifie avec aucune autre clé.');
    }

    public function test_le_module_fiscal_est_en_version_3_0(): void
    {
        $this->assertSame('3.0', ModuleFiscal::VERSION);
    }

    public function test_journal_audit_epingler_la_cle_epingle_l_empreinte_des_cles_une_seule_fois_et_jamais_la_cle(): void
    {
        $fichier = DossiersDeLaDemonstration::racine().'/cles-demonstration.txt';
        File::delete($fichier);
        config(['journal_audit.fichier_des_empreintes_de_demonstration' => $fichier, 'journal_audit.cle' => null, 'journal_audit.cles_precedentes' => []]);

        $this->artisan('journal-audit:epingler-la-cle')->expectsOutput(EpinglerLaCleDuJournalDAudit::MESSAGE_SANS_CLE)->assertFailed();
        $this->assertFileDoesNotExist($fichier);

        config(['journal_audit.cle' => 'cle-du-journal-de-demonstration', 'journal_audit.cles_precedentes' => ['2026-10' => 'ancienne-cle-de-demonstration']]);
        $this->artisan('journal-audit:epingler-la-cle')->assertSuccessful();
        $this->artisan('journal-audit:epingler-la-cle')->assertSuccessful();

        $this->assertSame(
            hash('sha256', 'cle-du-journal-de-demonstration')."\n".hash('sha256', 'ancienne-cle-de-demonstration')."\n",
            File::get($fichier),
            'Chaque clé est épinglée une seule fois, par son empreinte.',
        );
        $this->assertStringNotContainsString('cle-du-journal', File::get($fichier), 'Le fichier ne porte jamais la clé.');
        File::delete($fichier);
    }
}
