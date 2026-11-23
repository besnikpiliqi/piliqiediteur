<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les gestes de préférence de la grille des graphiques (décision de
 * l'éditeur du 23/11/2026 : un geste qui ne change que l'affichage de ce que
 * la page contient déjà ne fait pas attendre) : le type, « Monter »,
 * « Descendre », la croix, « Classer par » et le menu « Afficher : » se font
 * aussitôt dans la page (tests/Browser/Parcours/GestesSansAttenteTest.php) ;
 * le serveur les retient ensuite sans redessiner la grille, rend ce qu'il a
 * retenu, et un geste de la même requête qui change ce que la grille calcule
 * la redessine quand même. Ces tests prouvent le côté du serveur.
 */
class GestesDePreferenceDeLaGrilleTest extends TestCase
{
    use RefreshDatabase;

    private const ECRAN = ['ecran' => 'societe'];

    private const CA = 'ca_par_mois';

    private const PRODUITS = 'meilleurs_produits';

    private const ECART = 'ecart_des_prix_d_achat';

    /**
     * Chaque geste retient son choix en base, ne redessine pas la grille (la
     * réponse n'a pas de HTML) et rend l'état de ce que le serveur a retenu :
     * l'ordre des cartes et, pour chacune, son type, sa mesure ou son
     * affichage.
     */
    public function test_chaque_geste_de_preference_retient_son_choix_sans_redessiner_la_grille_et_rend_ce_que_le_serveur_a_retenu(): void
    {
        [$lyon, $proprietaire, $grille] = $this->grilleDeTroisCartes();
        $etat = fn (array $ordre, array $cartes): array => ['ordre' => $ordre, 'cartes' => $cartes];

        $grille->call('changerLeType', self::CA, TypeDeGraphique::Lignes->value)
            ->assertNotNotified()
            ->assertReturned($etat([self::CA, self::PRODUITS, self::ECART], [
                self::CA => ['type' => 'lignes'],
                self::PRODUITS => ['type' => 'barres_couchees', 'mesure' => 'ca'],
                self::ECART => ['type' => 'barres', 'affichage' => 'avant'],
            ]));
        $this->assertSansRedessin($grille, 'le type');

        $grille->call('changerLaMesure', self::PRODUITS, CarteDuTableauDeBord::PAR_QUANTITE)->assertNotNotified();
        $this->assertSansRedessin($grille, 'la mesure');
        $this->assertSame('quantite', $this->cartesDuCompte($proprietaire, $lyon)[self::PRODUITS]->mesure);

        $grille->call('changerLAffichage', self::ECART, CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS)->assertNotNotified();
        $this->assertSansRedessin($grille, 'l\'affichage');
        $this->assertSame('debut', $this->cartesDuCompte($proprietaire, $lyon)[self::ECART]->affichage);

        $grille->call('monter', self::PRODUITS)
            ->assertReturned($etat([self::PRODUITS, self::CA, self::ECART], [
                self::PRODUITS => ['type' => 'barres_couchees', 'mesure' => 'quantite'],
                self::CA => ['type' => 'lignes'],
                self::ECART => ['type' => 'barres', 'affichage' => 'debut'],
            ]));
        $this->assertSansRedessin($grille, '« Monter »');

        $grille->call('descendre', self::PRODUITS)->assertReturned($etat([self::CA, self::PRODUITS, self::ECART], [
            self::CA => ['type' => 'lignes'],
            self::PRODUITS => ['type' => 'barres_couchees', 'mesure' => 'quantite'],
            self::ECART => ['type' => 'barres', 'affichage' => 'debut'],
        ]));
        $this->assertSansRedessin($grille, '« Descendre »');

        $grille->call('retirerLeGraphique', self::CA)->assertReturned($etat([self::PRODUITS, self::ECART], [
            self::PRODUITS => ['type' => 'barres_couchees', 'mesure' => 'quantite'],
            self::ECART => ['type' => 'barres', 'affichage' => 'debut'],
        ]));
        $this->assertSansRedessin($grille, 'la croix');

        $this->assertSame([self::PRODUITS, self::ECART], array_keys($this->cartesDuCompte($proprietaire, $lyon)));
    }

