<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\Barre;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\DecoupageDesPeriodes;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\TextesDesPeriodes;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L'écran des graphiques par mois, par trimestre ou par année (choix de
 * l'éditeur du 16/11/2026, lot GRAPH-AN ; maquette 01-graph-an.html et
 * décisions de Claude du 22/11/2026, D76) : ce que la grille écrit pour le
 * découpage, le cumul, l'écart en % sur un an, la tendance, le détail d'une
 * barre jusqu'au jour et la fenêtre de ses documents, et ce qu'elle refuse
 * quand le navigateur forge un geste. Le jeu est celui de
 * DecoupageDesGraphiquesTest : des lampes à 20,00 € HT (24,00 € TTC) vendues
 * en 2026 et en 2028, aucune en 2027, regardé le 15 octobre 2028 à midi.
 */
class EcransDesGraphiquesParPeriodeTest extends TestCase
{
    use RefreshDatabase;

    private const CARTE = 'ca_par_mois';

    private Societe $societe;

    private User $proprietaire;

    /**
     * Par année, la carte n'a une colonne que pour les années qui ont des
     * ventes (2026, 2027 à 0 entre deux années qui en ont, 2028) et dit les
     * sept années du début qu'elle ne montre pas ; la période se grise sur
     * « 10 dernières années », la comparaison et le cumul aussi, et la
     * barre des filtres le dit.
     */
    public function test_par_annee_la_carte_ne_montre_que_les_annees_qui_ont_des_ventes_et_grise_ses_filtres(): void
    {
        $grille = $this->grille($this->jeuPrincipal())->call('choisirLeDecoupage', 'annee')->assertHasNoErrors();
        $carte = $this->carte($grille);

        $this->assertSame(3, substr_count($carte, 'class="colonne'));
        $this->assertStringContainsString('aria-label="Année 2027 : 0,00 € — cliquer pour voir le détail"', $carte);
        $this->assertStringContainsString('class="zero"', $carte, 'Une année sans vente entre deux années qui en ont est à 0, pas absente.');
        $this->assertStringContainsString(TextesDesPeriodes::anneesCachees(7, false), $carte);
        $this->assertStringContainsString('Chiffre d\'affaires HT par année', $carte);
        $this->assertStringContainsString(TextesDesPeriodes::PERIODE_PAR_ANNEE, $grille->html());
        $this->assertSame([true, true, true], [$this->desactive($grille, 'periode'), $this->desactive($grille, 'comparer'), $this->desactive($grille, 'cumul')], 'La période, la comparaison et le cumul se grisent par année.');
        $this->assertSame(TextesDesPeriodes::COMPARAISON_PAR_ANNEE.' '.TextesDesPeriodes::CUMUL_PAR_ANNEE, $this->notesDeLEcran($grille)[1]);
        $this->assertSame(DecoupageDuTableauDeBord::Annee, $this->reglages()->decoupage, 'Le découpage se retient en base.');

        $grille->call('choisirLeDecoupage', 'mois');
        $this->assertSame([false, false, false], [$this->desactive($grille, 'periode'), $this->desactive($grille, 'comparer'), $this->desactive($grille, 'cumul')]);
    }

