<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\ListeDeDocuments;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\TonDUnChiffre;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\TextesDesPeriodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le catalogue des graphiques et la carte d'une grille après le lot
 * GRAPH-AN (choix de l'éditeur du 16/11/2026 ; décisions de Claude du
 * 22/11/2026, D76), sans base de données : le nombre de graphiques s'écrit
 * une seule fois, « Chiffre d'affaires HT par an » n'existe plus, « Ventes
 * par vendeur » est un classement, sept graphiques sont par période, la
 * carte retient l'écart et la tendance et refuse toute autre valeur, les
 * titres suivent le pas des barres, les phrases grises disent ce qu'elles
 * doivent.
 */
class CartesDuTableauDeBordTest extends TestCase
{
    public function test_le_nombre_de_graphiques_s_ecrit_une_seule_fois_et_le_graphique_par_an_n_existe_plus(): void
    {
        $this->assertCount(Graphique::NOMBRE, Graphique::cases());
        $this->assertNull(Graphique::tryFrom('ca_par_an'));
        $this->assertSame(Graphique::VentesParVendeur, Graphique::from('ventes_par_vendeur'));
        $this->assertSame(Graphique::EcartDesPrixDAchat, Graphique::cases()[count(Graphique::cases()) - 1], 'L\'écart des prix d\'achat reste le dernier du catalogue.');
        $this->assertSame('Ventes par vendeur', Graphique::VentesParVendeur->getLabel());
    }

    /**
     * CLAUDE.md et le README disent le nombre de graphiques du catalogue,
     * et que le dernier est l'écart des prix d'achat : ce nombre s'écrit
     * dans Graphique::NOMBRE, et ces textes le relisent ici.
     */
    public function test_claude_md_et_le_readme_disent_le_nombre_de_graphiques_du_catalogue(): void
    {
        $cardinaux = [18 => 'dix-huit', 19 => 'dix-neuf', 20 => 'vingt'];
        $ordinaux = [18 => 'dix-huitième', 19 => 'dix-neuvième', 20 => 'vingtième'];
        $racine = dirname(__DIR__, 2);

        $this->assertArrayHasKey(Graphique::NOMBRE, $cardinaux, 'Écrire le nombre de graphiques en lettres ici, dans CLAUDE.md et dans le README.');
        $this->assertStringContainsString("parmi {$cardinaux[Graphique::NOMBRE]} graphiques", (string) file_get_contents($racine.'/CLAUDE.md'));
        $this->assertStringContainsString("Le {$ordinaux[Graphique::NOMBRE]}, « Écart des prix d'achat »", (string) file_get_contents($racine.'/CLAUDE.md'));
        $this->assertStringContainsString("(le {$ordinaux[Graphique::NOMBRE]} graphique)", (string) file_get_contents($racine.'/README.md'));
    }

    public function test_ventes_par_vendeur_est_un_classement_en_barres_couchees_des_ventes_de_toute_l_equipe(): void
    {
        $this->assertSame(TypeDeGraphique::BarresCouchees, Graphique::VentesParVendeur->typeParDefaut());
        $this->assertSame([TypeDeGraphique::BarresCouchees, TypeDeGraphique::Barres, TypeDeGraphique::Anneau, TypeDeGraphique::Camembert], Graphique::VentesParVendeur->types());
        $this->assertSame('ventes', Graphique::VentesParVendeur->sources(true)[0]->value);
        $this->assertCount(1, Graphique::VentesParVendeur->sources(false));
        $this->assertFalse(Graphique::VentesParVendeur->exigeDesArticles());
        $this->assertFalse(Graphique::VentesParVendeur->estParPeriode());
    }

    public function test_sept_graphiques_sont_par_periode_et_seul_le_stock_n_accepte_pas_le_cumul(): void
    {
        $parPeriode = array_values(array_filter(Graphique::cases(), fn (Graphique $graphique): bool => $graphique->estParPeriode()));

        $this->assertSame([Graphique::ChiffreDAffairesParMois, Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::MargeBruteParMois, Graphique::ValeurDuStock, Graphique::NombreDeVentesEtPanierMoyen, Graphique::AvoirsParMois], $parPeriode);
        $this->assertSame([Graphique::ValeurDuStock], array_values(array_filter($parPeriode, fn (Graphique $graphique): bool => ! $graphique->accepteLeCumul())));
        $this->assertFalse(Graphique::EcartDesPrixDAchat->accepteLeCumul());
    }

    public function test_le_titre_et_la_ligne_grise_suivent_le_pas_des_barres(): void
    {
        $this->assertSame('Chiffre d\'affaires HT par trimestre', Graphique::ChiffreDAffairesParMois->titre(NiveauDeLaBarre::Trimestre));
        $this->assertSame('Ventes et achats HT par année', Graphique::VentesEtAchatsParMois->titre(NiveauDeLaBarre::Annee, false));
        $this->assertSame('Marge brute HT par exercice', Graphique::MargeBruteParMois->titre(NiveauDeLaBarre::Annee, true));
        $this->assertSame('Avoirs émis par jour', Graphique::AvoirsParMois->titre(NiveauDeLaBarre::Jour));
        $this->assertSame('TVA à payer par mois (estimation)', Graphique::TvaAPayerParMois->titre());
        $this->assertSame('Valeur du stock au coût moyen', Graphique::ValeurDuStock->titre(NiveauDeLaBarre::Annee), 'Le titre qui ne dit pas « par mois » ne change pas.');
        $this->assertSame('Les ventes HT émises, moins les avoirs émis, mois par mois.', Graphique::ChiffreDAffairesParMois->ceQuIlCompte());
        $this->assertSame('Les ventes HT émises, moins les avoirs émis, exercice par exercice.', Graphique::ChiffreDAffairesParMois->ceQuIlCompte(NiveauDeLaBarre::Annee, true));
        $this->assertStringContainsString('à la fin de chaque trimestre', Graphique::ValeurDuStock->ceQuIlCompte(NiveauDeLaBarre::Trimestre));
    }

