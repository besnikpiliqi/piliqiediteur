<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\ListeDeDocuments;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChiffresDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ListesDesJours;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Barre;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Domain\TableauDeBord\Support\TextesDesPeriodes;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Les graphiques par mois, par trimestre ou par année (choix de l'éditeur du
 * 16/11/2026, lot GRAPH-AN ; décisions de Claude du 22/11/2026, D76), sur
 * des ventes réelles, en centimes, au jour de chaque magasin : « Découper
 * par », les dix ans et leurs années vides, le cumul depuis le début de
 * l'année, l'écart en % sur un an, la tendance, le détail d'une barre
 * jusqu'au jour et la liste de ce jour, « Ventes par vendeur ». Le jeu
 * principal, regardé le 15 octobre 2028 à midi, vend des lampes à 20,00 € HT
 * (24,00 € TTC) : une le 10 février 2026, deux le 20 juillet 2026, aucune en
 * 2027, une le 14 février 2028, trois le 18 avril, deux le 12 septembre et
 * une le 5 octobre 2028.
 */
class DecoupageDesGraphiquesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Par année : les dix dernières années, de 2019 à 2028, mais les sept
     * du début, sans donnée, ne sont pas affichées ; 2027, sans vente entre
     * deux années qui en ont, reste à 0 ; 2028, en cours, n'est pas finie.
     */
    public function test_par_annee_les_annees_vides_du_debut_sont_cachees_et_celle_du_milieu_reste_a_zero(): void
    {
        $magasin = $this->jeuPrincipal();

        $serie = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, decoupage: DecoupageDuTableauDeBord::Annee);

        $this->assertSame(['2026', '2027', '2028'], $serie->etiquettes);
        $this->assertSame([6000, 0, 14000], $serie->jeux[0]['valeurs']);
        $this->assertSame('7 années plus anciennes sans donnée ne sont pas affichées.', $serie->note);
        $this->assertSame(['Année 2026', 'Année 2027', 'Année 2028'], array_map(fn (Barre $barre): string => $barre->intitule, $serie->barres));
        $this->assertSame([false, false, true], array_map(fn (Barre $barre): bool => $barre->enCours, $serie->barres));
        $this->assertSame('Chiffre d\'affaires HT par année', $serie->titre);
        $this->assertStringEndsWith('année par année.', (string) $serie->descriptif);
    }

    /**
     * Une société qui clôt en juin : chaque barre est un exercice, de
     * juillet à juin, nommé par ses deux années.
     */
    public function test_par_annee_une_societe_qui_cloture_en_juin_a_une_barre_par_exercice(): void
    {
        $magasin = $this->jeuPrincipal(['mois_fin_exercice' => 6]);

        $serie = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, decoupage: DecoupageDuTableauDeBord::Annee);

        $this->assertSame(['2025-26', '2026-27', '2027-28', '2028-29'], $serie->etiquettes);
        $this->assertSame([2000, 4000, 8000, 6000], $serie->jeux[0]['valeurs']);
        $this->assertSame('6 exercices plus anciens sans donnée ne sont pas affichés.', $serie->note);
        $this->assertSame('Exercice 2028-2029 (juil. 2028 à juin 2029)', $serie->barres[3]->intitule);
        $this->assertSame('Chiffre d\'affaires HT par exercice', $serie->titre);
    }

    /**
     * Par trimestre : T1 à T4 de l'année en cours, ou quatre blocs de trois
     * mois, le dernier finissant ce mois-ci, sur « 12 derniers mois ».
     */
    public function test_par_trimestre_t1_a_t4_ou_quatre_blocs_de_trois_mois(): void
    {
        $magasin = $this->jeuPrincipal();

        $anneeEnCours = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, PeriodeDuTableauDeBord::AnneeEnCours, decoupage: DecoupageDuTableauDeBord::Trimestre);
        $glissants = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, decoupage: DecoupageDuTableauDeBord::Trimestre);

        $this->assertSame(['T1 2028', 'T2 2028', 'T3 2028', 'T4 2028'], $anneeEnCours->etiquettes);
        $this->assertSame([2000, 6000, 4000, 2000], $anneeEnCours->jeux[0]['valeurs']);
        $this->assertSame(['nov. 2027 à janv. 2028', 'févr. 2028 à avr. 2028', 'mai 2028 à juil. 2028', 'août 2028 à oct. 2028'], $glissants->etiquettes);
        $this->assertSame([0, 8000, 0, 6000], $glissants->jeux[0]['valeurs']);
        $this->assertTrue($glissants->barres[3]->enCours);
        $this->assertSame('Chiffre d\'affaires HT par trimestre', $glissants->titre);
    }

    /**
     * Le cumul depuis le début de l'année : chaque point ajoute les
     * précédents, les mois à venir n'ont pas de valeur, la carte part du
     * début de l'année en cours même sur « 12 derniers mois », et la
     * comparaison est l'année d'avant cumulée. Par année, il n'y a rien à
     * cumuler ; sur une valeur de stock, non plus.
     */
    public function test_le_cumul_part_du_debut_de_l_annee_et_ne_s_applique_ni_par_annee_ni_a_un_stock(): void
    {
        $magasin = $this->jeuPrincipal();

        $cumul = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, cumul: true);
        $compare = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, comparer: true, cumul: true);
        $parAnnee = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, decoupage: DecoupageDuTableauDeBord::Annee, cumul: true);
        $stock = $this->serie($magasin, Graphique::ValeurDuStock, cumul: true);

        $this->assertCount(12, $cumul->etiquettes);
        $this->assertSame('janv. 2028', $cumul->etiquettes[0]);
        $this->assertSame([0, 2000, 2000, 8000, 8000, 8000, 8000, 8000, 12000, 14000, null, null], $cumul->jeux[0]['valeurs']);
        $this->assertTrue($cumul->jeux[0]['en_ligne'], 'Le cumul se dessine en courbe.');
        $this->assertStringContainsString('Cumul depuis le début de l\'année : chaque point ajoute les précédents. La carte part du début de l\'année en cours.', (string) $cumul->note);
        $this->assertSame('Chiffre d\'affaires HT (un an plus tôt)', $compare->jeux[1]['libelle']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, null, null], $compare->jeux[1]['valeurs'], 'L\'année précédente cumulée, sans vente en 2027.');
        $this->assertStringContainsString(TextesDesPeriodes::MEMES_JOURS, (string) $compare->note);
        $this->assertSame([6000, 0, 14000], $parAnnee->jeux[0]['valeurs'], 'Par année, rien à cumuler : chaque barre est déjà une année.');
        $this->assertCount(1, $parAnnee->jeux);
        $this->assertCount(12, $stock->etiquettes);
        $this->assertSame('nov. 2027', $stock->etiquettes[0], 'Un stock garde sa période : jamais cumulé.');
        $this->assertStringContainsString(TextesDesPeriodes::CUMUL_D_UN_STOCK, (string) $stock->note);
        $this->assertFalse($this->reglages(decoupage: DecoupageDuTableauDeBord::Annee)->cumulPossible());
        $this->assertFalse($this->reglages(decoupage: DecoupageDuTableauDeBord::Annee)->comparaisonPossible());
        $this->assertTrue($this->reglages(decoupage: DecoupageDuTableauDeBord::Trimestre)->cumulPossible());
    }

    /**
     * L'écart en % sur un an, par année : « – » (null) quand l'année d'avant
     * valait 0, −100 % quand la barre est à 0 après une année qui vendait ;
     * l'année en cours se compare aux mêmes jours de l'an dernier. Il met
     * de côté le cumul, la comparaison et la tendance.
     */
    public function test_l_ecart_sur_un_an_par_annee_est_nul_sans_annee_d_avant_et_met_de_cote_cumul_comparaison_et_tendance(): void
    {
        $magasin = $this->jeuPrincipal();
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLEcart(true)->avecLaTendance(true);

        $serie = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee, comparer: true, cumul: true);

        $this->assertSame(['2026', '2027', '2028'], $serie->etiquettes);
        $this->assertCount(1, $serie->jeux, 'Ni comparaison ni tendance sur l\'écart.');
        $this->assertSame(['Écart sur un an', [null, -10000, null], 'pourcent'], [$serie->jeux[0]['libelle'], $serie->jeux[0]['valeurs'], $serie->jeux[0]['unite']]);
        $this->assertSame([['actuel' => 6000, 'precedent' => 0], ['actuel' => 0, 'precedent' => 6000], ['actuel' => 14000, 'precedent' => 0]], $serie->ecartSurUnAn['lignes'] ?? null);
        $this->assertStringContainsString(TextesDesPeriodes::MEMES_JOURS, (string) $serie->note);
        $this->assertStringContainsString(TextesDesPeriodes::ECART_SEUL, (string) $serie->note);
        $this->assertFalse($serie->estVide());
    }

    /**
     * La tendance : la moyenne de la période et des deux précédentes, en
     * centimes arrondis, sans point pour les deux premières ; par année,
     * des moyennes sur trois ans ; avec le cumul, pas de tendance.
     */
    public function test_la_tendance_est_la_moyenne_de_la_periode_et_des_deux_precedentes(): void
    {
        $magasin = $this->jeuPrincipal();
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLaTendance(true);

        $parMois = $this->serieDeLaCarte($magasin, $carte);
        $parAnnee = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee);
        $cumulee = $this->serieDeLaCarte($magasin, $carte, cumul: true);

        $this->assertSame('Moyenne sur 3 mois', $parMois->jeux[1]['libelle']);
        $this->assertSame([null, null, 0, 667, 667, 2667, 2000, 2000, 0, 0, 1333, 2000], $parMois->jeux[1]['valeurs']);
        $this->assertTrue($parMois->jeux[1]['en_ligne']);
        $this->assertSame('Moyenne sur 3 ans', $parAnnee->jeux[1]['libelle']);
        $this->assertSame([null, null, 6667], $parAnnee->jeux[1]['valeurs'], '(14 000 + 0 + 6 000) ÷ 3, arrondi au centime.');
        $this->assertCount(1, $cumulee->jeux, 'Pas de tendance sur un cumul.');
        $this->assertStringContainsString(TextesDesPeriodes::TENDANCE_ET_CUMUL, (string) $cumulee->note);
    }

    /**
     * Le détail d'une barre : une année s'ouvre en mois, un mois en jours,
     * les montants seulement ; le jour de la liste est une barre de la
     * carte, jamais à venir ; un détail forgé n'ouvre rien.
     */
    public function test_le_detail_d_une_barre_la_redecoupe_jusqu_au_jour_et_un_detail_forge_n_ouvre_rien(): void
    {
        $magasin = $this->jeuPrincipal();
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLaTendance(true);
        $perimetre = $this->perimetre($magasin);
        $annees = $this->calcul($perimetre, $carte, DecoupageDuTableauDeBord::Annee, comparer: true)->barresDe($carte);
        $jetonDe2028 = $this->jeton($annees, '2028');

        $mois = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee, comparer: true, details: [$jetonDe2028]);
        $octobre = $this->jeton($mois->barres, 'oct. 2028');
        $jours = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee, comparer: true, details: [$jetonDe2028, $octobre]);

        $this->assertSame(['janv. 2028', 'févr. 2028', 'mars 2028', 'avr. 2028', 'mai 2028', 'juin 2028', 'juil. 2028', 'août 2028', 'sept. 2028', 'oct. 2028', 'nov. 2028', 'déc. 2028'], $mois->etiquettes);
        $this->assertSame([0, 2000, 0, 6000, 0, 0, 0, 0, 4000, 2000, null, null], $mois->jeux[0]['valeurs']);
        $this->assertCount(1, $mois->jeux, 'Le détail montre les montants seulement : ni comparaison ni tendance.');
        $this->assertStringContainsString('Détail de Année 2028 : les montants seulement', (string) $mois->note);
        $this->assertSame('Chiffre d\'affaires HT par mois', $mois->titre);
        $this->assertCount(31, $jours->etiquettes);
        $this->assertSame('1', $jours->etiquettes[0]);
        $this->assertSame(2000, $jours->jeux[0]['valeurs'][4], 'Le 5 octobre.');
        $this->assertSame([0, null], [$jours->jeux[0]['valeurs'][14], $jours->jeux[0]['valeurs'][15]], 'Le 15, aujourd\'hui, vaut 0 ; le 16 est à venir.');
        $this->assertSame('Chiffre d\'affaires HT par jour', $jours->titre);
        $this->assertSame('Jeudi 5 octobre 2028', $jours->barres[4]->intitule);
        $this->assertSame(NiveauDeLaBarre::Jour, $jours->barres[4]->niveau);

        $forges = [
            'un jeton inconnu' => ['annee:2028-01-01:2028-12-30'],
            'un mois au niveau des années' => [$octobre],
            'une année à venir' => [$this->jeton($annees, '2028'), 'mois:2028-11-01:2028-11-30'],
            'un jour qu\'on ne détaille pas' => [$jetonDe2028, $octobre, 'jour:2028-10-05:2028-10-05'],
        ];

        foreach ($forges as $cas => $details) {
            $this->assertSame(['2026', '2027', '2028'], $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee, details: $details)->etiquettes, $cas);
        }
    }

    /**
     * Le clic sur un jour : les listes de documents de ce jour, dans chaque
     * magasin compté, pour qui les voit ; rien pour la valeur du stock ; un
     * jour à venir, un mois ou un jour qui n'est pas une barre de la carte
     * sont refusés ; un graphique que le périmètre ne permet pas aussi.
     */
    public function test_le_clic_sur_un_jour_ouvre_les_listes_de_ce_jour_pour_qui_les_voit(): void
    {
        $magasin = $this->jeuPrincipal();
        $perimetre = $this->perimetre($magasin);
        $cartes = [CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois), CarteDuTableauDeBord::parDefaut(Graphique::VentesEtAchatsParMois), CarteDuTableauDeBord::parDefaut(Graphique::ValeurDuStock)];
        $calcul = $this->calculDeCartes($perimetre, $cartes, decoupage: DecoupageDuTableauDeBord::Mois);
        $calculEnDetail = $this->calculDeCartes($perimetre, $cartes, decoupage: DecoupageDuTableauDeBord::Mois, details: array_map(
            fn (CarteDuTableauDeBord $carte): array => [$this->jeton($calcul->barresDe($carte), 'oct. 2028')],
            [Graphique::ChiffreDAffairesParMois->value => $cartes[0], Graphique::VentesEtAchatsParMois->value => $cartes[1], Graphique::ValeurDuStock->value => $cartes[2]],
        ));
        $jeton = fn (Graphique $graphique): string => $this->jeton($calculEnDetail->barresDe($calculEnDetail->carte($graphique) ?? throw new LogicException), '5');
        $listes = app(ListesDesJours::class);

        $ouvertes = $listes->pour($calculEnDetail, Graphique::ChiffreDAffairesParMois, $jeton(Graphique::ChiffreDAffairesParMois));

        $this->assertCount(1, $ouvertes);
        $this->assertSame([$magasin->getKey(), '2028-10-05', [ListeDeDocuments::Factures, ListeDeDocuments::Avoirs]], [$ouvertes[0]->magasin->getKey(), $ouvertes[0]->jour, $ouvertes[0]->listes]);
        $this->assertSame([ListeDeDocuments::Factures, ListeDeDocuments::Avoirs, ListeDeDocuments::FacturesAchat, ListeDeDocuments::AvoirsFournisseur], $listes->pour($calculEnDetail, Graphique::VentesEtAchatsParMois, $jeton(Graphique::VentesEtAchatsParMois))[0]->listes);
        $this->assertSame([], $listes->pour($calculEnDetail, Graphique::ValeurDuStock, $jeton(Graphique::ValeurDuStock)), 'La valeur du stock n\'ouvre aucune liste.');

        foreach (['un jour à venir' => 'jour:2028-10-16:2028-10-16', 'un mois' => 'mois:2028-10-01:2028-10-31', 'un jour d\'un autre mois' => 'jour:2028-09-12:2028-09-12', 'rien' => ''] as $cas => $refuse) {
            try {
                $listes->pour($calculEnDetail, Graphique::ChiffreDAffairesParMois, $refuse);
                $this->fail("{$cas} doit être refusé.");
            } catch (LogicException $refus) {
                $this->assertSame('Ce jour n\'est pas un jour que la carte montre.', $refus->getMessage(), $cas);
            }
        }

        $this->expectException(AuthorizationException::class);
        $listes->pour($calculEnDetail, Graphique::VentesParTauxDeTva, $jeton(Graphique::ChiffreDAffairesParMois));
    }

    /**
     * Dans l'espace de la société avec tous ses magasins, le clic sur un jour
     * ouvre une liste par magasin compté, par nom ; un administrateur qui ne
     * voit pas toutes les factures de l'un n'a que la liste de l'autre, sans
     * que rien ne dise qu'il y en a un deuxième.
     */
    public function test_le_clic_sur_un_jour_ouvre_une_liste_par_magasin_compte(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        foreach ([$lyon, $villeurbanne] as $magasin) {
            [$lampe, $client] = $this->lampeEtClient($magasin);
            $this->vendreAvec($magasin, self::responsableDeTest($magasin), $client, $lampe, 1);
        }
        $modele = [...PermissionRegistry::modeleDeLAdministrateur($lyon->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR];
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [$lyon->id => $modele, $villeurbanne->id => array_values(array_diff($modele, [PermissionRegistry::FACTURE_VIEW_TOUTES]))]);
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois);
        $jourDesVentes = 'jour:2026-10-01:2026-10-01';
        $ouvrir = function (Perimetre $perimetre) use ($carte, $jourDesVentes): array {
            $sansDetail = $this->calculDeCartes($perimetre, [$carte], DecoupageDuTableauDeBord::Mois);
            $enDetail = $this->calculDeCartes($perimetre, [$carte], DecoupageDuTableauDeBord::Mois, details: [Graphique::ChiffreDAffairesParMois->value => [$this->jeton($sansDetail->barresDe($carte), 'oct. 2026')]]);

            return array_map(fn ($liste): array => [$liste->magasin->nom, $liste->jour], app(ListesDesJours::class)->pour($enDetail, Graphique::ChiffreDAffairesParMois, $jourDesVentes));
        };

        $duProprietaire = $ouvrir($this->perimetre($lyon));
        app(TenantContext::class)->clear();
        $delAdministrateur = $ouvrir(app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe()->firstOrFail()));

        $this->assertSame([['Lyon Centre', '2026-10-01'], ['Villeurbanne', '2026-10-01']], $duProprietaire);
        $this->assertSame([['Lyon Centre', '2026-10-01']], $delAdministrateur);
    }

    /**
     * Un administrateur qui ne voit pas toutes les factures d'un magasin n'y
     * voit pas les listes de ce jour, ni le graphique : la liste est refusée
     * avant de rien lire.
     */
    public function test_un_administrateur_qui_ne_voit_pas_toutes_les_factures_n_a_pas_la_liste_du_jour(): void
    {
        $magasin = $this->jeuPrincipal();
        $administrateur = $this->administrateur($magasin, autorisationsMagasin: array_values(array_diff([...PermissionRegistry::modeleDeLAdministrateur($magasin->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR], [PermissionRegistry::FACTURE_VIEW_TOUTES])));
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $magasin->societe()->firstOrFail());
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois);
        $calcul = $this->calculDeCartes($perimetre, [$carte], decoupage: DecoupageDuTableauDeBord::Mois);
        $calculEnDetail = $this->calculDeCartes($perimetre, [$carte], decoupage: DecoupageDuTableauDeBord::Mois, details: [Graphique::ChiffreDAffairesParMois->value => [$this->jeton($calcul->barresDe($carte), 'oct. 2028')]]);

        $this->assertFalse($perimetre->voit(Graphique::ChiffreDAffairesParMois));
        $this->expectException(AuthorizationException::class);
        app(ListesDesJours::class)->pour($calculEnDetail, Graphique::ChiffreDAffairesParMois, $this->jeton($calculEnDetail->barresDe($carte), '5'));
    }

    /**
     * Le mois en cours se compare aux mêmes jours de l'an dernier, comme les
     * chiffres clés : au 15 octobre 2027, 60,00 € HT contre les 40,00 € du 1er
     * au 15 octobre 2026 (« +50,0 % »), et non contre les 140,00 € de tout
     * octobre 2026 ; au 31 octobre, contre ces 140,00 € (« −57,1 % »). Les
     * lignes sous les chiffres clés suivent le sens : vert quand ça monte,
     * rouge quand ça baisse, un retard toujours rouge.
     */
    public function test_le_mois_en_cours_se_compare_aux_memes_jours_de_l_an_dernier_et_les_chiffres_cles_prennent_leur_couleur(): void
    {
        $magasin = $this->jeuDesMemesJours();
        $perimetre = $this->perimetre($magasin);
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLEcart(true);

        $enEcart = $this->serieDeLaCarte($magasin, $carte);
        $compare = $this->serie($magasin, Graphique::ChiffreDAffairesParMois, comparer: true);
        $parAnnee = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee);
        $chiffres = app(ChiffresDuTableauDeBord::class)->pour($this->calculDeCartes($perimetre, [], decoupage: DecoupageDuTableauDeBord::Mois));

        $this->assertSame(['sept. 2027', 'oct. 2027'], array_slice($enEcart->etiquettes, 10, 2));
        $this->assertSame([10000, 5000], array_slice($enEcart->jeux[0]['valeurs'], 10, 2), 'Septembre : 40 contre 20 ; octobre en cours : 60 contre 40 (les mêmes jours).');
        $this->assertSame([['actuel' => 4000, 'precedent' => 2000], ['actuel' => 6000, 'precedent' => 4000]], array_slice($enEcart->ecartSurUnAn['lignes'] ?? [], 10, 2));
        $this->assertNull($enEcart->jeux[0]['valeurs'][0], 'Novembre 2026 : rien un an plus tôt, « – ».');
        $this->assertSame('Chiffre d\'affaires HT (un an plus tôt)', $compare->jeux[1]['libelle']);
        $this->assertSame([2000, 4000], array_slice($compare->jeux[1]['valeurs'], 10, 2), 'Octobre 2026 : les 40,00 € du 1er au 15, pas les 140,00 € du mois.');
        $this->assertSame(['2026', '2027'], $parAnnee->etiquettes);
        $this->assertSame([null, 6667], $parAnnee->jeux[0]['valeurs'], '2027 : 100,00 € contre 60,00 € du 1er janvier au 15 octobre 2026.');
        $this->assertSame(['valeur' => 6000, 'variation' => '+50,0 % sur un an', 'ton' => 'positif'], $chiffres['ca']);

        $this->travelTo(self::aParis('2027-10-31 12:00:00'));
        $finDuMois = app(ChiffresDuTableauDeBord::class)->pour($this->calculDeCartes($this->perimetre($magasin), [], decoupage: DecoupageDuTableauDeBord::Mois));

        $this->assertSame(['valeur' => 6000, 'variation' => '−57,1 % sur un an', 'ton' => 'negatif'], $finDuMois['ca']);
        $this->assertSame(['valeur' => 0, 'en_retard' => 0, 'ton_du_retard' => 'neutre'], $finDuMois['restant_du'], 'Sans retard, la ligne reste neutre.');
    }

    /**
     * La marge brute et la valeur du stock par trimestre, par année et par
     * jour, depuis le grand livre rejoué (10 câbles achetés 5,00 € HT le 10
     * septembre 2026, 3 vendus 12,00 € TTC le 15) : 15,00 € de marge le 15,
     * un stock de 50,00 € du 10 au 14 puis de 35,00 €.
     */
    public function test_la_marge_et_la_valeur_du_stock_se_lisent_par_trimestre_par_annee_et_par_jour(): void
    {
        $magasin = $this->jeuDuStock();
        $marge = CarteDuTableauDeBord::parDefaut(Graphique::MargeBruteParMois);
        $stock = CarteDuTableauDeBord::parDefaut(Graphique::ValeurDuStock);

        $parTrimestre = $this->serieDeLaCarte($magasin, $marge, decoupage: DecoupageDuTableauDeBord::Trimestre);
        $stockParAnnee = $this->serieDeLaCarte($magasin, $stock, decoupage: DecoupageDuTableauDeBord::Annee);
        $calcul = $this->calcul($this->perimetre($magasin), $marge, DecoupageDuTableauDeBord::Mois);
        $septembre = $this->jeton($calcul->barresDe($marge), 'sept. 2026');
        $margeParJour = $this->serieDeLaCarte($magasin, $marge, details: [$septembre]);
        $stockParJour = $this->serieDeLaCarte($magasin, $stock, details: [$septembre]);

        $this->assertSame([0, 0, 0, 1500], $parTrimestre->jeux[0]['valeurs']);
        $this->assertSame([3 => 'Taux de marge : 50,0 %'], $parTrimestre->infoBulles);
        $this->assertSame(['2026'], $stockParAnnee->etiquettes);
        $this->assertSame([3500], $stockParAnnee->jeux[0]['valeurs'], 'La valeur du stock à la fin de l\'année en cours, à ce jour.');
        $this->assertCount(30, $margeParJour->etiquettes);
        $this->assertSame(1500, $margeParJour->jeux[0]['valeurs'][14]);
        $this->assertSame(1500, array_sum($margeParJour->jeux[0]['valeurs']));
        $this->assertSame([14 => 'Taux de marge : 50,0 %'], $margeParJour->infoBulles);
        $this->assertSame([0, 5000, 5000, 3500, 3500], [$stockParJour->jeux[0]['valeurs'][8], $stockParJour->jeux[0]['valeurs'][9], $stockParJour->jeux[0]['valeurs'][13], $stockParJour->jeux[0]['valeurs'][14], $stockParJour->jeux[0]['valeurs'][29]]);
    }

    /**
     * La TVA par trimestre, par année et par jour : la collectée de la
     * vente (6,00 €), la déductible de l'achat (10,00 €), la TVA à payer
     * (−4,00 €), additionnées depuis les montants des documents ; le détail
     * par jour dit que les charges, d'un mois de TVA, n'y sont pas.
     */
    public function test_la_tva_par_trimestre_par_annee_et_par_jour(): void
    {
        $magasin = $this->jeuDuStock();
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::TvaAPayerParMois);

        $parTrimestre = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Trimestre);
        $parAnnee = $this->serieDeLaCarte($magasin, $carte, decoupage: DecoupageDuTableauDeBord::Annee);
        $calcul = $this->calcul($this->perimetre($magasin), $carte, DecoupageDuTableauDeBord::Mois);
        $parJour = $this->serieDeLaCarte($magasin, $carte, details: [$this->jeton($calcul->barresDe($carte), 'sept. 2026')]);
        $valeurs = fn (SerieDuGraphique $serie): array => array_combine(array_column($serie->jeux, 'libelle'), array_column($serie->jeux, 'valeurs'));

        $this->assertSame([0, 0, 0, -400], $valeurs($parTrimestre)['TVA à payer']);
        $this->assertSame([600], $valeurs($parAnnee)['TVA collectée']);
        $this->assertSame([1000], $valeurs($parAnnee)['TVA déductible des achats']);
        $this->assertSame([-400], $valeurs($parAnnee)['TVA à payer (estimation)']);
        $this->assertSame([9 => 1000, 14 => -600], [9 => $valeurs($parJour)['TVA déductible des achats'][9], 14 => -$valeurs($parJour)['TVA collectée'][14]]);
        $this->assertSame(-400, array_sum($valeurs($parJour)['TVA à payer']));
        $this->assertStringContainsString(TextesDesPeriodes::TVA_DES_CHARGES_PAR_JOUR, (string) $parJour->note);
        $this->assertStringNotContainsString(TextesDesPeriodes::TVA_DES_CHARGES_PAR_JOUR, (string) $parAnnee->note);
    }

    /**
     * « Ventes par vendeur » : le chiffre d'affaires HT net des avoirs de
     * chaque compte qui a vendu, avec le nom que sa dernière vente a gardé ;
     * l'avoir du propriétaire, qui ne vend jamais, se déduit du compte qui
     * avait vendu, non du propriétaire ; la somme des comptes est le chiffre
     * d'affaires net du magasin ; un compte qui a changé de nom reste une
     * barre, au nom de sa dernière vente, et sa première vente garde l'ancien
     * en base.
     */
    public function test_les_ventes_par_vendeur_sont_nettes_des_avoirs_au_nom_fige_de_chaque_vente(): void
    {
        $this->travelTo(self::aParis('2026-07-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $lea = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Léa Martin']);
        $paul = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Petit']);
        [$lampe, $client] = $this->lampeEtClient($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->travelTo(self::aParis('2026-08-03 10:00:00'));
        $ticketDeLea = $this->vendreAvec($magasin, $lea, $client, $lampe, 3);
        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        [$autreLampe, $autreClient] = $this->lampeEtClient($autre);
        $this->vendreAvec($autre, $this->membre($autre, RoleMagasin::Vendeur, null, ['name' => 'Étienne Voisin']), $autreClient, $autreLampe, 9);
        $this->travelTo(self::aParis('2026-09-10 10:00:00'));
        $premierTicketDePaul = $this->vendreAvec($magasin, $paul, $client, $lampe, 2);
        $paul->forceFill(['name' => 'Paul Petit-Durand'])->save();
        $this->travelTo(self::aParis('2026-09-20 10:00:00'));
        $this->vendreAvec($magasin, $paul, $client, $lampe, 1);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->actingAs($proprietaire);
        app(TenantContext::class)->runAs($magasin, fn () => app(EnregistrerAvoirAction::class)->handle($proprietaire, $client, [['ligne_facture_id' => $ticketDeLea->lignes()->value('id'), 'quantite' => 1]], 'Retour'));
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));

        $serie = $this->serie($magasin, Graphique::VentesParVendeur);
        $total = $this->serie($magasin, Graphique::ChiffreDAffairesParMois);

        $this->assertSame(['Paul Petit-Durand', 'Léa Martin'], $serie->etiquettes);
        $this->assertSame([6000, 4000], $serie->jeux[0]['valeurs'], 'Paul : 40,00 + 20,00 € ; Léa : 60,00 − 20,00 € d\'avoir.');
        $this->assertSame(array_sum($total->jeux[0]['valeurs']), array_sum($serie->jeux[0]['valeurs']), 'La somme des comptes est le chiffre d\'affaires net.');
        $this->assertSame('Paul Petit', $premierTicketDePaul->fresh()?->cree_par_nom, 'La vente garde le nom du jour.');
        $this->assertNull($serie->note);
        $this->assertNotContains('Responsable de test', $serie->etiquettes);
        $this->assertNotContains($proprietaire->name, $serie->etiquettes, 'Le propriétaire ne vend jamais, son avoir n\'est pas une vente.');
        $this->assertNotContains('Étienne Voisin', $serie->etiquettes, 'Une autre société n\'est jamais comptée.');
    }

    /**
     * Une barre par magasin pour un compte qui vend dans deux ; un magasin
     * où l'on ne voit pas toutes les ventes de l'équipe n'est pas compté, et
     * une phrase grise le dit sans le nommer.
     */
    public function test_les_ventes_par_vendeur_ne_comptent_que_les_magasins_ou_l_on_voit_toutes_les_ventes(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $deLyon = $this->membre($lyon, RoleMagasin::Vendeur, null, ['name' => 'Camille Roux']);
        $deVilleurbanne = $this->membre($villeurbanne, RoleMagasin::Vendeur, null, ['name' => 'Camille Roux']);
        foreach ([[$lyon, $deLyon, 2], [$villeurbanne, $deVilleurbanne, 5]] as [$magasin, $vendeur, $lampes]) {
            [$lampe, $client] = $this->lampeEtClient($magasin);
            $this->vendreAvec($magasin, $vendeur, $client, $lampe, $lampes);
        }
        $modele = [...PermissionRegistry::modeleDeLAdministrateur($lyon->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR];
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [$lyon->id => $modele, $villeurbanne->id => array_values(array_diff($modele, [PermissionRegistry::FACTURE_VIEW_TOUTES]))]);
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));

        $duProprietaire = $this->serie($lyon, Graphique::VentesParVendeur);
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::VentesParVendeur);
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe()->firstOrFail());
        $delAdministrateur = app(SeriesDesGraphiques::class)->pour($this->calculDeCartes($perimetre, [$carte], decoupage: DecoupageDuTableauDeBord::Mois))[Graphique::VentesParVendeur->value];

        $this->assertSame(['Camille Roux · Villeurbanne', 'Camille Roux · Lyon Centre'], $duProprietaire->etiquettes, 'Deux comptes de même nom : une barre par magasin.');
        $this->assertSame([10000, 4000], $duProprietaire->jeux[0]['valeurs']);
        $this->assertSame(['Camille Roux'], $delAdministrateur->etiquettes);
        $this->assertSame([4000], $delAdministrateur->jeux[0]['valeurs']);
        $this->assertSame('1 magasin n\'est pas compté : vous n\'y voyez pas toutes les ventes.', $delAdministrateur->note);
        $this->assertStringNotContainsString('Villeurbanne', (string) $delAdministrateur->note);

        $this->expectException(AuthorizationException::class);
        app(VentesDuTableauDeBord::class)->ventesParCompte($perimetre, new Collection([$villeurbanne]), '2026-01-01', '2026-12-31');
    }

    /**
     * Un vendeur ou un observateur n'a jamais le graphique : ni périmètre,
     * ni carte, quelles que soient les cases qu'il a.
     */
    public function test_un_vendeur_ou_un_observateur_n_a_jamais_les_ventes_par_vendeur(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());

        foreach ([RoleMagasin::Vendeur, RoleMagasin::Observateur] as $role) {
            $compte = $this->membre($magasin, $role, self::toutesCellesDUnVendeur());
            app(TenantContext::class)->clear();

            $this->assertFalse(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuMagasin($compte, $magasin), $role->value);
            $this->assertFalse(app(PerimetreDesGraphiques::class)->voitLesGraphiquesDeLaSociete($compte, $magasin->societe()->firstOrFail()), $role->value);
        }
    }

    /**
     * Le jeu principal : une société qui clôt en décembre (ou en juin), des
     * lampes vendues comme décrit en tête de la classe, regardé le 15
     * octobre 2028 à midi ; la boutique ouvre en février 2026.
     *
     * @param  array<string, mixed>  $options
     */
    private function jeuPrincipal(array $options = []): Magasin
    {
        $this->travelTo(self::aParis('2026-02-09 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete($options));
        [$lampe, $client] = $this->lampeEtClient($magasin);

        foreach ([['2026-02-10', 1], ['2026-07-20', 2], ['2028-02-14', 1], ['2028-04-18', 3], ['2028-09-12', 2], ['2028-10-05', 1]] as [$jour, $lampes]) {
            $this->travelTo(self::aParis($jour.' 10:00:00'));
            $this->vendreAvec($magasin, self::responsableDeTest($magasin), $client, $lampe, $lampes);
        }

        $this->travelTo(self::aParis('2028-10-15 12:00:00'));

        return $magasin;
    }

    /**
     * Le jeu des mêmes jours, regardé le 15 octobre 2027 à midi : une lampe
     * le 15 septembre 2026, deux le 10 octobre, cinq le 20 octobre 2026
     * (après le 15 : jamais comparées à octobre 2027 en cours), deux le 20
     * septembre 2027 et trois le 5 octobre 2027.
     */
    private function jeuDesMemesJours(): Magasin
    {
        $this->travelTo(self::aParis('2026-09-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        [$lampe, $client] = $this->lampeEtClient($magasin);

        foreach ([['2026-09-15', 1], ['2026-10-10', 2], ['2026-10-20', 5], ['2027-09-20', 2], ['2027-10-05', 3]] as [$jour, $lampes]) {
            $this->travelTo(self::aParis($jour.' 10:00:00'));
            $this->vendreAvec($magasin, self::responsableDeTest($magasin), $client, $lampe, $lampes);
        }

        $this->travelTo(self::aParis('2027-10-15 12:00:00'));

        return $magasin;
    }

    /**
     * Dix câbles achetés 5,00 € HT chez Grossiste Est le 10 septembre 2026,
     * trois vendus 12,00 € TTC le 15 ; regardé le 15 octobre 2026 à midi.
     */
    private function jeuDuStock(): Magasin
    {
        $this->travelTo(self::aParis('2026-09-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $client = Client::factory()->create(['nom' => 'Julie Petit']);
        $this->travelTo(self::aParis('2026-09-10 10:00:00'));
        app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(['nom' => 'Grossiste Est']), [
            ['produit_id' => $cable->id, 'quantite' => 10, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
        ], ['date_facture' => '2026-09-10']));
        $this->travelTo(self::aParis('2026-09-15 10:00:00'));
        self::vendreAuComptant($responsable, $client, [['produit_id' => $cable->id, 'quantite' => 3]]);
        $this->travelTo(self::aParis('2026-10-15 12:00:00'));
        app(TenantContext::class)->clear();

        return $magasin;
    }

    /**
     * La lampe à 24,00 € TTC (20,00 € HT) et le client particulier d'un
     * magasin.
     *
     * @return array{0: Produit, 1: Client}
     */
    private function lampeEtClient(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Client::factory()->create(['nom' => 'Julie Petit']),
        ]);
    }

    private function vendreAvec(Magasin $magasin, User $vendeur, Client $client, Produit $lampe, int $lampes): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, $client, [['produit_id' => $lampe->id, 'quantite' => $lampes]]));
    }

    private function perimetre(Magasin $magasin): Perimetre
    {
        app(TenantContext::class)->clear();

        return app(PerimetreDesGraphiques::class)->deLaSociete($this->proprietaireDe($magasin), $magasin->societe()->firstOrFail());
    }

    private function reglages(PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false, DecoupageDuTableauDeBord $decoupage = DecoupageDuTableauDeBord::Mois, bool $cumul = false): ReglagesDuTableauDeBord
    {
        return new ReglagesDuTableauDeBord([], $periode, null, $comparer, $decoupage, $cumul);
    }

    /**
     * Le calcul d'une carte avec ces réglages et, au besoin, son détail
     * ouvert (les jetons des barres ouvertes, de la plus large à la plus
     * fine).
     *
     * @param  list<string>  $details
     */
    private function calcul(Perimetre $perimetre, CarteDuTableauDeBord $carte, DecoupageDuTableauDeBord $decoupage, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false, bool $cumul = false, array $details = []): CalculDesSeries
    {
        return $this->calculDeCartes($perimetre, [$carte], $decoupage, $periode, $comparer, $cumul, $details === [] ? [] : [$carte->graphique->value => $details]);
    }

    /**
     * @param  list<CarteDuTableauDeBord>  $cartes
     * @param  array<string, list<string>>  $details
     */
    private function calculDeCartes(Perimetre $perimetre, array $cartes, DecoupageDuTableauDeBord $decoupage, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false, bool $cumul = false, array $details = []): CalculDesSeries
    {
        return new CalculDesSeries($perimetre, new ReglagesDuTableauDeBord($cartes, $periode, null, $comparer, $decoupage, $cumul), $cartes, $details);
    }

    /**
     * La série du graphique, à sa carte par défaut, pour le propriétaire.
     */
    private function serie(Magasin $magasin, Graphique $graphique, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false, DecoupageDuTableauDeBord $decoupage = DecoupageDuTableauDeBord::Mois, bool $cumul = false): SerieDuGraphique
    {
        return $this->serieDeLaCarte($magasin, CarteDuTableauDeBord::parDefaut($graphique), $periode, $comparer, $decoupage, $cumul);
    }

    /**
     * La série de cette carte pour le propriétaire.
     *
     * @param  list<string>  $details
     */
    private function serieDeLaCarte(Magasin $magasin, CarteDuTableauDeBord $carte, PeriodeDuTableauDeBord $periode = PeriodeDuTableauDeBord::DouzeDerniersMois, bool $comparer = false, DecoupageDuTableauDeBord $decoupage = DecoupageDuTableauDeBord::Mois, bool $cumul = false, array $details = []): SerieDuGraphique
    {
        return app(SeriesDesGraphiques::class)->pour($this->calcul($this->perimetre($magasin), $carte, $decoupage, $periode, $comparer, $cumul, $details))[$carte->graphique->value];
    }

    /**
     * Le jeton de la barre qui porte cette étiquette.
     *
     * @param  list<Barre>  $barres
     */
    private function jeton(array $barres, string $etiquette): string
    {
        foreach ($barres as $barre) {
            if ($barre->etiquette === $etiquette) {
                return $barre->jeton();
            }
        }

        throw new LogicException("Aucune barre « {$etiquette} ».");
    }
}
