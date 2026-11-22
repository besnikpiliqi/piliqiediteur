<?php

namespace Tests\Feature;

use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Calendrier\Calendrier;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Carbon\CarbonImmutable;
use Database\Seeders\Semis\SemisComplet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\SemeSansTransaction;
use Tests\TestCase;

/**
 * Le cas « achat daté d'un mois à venir » de la carte « Écart des prix
 * d'achat » (règle 6 de CLAUDE.md, B10) existe à toute date du semis : le
 * semis se refait chaque nuit pour les bases modèles de la démonstration et
 * à chaque migrate:fresh --seed, jamais un seul jour. Il tourne ici le 2 et
 * le 25 d'un mois (le 2, la boucle des jours n'atteint presque rien du mois
 * en cours ; le 25, presque tout) : les deux factures de la boule de Noël
 * des Halles se posent dans le mois précédent, la seconde datée du mois
 * suivant le semis. Le semis prend plusieurs minutes : groupe « semis »,
 * hors de la suite par défaut (phpunit.xml).
 */
#[Group('semis')]
class SemisAchatDUnMoisAVenirTest extends TestCase
{
    use RefreshDatabase;
    use SemeSansTransaction;

    private const PRODUIT = 'Boule de Noël en verre (boîte de 6)';

    /**
     * @return array<string, array{0: int}> le quantième de novembre 2026 où tourne le semis
     */
    public static function datesDuSemis(): array
    {
        return [
            'le 2 du mois' => [2],
            'le 25 du mois' => [25],
        ];
    }

    #[DataProvider('datesDuSemis')]
    public function test_le_semis_pose_un_achat_date_d_un_mois_a_venir_a_toute_date(int $quantieme): void
    {
        config(['piliqi.semis_mot_de_passe' => null]);
        $this->travelTo(self::aParis(sprintf('2026-11-%02d 10:00:00', $quantieme)));
        $jourDuSemis = Calendrier::deParis()->aujourdhui();
        $moisDuSemis = Mois::duJour($jourDuSemis);

        Storage::fake('local');
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->artisan('db:seed')->assertSuccessful();

        $societe = Societe::query()->where('siren', SemisComplet::SIREN_COMMERCE)->sole();
        $halles = Magasin::query()->withoutGlobalScopes()->where('societe_id', $societe->getKey())->where('code', 'HALLES')->sole();

        $factures = DB::table('lignes_achat')
            ->join('factures_achat', fn ($jointure) => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
            ->join('produits', fn ($jointure) => $jointure->on('produits.magasin_id', '=', 'lignes_achat.magasin_id')->on('produits.id', '=', 'lignes_achat.produit_id'))
            ->where('lignes_achat.magasin_id', $halles->getKey())
            ->where('factures_achat.statut', 'validee')
            ->where('produits.nom', self::PRODUIT)
            ->orderBy('factures_achat.date_facture')
            ->get(['factures_achat.date_facture', 'factures_achat.date_validation', 'lignes_achat.quantite']);

        $this->assertCount(2, $factures, 'La boule de Noël a ses deux factures d\'achat validées.');
        $this->assertSame(
            [Mois::decaler($moisDuSemis, -1).'-10', Mois::decaler($moisDuSemis, 1).'-10'],
            $factures->map(fn (object $facture): string => substr((string) $facture->date_facture, 0, 10))->all(),
            'Une facture du mois précédent et une datée du mois qui suit le semis.',
        );

        foreach ($factures as $facture) {
            $this->assertLessThan($jourDuSemis, Calendrier::deParis()->jourDe(CarbonImmutable::parse((string) $facture->date_validation, 'UTC')), 'Chaque facture est validée avant le jour du semis, même un semis du 2.');
        }

        $this->assertGreaterThan(Calendrier::ajouterJours(Mois::decaler($moisDuSemis, 1).'-01', -1), substr((string) $factures->last()->date_facture, 0, 10), 'La seconde facture est datée d\'un mois à venir : après la fin du mois du semis, hors des 12 derniers mois.');
    }
}
