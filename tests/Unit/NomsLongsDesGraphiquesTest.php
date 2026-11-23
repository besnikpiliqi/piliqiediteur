<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Filament\GrilleDesGraphiques;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seules les cartes dont les étiquettes sont des noms saisis par la société
 * (produits, clients, fournisseurs, magasins) coupent un nom trop long par
 * son début suivi de « … » (demande de l'éditeur du 20/11/2026) : les
 * mois, les jours, les taux et les modes de paiement gardent l'axe de
 * Chart.js, qui ne coupe jamais « janv. 2026 » en « janv.… » (relecture du
 * lot écrans). La configuration que le script reçoit le dit.
 */
class NomsLongsDesGraphiquesTest extends TestCase
{
    /**
     * @return array<string, array{0: Graphique, 1: bool}>
     */
    public static function graphiques(): array
    {
        $noms = [Graphique::ChiffreDAffairesParMagasin, Graphique::MeilleursProduits, Graphique::MeilleursClients, Graphique::AchatsParFournisseur];
        $cas = [];

        foreach (Graphique::cases() as $graphique) {
            $cas[$graphique->value] = [$graphique, in_array($graphique, $noms, true)];
        }

        return $cas;
    }

    #[DataProvider('graphiques')]
    public function test_seules_les_cartes_de_noms_saisis_par_la_societe_coupent_leurs_noms_longs(Graphique $graphique, bool $sontDesNoms): void
    {
        $this->assertSame($sontDesNoms, $graphique->etiquettesSontDesNoms());

        foreach ($graphique->types() as $type) {
            $serie = new SerieDuGraphique(['janv. 2026', 'févr. 2026'], [SerieDuGraphique::jeu('Montant', [100, 200])]);
            $configuration = GrilleDesGraphiques::configuration(CarteDuTableauDeBord::parDefaut($graphique, $type), $serie);

            $this->assertSame($sontDesNoms, $configuration['noms_longs'], "{$graphique->value} en {$type->value}");
        }
    }
}
