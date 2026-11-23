<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Charges\Support\ContenuDeLEtat;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Services\RecapitulatifDesDocuments;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Models\User;
use Tests\DemonstrationTestCase;

/**
 * Le marquage des fichiers d'une copie (lot DEMO-COPIE, C.10, N18) : sur le
 * site de démonstration, l'état des charges (titre de sa feuille et de son
 * PDF, nom du fichier), le récapitulatif CSV (première ligne) et le ZIP de
 * « Télécharger les documents » (nom du fichier) portent « Démonstration —
 * sans valeur » ; les PDF des documents le portent par leur émetteur figé
 * (voir ClesDeLaDemonstrationTest). Le site réel ne les marque jamais
 * (voir BandeauEtCourrielsHorsDuSiteReelTest).
 */
class MarquageDesFichiersDeLaCopieTest extends DemonstrationTestCase
{
    public function test_l_etat_des_charges_le_recapitulatif_et_le_zip_portent_demonstration_sans_valeur(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        $this->assertSame('Démonstration — sans valeur · ', ContenuDeLEtat::marqueDeLaDemonstration(' · '));

        foreach ([FormatDuTelechargement::Zip, FormatDuTelechargement::Csv] as $format) {
            $telechargement = (new TelechargementDeDocuments)->forceFill(['du' => '2026-10-01', 'au' => '2026-10-31']);
            $telechargement->format = $format;
            $this->assertSame("demonstration-sans-valeur-documents-2026-10-01-au-2026-10-31.{$format->value}", $telechargement->nomDuFichier());
        }

        $recapitulatif = app(OuvertureDUneCopie::class)->pendant($copie, fn (): string => app(RecapitulatifDesDocuments::class)->ecrire(User::query()->firstOrFail(), [], '2026-10-01', '2026-10-31'));
        $lignes = array_map(fn (string $ligne): array => str_getcsv($ligne, ';', '"', ''), explode("\r\n", substr($recapitulatif, 3)));
        $this->assertSame([RecapitulatifDesDocuments::DEMONSTRATION], $lignes[0], 'La première ligne du récapitulatif dit « Démonstration — sans valeur ».');
        $this->assertSame(RecapitulatifDesDocuments::COLONNES, $lignes[1]);
    }
}