    public function test_les_listes_qu_un_jour_ouvre(): void
    {
        $this->assertSame([ListeDeDocuments::Factures, ListeDeDocuments::Avoirs], Graphique::ChiffreDAffairesParMois->listesDuJour(true));
        $this->assertSame([ListeDeDocuments::Factures, ListeDeDocuments::Avoirs, ListeDeDocuments::FacturesAchat, ListeDeDocuments::AvoirsFournisseur], Graphique::VentesEtAchatsParMois->listesDuJour(true));
        $this->assertSame([ListeDeDocuments::Factures, ListeDeDocuments::Avoirs], Graphique::TvaAPayerParMois->listesDuJour(false), 'Une société de services n\'a pas d\'achats.');
        $this->assertSame([ListeDeDocuments::Avoirs], Graphique::AvoirsParMois->listesDuJour(true));
        $this->assertSame([], Graphique::ValeurDuStock->listesDuJour(true), 'La valeur du stock n\'ouvre aucune liste.');
        $this->assertSame([], Graphique::MeilleursClients->listesDuJour(true));
    }

    public function test_la_carte_retient_l_ecart_et_la_tendance_d_un_graphique_par_periode(): void
    {
        $carte = CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLEcart(true)->avecLaTendance(true)->avecLeType(TypeDeGraphique::Lignes);

        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'lignes', 'ecart' => true, 'tendance' => true], $carte->versTableau());
        $this->assertEquals($carte, CarteDuTableauDeBord::lire($carte->versTableau()));
        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'lignes', 'ecart' => false, 'tendance' => true], $carte->avecLEcart(false)->versTableau());
        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'barres', 'ecart' => false, 'tendance' => false], CarteDuTableauDeBord::lire(['graphique' => 'ca_par_mois', 'type' => 'barres'])?->versTableau(), 'Sans les clés, ni écart ni tendance.');
        $this->assertTrue($carte->avecLaMesure('quantite')->ecart, 'Un autre réglage de la carte garde son écart.');
        $this->assertTrue($carte->avecLAffichage('debut')->tendance);
    }

    /**
     * @param  array<string, mixed>  $carte
     */
    #[DataProvider('cartesIllisibles')]
    public function test_une_carte_dont_une_valeur_est_inconnue_ne_se_lit_pas(array $carte): void
    {
        $this->assertNull(CarteDuTableauDeBord::lire($carte));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cartesIllisibles(): array
    {
        return [
            'le graphique retiré' => [['graphique' => 'ca_par_an', 'type' => 'barres']],
            'un écart qui n\'est pas un booléen' => [['graphique' => 'ca_par_mois', 'type' => 'barres', 'ecart' => 'oui']],
            'une tendance qui n\'est pas un booléen' => [['graphique' => 'ca_par_mois', 'type' => 'barres', 'tendance' => 1]],
            'un écart sur un graphique qui n\'est pas par période' => [['graphique' => 'ca_par_magasin', 'type' => 'anneau', 'ecart' => true]],
            'une tendance sur les meilleurs produits' => [['graphique' => 'meilleurs_produits', 'type' => 'barres_couchees', 'tendance' => false]],
            'un écart sur l\'écart des prix d\'achat' => [['graphique' => 'ecart_des_prix_d_achat', 'type' => 'barres', 'ecart' => false]],
            'une clé inconnue' => [['graphique' => 'ca_par_mois', 'type' => 'barres', 'cumul' => true]],
            'un type que le graphique ne permet pas' => [['graphique' => 'ca_par_mois', 'type' => 'anneau']],
        ];
    }

    public function test_les_tons_et_les_phrases_du_decoupage(): void
    {
        $this->assertSame([TonDUnChiffre::Positif, TonDUnChiffre::Negatif, TonDUnChiffre::Neutre], [TonDUnChiffre::dUneVariation(1), TonDUnChiffre::dUneVariation(-1), TonDUnChiffre::dUneVariation(0)]);
        $this->assertSame(['mois', 'trimestre', 'annee'], array_map(fn (DecoupageDuTableauDeBord $decoupage): string => $decoupage->value, DecoupageDuTableauDeBord::cases()));
        $this->assertSame('Une année plus ancienne sans donnée n\'est pas affichée.', TextesDesPeriodes::anneesCachees(1, false));
        $this->assertSame('7 années plus anciennes sans donnée ne sont pas affichées.', TextesDesPeriodes::anneesCachees(7, false));
        $this->assertSame('Un exercice plus ancien sans donnée n\'est pas affiché.', TextesDesPeriodes::anneesCachees(1, true));
        $this->assertSame('6 exercices plus anciens sans donnée ne sont pas affichés.', TextesDesPeriodes::anneesCachees(6, true));
        $this->assertSame('Cumul depuis le début de l\'année : chaque point ajoute les précédents. La carte part du début de l\'année en cours.', TextesDesPeriodes::cumul(false, true));
        $this->assertSame('Cumul depuis le début de l\'exercice : chaque point ajoute les précédents.', TextesDesPeriodes::cumul(true, false));
        $this->assertStringContainsString('La période en cours se compare aux mêmes jours de l\'an dernier.', TextesDesPeriodes::ecart(true));
        $this->assertStringNotContainsString('mêmes jours', TextesDesPeriodes::ecart(false));
    }
}