    /**
     * Une valeur forgée est refusée, rien ne change : un découpage qui
     * n'existe pas, le cumul coché par année, un mode ou une carte qui
     * n'existent pas, une barre inventée, une barre à venir, un jour que la
     * carte ne montre pas, et le détail d'une carte en cumul.
     */
    public function test_une_valeur_forgee_est_refusee_sans_rien_changer(): void
    {
        $grille = $this->grille($this->jeuPrincipal());
        $avant = $this->reglages()->versTableau();

        $grille->call('choisirLeDecoupage', 'semaine')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLeDecoupage', 'annee')->call('cumuler', true)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLeDecoupage', 'mois');
        $grille->call('changerLeMode', self::CARTE, 'pourcentage')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('changerLeMode', Graphique::ChiffreDAffairesParMagasin->value, 'ecart')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('ajouterLaTendance', Graphique::ChiffreDAffairesParMagasin->value, true)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLaBarre', self::CARTE, 'mois:2020-01-01:2020-01-31')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLaBarre', self::CARTE, 'mois:2028-11-01:2028-11-30')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLaBarre', 'inconnu', 'mois:2028-10-01:2028-10-31')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('choisirLaBarre', self::CARTE, 'jour:2028-10-05:2028-10-05')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);

        $this->assertSame([], $grille->get('details'), 'Aucun détail ne s\'ouvre sur une barre qui n\'est pas celle de la carte.');
        $this->assertNull($grille->get('listeDuJour'));

        $grille->call('cumuler', true)->call('choisirLaBarre', self::CARTE, 'mois:2028-10-01:2028-10-31')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $grille->call('cumuler', false);
        $this->assertSame($avant, $this->reglages()->versTableau(), 'Les gestes refusés n\'ont rien retenu.');
    }

    /**
     * Le clic sur une barre redécoupe la carte, plus finement à chaque
     * niveau (année, mois, jours), avec son fil et « Revenir » ; changer de
     * découpage ou de période referme le détail ; le détail n'est jamais
     * retenu en base.
     */
    public function test_le_clic_sur_une_barre_redecoupe_la_carte_et_revenir_remonte(): void
    {
        $grille = $this->grille($this->jeuPrincipal())->call('choisirLeDecoupage', 'annee');
        $annee = $this->jeton('Année 2028');
        $grille->call('choisirLaBarre', self::CARTE, $annee);
        $carte = $this->carte($grille);

        $this->assertSame(12, substr_count($carte, 'class="colonne'), 'L\'année se redécoupe en ses douze mois.');
        $this->assertStringContainsString('data-fil-niveau="0">Par année</button>', $carte);
        $this->assertStringContainsString('aria-current="page" data-fil-ici>2028</span>', $carte);
        $this->assertStringContainsString(TextesDesPeriodes::detail('Année 2028'), $carte);
        $this->assertSame([self::CARTE => [$annee]], $grille->get('details'));
        $this->assertStringContainsString('détail ouvert sur Année 2028.', $grille->html());

        $octobre = $this->jeton('Octobre 2028');
        $grille->call('choisirLaBarre', self::CARTE, $octobre);
        $carte = $this->carte($grille);

        $this->assertSame(31, substr_count($carte, 'class="colonne'), 'Octobre se redécoupe en ses 31 jours.');
        $this->assertStringContainsString('Cliquer sur un jour pour ouvrir la liste de ses documents.', $carte);
        $this->assertStringContainsString('aria-current="page" data-fil-ici>Octobre 2028</span>', $carte);
        $this->assertStringContainsString('aria-label="Samedi 21 octobre 2028 : à venir"', $carte, 'Un jour à venir n\'est pas un geste.');

        $grille->call('remonterAuNiveau', self::CARTE, 1);
        $this->assertSame([self::CARTE => [$annee]], $grille->get('details'));
        $grille->call('choisirLaBarre', self::CARTE, $octobre)->call('revenir', self::CARTE);
        $this->assertSame([self::CARTE => [$annee]], $grille->get('details'));
        $grille->call('revenir', self::CARTE);
        $this->assertSame([], $grille->get('details'));
        $this->assertSame(3, substr_count($this->carte($grille), 'class="colonne'));
        $grille->call('revenir', self::CARTE)->assertHasNoErrors();

        $grille->call('choisirLaBarre', self::CARTE, $annee)->call('choisirLeDecoupage', 'trimestre');
        $this->assertSame([], $grille->get('details'), 'Un autre découpage referme le détail.');
        $this->assertArrayNotHasKey('details', $this->reglages()->versTableau(), 'Le détail n\'est jamais retenu en base.');
    }

    /**
     * Le clic sur un jour ouvre la fenêtre de ses documents : un tableau par
     * liste que la personne voit en entier, avec le nombre de documents et
     * leur total HT net, et le lien vers la liste filtrée sur ce jour ; la
     * fenêtre se ferme, et un autre réglage la ferme aussi.
     */
    public function test_le_clic_sur_un_jour_ouvre_la_liste_de_ses_documents(): void
    {
        $grille = $this->grille($this->jeuPrincipal())->call('choisirLeDecoupage', 'annee');
        $grille->call('choisirLaBarre', self::CARTE, $this->jeton('Année 2028'))->call('choisirLaBarre', self::CARTE, $this->jeton('Octobre 2028'));
        $jour = $this->jeton('Jeudi 5 octobre 2028');
        $grille->call('choisirLaBarre', self::CARTE, $jour)->assertHasNoErrors();
        $html = $grille->html();

        $this->assertSame(['graphique' => self::CARTE, 'jeton' => $jour], $grille->get('listeDuJour'));
        $this->assertStringContainsString('data-liste-du-jour', $html);
        $this->assertStringContainsString('Documents du jeudi 5 octobre 2028', $html);
        $this->assertMatchesRegularExpression('#Factures et tickets</a></th>\s*<td class="piliqi-graphiques__montant">1</td>\s*<td class="piliqi-graphiques__montant">20,00 €</td>#u', $html);
        $this->assertMatchesRegularExpression('#Avoirs</a></th>\s*<td class="piliqi-graphiques__montant">0</td>\s*<td class="piliqi-graphiques__montant">0,00 €</td>#u', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/factures\?[^"]*tableFilters(?:%5B|\[)jour(?:%5D|\])(?:%5B|\[)jour(?:%5D|\])=2028-10-05#', $html);
        $this->assertStringNotContainsString('data-liste-du-magasin><h3', str_replace(["\n", '  '], '', $html), 'Un seul magasin : aucun nom de magasin.');
        $this->assertStringNotContainsString('Julie Petit', $html, 'Aucun nom de client dans la fenêtre.');

        $grille->call('fermerLaListe');
        $this->assertNull($grille->get('listeDuJour'));
        $this->assertStringNotContainsString('data-liste-du-jour', $grille->html());

        $grille->call('choisirLaBarre', self::CARTE, $jour)->call('revenir', self::CARTE);
        $this->assertNull($grille->get('listeDuJour'), '« Revenir » ferme la fenêtre.');
    }

    /**
     * Dans la fenêtre d'un jour, un avoir se compte en moins, comme dans le
     * graphique : un ticket de 40,00 € HT et un avoir de 20,00 € HT le même
     * jour font 40,00 € de ventes et −20,00 € d'avoirs, et 20,00 € de ventes
     * nettes dans la barre.
     */
    public function test_la_fenetre_d_un_jour_compte_un_avoir_en_moins(): void
    {
        $this->travelTo(self::aParis('2028-09-30 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        [$lampe, $client] = $this->lampeEtClient($magasin);
        $this->travelTo(self::aParis('2028-10-05 10:00:00'));
        $ticket = $this->vendre($magasin, $client, $lampe, 2);
        $this->travelTo(self::aParis('2028-10-05 11:00:00'));
        app(TenantContext::class)->runAs($magasin, fn () => app(EnregistrerAvoirAction::class)->handle(self::responsableDeTest($magasin), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour'));
        $this->travelTo(self::aParis('2028-10-15 12:00:00'));

        $grille = $this->grille($magasin);
        $grille->call('choisirLaBarre', self::CARTE, $this->jeton('Octobre 2028'));
        $grille->call('choisirLaBarre', self::CARTE, $this->jeton('Jeudi 5 octobre 2028'))->assertHasNoErrors();
        $html = html_entity_decode($grille->html(), ENT_QUOTES | ENT_HTML5);

        $this->assertMatchesRegularExpression('#Factures et tickets</a></th>\s*<td class="piliqi-graphiques__montant">1</td>\s*<td class="piliqi-graphiques__montant">40,00 €</td>#u', $html);
        $this->assertMatchesRegularExpression('#Avoirs</a></th>\s*<td class="piliqi-graphiques__montant">1</td>\s*<td class="piliqi-graphiques__montant">[-−]20,00 €</td>#u', $html);
        $this->assertStringContainsString('aria-label="Jeudi 5 octobre 2028 : 20,00 €', $this->carte($grille), 'La barre du jour est nette des avoirs.');
    }

    /**
     * La valeur du stock n'est la somme d'aucun document du jour : ses jours
     * ne s'ouvrent sur aucune liste.
     */
    public function test_la_valeur_du_stock_n_ouvre_aucune_liste(): void
    {
        $grille = $this->grille($this->jeuPrincipal());
        $grille->call('ajouterUnGraphique', Graphique::ValeurDuStock->value);
        $stock = Graphique::ValeurDuStock->value;
        $grille->call('choisirLaBarre', $stock, $this->jeton('Octobre 2028'));
        $grille->call('choisirLaBarre', $stock, $this->jeton('Jeudi 5 octobre 2028'))->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);

        $this->assertNull($grille->get('listeDuJour'));
        $this->assertStringNotContainsString('Cliquer sur un jour pour ouvrir la liste de ses documents.', $this->carte($grille, $stock));
    }

    /**
     * « Afficher : Écart en % sur un an » remplace les barres par une barre
     * verte ou rouge par période, avec « – » quand l'année d'avant valait 0 ;
     * le tableau garde les deux montants ; la tendance se grise ; le choix
     * se retient en base ; « Les montants » le défait.
     */
    public function test_l_ecart_en_pourcent_se_dessine_et_se_retient(): void
    {
        $grille = $this->grille($this->jeuPrincipal())->call('choisirLeDecoupage', 'annee');
        $grille->call('changerLeMode', self::CARTE, 'ecart')->assertHasNoErrors();
        $carte = $this->carte($grille);

        $this->assertStringContainsString('fill="var(--periode-baisse)"', $carte, '2027 est à −100 % de 2026.');
        $this->assertStringContainsString('class="tiret"', $carte, '2026 n\'a pas d\'année d\'avant : « – ».');
        $this->assertStringContainsString('Écart sur un an : −100,0 %', $carte);
        $this->assertStringContainsString('Cette année', $carte);
        $this->assertStringContainsString('Un an plus tôt', $carte);
        $this->assertStringContainsString('Au-dessus de 0 : en hausse sur un an', $carte);
        $this->assertStringContainsString(TextesDesPeriodes::ecart(true), $carte);
        $this->assertTrue($this->reglages()->carte(Graphique::ChiffreDAffairesParMois)?->ecart);

        $grille->call('changerLeMode', self::CARTE, 'montants');
        $this->assertFalse($this->reglages()->carte(Graphique::ChiffreDAffairesParMois)?->ecart);
        $this->assertStringNotContainsString('class="tiret"', $this->carte($grille));
    }

    /**
     * « Ajouter la tendance » dessine la moyenne sur trois périodes en
     * encre, avec sa légende, et se retient ; avec le cumul, avec l'écart
     * et dans un détail, la case se grise.
     */
    public function test_la_tendance_se_dessine_et_se_grise_avec_le_cumul_l_ecart_et_le_detail(): void
    {
        $grille = $this->grille($this->jeuPrincipal());
        $grille->call('ajouterLaTendance', self::CARTE, true)->assertHasNoErrors();
        $carte = $this->carte($grille);

        $this->assertStringContainsString('Moyenne sur 3 mois', $carte);
        $this->assertStringContainsString('stroke="var(--periode-encre)"', $carte);
        $this->assertStringContainsString('Ajouter la tendance (moyenne sur 3 mois)', $carte);
        $this->assertTrue($this->reglages()->carte(Graphique::ChiffreDAffairesParMois)?->tendance);
        $this->assertFalse($this->tendanceGrisee($carte));

        $grille->call('cumuler', true);
        $this->assertTrue($this->tendanceGrisee($this->carte($grille)), 'Pas de tendance sur un cumul.');
        $this->assertStringContainsString(TextesDesPeriodes::TENDANCE_ET_CUMUL, $this->carte($grille));
        $grille->call('cumuler', false)->call('changerLeMode', self::CARTE, 'ecart');
        $this->assertTrue($this->tendanceGrisee($this->carte($grille)), 'Pas de tendance sur l\'écart.');
        $grille->call('changerLeMode', self::CARTE, 'montants')->call('choisirLaBarre', self::CARTE, $this->jeton('Octobre 2028'));
        $this->assertTrue($this->tendanceGrisee($this->carte($grille)), 'Pas de tendance dans un détail.');

        $grille->call('revenir', self::CARTE)->call('ajouterLaTendance', self::CARTE, false);
        $this->assertStringNotContainsString('Moyenne sur 3 mois', $this->carte($grille));
    }

    /**
     * Le cumul depuis le début de l'année se dessine en courbe, avec
     * l'année précédente cumulée en pointillés quand « Comparer » est
     * cochée ; ses points ne se cliquent pas.
     */
    public function test_le_cumul_se_dessine_en_courbe_et_ne_se_clique_pas(): void
    {
        $grille = $this->grille($this->jeuPrincipal());
        $grille->call('cumuler', true)->call('comparer', true)->assertHasNoErrors();
        $carte = $this->carte($grille);

        $this->assertStringContainsString('stroke-dasharray="6 4"', $carte, 'L\'année précédente cumulée est en pointillés.');
        $this->assertStringNotContainsString('role="button"', $carte, 'Un point de cumul ne se détaille pas.');
        $this->assertStringContainsString('Cumul depuis le début de l\'année', html_entity_decode($grille->html(), ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('Le cumul se compte depuis le début de l\'année ; l\'année précédente est cumulée en pointillés.', $this->notesDeLEcran($grille)[0]);
        $this->assertSame([true], [$this->tendanceGrisee($carte)]);
    }

    /**
     * Les cartes ouvertes au départ, sur le site réel : les cartes
     * principales plus « Factures à encaisser par ancienneté » pour qui voit
     * ces factures ; une préférence enregistrée prime.
     */
    public function test_les_cartes_ouvertes_au_depart_du_site_reel_ajoutent_les_factures_par_anciennete(): void
    {
        $magasin = $this->jeuPrincipal();
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->clear();
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $magasin->societe()->firstOrFail());

        $this->assertSame(
            [Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::ChiffreDAffairesParMois, Graphique::FacturesParAnciennete],
            array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, ReglagesDuTableauDeBord::parDefaut($perimetre)->cartes),
        );

        $choisies = new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)]);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, $choisies);
        $this->assertSame([Graphique::ChiffreDAffairesParMois], array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->cartes), 'Une préférence enregistrée prime.');
    }

    /**
     * La ligne sous un chiffre clé a sa couleur : la variation sur un an
     * verte quand elle monte, « dont … en retard » rouge quand un retard
     * existe, « collectée − déductible » neutre.
     */
    public function test_la_ligne_sous_un_chiffre_cle_a_sa_couleur(): void
    {
        $this->travelTo(self::aParis('2027-09-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        [$lampe, $client] = $this->lampeEtClient($magasin);

        foreach ([['2026-10-10', 2], ['2027-10-05', 3]] as [$jour, $lampes]) {
            $this->travelTo(self::aParis($jour.' 10:00:00'));
            $this->vendre($magasin, $client, $lampe, $lampes);
        }

        $this->travelTo(self::aParis('2027-10-15 12:00:00'));
        $html = $this->grille($magasin)->html();

        $this->assertMatchesRegularExpression('#data-chiffre="ca".*?data-ton="positif">\+50,0 % sur un an#su', $html, '60,00 € contre 40,00 € le même mois : la variation monte, elle est verte.');
        $this->assertMatchesRegularExpression('#data-chiffre="tva".*?data-ton="neutre">collectée#su', $html);
        $this->assertMatchesRegularExpression('#data-chiffre="restant_du".*?data-ton="neutre">dont 0,00 € en retard#su', $html, 'Sans retard, la ligne reste neutre.');
    }

    private function jeuPrincipal(): Magasin
    {
        $this->travelTo(self::aParis('2026-02-09 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        [$lampe, $client] = $this->lampeEtClient($magasin);

        foreach ([['2026-02-10', 1], ['2026-07-20', 2], ['2028-02-14', 1], ['2028-04-18', 3], ['2028-09-12', 2], ['2028-10-05', 1]] as [$jour, $lampes]) {
            $this->travelTo(self::aParis($jour.' 10:00:00'));
            $this->vendre($magasin, $client, $lampe, $lampes);
        }

        $this->travelTo(self::aParis('2028-10-15 12:00:00'));

        return $magasin;
    }

    /**
     * @return array{0: Produit, 1: Client}
     */
    private function lampeEtClient(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Client::factory()->create(['nom' => 'Julie Petit']),
        ]);
    }

    private function vendre(Magasin $magasin, Client $client, Produit $lampe, int $lampes): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant(self::responsableDeTest($magasin), $client, [['produit_id' => $lampe->id, 'quantite' => $lampes]]));
    }

    /**
     * La grille dans l'espace de la société, pour le propriétaire du magasin
     * du jeu.
     */
    private function grille(Magasin $magasin): Testable
    {
        $this->societe = $magasin->societe()->firstOrFail();
        $this->proprietaire = $this->proprietaireDe($magasin);
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        return Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value]);
    }

    /**
     * Les réglages retenus en base pour le propriétaire, sur l'écran de la
     * société.
     */
    private function reglages(): ReglagesDuTableauDeBord
    {
        app(TenantContext::class)->clear();

        return app(PreferencesDesGraphiques::class)->lire($this->proprietaire, app(PerimetreDesGraphiques::class)->deLaSociete($this->proprietaire, $this->societe));
    }

    /**
     * Le HTML de la carte de ce graphique, tel que le navigateur le lit.
     */
    private function carte(Testable $grille, string $graphique = self::CARTE): string
    {
        preg_match('#<section\b[^>]*data-graphique="'.preg_quote($graphique, '#').'".*?</section>#su', $grille->html(), $carte);

        $this->assertNotEmpty($carte, "La carte {$graphique} n'est pas dans la page.");

        return html_entity_decode($carte[0], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Le jeton de la barre qui porte cet intitulé, une année, un mois ou un
     * jour des dix ans qui finissent le 15 octobre 2028.
     */
    private function jeton(string $intitule): string
    {
        $barres = DecoupageDesPeriodes::barres(DecoupageDuTableauDeBord::Annee, PeriodeDuTableauDeBord::DouzeDerniersMois, false, '2028-10-15', 12);

        return $this->chercher($barres, $intitule) ?? $this->fail("Aucune barre « {$intitule} ».");
    }

    /**
     * @param  list<Barre>  $barres
     */
    private function chercher(array $barres, string $intitule): ?string
    {
        foreach ($barres as $barre) {
            if ($barre->intitule === $intitule) {
                return $barre->jeton();
            }

            $trouvee = $barre->niveau === NiveauDeLaBarre::Jour ? null : $this->chercher(DecoupageDesPeriodes::sousBarres($barre, '2028-10-15', 12), $intitule);

            if ($trouvee !== null) {
                return $trouvee;
            }
        }

        return null;
    }

    private function desactive(Testable $grille, string $filtre): bool
    {
        preg_match('#<(?:input|select)\b[^>]*data-filtre="'.$filtre.'"[^>]*>#su', $grille->html(), $balise);

        return preg_match('/\sdisabled(?=[\s=>])/', $balise[0] ?? '') === 1;
    }

    /**
     * @return list<string>
     */
    private function notesDeLEcran(Testable $grille): array
    {
        preg_match('#data-notes-de-l-ecran>(.*?)</div>#su', $grille->html(), $bloc);
        preg_match_all('#<p class="piliqi-graphiques__gris">(.*?)</p>#su', $bloc[1] ?? '', $notes);

        return array_map(fn (string $note): string => html_entity_decode(trim($note), ENT_QUOTES | ENT_HTML5), $notes[1]);
    }

    private function tendanceGrisee(string $carte): bool
    {
        preg_match('#<input\b[^>]*data-geste="tendance"[^>]*>#su', $carte, $balise);

        return preg_match('/\sdisabled(?=[\s=>])/', $balise[0] ?? '') === 1;
    }
}
