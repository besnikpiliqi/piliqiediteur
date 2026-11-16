<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Seeders\Semis\SemisComplet;
use Database\Seeders\Semis\SocietesDesCas;
use ReflectionClass;
use Tests\DemonstrationTestCase;

/**
 * Le répertoire Sirene factice du site de démonstration (lot DEMO-COPIE,
 * A.6 et F.5) ne connaît que les SIREN fictifs du semis et leurs
 * établissements : un visiteur ne fait jamais entrer une vraie entreprise
 * dans sa copie, et un SIRET tapé à la main hors de la plage réservée est
 * inconnu.
 */
class RepertoireSireneDeLaDemonstrationTest extends DemonstrationTestCase
{
    public function test_seuls_les_siren_fictifs_du_semis_et_leurs_etablissements_sont_connus(): void
    {
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);
        $fictif = '000000109';
        $autre = IdentifiantsInsee::avecSaCle('55208131');

        $this->assertNotNull($repertoire->unite($fictif));
        $this->assertNotNull($repertoire->etablissement(IdentifiantsInsee::avecSaCle($fictif.'0004')), 'Un établissement de la plage réservée, encore libre, est connu.');
        $this->assertCount(2, $repertoire->etablissementsActifs($fictif)->etablissements);
        $this->assertNull($repertoire->unite($autre), 'Un SIREN hors du semis est inconnu sur le site de démonstration.');
        $this->assertNull($repertoire->etablissement(IdentifiantsInsee::avecSaCle($autre.'0001')));
        $this->assertSame([], $repertoire->etablissementsActifs($autre)->etablissements);
    }

    public function test_chaque_siren_du_semis_est_connu_du_repertoire_du_site_de_demonstration(): void
    {
        $sirens = [];

        foreach ([SemisComplet::class, SocietesDesCas::class] as $classe) {
            foreach ((new ReflectionClass($classe))->getConstants() as $valeur) {
                foreach ((array) $valeur as $siren) {
                    if (is_string($siren) && preg_match('/^0{6}\d{3}$/', $siren) === 1) {
                        $sirens[$siren] = true;
                    }
                }
            }
        }

        $this->assertGreaterThan(20, count($sirens));
        $this->assertSame([], array_values(array_diff(array_keys($sirens), FauxRepertoireSirene::SIREN_DU_SEMIS)), 'Chaque SIREN fictif du semis est connu du répertoire factice du site de démonstration.');
    }
}