    /**
     * Un geste refusé rend null (la page revient à ce que le serveur avait
     * retenu), dit pourquoi en alerte et n'écrit rien, sans redessiner la
     * grille non plus.
     */
    public function test_un_geste_de_preference_refuse_rend_null_dit_pourquoi_et_n_ecrit_rien(): void
    {
        [$lyon, $proprietaire, $grille] = $this->grilleDeTroisCartes();
        $avant = $this->reglagesEnBase($proprietaire);

        foreach ([
            ['changerLeType', [self::CA, TypeDeGraphique::Radar->value]],
            ['changerLeType', [self::CA, 'histogramme']],
            ['changerLeType', ['inconnu', 'barres']],
            ['changerLaMesure', [self::CA, CarteDuTableauDeBord::PAR_QUANTITE]],
            ['changerLaMesure', [self::PRODUITS, 'poids']],
            ['changerLAffichage', [self::PRODUITS, 'debut']],
            ['changerLAffichage', [self::ECART, 'hier']],
            ['monter', ['inconnu']],
            ['descendre', ['inconnu']],
            ['retirerLeGraphique', ['inconnu']],
        ] as [$geste, $valeurs]) {
            $grille->call($geste, ...$valeurs)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR)->assertReturned(null);
            $this->assertSansRedessin($grille, "{$geste} refusé");
        }

