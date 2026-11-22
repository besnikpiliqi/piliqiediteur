<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\SemeSansTransaction;
use Tests\TestCase;

/**
 * Le volume de la page de la carte « Écart des prix d'achat » (décision de
 * l'éditeur du 21/11/2026, D76, B8), au 13 novembre 2026 : 300 produits et
 * 12 000 lignes d'achat validées, créés par les vraies actions (40 factures
 * de 300 lignes, une ligne par produit ; la première facture fait naître les
 * produits). La grille est rendue avec la carte : la lecture des lignes
 * d'achat est une seule requête, la taille du HTML rendu est bornée par un
 * nombre écrit ici d'après la mesure du lot (marge de 50 %), et le calcul de
 * l'affichage caché et ceux des mois non choisis ne sont pas écrits dans la
 * page. Aucune limite de temps : aucun test ne dépend du temps réel.
 *
 * Les 12 000 lignes se valident une facture après l'autre, chacune dans sa
 * transaction, comme le fait l'application (voir SemeSansTransaction) : une
 * seule transaction de tout le test garderait les verrous de chaque ligne
 * et épuiserait la mémoire partagée de PostgreSQL (max_locks_per_transaction).
 */
class EcartDesPrixDAchatVolumeDeLaPageTest extends TestCase
{
    use RefreshDatabase;
    use SemeSansTransaction;

    private const PRODUITS = 300;

    private const FACTURES = 40;

    /**
     * La taille mesurée au lot du HTML de la grille avec la carte par mois
     * au mois de départ et 12 000 achats (689 955 octets), plus 50 %.
     */
    private const TAILLE_MAXIMALE_DU_HTML_PAR_MOIS = 1_035_000;

    /**
     * La taille mesurée au lot du HTML de la grille avec la carte des
     * douze mois et 12 000 achats (8 760 606 octets : une ligne de six
     * cellules par achat affiché, la carte ne limite pas ses tableaux),
     * plus 50 %.
     */
    private const TAILLE_MAXIMALE_DU_HTML_SUR_DOUZE_MOIS = 13_141_000;

    public function test_la_page_de_12_000_achats_lit_les_lignes_une_seule_fois_n_ecrit_que_l_affichage_et_le_mois_choisis_et_reste_bornee(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $this->semer($magasin);

        $this->assertSame(12000, DB::table('lignes_achat')->where('magasin_id', $magasin->getKey())->count());
        $this->assertSame(self::PRODUITS, DB::table('produits')->where('magasin_id', $magasin->getKey())->count());

        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe()->firstOrFail(), $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));

        DB::enableQueryLog();
        $grille = Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'societe']);
        $lectures = collect(DB::getQueryLog())->filter(fn (array $requete): bool => str_contains((string) $requete['query'], '"lignes_achat"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $lectures, 'La lecture des lignes d\'achat est une seule requête.');
        $parMois = $grille->html();

        $this->assertStringContainsString('Écart total du mois : nov. 2026', $parMois);
        $this->assertStringContainsString('Comment nov. 2026 est calculé, achat par achat', $parMois);
        $this->assertStringNotContainsString('Comment mars 2026 est calculé', $parMois, 'Le calcul d\'un mois non choisi n\'est pas écrit.');
        $this->assertStringNotContainsString('Comment les 12 mois', $parMois, 'Le calcul de l\'affichage caché n\'est pas écrit.');
        $this->assertStringNotContainsString('Prix de départ de l’année', $parMois);
        $this->assertSame(1, substr_count($parMois, 'Comment '));
        $this->assertLessThanOrEqual(self::TAILLE_MAXIMALE_DU_HTML_PAR_MOIS, strlen($parMois));

        $grille->call('changerLAffichage', 'ecart_des_prix_d_achat', 'debut');
        $surDouzeMois = $grille->html();

        $this->assertStringContainsString('Comment les 12 mois (déc. 2025 à nov. 2026) sont calculés, achat par achat', $surDouzeMois);
        $this->assertStringNotContainsString('Prix de départ du mois', $surDouzeMois, 'Le calcul de l\'affichage caché n\'est pas écrit.');
        $this->assertStringNotContainsString('data-ecart="graphique"', $surDouzeMois);
        $this->assertLessThanOrEqual(self::TAILLE_MAXIMALE_DU_HTML_SUR_DOUZE_MOIS, strlen($surDouzeMois));
    }

    /**
     * 300 produits et 40 factures d'achat validées de 300 lignes (une ligne
     * par produit, 5,00 € à 5,89 € HT), datées de décembre 2025 à novembre
     * 2026 ; la première fait naître les produits.
     */
    private function semer(Magasin $magasin): void
    {
        $mois = Mois::du('2025-12', '2026-11');

        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $mois): void {
            $auteur = self::responsableDeTest($magasin);
            $fournisseur = Fournisseur::factory()->create(['nom' => 'Maison Verdier']);

            for ($facture = 0; $facture < self::FACTURES; $facture++) {
                $lignes = [];

                for ($produit = 0; $produit < self::PRODUITS; $produit++) {
                    $centimes = 500 + ($produit * 7 + $facture * 13) % 90;
                    $ligne = ['quantite' => 1 + ($produit + $facture) % 20, 'prix_unitaire_ht' => sprintf('%d.%02d', intdiv($centimes, 100), $centimes % 100), 'taux_tva' => 20];
                    $lignes[] = $facture === 0
                        ? [...$ligne, 'produit' => ['nom' => sprintf('Article %03d', $produit), 'reference' => sprintf('ART-%03d', $produit), 'prix_vente_ttc' => '19.90', 'taux_tva' => '20']]
                        : [...$ligne, 'produit_id' => $produits[$produit]];
                }

                $jour = sprintf('%s-%02d', $mois[intdiv($facture * 12, self::FACTURES)], 1 + $facture % 28);
                $achat = app(ValiderFactureAchatAction::class)->handle($auteur, app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, $lignes, ['date_facture' => $jour]), (string) Str::uuid());

                if ($facture === 0) {
                    $produits = [];

                    foreach ($achat->lignes()->orderBy('id')->get() as $rang => $ligneAchat) {
                        $produits[$rang] = (int) $ligneAchat->produit_id;
                    }
                }
            }
        });
    }
}
