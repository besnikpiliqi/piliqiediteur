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
use Tests\Fixtures\EcartDesPrixDAchat\LectureDeLaPage;
use Tests\SemeSansTransaction;
use Tests\TestCase;

/**
 * Le volume de la page de la carte « Écart des prix d'achat » (décision de
 * l'éditeur du 21/11/2026, D76, B8), au 13 novembre 2026 : 300 produits et
 * 12 000 lignes d'achat validées, créés par les vraies actions (40 factures
 * de 300 lignes, une ligne par produit ; la première facture fait naître les
 * produits). La grille est rendue avec la carte : la lecture des lignes
 * d'achat est une seule requête, la taille du HTML rendu est bornée par un
 * nombre écrit ici d'après la mesure du lot (marge de 50 %), et la page écrit
 * les deux affichages et les douze mois, puisque choisir un mois et le menu
 * « Afficher : » n'appellent ni le serveur ni la base (décision de l'éditeur
 * du 23/11/2026) ; les lignes de leur calcul achat par achat, qui comptent
 * des milliers d'achats, y sont en JSON compact et non en HTML. Aucune limite
 * de temps : aucun test ne dépend du temps réel.
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
     * La taille mesurée du HTML de la grille avec la carte et 12 000 achats,
     * les deux affichages et les douze mois écrits (2 337 434 octets, dont
     * 2 229 046 de lignes de calcul en JSON compact, 24 313 lignes), plus
     * 50 %, arrondie aux 100 000 octets au-dessus. Le JSON écrit chaque
     * classe une fois, dans sa table, au lieu de répéter le préfixe de la
     * feuille dans chaque ligne et chaque cellule : à la première mesure du
     * lot CLIC-MOIS, que chaque redessin de la grille (période, magasin,
     * comparaison, ajout d'un graphique) renvoie en entier, il faisait 4 598 310
     * octets, dont 4 489 922 de JSON. Avant ce lot, le mois de départ seul
     * était écrit en HTML (689 955 octets par mois, 8 760 606 octets pour les
     * douze mois, une ligne de six cellules par achat affiché).
     */
    private const TAILLE_MAXIMALE_DU_HTML = 3_600_000;

    public function test_la_page_de_12_000_achats_lit_les_lignes_une_seule_fois_ecrit_les_deux_affichages_et_reste_bornee(): void
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
        $page = $grille->html();

        $this->assertStringContainsString('Écart total du mois : nov. 2026', $page);
        $this->assertStringContainsString('Comment nov. 2026 est calculé, achat par achat', $page);
        $this->assertStringContainsString('Comment mars 2026 est calculé, achat par achat', $page, 'Le calcul de chaque mois est écrit : choisir un mois n\'appelle pas le serveur.');
        $this->assertStringContainsString('Comment les 12 mois (déc. 2025 à nov. 2026) sont calculés, achat par achat', $page, 'Le calcul des douze mois est écrit : le menu « Afficher : » n\'appelle pas le serveur.');
        $this->assertSame(13, substr_count($page, 'Comment '));
        $this->assertSame(1, substr_count($page, 'data-ecart="graphique"'), 'Le dessin, le même d\'un mois à l\'autre, est écrit une fois.');
        $this->assertSame(0, substr_count($page, '<tr><td'), 'Les lignes du calcul ne sont pas écrites en HTML.');

        $debutDuJson = strpos($page, '<script type="application/json" data-ecart="calculs">');
        $this->assertIsInt($debutDuJson, 'La page porte les lignes du calcul en JSON.');
        $debutDuJson += strlen('<script type="application/json" data-ecart="calculs">');
        $json = substr($page, $debutDuJson, (int) strpos($page, '</script>', $debutDuJson) - $debutDuJson);
        $calculs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(count($calculs['classes']) - 1, substr_count($json, LectureDeLaPage::PREFIXE_DES_CLASSES), 'Le préfixe des classes n\'est écrit que dans la table des classes, une fois par classe, jamais dans une ligne ni une cellule : répété, il en faisait près de la moitié.');
        $lignes = array_sum(array_map('count', $calculs['avant'])) + count($calculs['debut'][0]);
        $this->assertGreaterThan(12000, $lignes, 'Les lignes de chaque mois et des douze mois, une par achat affiché, sont dans le JSON.');

        $this->assertLessThanOrEqual(self::TAILLE_MAXIMALE_DU_HTML, strlen($page));
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
