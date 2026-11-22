<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\RegulariserUneExonerationNonProuveeAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChiffresDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les graphiques du catalogue (Graphique::NOMBRE) et les quatre chiffres clés
 * du tableau de bord (décision de l'éditeur du 14/11/2026, lot GRAPH), sur des montants
 * calculés à la main : les montants enregistrés sur les documents émis ou
 * validés, additionnés en centimes, chaque opération dans le mois de son
 * magasin, jamais un brouillon, un magasin hors
 * de la portée ni une autre société.
 *
 * Le magasin du jeu de données (préparer()), à Paris, au 15 octobre 2026 à
 * midi :
 * - le 3 août (lundi), une facture à l'Atelier Martin (professionnel) d'une
 *   lampe à 24,00 € TTC (20,00 € HT, 4,00 € de TVA), jamais payée, échue le
 *   2 septembre ;
 * - le 10 septembre, l'achat de 10 câbles à 5,00 € HT chez Grossiste Est
 *   (50,00 € HT, 10,00 € de TVA déductible) ;
 * - le 15 septembre (mardi), un ticket à Julie Petit (particulier) de
 *   3 câbles à 12,00 € TTC (30,00 € HT, 6,00 € de TVA), payé en espèces ;
 * - le 1er octobre (jeudi), une facture à l'Atelier Martin d'un câble et
 *   d'une réparation à 60,00 € TTC (60,00 € HT, 12,00 € de TVA, 72,00 €
 *   TTC), échue le 31 octobre ;
 * - le 5 octobre (lundi), l'avoir d'un câble du ticket de Julie Petit
 *   (10,00 € HT, 2,00 € de TVA) ;
 * - le 10 octobre, 20,00 € reçus par carte sur la facture du 1er octobre ;
 * - le 12 octobre, un brouillon de facture d'une lampe, jamais émis ;
 * - le 14 octobre (mercredi), un ticket au client comptoir d'une lampe
 *   (20,00 € HT, 4,00 € de TVA), payé en espèces.
 */
class SeriesDesGraphiquesTest extends TestCase
{
    use RefreshDatabase;

    /** Les douze derniers mois au 15 octobre 2026 : de novembre 2025 à octobre 2026. */
    private const AOUT = 9;

    private const SEPTEMBRE = 10;

    private const OCTOBRE = 11;

