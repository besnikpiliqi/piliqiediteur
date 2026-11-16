<?php

namespace Tests\Feature\Demonstration;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le vérificateur d'intégrité du site réel (lot DEMO-COPIE, C.10, choix 18
 * et 19, N19) : un enregistrement marqué « demonstration », comme un JET
 * 100 ou 105 du mode d'entraînement, n'a pas sa place dans une base réelle
 * et se signale ; le registre du site réel ne porte jamais ce marqueur.
 */
class VerificateurDuSiteReelTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_registre_du_site_reel_ne_porte_jamais_le_marqueur_de_la_demonstration(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $magasin = $this->ouvrirUneSociete();

        DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => 'essai']));

        $enregistrements = EnregistrementFiscal::query()->orderBy('sequence')->get(['type', 'contenu']);
        $this->assertSame(['260', '110'], $enregistrements->pluck('type')->all(), 'Aucun JET 100 au site réel.');
        $this->assertSame([false, false], $enregistrements->map(fn (EnregistrementFiscal $enregistrement): bool => array_key_exists('demonstration', json_decode($enregistrement->contenu, true)))->all());
        $this->assertTrue(app(VerificateurIntegrite::class)->verifier($magasin)->estConforme());
    }

    public function test_un_enregistrement_d_une_copie_dans_une_base_reelle_est_signale(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        config(['piliqi.site' => 'demonstration']);
        DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => 'essai']));
        config(['piliqi.site' => 'reel']);

        $anomalies = array_values(array_filter(
            app(VerificateurIntegrite::class)->verifier($magasin)->anomalies(),
            fn (AnomalieFiscale $anomalie): bool => $anomalie->controle === 'demonstration',
        ));

        $this->assertSame([
            'Enregistrement n° 1 du journal des événements : il vient d\'une copie du site de démonstration (en-tête marqué « demonstration ») et n\'a pas sa place dans une base du site réel.',
            'Enregistrement n° 2 du journal des événements : il vient d\'une copie du site de démonstration (JET 100 du mode d\'entraînement) et n\'a pas sa place dans une base du site réel.',
            'Enregistrement n° 3 du journal des événements : il vient d\'une copie du site de démonstration (en-tête marqué « demonstration ») et n\'a pas sa place dans une base du site réel.',
        ], array_map(fn (AnomalieFiscale $anomalie): string => $anomalie->message, $anomalies));
    }
}
