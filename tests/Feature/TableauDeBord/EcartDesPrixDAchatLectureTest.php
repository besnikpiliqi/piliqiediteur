<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\EcartDesPrixDAchat\AchatsDeLaMaquette;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;
use Tests\TestCase;

/**
 * La carte « Écart des prix d'achat » du tableau de bord lue sur de vrais
 * achats (décision de l'éditeur du 21/11/2026, D76, B3 à B5 et B9), au 13
 * novembre 2026 : les 122 achats du jeu de la maquette, semés par les vraies
 * actions d'achat, donnent mois par mois, pour chacun des deux affichages,
 * la lecture de la maquette ; chaque règle de lecture (date, prix, ce qui ne
 * compte pas, ordre, produit, magasins, isolation) a son test.
 */
class EcartDesPrixDAchatLectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La preuve que la base donne le jeu de la maquette : la série de la
     * carte, lue sur les 122 achats semés par les vraies actions, se lit
     * comme le relevé de la maquette, mot pour mot, nombre pour nombre,
     * pour chacun des 24 états (2 affichages, 12 mois).
     */
    public function test_les_achats_de_la_maquette_donnent_sa_lecture_pour_chacun_des_24_etats(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $produits = AchatsDeLaMaquette::semer($responsable, Fournisseur::factory()->create(['nom' => 'Maison Verdier']));
        app(TenantContext::class)->clear();

        $this->assertSame(array_column(LectureDesFixtures::verite()['jeu']['produits'], 'nom'), array_keys($produits));
        $this->assertSame(array_values($produits), collect($produits)->sort()->values()->all(), 'Les produits naissent dans l\'ordre de la maquette : leur identifiant croît avec lui.');

        $calcul = $this->serie($this->perimetreDuProprietaire($magasin))->ecartDesPrixDAchat;

        $this->assertNotNull($calcul);

        foreach ([TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS] as $affichage) {
            for ($mois = 0; $mois < 12; $mois++) {
                $obtenu = TextesDeLEcartDesPrixDAchat::lecture($calcul, $affichage, $mois);
                $ecart = LectureDesFixtures::premierEcart($obtenu, LectureDesFixtures::verite()['etats'][$affichage][$mois]);

                $this->assertNull($ecart, "Affichage {$affichage}, mois {$mois} : premier écart avec la maquette : {$ecart}");
            }
        }
    }

    /**
     * Un achat se range à la date de la facture du fournisseur, pas à celle
     * de sa validation : une facture datée du 31 janvier et validée le
     * 1er février compte en janvier, où elle est comparée au premier achat
     * du mois, et rien ne compte en février.
     */
    public function test_un_achat_date_du_31_janvier_et_valide_le_1er_fevrier_compte_en_janvier(): void
    {
        $this->travelTo(self::aParis('2026-02-01 12:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-01-10');
        $this->acheter($magasin, $lampe, 3, '6.00', '2026-01-31');

        $serie = $this->serie($this->perimetreDuProprietaire($magasin));

        $this->assertSame([['Lampe', 31, 3, 600, 300]], $this->comparaisons($serie, 10), 'Janvier est le onzième mois des douze derniers mois de février 2026.');
        $this->assertSame([], $this->comparaisons($serie, 11));
    }

    /**
     * Un achat sans date, comme l'API le crée, se range au jour de sa
     * validation dans le calendrier de son magasin : validé à 23 h 30 en
     * Martinique le 31 janvier, soit le 1er février à 4 h 30 à Paris, il
     * compte en janvier.
     */
    public function test_un_achat_sans_date_valide_a_23_h_30_en_martinique_le_31_janvier_compte_en_janvier(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-01-31 23:30:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee(Territoire::Martinique));
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-01-10');
        $this->acheter($magasin, $lampe, 2, '8.00', null, facture: ['source' => SourceFactureAchat::Api]);

        $serie = $this->serie($this->perimetreDuProprietaire($magasin));

        $this->assertSame([['Lampe', 31, 2, 800, 600]], $this->comparaisons($serie, 10));
        $this->assertSame([], $this->comparaisons($serie, 11));
    }

    /**
     * Un achat d'avant la période ne compte pas, et n'est jamais le prix de
     * départ : le premier achat de décembre 2025 (5,00 €) est celui de la
     * période, pas celui de novembre 2025 (9,00 €).
     */
    public function test_un_achat_d_avant_la_periode_n_est_jamais_le_prix_de_depart(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 4, '9.00', '2025-11-20');
        $this->acheter($magasin, $lampe, 2, '5.00', '2025-12-05');
        $this->acheter($magasin, $lampe, 3, '6.00', '2025-12-20');

        $calcul = $this->serie($this->perimetreDuProprietaire($magasin))->ecartDesPrixDAchat;

        $this->assertNotNull($calcul);
        $this->assertSame(['Lampe'], $calcul['produits']);
        $this->assertSame(500, $calcul['calculDeLaPeriode']['lignes'][0]['ref']['c']);
        $this->assertSame([300, 0], [$calcul['calculDeLaPeriode']['hausses'], $calcul['calculDeLaPeriode']['baisses']]);
    }

    /**
     * Un achat daté d'un mois à venir compte comme tout achat quand la
     * période le contient (« Année en cours » va jusqu'en décembre) et
     * n'existe pas pour les douze derniers mois.
     */
    public function test_un_achat_date_d_un_mois_a_venir_compte_dans_l_annee_en_cours_et_pas_dans_les_douze_derniers_mois(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-12-02');
        $this->acheter($magasin, $lampe, 2, '8.00', '2026-12-20');
        $perimetre = $this->perimetreDuProprietaire($magasin);

        $anneeEnCours = $this->serie($perimetre, PeriodeDuTableauDeBord::AnneeEnCours);
        $douzeMois = $this->serie($perimetre);

        $this->assertSame([['Lampe', 20, 2, 800, 600]], $this->comparaisons($anneeEnCours, 11));
        $this->assertSame([], $douzeMois->ecartDesPrixDAchat['produits'] ?? null);
    }

    /**
     * Le prix d'un achat est son coût réel en centimes, calculé depuis
     * l'exact avec un seul arrondi, au centime le plus proche, un
     * demi-centime au centime supérieur : une remise de ligne, un escompte
     * de facture, le HT de la ligne écrit par la facture, un prix à six
     * décimales (4,004950 € fait 4,00 €, 4,005000 € 4,01 €, 4,004999 €
     * 4,00 €) et un prix à 0,00 €. Chaque produit est d'abord acheté 10,00 €
     * le 2 novembre, puis au prix du cas le 3.
     */
    public function test_le_prix_d_un_achat_se_calcule_en_centimes_avec_un_seul_arrondi(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $cas = [
            'remise de ligne de 19,90 % sur 5,00 € (4,005 €)' => [['5.00', 1, ['remise_pourcentage' => '19.90'], []], 401],
            'escompte de facture de 19,90 % sur 5,00 € (4,005 €)' => [['5.00', 1, [], ['escompte_pourcentage' => '19.90']], 401],
            'remise de 10 % puis escompte de 2 % sur 8,33 € (7,34706 €)' => [['8.33', 1, ['remise_pourcentage' => '10'], ['escompte_pourcentage' => '2']], 735],
            'HT de la ligne écrit : 8,01 € pour 2 articles (4,005 €)' => [['4.00', 2, ['montant_ht' => '8.01'], []], 401],
            'six décimales : 4,004950 €' => [['4.004950', 1, [], []], 400],
            'six décimales : 4,005000 €' => [['4.005000', 1, [], []], 401],
            'six décimales : 4,004999 €' => [['4.004999', 1, [], []], 400],
            'un prix à 0,00 €' => [['0.00', 1, [], []], 0],
        ];
        $produits = [];

        foreach ($cas as $nom => [[$prix, $quantite, $ligne, $facture], $attendu]) {
            $produits[$nom] = $this->produit($magasin, $nom);
            $this->acheter($magasin, $produits[$nom], 1, '10.00', '2026-11-02');
            $this->acheter($magasin, $produits[$nom], $quantite, $prix, '2026-11-03', $ligne, $facture);
        }

        $serie = $this->serie($this->perimetreDuProprietaire($magasin));
        $obtenus = array_map(fn (array $comparaison): array => [$comparaison[0], $comparaison[3]], $this->comparaisons($serie, 11));

        $this->assertSame(array_map(fn (string $nom, array $cas): array => [$nom, $cas[1]], array_keys($cas), array_values($cas)), $obtenus);
    }

    /**
     * Ne comptent ni un brouillon d'achat, ni un avoir fournisseur (la
     * quantité d'une ligne ne change pas), ni un inventaire d'ouverture :
     * seules les lignes de factures d'achat validées.
     */
    public function test_un_brouillon_un_avoir_fournisseur_et_un_inventaire_d_ouverture_ne_comptent_pas(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-11-02');
        $achat = $this->acheter($magasin, $lampe, 4, '6.00', '2026-11-05');
        $this->acheter($magasin, $lampe, 9, '20.00', '2026-11-06', valider: false);
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $achat): void {
            $auteur = self::responsableDeTest($magasin);
            app(EmettreAvoirFournisseurAction::class)->handle($auteur, app(CreerAvoirFournisseurAction::class)->handle($auteur, $achat, [['ligne_achat_id' => $achat->lignes->sole()->getKey(), 'quantite' => 2]], 'Produit défectueux'));
            app(ValiderInventaireOuvertureAction::class)->handle($auteur, app(CreerInventaireOuvertureAction::class)->handle($auteur, [['produit' => ['nom' => 'Théière', 'reference' => 'THEIERE', 'prix_vente_ttc' => '30.00', 'taux_tva' => '20'], 'quantite' => 5, 'cout_unitaire_ht' => '3.00']]), (string) Str::uuid());
        });

        $calcul = $this->serie($this->perimetreDuProprietaire($magasin))->ecartDesPrixDAchat;

        $this->assertNotNull($calcul);
        $this->assertSame(['Lampe'], $calcul['produits'], 'L\'inventaire d\'ouverture et son produit ne comptent pas.');
        $this->assertSame([['Lampe', 5, 4, 600, 400]], array_map(fn (array $pas): array => ['Lampe', $pas['e']['j'], $pas['e']['q'], $pas['e']['c'], $pas['ecart']], $calcul['calculs'][11]['pas']));
    }

    /**
     * Deux achats du même produit le même jour, dans deux factures : le rang
     * de validation de la facture les départage, pas l'ordre de création. La
     * facture à 8,00 € est créée la première mais validée la seconde : le
     * prix de départ du jour est celui de la facture à 5,00 €.
     */
    public function test_deux_achats_du_meme_jour_se_depart_agent_par_le_rang_de_validation(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $cherte = $this->acheter($magasin, $lampe, 1, '8.00', '2026-11-04', valider: false);
        $economique = $this->acheter($magasin, $lampe, 2, '5.00', '2026-11-04', valider: false);
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $cherte, $economique): void {
            $auteur = self::responsableDeTest($magasin);
            app(ValiderFactureAchatAction::class)->handle($auteur, $economique, (string) Str::uuid());
            app(ValiderFactureAchatAction::class)->handle($auteur, $cherte, (string) Str::uuid());
        });

        $serie = $this->serie($this->perimetreDuProprietaire($magasin));

        $this->assertSame([['Lampe', 4, 1, 800, 300]], $this->comparaisons($serie, 11));
    }

    /**
     * Une facture validée corrigée après coup par ModifierFactureAchatAction
     * donne son prix corrigé à la carte, sans reste du calcul ancien : la
     * seconde facture passe de 6,00 € à 7,50 € pour 2 articles.
     */
    public function test_une_facture_validee_corrigee_apres_coup_donne_son_prix_corrige(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-11-02');
        $achat = $this->acheter($magasin, $lampe, 2, '6.00', '2026-11-05');
        $perimetre = $this->perimetreDuProprietaire($magasin);
        $this->assertSame([['Lampe', 5, 2, 600, 200]], $this->comparaisons($this->serie($perimetre), 11));

        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $achat, $lampe): void {
            $auteur = self::responsableDeTest($magasin);
            app(ModifierFactureAchatAction::class)->handle($auteur, $achat, $achat->fournisseur()->firstOrFail(), [['produit_id' => $lampe->getKey(), 'quantite' => 2, 'prix_unitaire_ht' => '7.50', 'taux_tva' => 20]], ['date_facture' => '2026-11-05']);
        });

        $this->assertSame([['Lampe', 5, 2, 750, 500]], $this->comparaisons($this->serie($perimetre), 11));
    }

    /**
     * Le nom d'un produit est celui de sa fiche aujourd'hui : renommé entre
     * deux achats, il porte son nouveau nom sur tous ses achats ; archivé, il
     * reste nommé.
     */
    public function test_un_produit_renomme_porte_son_nom_actuel_et_un_produit_archive_reste_nomme(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lampe = $this->produit($magasin, 'Lampe de chevet');
        $bougie = $this->produit($magasin, 'Bougie');
        $this->acheter($magasin, $lampe, 1, '5.00', '2026-11-02');
        $this->acheter($magasin, $bougie, 1, '2.00', '2026-11-02');
        app(TenantContext::class)->runAs($magasin, fn (): bool => $lampe->forceFill(['nom' => 'Lampe de chevet en bois'])->save());
        $this->acheter($magasin, $lampe, 1, '6.00', '2026-11-05');
        $this->acheter($magasin, $bougie, 1, '3.00', '2026-11-05');
        app(TenantContext::class)->runAs($magasin, fn () => $bougie->delete());

        $calcul = $this->serie($this->perimetreDuProprietaire($magasin))->ecartDesPrixDAchat;

        $this->assertNotNull($calcul);
        $this->assertSame(['Lampe de chevet en bois', 'Bougie'], $calcul['produits']);
    }

    /**
     * Deux magasins de la même société qui achètent un produit de même nom le
     * même mois à deux prix ne mélangent jamais leurs prix ; le nom du
     * produit dit son magasin quand plusieurs sont comptés, jamais dans le
     * panneau d'un seul magasin.
     */
    public function test_deux_magasins_ne_melangent_jamais_leurs_prix_et_le_nom_dit_le_magasin(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);

        foreach ([[$lyon, '5.00', '6.00'], [$villeurbanne, '20.00', '22.00']] as [$magasin, $premier, $second]) {
            $lampe = $this->produit($magasin, 'Lampe');
            $this->acheter($magasin, $lampe, 1, $premier, '2026-11-02');
            $this->acheter($magasin, $lampe, 1, $second, '2026-11-05');
        }

        $proprietaire = $this->proprietaireDe($lyon);
        app(TenantContext::class)->clear();
        $deLaSociete = $this->serie(app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe()->firstOrFail()))->ecartDesPrixDAchat;
        $duMagasin = $this->serie(app(PerimetreDesGraphiques::class)->duMagasin($proprietaire, $villeurbanne))->ecartDesPrixDAchat;

        $this->assertNotNull($deLaSociete);
        $this->assertNotNull($duMagasin);
        $this->assertSame(['Lampe · Lyon Centre', 'Lampe · Villeurbanne'], $deLaSociete['produits']);
        $this->assertSame([100, 200, 300], [$deLaSociete['calculs'][11]['pas'][0]['ecart'], $deLaSociete['calculs'][11]['pas'][1]['ecart'], $deLaSociete['calculs'][11]['ecart']]);
        $this->assertSame(['Lampe'], $duMagasin['produits']);
        $this->assertSame(200, $duMagasin['calculs'][11]['ecart']);
    }

    /**
     * Un achat d'une autre société ne change aucun nombre, et la lecture d'un
     * magasin hors du périmètre (une autre société, ou un magasin de la
     * société hors de la portée de l'administrateur) est refusée par le
     * message des graphiques, sans lire aucune ligne.
     */
    public function test_un_achat_d_une_autre_societe_ne_change_rien_et_un_magasin_hors_du_perimetre_est_refuse(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $lampe = $this->produit($lyon, 'Lampe');
        $this->acheter($lyon, $lampe, 1, '5.00', '2026-11-02');
        $this->acheter($lyon, $lampe, 1, '6.00', '2026-11-05');
        $perimetre = $this->perimetreDuProprietaire($lyon);
        $avant = $this->serie($perimetre)->ecartDesPrixDAchat;
        $lampeDUnAutre = $this->produit($autre, 'Lampe');
        $this->acheter($autre, $lampeDUnAutre, 1, '50.00', '2026-11-02');
        $this->acheter($autre, $lampeDUnAutre, 1, '90.00', '2026-11-05');
        $administrateur = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR]);
        app(TenantContext::class)->clear();

        $this->assertSame($avant, $this->serie($this->perimetreDuProprietaire($lyon))->ecartDesPrixDAchat, 'Les achats d\'une autre société ne changent aucun nombre.');

        DB::enableQueryLog();
        $deLAdministrateur = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe()->firstOrFail());

        foreach ([[$perimetre, $autre], [$deLAdministrateur, $villeurbanne]] as [$demandeur, $horsDuPerimetre]) {
            try {
                app(AchatsDuTableauDeBord::class)->lignes($demandeur, new Collection([$horsDuPerimetre]), '2025-12-01', '2026-11-30');
                $this->fail('Une lecture hors du périmètre doit être refusée.');
            } catch (AuthorizationException $refus) {
                $this->assertSame(PerimetreDesGraphiques::REFUS, $refus->getMessage());
            }
        }

        $this->assertSame(0, $this->requetesSur('lignes_achat'), 'Un magasin refusé ne fait rien lire.');
    }

    /**
     * Un magasin où l'on ne voit pas les achats sort du calcul, et la phrase
     * grise le dit sans le nommer.
     */
    public function test_un_magasin_ou_l_on_ne_voit_pas_les_achats_sort_du_calcul_sans_etre_nomme(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);

        foreach ([[$lyon, '5.00', '6.00'], [$villeurbanne, '20.00', '22.00']] as [$magasin, $premier, $second]) {
            $lampe = $this->produit($magasin, 'Lampe');
            $this->acheter($magasin, $lampe, 1, $premier, '2026-11-02');
            $this->acheter($magasin, $lampe, 1, $second, '2026-11-05');
        }

        $modele = [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR];
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => $modele,
            $villeurbanne->id => array_values(array_diff($modele, [PermissionRegistry::FACTURE_ACHAT_VIEW])),
        ]);
        app(TenantContext::class)->clear();

        $serie = $this->serie(app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe()->firstOrFail()));

        $this->assertSame(['Lampe'], $serie->ecartDesPrixDAchat['produits'] ?? null, 'Villeurbanne sort du calcul : les prix de Lyon seuls sont comptés, et avec un seul magasin compté le nom ne dit pas le magasin.');
        $this->assertSame('1 magasin n\'est pas compté : vous n\'y voyez pas les achats.', $serie->note);
        $this->assertStringNotContainsString('Villeurbanne', (string) $serie->note);
        $this->assertNotNull($serie->ecartDesPrixDAchat, 'La phrase grise ne fait pas disparaître le calcul de la carte.');
    }

    /**
     * Une société de services n'a pas d'achats : la carte n'existe pas pour
     * elle, et la demander est refusée.
     */
    public function test_une_societe_de_services_n_a_pas_la_carte(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['type' => TypeDActivite::Services]));
        $perimetre = $this->perimetreDuProprietaire($magasin);

        $this->assertFalse($perimetre->voit(Graphique::EcartDesPrixDAchat));
        $this->assertNotContains(Graphique::EcartDesPrixDAchat, $perimetre->graphiquesVisibles());
        $this->assertThrows(fn () => $this->serie($perimetre), AuthorizationException::class, PerimetreDesGraphiques::REFUS);
    }

    /**
     * La lecture des lignes d'achat est une seule requête, quel que soit le
     * nombre de magasins, et chaque table lue est filtrée par les magasins
     * du périmètre.
     */
    public function test_la_lecture_des_lignes_est_une_seule_requete_quel_que_soit_le_nombre_de_magasins(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $caluire = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Caluire']);

        foreach ([$lyon, $villeurbanne, $caluire] as $magasin) {
            $lampe = $this->produit($magasin, 'Lampe');
            $this->acheter($magasin, $lampe, 1, '5.00', '2026-11-02');
            $this->acheter($magasin, $lampe, 1, '6.00', '2026-11-05');
        }

        $proprietaire = $this->proprietaireDe($lyon);
        app(TenantContext::class)->clear();
        $graphiques = app(PerimetreDesGraphiques::class);
        $societe = $lyon->societe()->firstOrFail();

        DB::enableQueryLog();
        $this->serie($graphiques->duMagasin($proprietaire, $lyon));
        $unMagasin = $this->requetesSur('lignes_achat');
        DB::flushQueryLog();
        $serie = $this->serie($graphiques->deLaSociete($proprietaire, $societe));
        $troisMagasins = $this->requetesSur('lignes_achat');
        $requete = collect(DB::getQueryLog())->first(fn (array $requete): bool => str_contains((string) $requete['query'], '"lignes_achat"'));

        $this->assertSame([1, 1], [$unMagasin, $troisMagasins]);
        $this->assertNotNull($serie->ecartDesPrixDAchat);
        $this->assertCount(3, $serie->ecartDesPrixDAchat['produits']);
        $this->assertIsArray($requete);

        foreach (['"lignes_achat"."magasin_id" in', '"factures_achat"."magasin_id" in', '"produits"."magasin_id" in'] as $filtre) {
            $this->assertStringContainsString($filtre, (string) $requete['query']);
        }
    }

    /**
     * Un magasin sans achat validé ne rend aucune ligne : la carte a ses
     * douze mois et aucun produit.
     */
    public function test_un_magasin_sans_achat_valide_ne_rend_aucune_ligne(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $serie = $this->serie($this->perimetreDuProprietaire($magasin));

        $this->assertSame([], $serie->ecartDesPrixDAchat['produits'] ?? null);
        $this->assertSame([], $serie->jeux);
        $this->assertCount(12, $serie->etiquettes);
    }

    private function produit(Magasin $magasin, string $nom): Produit
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::factory()->create(['nom' => $nom, 'prix_vente_ttc' => '30.00', 'taux_tva' => 20, 'suivi_stock' => true]));
    }

    /**
     * Une facture d'achat d'une ligne de ce produit, créée au nom du
     * responsable de test du magasin, puis validée ; sans date, comme l'API
     * la crée, quand $jour est null.
     *
     * @param  array<string, mixed>  $ligne
     * @param  array<string, mixed>  $facture
     */
    private function acheter(Magasin $magasin, Produit $produit, int $quantite, string $prix, ?string $jour, array $ligne = [], array $facture = [], bool $valider = true): FactureAchat
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin, $produit, $quantite, $prix, $jour, $ligne, $facture, $valider): FactureAchat {
            $auteur = self::responsableDeTest($magasin);
            $fournisseur = Fournisseur::query()->first() ?? Fournisseur::factory()->create(['nom' => 'Maison Verdier']);
            $attributs = $jour === null ? $facture : ['date_facture' => $jour, ...$facture];
            $achat = app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, [['produit_id' => $produit->getKey(), 'quantite' => $quantite, 'prix_unitaire_ht' => $prix, 'taux_tva' => 20, ...$ligne]], $attributs);

            return $valider ? app(ValiderFactureAchatAction::class)->handle($auteur, $achat, (string) Str::uuid()) : $achat;
        });
    }

    /**
     * Les achats comparés d'un mois : le nom du produit, le jour, la
     * quantité, le prix en centimes et l'écart.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private function comparaisons(SerieDuGraphique $serie, int $mois): array
    {
        $calcul = $serie->ecartDesPrixDAchat;
        $this->assertNotNull($calcul);

        return array_map(fn (array $pas): array => [$calcul['produits'][$pas['i']], $pas['e']['j'], $pas['e']['q'], $pas['e']['c'], $pas['ecart']], $calcul['calculs'][$mois]['pas']);
    }

    private function requetesSur(string $table): int
    {
        return collect(DB::getQueryLog())->filter(fn (array $requete): bool => str_contains((string) $requete['query'], '"'.$table.'"'))->count();
    }

    private function perimetreDuProprietaire(Magasin $magasin): Perimetre
    {
        app(TenantContext::class)->clear();

        return app(PerimetreDesGraphiques::class)->deLaSociete($this->proprietaireDe($magasin), $magasin->societe()->firstOrFail());
    }

    private function serie(Perimetre $perimetre, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois): SerieDuGraphique
    {
        $cartes = [CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)];

        return app(SeriesDesGraphiques::class)->pour(new CalculDesSeries($perimetre, new ReglagesDuTableauDeBord($cartes, $periode), $cartes))[Graphique::EcartDesPrixDAchat->value];
    }
}