    /**
     * Chaque graphique du catalogue, ses étiquettes et ses jeux attendus
     * sur les douze derniers mois du jeu de données.
     *
     * @return array<string, array{0: Graphique, 1: list<string>|null, 2: array<string, list<int|null>>, 3?: array<int, string>}>
     */
    public static function graphiques(): array
    {
        $parMois = fn (int $aout, int $septembre, int $octobre): array => array_replace(array_fill(0, 12, 0), [self::AOUT => $aout, self::SEPTEMBRE => $septembre, self::OCTOBRE => $octobre]);

        return [
            'chiffre d\'affaires HT par mois' => [Graphique::ChiffreDAffairesParMois, null, ['Chiffre d\'affaires HT' => $parMois(2000, 3000, 7000)]],
            'ventes et achats HT par mois' => [Graphique::VentesEtAchatsParMois, null, ['Ventes HT' => $parMois(2000, 3000, 7000), 'Achats HT' => $parMois(0, 5000, 0)]],
            'TVA à payer par mois' => [Graphique::TvaAPayerParMois, null, ['TVA collectée' => $parMois(400, 600, 1400), 'TVA déductible des achats' => $parMois(0, 1000, 0), 'TVA déductible des charges' => $parMois(0, 0, 0), 'TVA à payer' => $parMois(400, -400, 1400), 'TVA à payer (estimation)' => $parMois(400, -400, 1400)]],
            'marge brute HT par mois' => [Graphique::MargeBruteParMois, null, ['Marge brute HT' => $parMois(0, 1500, 0)], [self::SEPTEMBRE => 'Taux de marge : 50,0 %']],
            'chiffre d\'affaires par magasin' => [Graphique::ChiffreDAffairesParMagasin, ['Ma Boutique'], ['Chiffre d\'affaires HT' => [12000]]],
            'ventes par taux de TVA' => [Graphique::VentesParTauxDeTva, ['20 %'], ['Ventes HT' => [12000]]],
            'particuliers et professionnels' => [Graphique::ParticuliersEtProfessionnels, ['Particuliers', 'Professionnels'], ['Ventes HT' => [4000, 8000]]],
            'encaissements par mode de paiement' => [Graphique::EncaissementsParMode, ['Virement', 'Chèque', 'Espèces', 'Carte bancaire', 'Prélèvement', 'Crédit d\'avoir utilisé'], ['Encaissements TTC' => [0, 0, 6000, 2000, 0, 0]]],
            'meilleurs produits' => [Graphique::MeilleursProduits, ['Réparation', 'Lampe', 'Câble'], ['Chiffre d\'affaires HT' => [5000, 4000, 3000]]],
            'meilleurs clients' => [Graphique::MeilleursClients, ['Atelier Martin', 'Julie Petit'], ['Chiffre d\'affaires HT' => [8000, 2000]]],
            // Toutes les ventes du jeu de données sont celles du responsable de test : 120,00 € HT, avoir de 10,00 € déduit.
            'ventes par vendeur' => [Graphique::VentesParVendeur, ['Responsable de test'], ['Chiffre d\'affaires HT' => [12000]]],
            'achats par fournisseur' => [Graphique::AchatsParFournisseur, ['Grossiste Est'], ['Achats HT' => [5000]]],
            'factures à encaisser par ancienneté' => [Graphique::FacturesParAnciennete, ['À échoir', '1 à 30 j de retard', '31 à 60 j de retard', 'Plus de 60 j de retard'], ['Restant dû' => [5200, 0, 2400, 0]]],
            'valeur du stock au coût moyen' => [Graphique::ValeurDuStock, null, ['Valeur du stock HT' => $parMois(0, 3500, 3500)]],
            'nombre de ventes et panier moyen' => [Graphique::NombreDeVentesEtPanierMoyen, null, ['Nombre de ventes' => $parMois(1, 1, 2), 'Panier moyen TTC' => $parMois(2400, 3600, 4800)]],
            'avoirs émis par mois' => [Graphique::AvoirsParMois, null, ['Avoirs HT' => $parMois(0, 0, 1000)]],
            // Du samedi 1er novembre 2025 au jeudi 15 octobre 2026 : 49 vendredis, 50 de chaque autre jour.
            'ventes par jour de la semaine' => [Graphique::VentesParJourDeLaSemaine, ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'], ['Chiffre d\'affaires HT moyen' => [20, 60, 40, 120, 0, 0, 0]]],
            'articles et services' => [Graphique::ArticlesEtServices, ['Articles', 'Services'], ['Ventes HT' => [7000, 5000]]],
            // Ni jeu ni valeur par mois : le calcul de la carte est lu à part (voir EcartDesPrixDAchatLectureTest) ; un seul achat ne se compare à aucun autre.
            'écart des prix d\'achat' => [Graphique::EcartDesPrixDAchat, null, []],
        ];
    }

    /**
     * Un test par graphique : ses valeurs, calculées à la main sur le jeu
     * de données, avoirs déduits, le brouillon jamais compté, le client
     * comptoir hors des meilleurs clients.
     *
     * @param  list<string>|null  $etiquettes  null pour les douze mois
     * @param  array<string, list<int|null>>  $jeux
     * @param  array<int, string>  $infoBulles
     */
    #[DataProvider('graphiques')]
    public function test_chaque_graphique_compte_les_montants_enregistres_des_documents_emis(Graphique $graphique, ?array $etiquettes, array $jeux, array $infoBulles = []): void
    {
        $magasin = $this->preparer();

        $serie = $this->series($this->perimetreDuProprietaire($magasin), [$graphique])[$graphique->value];

        $this->assertSame($jeux, $this->valeurs($serie));
        $this->assertSame($etiquettes ?? self::douzeMois(), $serie->etiquettes);
        $this->assertSame($infoBulles, $serie->infoBulles);
        $this->assertNull($serie->note, 'Le propriétaire voit tout : aucun magasin n\'est écarté.');
        $this->assertSame($graphique === Graphique::EcartDesPrixDAchat, $serie->ecartDesPrixDAchat !== null, 'Seule la carte de l\'écart des prix d\'achat porte son calcul.');
    }

    /**
     * Les quatre chiffres clés d'octobre 2026 : 70,00 € de chiffre
     * d'affaires (60,00 € de la facture, 20,00 € du ticket comptoir, moins
     * 10,00 € d'avoir), aucun achat, 14,00 € de TVA collectée et rien de
     * déductible, et 76,00 € restant dus, dont les 24,00 € de la facture
     * d'août en retard ; aucune variation sans rien en octobre 2025.
     */
    public function test_les_chiffres_cles_sont_ceux_du_mois_en_cours(): void
    {
        $magasin = $this->preparer();

        $chiffres = $this->chiffres($this->perimetreDuProprietaire($magasin));

        $this->assertSame([
            'ca' => ['valeur' => 7000, 'variation' => null, 'ton' => 'neutre'],
            'achats' => ['valeur' => 0, 'variation' => null, 'ton' => 'neutre'],
            'tva' => ['franchise' => false, 'a_payer' => 1400, 'collectee' => 1400, 'deductible' => 0, 'estimation' => null, 'avec_les_charges' => true, 'sans_celles_de_la_societe' => false],
            'restant_du' => ['valeur' => 7600, 'en_retard' => 2400, 'ton_du_retard' => 'negatif'],
        ], $chiffres);
    }

    /**
     * La variation d'un chiffre clé compare le même mois de l'année
     * précédente : 30,00 € en septembre 2026 contre 20,00 € en septembre
     * 2025, « +50,0 % sur un an ».
     */
    public function test_la_variation_compare_le_meme_mois_un_an_plus_tot(): void
    {
        $this->travelTo(self::aParis('2025-09-10 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '36.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $lampe->id, 'quantite' => 1]]);
        $this->travelTo(self::aParis('2026-09-10 10:00:00'));
        self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1]]);
        $this->travelTo(self::aParis('2026-09-20 10:00:00'));

        $chiffres = $this->chiffres($this->perimetreDuProprietaire($magasin));

        $this->assertSame(['valeur' => 3000, 'variation' => '+50,0 % sur un an', 'ton' => 'positif'], $chiffres['ca']);
    }

    /**
     * La case « Comparer avec l'année précédente » ajoute à chaque graphique
     * par mois la même période un an plus tôt : la vente de septembre 2025
     * y apparaît sous le mois de septembre 2026.
     */
    public function test_la_comparaison_ajoute_la_meme_periode_un_an_plus_tot(): void
    {
        $this->travelTo(self::aParis('2025-09-10 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $lampe->id, 'quantite' => 1]]);
        $this->travelTo(self::aParis('2026-09-10 10:00:00'));
        self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $lampe->id, 'quantite' => 2]]);
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));

        $serie = $this->series($this->perimetreDuProprietaire($magasin), [Graphique::ChiffreDAffairesParMois], comparer: true)[Graphique::ChiffreDAffairesParMois->value];

        $cetteAnnee = array_replace(array_fill(0, 12, 0), [self::SEPTEMBRE => 4000]);
        $anneePrecedente = array_replace(array_fill(0, 12, 0), [self::SEPTEMBRE => 2000]);
        $this->assertSame(['Chiffre d\'affaires HT' => $cetteAnnee, 'Chiffre d\'affaires HT (un an plus tôt)' => $anneePrecedente], $this->valeurs($serie));
    }

    /**
     * Le brouillon n'entre nulle part : seuls comptent les documents émis.
     * Le jeu de données porte une facture brouillon d'une lampe ; une fois
     * émise, elle ajoute ses 20,00 € HT au chiffre d'affaires d'octobre.
     */
    public function test_un_brouillon_n_est_jamais_compte(): void
    {
        $magasin = $this->preparer();
        $avant = $this->series($this->perimetreDuProprietaire($magasin), [Graphique::ChiffreDAffairesParMois])[Graphique::ChiffreDAffairesParMois->value];
        app(TenantContext::class)->set($magasin);
        app(EmettreFactureAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->where('statut', 'brouillon')->sole());

        $apres = $this->series($this->perimetreDuProprietaire($magasin), [Graphique::ChiffreDAffairesParMois])[Graphique::ChiffreDAffairesParMois->value];

        $this->assertSame(7000, $avant->jeux[0]['valeurs'][self::OCTOBRE]);
        $this->assertSame(9000, $apres->jeux[0]['valeurs'][self::OCTOBRE]);
    }

    /**
     * Un administrateur à qui le propriétaire n'ouvre que Lyon ne compte
     * jamais Villeurbanne : ni dans le chiffre d'affaires par magasin, ni
     * dans aucune somme, ni dans le filtre des magasins.
     */
    public function test_un_magasin_hors_de_la_portee_n_est_jamais_compte(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->vendreUneLampe($lyon, '24.00');
        $this->vendreUneLampe($villeurbanne, '120.00');
        $administrateur = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: $this->modeleAvecLesGraphiques($lyon));
        app(TenantContext::class)->clear();

        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe);
        $series = $this->series($perimetre, [Graphique::ChiffreDAffairesParMagasin, Graphique::ChiffreDAffairesParMois]);

        $this->assertSame([$lyon->id], $perimetre->magasins()->modelKeys());
        $this->assertSame(['Lyon Centre'], $series[Graphique::ChiffreDAffairesParMagasin->value]->etiquettes);
        $this->assertSame([2000], $series[Graphique::ChiffreDAffairesParMagasin->value]->jeux[0]['valeurs']);
        $this->assertSame(2000, array_sum($series[Graphique::ChiffreDAffairesParMois->value]->jeux[0]['valeurs']));
    }

    /**
     * Dans un magasin de sa portée où il ne voit que ses propres ventes,
     * l'administrateur ne voit pas ce magasin compté : il sort des
     * graphiques des ventes, et une phrase grise le dit sans le nommer ;
     * les graphiques qui n'ont besoin que des avoirs le comptent encore.
     */
    public function test_un_magasin_ou_l_on_ne_voit_qu_une_partie_des_ventes_sort_du_graphique_sans_etre_nomme(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->vendreUneLampe($lyon, '24.00');
        $this->vendreUneLampe($villeurbanne, '120.00');
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => $this->modeleAvecLesGraphiques($lyon),
            $villeurbanne->id => array_values(array_diff($this->modeleAvecLesGraphiques($lyon), [PermissionRegistry::FACTURE_VIEW_TOUTES])),
        ]);
        app(TenantContext::class)->clear();

        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe);
        $serie = $this->series($perimetre, [Graphique::ChiffreDAffairesParMagasin])[Graphique::ChiffreDAffairesParMagasin->value];

        $this->assertSame(['Lyon Centre'], $serie->etiquettes);
        $this->assertSame([2000], $serie->jeux[0]['valeurs']);
        $this->assertSame('1 magasin n\'est pas compté : vous n\'y voyez pas toutes les ventes.', $serie->note);
        $this->assertStringNotContainsString('Villeurbanne', (string) $serie->note);
    }

    /**
     * Une autre société n'est jamais comptée : sa vente du même jour, du
     * même montant, n'entre dans aucun graphique de la première.
     */
    public function test_une_autre_societe_n_est_jamais_comptee(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $this->vendreUneLampe($magasin, '24.00');
        $this->vendreUneLampe($autre, '480.00');
        app(TenantContext::class)->clear();

        $perimetre = $this->perimetreDuProprietaire($magasin);
        $series = $this->series($perimetre, [Graphique::ChiffreDAffairesParMois, Graphique::ChiffreDAffairesParMagasin]);

        $this->assertSame([$magasin->id], $perimetre->magasins()->modelKeys());
        $this->assertSame(2000, array_sum($series[Graphique::ChiffreDAffairesParMois->value]->jeux[0]['valeurs']));
        $this->assertSame(['Ma Boutique'], $series[Graphique::ChiffreDAffairesParMagasin->value]->etiquettes);
    }

    /**
     * Le garde des services des sources : une lecture des ventes d'un
     * magasin hors du périmètre, ou d'une source que le compte n'y voit
     * pas, est refusée sans rien lire.
     */
    public function test_le_garde_refuse_un_magasin_ou_une_source_hors_du_perimetre(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: array_values(array_diff($this->modeleAvecLesGraphiques($lyon), [PermissionRegistry::CLIENT_VIEW])));
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe);

        foreach ([$villeurbanne, $autre] as $horsDuPerimetre) {
            try {
                app(VentesDuTableauDeBord::class)->documents($perimetre, new Collection([$horsDuPerimetre]), '2026-01-01', '2026-12-31');
                $this->fail('Une lecture hors du périmètre doit être refusée.');
            } catch (AuthorizationException $refus) {
                $this->assertSame(PerimetreDesGraphiques::REFUS, $refus->getMessage());
            }

            try {
                app(AchatsDuTableauDeBord::class)->lignes($perimetre, new Collection([$horsDuPerimetre]), '2026-01-01', '2026-12-31');
                $this->fail('Une lecture des lignes d\'achat hors du périmètre doit être refusée.');
            } catch (AuthorizationException $refus) {
                $this->assertSame(PerimetreDesGraphiques::REFUS, $refus->getMessage());
            }
        }

        $this->expectException(AuthorizationException::class);
        $perimetre->exiger(new Collection([$lyon]), SourceDesGraphiques::Clients);
    }

    /**
     * Les lignes d'achat de la carte « Écart des prix d'achat » ne se lisent
     * que dans un magasin où le compte voit les achats : un administrateur
     * à qui le propriétaire n'a pas coché « Factures d'achat : voir » est
     * refusé sans rien lire, et la carte ne lui est pas proposée.
     */
    public function test_les_lignes_d_achat_ne_se_lisent_pas_sans_voir_les_achats(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $administrateur = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: array_values(array_diff($this->modeleAvecLesGraphiques($lyon), [PermissionRegistry::FACTURE_ACHAT_VIEW])));
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe);

        $this->assertNotContains(Graphique::EcartDesPrixDAchat, $perimetre->graphiquesVisibles());
        $this->assertThrows(fn () => app(AchatsDuTableauDeBord::class)->lignes($perimetre, new Collection([$lyon]), '2026-01-01', '2026-12-31'), AuthorizationException::class, PerimetreDesGraphiques::REFUS);
        $this->assertThrows(fn () => $this->series($perimetre, [Graphique::EcartDesPrixDAchat]), AuthorizationException::class, PerimetreDesGraphiques::REFUS);
    }

    /**
     * Une opération appartient au mois de son magasin : une vente faite à
     * Fort-de-France le 31 janvier 2026 à 23 h 30, le 1er février à 4 h 30
     * à Paris, compte en janvier.
     */
    public function test_une_vente_a_23_h_30_en_martinique_le_31_janvier_compte_en_janvier(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-01-31 23:30:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee(Territoire::Martinique));
        $this->vendreUneLampe($magasin, '24.00');
        $this->travelTo(self::aParis('2026-03-10 12:00:00'));

        $serie = $this->series($this->perimetreDuProprietaire($magasin), [Graphique::ChiffreDAffairesParMois], PeriodeDuTableauDeBord::AnneeEnCours)[Graphique::ChiffreDAffairesParMois->value];

        // La lampe vendue à 24,00 € TTC à 8,5 % : 22,12 € HT (TVA 1,88 €).
        $this->assertSame([2212, 0, 0], array_slice($serie->jeux[0]['valeurs'], 0, 3));
    }

    /**
     * Un exercice décalé, clos fin juin : « Exercice en cours » va de
     * juillet 2026 à juin 2027 au 15 octobre 2026, et ses mois à venir n'ont
     * pas de valeur ; le chiffre d'affaires par exercice se lit dans
     * DecoupageDesGraphiquesTest.
     */
    public function test_un_exercice_decale_a_juin_compte_de_juillet_a_juin(): void
    {
        $this->travelTo(self::aParis('2026-05-10 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['mois_fin_exercice' => 6]));
        $this->vendreUneLampe($magasin, '24.00');
        $this->travelTo(self::aParis('2026-08-10 10:00:00'));
        $this->vendreUneLampe($magasin, '48.00');
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));
        $perimetre = $this->perimetreDuProprietaire($magasin);

        $parMois = $this->series($perimetre, [Graphique::ChiffreDAffairesParMois], PeriodeDuTableauDeBord::ExerciceEnCours)[Graphique::ChiffreDAffairesParMois->value];

        $this->assertCount(12, $parMois->etiquettes);
        $this->assertSame(array_replace(array_fill(0, 12, null), [0 => 0, 1 => 4000, 2 => 0, 3 => 0]), $parMois->jeux[0]['valeurs'], 'De juillet 2026 à juin 2027 : les mois à venir n\'ont pas de valeur.');
        $this->assertSame('Exercice en cours (juil. 2026 à juin 2027)', PeriodeDuTableauDeBord::ExerciceEnCours->libelle('2026-10-15', 6));
    }

    /**
     * Une société en franchise en base ne collecte ni ne déduit aucune
     * TVA : le graphique de la TVA à payer n'est pas proposé, et le chiffre
     * clé dit « TVA non applicable (franchise en base) ».
     */
    public function test_la_franchise_en_base_n_a_pas_de_tva_a_estimer(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $this->vendreUneLampe($magasin, '24.00');
        $perimetre = $this->perimetreDuProprietaire($magasin);

        $chiffres = $this->chiffres($perimetre);

        $this->assertFalse($perimetre->voit(Graphique::TvaAPayerParMois));
        $this->assertSame(['franchise' => true, 'a_payer' => 0, 'collectee' => 0, 'deductible' => 0, 'estimation' => null, 'avec_les_charges' => false, 'sans_celles_de_la_societe' => false], $chiffres['tva']);
        $this->assertSame(['valeur' => 2400, 'variation' => null, 'ton' => 'neutre'], $chiffres['ca'], 'À 0 %, la lampe se vend 24,00 € HT.');
    }

    /**
     * Une société « Services, sans vente d'articles » n'a ni achats, ni
     * marge, ni stock, ni part des articles : ces graphiques ne sont pas
     * proposés, pas plus que le chiffre clé des achats ; sa TVA à payer ne
     * compte que ses ventes.
     */
    public function test_une_societe_de_services_n_a_ni_achats_ni_marge_ni_stock(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['type' => TypeDActivite::Services]));
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        self::vendreAuComptant($responsable, Client::factory()->create(), [['service_id' => Service::factory()->auPrixDe('60.00')->create(['nom' => 'Réparation'])->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1]]);
        $perimetre = $this->perimetreDuProprietaire($magasin);

        $visibles = $perimetre->graphiquesVisibles();
        $chiffres = $this->chiffres($perimetre);
        $tva = $this->series($perimetre, [Graphique::TvaAPayerParMois])[Graphique::TvaAPayerParMois->value];

        foreach ([Graphique::VentesEtAchatsParMois, Graphique::MargeBruteParMois, Graphique::AchatsParFournisseur, Graphique::ValeurDuStock, Graphique::ArticlesEtServices, Graphique::EcartDesPrixDAchat] as $absent) {
            $this->assertNotContains($absent, $visibles, $absent->getLabel());
        }

        $this->assertNull($chiffres['achats']);
        $this->assertSame(['franchise' => false, 'a_payer' => 1000, 'collectee' => 1000, 'deductible' => 0, 'estimation' => null, 'avec_les_charges' => true, 'sans_celles_de_la_societe' => false], $chiffres['tva']);
        $this->assertSame(['TVA à payer', 1000], [$tva->jeux[3]['libelle'], $tva->jeux[3]['valeurs'][self::OCTOBRE]]);
    }

    /**
     * Une exonération non prouvée se régularise par un avoir sans retour
     * en stock et une facture rectificative sans sortie de stock : la
     * marge du mois de la régularisation en déduit le HT de l'avoir et y
     * ajoute celui de la rectificative, sans coût de plus. Une lampe suivie
     * en stock, achetée 52,78 € HT, exportée le 2 octobre 2026 à 500,00 €
     * HT (600,00 € TTC sans la TVA française), sans preuve de sortie le
     * 1er mars 2027 : octobre fait 447,22 € de marge (500,00 − 52,78), mars
     * −83,33 € (−500,00 + 416,67), soit 363,89 € en tout, le chiffre
     * d'affaires des articles moins leur coût (416,67 − 52,78).
     */
    public function test_une_exoneration_regularisee_corrige_la_marge_du_mois_de_sa_regularisation(): void
    {
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '600.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(['nom' => 'Grossiste Est']), [
            ['produit_id' => $lampe->id, 'quantite' => 5, 'prix_unitaire_ht' => '52.78', 'taux_tva' => 20],
        ], ['date_facture' => '2026-10-01']));

        $this->travelTo(self::aParis('2026-10-02 10:00:00'));
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $export = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $lampe->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $rectificative = app(RegulariserUneExonerationNonProuveeAction::class)->handle($responsable, $export->fresh() ?? $export);
        $this->assertSame(['500.00', '416.67'], [(string) $export->total_ht, (string) $rectificative->total_ht]);

        $this->travelTo(self::aParis('2027-03-15 12:00:00'));
        $serie = $this->series($this->perimetreDuProprietaire($magasin), [Graphique::MargeBruteParMois])[Graphique::MargeBruteParMois->value];
        $marge = array_combine($serie->etiquettes, $serie->jeux[0]['valeurs']);

        $this->assertSame([44722, -8333], [$marge['oct. 2026'], $marge['mars 2027']]);
        $this->assertSame(36389, array_sum($marge));
    }

    /**
     * Le jeu de données décrit en tête de la classe ; le propriétaire
     * regarde le 15 octobre 2026 à midi.
     */
    private function preparer(): Magasin
    {
        $this->travelTo(self::aParis('2026-07-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $reparation = Service::factory()->auPrixDe('60.00')->create(['nom' => 'Réparation']);
        $atelier = Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']);
        $julie = Client::factory()->create(['nom' => 'Julie Petit']);
        $grossiste = Fournisseur::factory()->create(['nom' => 'Grossiste Est']);

        $this->travelTo(self::aParis('2026-08-03 10:00:00'));
        app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $atelier, [['produit_id' => $lampe->id, 'quantite' => 1]]));

        $this->travelTo(self::aParis('2026-09-10 10:00:00'));
        app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, $grossiste, [
            ['produit_id' => $cable->id, 'quantite' => 10, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
        ], ['date_facture' => '2026-09-10']));

        $this->travelTo(self::aParis('2026-09-15 10:00:00'));
        $ticket = self::vendreAuComptant($responsable, $julie, [['produit_id' => $cable->id, 'quantite' => 3]]);

        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $atelier, [
            ['produit_id' => $cable->id, 'quantite' => 1],
            ['service_id' => $reparation->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1],
        ]));

        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle($responsable, $julie, [['ligne_facture_id' => $ticket->lignes()->value('id'), 'quantite' => 1]], 'Retour');

        $this->travelTo(self::aParis('2026-10-10 10:00:00'));
        app(EnregistrerPaiementAction::class)->handle($responsable, $atelier, ['mode_paiement' => ModePaiement::CarteBancaire->value, 'montant' => '20.00', 'recu_le' => '2026-10-10'], [['facture_id' => $facture->id, 'montant' => '20.00']]);

        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        app(CreerFactureAction::class)->handle($responsable, $atelier, [['produit_id' => $lampe->id, 'quantite' => 1]]);

        $this->travelTo(self::aParis('2026-10-14 10:00:00'));
        self::vendreAuComptant($responsable, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $lampe->id, 'quantite' => 1]]);

        $this->travelTo(self::aParis('2026-10-15 12:00:00'));
        app(TenantContext::class)->clear();

        return $magasin;
    }

    /**
     * Une lampe à ce prix TTC, vendue au comptant dans ce magasin par son
     * responsable de test ; aucun contexte n'est actif ensuite.
     */
    private function vendreUneLampe(Magasin $magasin, string $prixTtc): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin, $prixTtc): Facture {
            $responsable = self::responsableDeTest($magasin);
            $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $magasin->tauxTvaParDefaut(), 'suivi_stock' => false]);

            return self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $lampe->id, 'quantite' => 1]]);
        });
    }

    /**
     * Le modèle de l'administrateur dans ce type d'activité, avec la case
     * des graphiques, que le propriétaire coche.
     *
     * @return list<string>
     */
    private function modeleAvecLesGraphiques(Magasin $magasin): array
    {
        return [...PermissionRegistry::modeleDeLAdministrateur($magasin->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR];
    }

    private function perimetreDuProprietaire(Magasin $magasin): Perimetre
    {
        app(TenantContext::class)->clear();

        return app(PerimetreDesGraphiques::class)->deLaSociete($this->proprietaireDe($magasin), $magasin->societe()->firstOrFail());
    }

    /**
     * Les séries de ces graphiques sur ce périmètre.
     *
     * @param  list<Graphique>  $graphiques
     * @return array<string, SerieDuGraphique>
     */
    private function series(Perimetre $perimetre, array $graphiques, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false): array
    {
        $cartes = array_map(fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique), $graphiques);

        return app(SeriesDesGraphiques::class)->pour(new CalculDesSeries($perimetre, new ReglagesDuTableauDeBord($cartes, $periode, null, $comparer), $cartes));
    }

    /**
     * @return array<string, mixed>
     */
    private function chiffres(Perimetre $perimetre): array
    {
        return app(ChiffresDuTableauDeBord::class)->pour(new CalculDesSeries($perimetre, new ReglagesDuTableauDeBord([]), []));
    }

    /**
     * Les valeurs de chaque jeu, par libellé.
     *
     * @return array<string, list<int|null>>
     */
    private function valeurs(SerieDuGraphique $serie): array
    {
        return array_combine(array_column($serie->jeux, 'libelle'), array_column($serie->jeux, 'valeurs'));
    }

    /**
     * Les étiquettes des douze derniers mois au 15 octobre 2026.
     *
     * @return list<string>
     */
    private static function douzeMois(): array
    {
        return ['nov. 2025', 'déc. 2025', 'janv. 2026', 'févr. 2026', 'mars 2026', 'avr. 2026', 'mai 2026', 'juin 2026', 'juil. 2026', 'août 2026', 'sept. 2026', 'oct. 2026'];
    }
}