        $this->assertSame($avant, $this->reglagesEnBase($proprietaire));
        $this->assertSame([self::CA, self::PRODUITS, self::ECART], array_keys($this->cartesDuCompte($proprietaire, $lyon)));
    }

    /**
     * « Monter » en bout de grille ne change rien, et le serveur le dit :
     * l'état rendu est celui d'avant.
     */
    public function test_monter_la_premiere_carte_ne_change_rien_et_rend_l_ordre_retenu(): void
    {
        [, , $grille] = $this->grilleDeTroisCartes();

        $grille->call('monter', self::CA)->assertNotNotified()->assertReturned(fn (array $etat): bool => $etat['ordre'] === [self::CA, self::PRODUITS, self::ECART]);
        $grille->call('descendre', self::ECART)->assertNotNotified()->assertReturned(fn (array $etat): bool => $etat['ordre'] === [self::CA, self::PRODUITS, self::ECART]);
    }

    /**
     * Livewire saute le dessin de toute la requête dès qu'un appel le
     * demande : un geste de préférence et un geste qui change ce que la grille
     * calcule, envoyés ensemble (l'utilisateur les enchaîne vite, ou le
     * premier attend encore sa réponse), redessinent la grille, dans l'un et
     * l'autre ordre, et chacun est retenu.
     */
    public function test_un_geste_de_preference_et_un_geste_qui_redessine_dans_la_meme_requete_redessinent_la_grille_dans_les_deux_ordres(): void
    {
        [$lyon, $proprietaire, $grille] = $this->grilleDeTroisCartes();
        $appel = fn (string $methode, mixed ...$valeurs): array => ['method' => $methode, 'params' => $valeurs, 'path' => ''];

        $grille->update(calls: [$appel('monter', self::PRODUITS), $appel('choisirLaPeriode', PeriodeDuTableauDeBord::AnneeEnCours->value)]);

        $this->assertArrayHasKey('html', $grille->effects, 'Un geste de préférence avant la période n\'empêche pas le dessin.');
        $this->assertSame([self::PRODUITS, self::CA, self::ECART], array_keys($this->cartesDuCompte($proprietaire, $lyon)));
        $this->assertSame(PeriodeDuTableauDeBord::AnneeEnCours, $this->reglagesDuCompte($proprietaire, $lyon)->periode);
        $this->assertStringContainsString('value="annee_en_cours" selected', $grille->html());

        $grille->update(calls: [$appel('choisirLaPeriode', PeriodeDuTableauDeBord::DouzeDerniersMois->value), $appel('descendre', self::PRODUITS)]);

        $this->assertArrayHasKey('html', $grille->effects, 'Un geste de préférence après la période n\'empêche pas le dessin.');
        $this->assertSame([self::CA, self::PRODUITS, self::ECART], array_keys($this->cartesDuCompte($proprietaire, $lyon)));
        $this->assertSame(PeriodeDuTableauDeBord::DouzeDerniersMois, $this->reglagesDuCompte($proprietaire, $lyon)->periode);
        $this->assertStringContainsString('value="douze_derniers_mois" selected', $grille->html());

        $grille->update(calls: [$appel('changerLeType', self::CA, 'aires'), $appel('comparer', true)]);
        $this->assertArrayHasKey('html', $grille->effects);
        $this->assertTrue($this->reglagesDuCompte($proprietaire, $lyon)->comparer);
        $this->assertSame(TypeDeGraphique::Aires, $this->cartesDuCompte($proprietaire, $lyon)[self::CA]->type);

        $grille->update(calls: [$appel('ajouterUnGraphique', Graphique::AvoirsParMois->value)]);
        $this->assertArrayHasKey('html', $grille->effects, 'Ajouter un graphique redessine la grille.');
        $this->assertStringContainsString('data-graphique="'.Graphique::AvoirsParMois->value.'"', $grille->html());
    }

    /**
     * Les meilleurs produits sont dans la page avec leurs deux classements,
     * chacun dans sa face (le chiffre d'affaires d'abord) : celle de la
     * mesure retenue est montrée, l'autre est cachée jusqu'à « Classer
     * par ».
     */
    public function test_la_page_ecrit_les_deux_classements_des_meilleurs_produits_et_cache_celui_qui_n_est_pas_retenu(): void
    {
        [, , $grille] = $this->grilleDeTroisCartes();

        $grille->assertViewHas('cartes', function (array $cartes): bool {
            $produits = array_values(array_filter($cartes, fn (array $carte): bool => $carte['carte']->graphique === Graphique::MeilleursProduits))[0];

            return array_keys($produits['faces']) === ['ca', 'quantite'] && $produits['faces']['ca']['config']['jeux'][0]['libelle'] === 'Chiffre d\'affaires HT' && $produits['faces']['quantite']['config']['jeux'][0]['libelle'] === 'Quantité vendue';
        });
        $html = $grille->html();
        $this->assertSame(1, preg_match('/data-face="ca"\s*>/', $html), 'Le classement retenu n\'est pas caché.');
        $this->assertSame(1, preg_match('/x-show="mesure === \'quantite\'"\s+x-cloak\s+data-face="quantite"/', $html), 'L\'autre classement est caché.');
        $this->assertSame(1, preg_match('/data-mesure="ca"/', $html));
        $this->assertSame(1, preg_match('/x-on:change="appliquer\(\(valeur\) => mesure = valeur, \(valeur\) => \$wire\.changerLaMesure\(/', $html), 'Classer par change la page, puis s\'enregistre.');

        $parQuantite = $this->grille(CarteDuTableauDeBord::PAR_QUANTITE);
        $this->assertSame(1, preg_match('/data-face="quantite"\s*>/', $parQuantite->html()));
        $this->assertSame(1, preg_match('/x-show="mesure === \'ca\'"\s+x-cloak\s+data-face="ca"/', $parQuantite->html()));
    }

    /**
     * Les gestes qui calculent redessinent la grille et la page relit alors ce
     * que le serveur a retenu : le type, la mesure et l'affichage de chaque
     * carte sont écrits dans la carte, et la liste des graphiques que le menu
     * peut proposer dans la grille.
     */
    public function test_la_page_ecrit_ce_que_le_serveur_a_retenu_pour_que_la_page_le_relise_apres_un_redessin(): void
    {
        [, , $grille] = $this->grilleDeTroisCartes();
        $grille->call('changerLeType', self::CA, 'aires');
        $grille->call('changerLAffichage', self::ECART, 'debut');
        $grille->call('comparer', true);
        $html = $grille->html();

        $this->assertMatchesRegularExpression('/data-graphique="ca_par_mois"\s+data-type="aires"/', $html);
        $this->assertMatchesRegularExpression('/data-graphique="meilleurs_produits"\s+data-type="barres_couchees"\s+data-mesure="ca"/', $html);
        $this->assertMatchesRegularExpression('/data-graphique="ecart_des_prix_d_achat"\s+data-type="barres"\s+data-affichage="debut"/', $html);
        $this->assertMatchesRegularExpression('/<div x-data="piliqiGrille\(\)" data-disponibles="[^"]*ca_par_mois[^"]*"/', $html);
    }

    /**
     * Les gestes sont ceux du compte connecté, dans sa société : le serveur
     * ne retient rien pour un autre compte ni une autre société, et la
     * valeur d'un graphique que le compte ne voit pas est refusée.
     */
    public function test_les_gestes_ne_touchent_que_les_preferences_du_compte_dans_sa_societe(): void
    {
        [$lyon, $proprietaire, $grille] = $this->grilleDeTroisCartes();
        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $autreProprietaire = $this->proprietaireDe($autre);
        app(TenantContext::class)->clear();
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $grille->call('retirerLeGraphique', self::CA)->call('changerLeType', self::PRODUITS, 'camembert');

        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $autreProprietaire->id)->count());
        $this->assertSame(1, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $lyon->societe_id)->where('user_id', $proprietaire->id)->count());
        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $autre->societe_id)->count());
    }

    /**
     * La réponse d'un geste de préférence ne dessine pas la grille.
     */
    private function assertSansRedessin(Testable $grille, string $geste): void
    {
        $this->assertArrayNotHasKey('html', $grille->effects, "{$geste} ne redessine pas la grille.");
    }

    /**
     * La grille de l'espace de la société de Lyon Centre et Villeurbanne,
     * du propriétaire, avec trois cartes : le chiffre d'affaires par mois, les
     * meilleurs produits et l'écart des prix d'achat.
     *
     * @return array{0: Magasin, 1: User, 2: Testable}
     */
    private function grilleDeTroisCartes(string $mesure = CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES): array
    {
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();
        $lyon = $lyon->fresh();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois),
            new CarteDuTableauDeBord(Graphique::MeilleursProduits, Graphique::MeilleursProduits->typeParDefaut(), $mesure),
            CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat),
        ]));

        return [$lyon, $proprietaire, Livewire::test(GrilleDesGraphiques::class, self::ECRAN)];
    }

    private function grille(string $mesure): Testable
    {
        return $this->grilleDeTroisCartes($mesure)[2];
    }

    /**
     * Les cartes retenues du compte dans l'espace de la société, par graphique.
     *
     * @return array<string, CarteDuTableauDeBord>
     */
    private function cartesDuCompte(User $compte, Magasin $magasin): array
    {
        $cartes = [];

        foreach ($this->reglagesDuCompte($compte, $magasin)->cartes as $carte) {
            $cartes[$carte->graphique->value] = $carte;
        }

        return $cartes;
    }

    private function reglagesDuCompte(User $compte, Magasin $magasin): ReglagesDuTableauDeBord
    {
        $societe = $magasin->societe()->firstOrFail();
        $this->dansLEspaceDeLaSociete($societe, $compte);

        return app(PreferencesDesGraphiques::class)->lire($compte, app(PerimetreDesGraphiques::class)->deLaSociete($compte, $societe));
    }

    /**
     * Ce que la base retient des préférences du compte.
     *
     * @return array<string, mixed>
     */
    private function reglagesEnBase(User $compte): array
    {
        return PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $compte->id)->sole()->reglages;
    }
}
