<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les préférences du tableau de bord (décision de l'éditeur du 14/11/2026,
 * lot GRAPH) : pour chaque compte et chaque écran, les graphiques
 * affichés, leur ordre, leur type et les filtres, retenus en base dans la
 * société du compte, jamais dans le navigateur ; une valeur forgée est
 * refusée et rien ne change ; le réglage d'un compte ne touche jamais
 * celui d'un autre.
 */
class PreferencesDesGraphiquesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire ajoute « Ventes par taux de TVA », le passe en
     * camembert, le monte avant le chiffre d'affaires par magasin, retire
     * la TVA à payer, choisit l'année précédente, Villeurbanne et la
     * comparaison ; à sa connexion suivante, tout est là, dans cet ordre.
     */
    public function test_ajout_retrait_type_ordre_et_filtres_sont_retenus_apres_une_nouvelle_connexion(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('ajouterUnGraphique', Graphique::VentesParTauxDeTva->value)
            ->call('changerLeType', Graphique::VentesParTauxDeTva->value, TypeDeGraphique::Camembert->value)
            ->call('monter', Graphique::VentesParTauxDeTva->value)
            ->call('retirerLeGraphique', Graphique::TvaAPayerParMois->value)
            ->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneePrecedente->value)
            ->call('choisirLeMagasin', (string) $villeurbanne->uuid)
            ->call('comparer', true)
            ->assertHasNoErrors();

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire->refresh());
        $reglages = app(PreferencesDesGraphiques::class)->lire($proprietaire, app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe));

        $this->assertSame([
            'cartes' => [
                ['graphique' => Graphique::VentesEtAchatsParMois->value, 'type' => 'barres', 'ecart' => false, 'tendance' => false],
                ['graphique' => Graphique::ChiffreDAffairesParMois->value, 'type' => 'barres', 'ecart' => false, 'tendance' => false],
                ['graphique' => Graphique::VentesParTauxDeTva->value, 'type' => 'camembert'],
                ['graphique' => Graphique::ChiffreDAffairesParMagasin->value, 'type' => 'anneau'],
            ],
            'periode' => 'annee_precedente',
            'magasin' => (string) $villeurbanne->uuid,
            'comparer' => true,
            'decoupage' => 'mois',
            'cumul' => false,
        ], $reglages->versTableau());
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->assertSeeInOrder(['Ventes et achats HT par mois', 'Chiffre d\'affaires HT par mois', 'Ventes par taux de TVA', 'Chiffre d\'affaires par magasin']);
    }

    /**
     * Chaque valeur forgée (un graphique hors du catalogue, un type que le
     * graphique ne permet pas, une période inconnue, un filtre de magasin dans
     * le panneau d'un magasin) est refusée en alerte, le magasin d'une
     * autre société aussi, sans dire pourquoi, et rien n'est enregistré.
     */
    public function test_une_valeur_forgee_est_refusee_et_rien_ne_change(): void
    {
        [$lyon] = $this->deuxMagasins();
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        $grille = Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value]);

        foreach ([
            ['ajouterUnGraphique', ['inconnu']],
            ['changerLeType', [Graphique::ChiffreDAffairesParMois->value, TypeDeGraphique::Radar->value]],
            ['changerLeType', [Graphique::ChiffreDAffairesParMois->value, 'histogramme']],
            ['changerLaMesure', [Graphique::ChiffreDAffairesParMois->value, CarteDuTableauDeBord::PAR_QUANTITE]],
            ['choisirLaPeriode', ['depuis_toujours']],
        ] as [$geste, $valeurs]) {
            $grille->call($geste, ...$valeurs)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        }

        $grille->call('choisirLeMagasin', (string) $autre->uuid)->assertNotified(PerimetreDesGraphiques::REFUS);

        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count());

        $this->dansLeMagasin($lyon, $proprietaire);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Magasin->value])
            ->call('choisirLeMagasin', (string) $lyon->uuid)
            ->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Le menu « Ajouter un graphique » (constat de l'éditeur du 15/11/2026)
     * : après l'ajout, il revient sur « — Choisir — » et ne propose plus le
     * graphique affiché, qui y revient quand sa croix le retire ; la
     * valeur vide ne fait rien, sans message ni enregistrement ; une valeur
     * inconnue reste refusée ; quand tout est affiché, il le dit.
     */
    public function test_le_menu_ajouter_revient_sur_choisir_ignore_la_valeur_vide_et_ne_propose_que_les_graphiques_non_affiches(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        $option = fn (Graphique $graphique): string => '<option value="'.$graphique->value.'">';
        $grille = Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value]);

        $grille->call('ajouterUnGraphique', '')
            ->assertNotNotified()
            ->assertSeeHtml($option(Graphique::VentesParTauxDeTva))
            ->assertSeeHtmlInOrder([$option(Graphique::ArticlesEtServices), $option(Graphique::EcartDesPrixDAchat)]);
        $this->assertSame(Graphique::EcartDesPrixDAchat, Graphique::cases()[count(Graphique::cases()) - 1], 'La carte de l\'écart des prix d\'achat est la dernière du menu.');
        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count(), 'La valeur vide n\'enregistre rien.');

        $grille->call('ajouterUnGraphique', Graphique::VentesParTauxDeTva->value)
            ->assertNotNotified()
            ->assertSeeHtml('<option value="" selected>— Choisir —</option>')
            ->assertDontSeeHtml($option(Graphique::VentesParTauxDeTva))
            ->assertSeeHtml($option(Graphique::MeilleursProduits));
        $reglages = PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->sole()->reglages;

        $grille->call('ajouterUnGraphique', '')->assertNotNotified();
        $grille->call('ajouterUnGraphique', 'inconnu')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $this->assertSame($reglages, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->sole()->reglages);

        $grille->call('retirerLeGraphique', Graphique::VentesParTauxDeTva->value)->assertSeeHtml($option(Graphique::VentesParTauxDeTva));

        foreach (Graphique::cases() as $graphique) {
            $grille->call('ajouterUnGraphique', $graphique->value);
        }

        $grille->assertSee('Tous les graphiques sont affichés')
            ->assertDontSee('— Choisir —')
            ->assertSeeHtml('data-graphique="'.Graphique::ArticlesEtServices->value.'"')
            ->assertSeeHtml('data-graphique="'.Graphique::EcartDesPrixDAchat->value.'"');
    }

    /**
     * Le menu « Période » (choix B de l'éditeur, le 15/11/2026) : sans
     * « Exercice » quand l'exercice de la société finit en décembre, où
     * l'année dit « (exercice en cours) », et un exercice y est refusé ;
     * avec les cinq choix quand il finit en juin.
     */
    public function test_le_menu_periode_n_a_pas_d_exercice_quand_l_exercice_finit_en_decembre(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->assertSeeHtmlInOrder(['>12 derniers mois</option>', '>Année 2026 (exercice en cours)</option>', '>Année 2025 (exercice précédent)</option>'])
            ->assertDontSeeHtml('value="exercice_en_cours"')
            ->assertDontSee('Exercice en cours')
            ->call('choisirLaPeriode', PeriodeDuTableauDeBord::ExerciceEnCours->value)
            ->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count());

        app(TenantContext::class)->clear();
        $juin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Annecy', 'mois_fin_exercice' => 6]));
        $this->dansLEspaceDeLaSociete($juin->societe, $this->proprietaireDe($juin));

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->assertSeeHtmlInOrder(['>12 derniers mois</option>', '>Année 2026</option>', '>Année 2025</option>', '>Exercice en cours (juil. 2026 à juin 2027)</option>', '>Exercice précédent (juil. 2025 à juin 2026)</option>'])
            ->call('choisirLaPeriode', PeriodeDuTableauDeBord::ExerciceEnCours->value)
            ->assertNotNotified()
            ->assertSeeHtml('<option value="exercice_en_cours" selected>');
    }

    /**
     * Un « exercice en cours » retenu en base par une société dont
     * l'exercice finit en décembre se lit comme l'année en cours, la même
     * période, sans erreur, et le menu montre ce choix.
     */
    public function test_un_exercice_retenu_d_une_societe_qui_cloture_en_decembre_se_lit_comme_l_annee(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->insert([
            'societe_id' => $proprietaire->societe_id,
            'user_id' => $proprietaire->id,
            'ecran' => EcranDuTableauDeBord::Societe->value,
            'reglages' => json_encode(['cartes' => [['graphique' => Graphique::ChiffreDAffairesParMois->value, 'type' => 'barres']], 'periode' => 'exercice_precedent', 'magasin' => null, 'comparer' => false], JSON_THROW_ON_ERROR),
        ]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $reglages = app(PreferencesDesGraphiques::class)->lire($proprietaire, app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe));

        $this->assertSame(PeriodeDuTableauDeBord::AnneePrecedente, $reglages->periode);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->assertNotNotified()
            ->assertSeeHtml('<option value="annee_precedente" selected>Année 2025 (exercice précédent)</option>');
    }

    /**
     * Ce qui se lit en base est vérifié valeur par valeur : un graphique
     * inconnu, une carte en double, une période illisible ou un magasin qui
     * n'est pas un identifiant reprennent le défaut, sans rien casser.
     */
    public function test_une_preference_illisible_en_base_reprend_le_defaut(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $ecrire = fn (array $reglages): int => PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->upsert(
            [['societe_id' => $proprietaire->societe_id, 'user_id' => $proprietaire->id, 'ecran' => EcranDuTableauDeBord::Societe->value, 'reglages' => json_encode($reglages, JSON_THROW_ON_ERROR)]],
            ['societe_id', 'user_id', 'ecran'],
            ['reglages', 'updated_at'],
        );

        $ecrire(['cartes' => [['graphique' => 'inconnu', 'type' => 'barres']], 'periode' => 'depuis_toujours', 'magasin' => 'Lyon', 'comparer' => 'oui']);
        $illisible = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);
        $ecrire(['cartes' => [['graphique' => 'ca_par_mois', 'type' => 'barres'], ['graphique' => 'ca_par_mois', 'type' => 'lignes']], 'periode' => 'annee_en_cours', 'comparer' => true]);
        $enDouble = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);

        $this->assertEquals(ReglagesDuTableauDeBord::parDefaut($perimetre)->versTableau(), $illisible->versTableau());
        $this->assertSame(ReglagesDuTableauDeBord::parDefaut($perimetre)->versTableau()['cartes'], $enDouble->versTableau()['cartes']);
        $this->assertSame(['annee_en_cours', true], [$enDouble->periode->value, $enDouble->comparer]);
    }

    /**
     * L'affichage de la carte « Écart des prix d'achat » (décision de
     * l'éditeur du 21/11/2026, D76, B6) se retient avec elle, par compte et
     * par écran, et un changement de type de la carte le garde ; son défaut
     * est la première option du menu, un total par mois.
     */
    public function test_l_affichage_de_la_carte_de_l_ecart_des_prix_d_achat_est_retenu_et_survit_au_changement_de_type(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $defaut = CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat);

        $this->assertSame(['avant', false, ['graphique' => 'ecart_des_prix_d_achat', 'type' => 'barres', 'affichage' => 'avant']], [$defaut->affichage, $defaut->surDouzeMois(), $defaut->versTableau()]);
        $this->assertSame(['graphique' => 'meilleurs_produits', 'type' => 'barres_couchees', 'mesure' => 'ca'], CarteDuTableauDeBord::parDefaut(Graphique::MeilleursProduits)->versTableau(), 'L\'affichage ne s\'écrit que pour la carte de l\'écart des prix d\'achat.');
        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'barres', 'ecart' => false, 'tendance' => false], CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->versTableau(), 'L\'écart et la tendance ne s\'écrivent que pour un graphique par période.');
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
            new CarteDuTableauDeBord(Graphique::EcartDesPrixDAchat, TypeDeGraphique::Barres, affichage: CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS),
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois),
        ]));

        $carte = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->carte(Graphique::EcartDesPrixDAchat);

        $this->assertNotNull($carte);
        $this->assertTrue($carte->surDouzeMois());
        $this->assertSame('debut', $carte->affichage);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('changerLeType', Graphique::EcartDesPrixDAchat->value, TypeDeGraphique::Barres->value)
            ->assertHasNoErrors()
            ->assertNotNotified();

        $apres = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->carte(Graphique::EcartDesPrixDAchat);

        $this->assertSame('debut', $apres?->affichage, 'Changer le type de la carte ne remet pas son affichage au défaut.');
    }

    /**
     * Une valeur forgée de l'affichage est refusée : une valeur inconnue,
     * la clé sur un autre graphique, la mesure des meilleurs produits sur la
     * carte, une clé inconnue ; stockée en base, elle remet les cartes du
     * compte au défaut, comme une mesure illisible.
     */
    public function test_une_valeur_forgee_de_l_affichage_est_refusee_et_une_preference_illisible_reprend_le_defaut(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $ecart = ['graphique' => 'ecart_des_prix_d_achat', 'type' => 'barres'];

        $this->assertNull(CarteDuTableauDeBord::lire([...$ecart, 'affichage' => 'annuel']));
        $this->assertNull(CarteDuTableauDeBord::lire([...$ecart, 'affichage' => 12]));
        $this->assertNull(CarteDuTableauDeBord::lire(['graphique' => 'ca_par_mois', 'type' => 'barres', 'affichage' => 'avant']));
        $this->assertNull(CarteDuTableauDeBord::lire([...$ecart, 'mesure' => 'quantite']));
        $this->assertNull(CarteDuTableauDeBord::lire([...$ecart, 'mode' => 'avant']));
        $this->assertSame('debut', CarteDuTableauDeBord::lire([...$ecart, 'affichage' => 'debut'])?->affichage);
        $this->assertSame('avant', CarteDuTableauDeBord::lire($ecart)?->affichage, 'Sans la clé, la carte prend le défaut.');

        $ecrire = fn (array $cartes): int => PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->upsert(
            [['societe_id' => $proprietaire->societe_id, 'user_id' => $proprietaire->id, 'ecran' => EcranDuTableauDeBord::Societe->value, 'reglages' => json_encode(['cartes' => $cartes, 'periode' => 'douze_derniers_mois', 'magasin' => null, 'comparer' => false], JSON_THROW_ON_ERROR)]],
            ['societe_id', 'user_id', 'ecran'],
            ['reglages', 'updated_at'],
        );

        $ecrire([[...$ecart, 'affichage' => 'annuel'], ['graphique' => 'ca_par_mois', 'type' => 'barres']]);
        $this->assertSame(ReglagesDuTableauDeBord::parDefaut($perimetre)->versTableau()['cartes'], app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->versTableau()['cartes']);

        $ecrire([[...$ecart, 'affichage' => 'debut'], ['graphique' => 'ca_par_mois', 'type' => 'barres']]);
        $this->assertSame([[...$ecart, 'affichage' => 'debut'], ['graphique' => 'ca_par_mois', 'type' => 'barres', 'ecart' => false, 'tendance' => false]], app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->versTableau()['cartes']);
    }

    /**
     * Le découpage (« Découper par »), le cumul depuis le début de l'année,
     * et, pour chaque graphique par période, l'écart en % sur un an et la
     * tendance (choix de l'éditeur du 16/11/2026, lot GRAPH-AN) se
     * retiennent en base par compte et par écran : le même compte, sur un
     * autre écran, et un autre compte gardent les leurs.
     */
    public function test_le_decoupage_le_cumul_l_ecart_et_la_tendance_sont_retenus_par_compte_et_par_ecran(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $administrateur = $this->administrateur($lyon, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR]);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $preferences = app(PreferencesDesGraphiques::class);
        $choisis = ReglagesDuTableauDeBord::parDefaut($perimetre)
            ->avecLeDecoupage(DecoupageDuTableauDeBord::Trimestre)
            ->avecLeCumul(true)
            ->avecLaCarte(CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLEcart(true)->avecLaTendance(true));

        $preferences->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, $choisis);

        $relus = $preferences->lire($proprietaire, $perimetre);
        $this->assertSame(['trimestre', true], [$relus->decoupage->value, $relus->cumul]);
        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'barres', 'ecart' => true, 'tendance' => true], $relus->carte(Graphique::ChiffreDAffairesParMois)?->versTableau());
        $this->assertSame(['graphique' => 'ventes_et_achats_par_mois', 'type' => 'barres', 'ecart' => false, 'tendance' => false], $relus->carte(Graphique::VentesEtAchatsParMois)?->versTableau(), 'L\'écart et la tendance sont ceux de chaque carte.');
        $this->assertSame($choisis->versTableau(), $relus->versTableau());
        $this->assertSame(['mois', false], [($autreEcran = $preferences->lire($proprietaire, app(PerimetreDesGraphiques::class)->duMagasin($proprietaire, $lyon)))->decoupage->value, $autreEcran->cumul], 'Le panneau du magasin garde son réglage.');
        $this->assertSame(['mois', false], [($autreCompte = $preferences->lire($administrateur, app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $lyon->societe)))->decoupage->value, $autreCompte->cumul], 'Un autre compte garde le sien.');
        $this->assertSame($relus->avecLeDecoupage(DecoupageDuTableauDeBord::Annee)->cumulPossible(), false);
        $this->assertSame(['annee', false], [$relus->avecLeDecoupage(DecoupageDuTableauDeBord::Annee)->decoupage->value, $relus->avecLeDecoupage(DecoupageDuTableauDeBord::Annee)->comparaisonPossible()]);
    }

    /**
     * Changer le type d'une carte garde son écart en % et sa tendance, comme
     * son affichage et sa mesure : le geste ne remet rien au défaut.
     */
    public function test_changer_le_type_d_une_carte_garde_son_ecart_et_sa_tendance(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, ReglagesDuTableauDeBord::parDefaut($perimetre)
            ->avecLaCarte(CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois)->avecLEcart(true)->avecLaTendance(true)));
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('changerLeType', Graphique::ChiffreDAffairesParMois->value, TypeDeGraphique::Lignes->value)
            ->assertNotNotified();

        $this->assertSame(['graphique' => 'ca_par_mois', 'type' => 'lignes', 'ecart' => true, 'tendance' => true], app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre)->carte(Graphique::ChiffreDAffairesParMois)?->versTableau());
    }

    /**
     * Ce qui se lit en base est vérifié valeur par valeur : un découpage
     * inconnu ou un cumul qui n'est pas un booléen reprennent le défaut sans
     * toucher aux cartes, et une carte dont une valeur est inconnue (le
     * graphique « Chiffre d'affaires HT par an », retiré ; un écart qui n'est
     * pas un booléen ; un écart sur un graphique qui n'est pas par période)
     * remet les cartes du compte au défaut, sans erreur.
     */
    public function test_un_decoupage_un_cumul_ou_une_carte_illisible_en_base_reprend_le_defaut_sans_erreur(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $defaut = ReglagesDuTableauDeBord::parDefaut($perimetre);
        $ecrire = fn (array $reglages): int => PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->upsert(
            [['societe_id' => $proprietaire->societe_id, 'user_id' => $proprietaire->id, 'ecran' => EcranDuTableauDeBord::Societe->value, 'reglages' => json_encode($reglages, JSON_THROW_ON_ERROR)]],
            ['societe_id', 'user_id', 'ecran'],
            ['reglages', 'updated_at'],
        );
        $carte = ['graphique' => 'ca_par_mois', 'type' => 'lignes', 'ecart' => true, 'tendance' => false];

        $ecrire(['cartes' => [$carte], 'periode' => 'annee_en_cours', 'magasin' => null, 'comparer' => true, 'decoupage' => 'semaine', 'cumul' => 'oui']);
        $relus = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);
        $this->assertSame(['mois', false, [$carte], 'annee_en_cours', true], [$relus->decoupage->value, $relus->cumul, $relus->versTableau()['cartes'], $relus->periode->value, $relus->comparer], 'Le découpage et le cumul illisibles reprennent le défaut, le reste est lu.');

        foreach ([
            'le graphique retiré' => [['graphique' => 'ca_par_an', 'type' => 'barres'], $carte],
            'un écart qui n\'est pas un booléen' => [[...$carte, 'ecart' => 'oui']],
            'un écart sur un graphique qui n\'est pas par période' => [['graphique' => 'ca_par_magasin', 'type' => 'anneau', 'ecart' => true]],
        ] as $cas => $cartes) {
            $ecrire(['cartes' => $cartes, 'periode' => 'annee_en_cours', 'magasin' => null, 'comparer' => false, 'decoupage' => 'annee', 'cumul' => true]);
            $relus = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);

            $this->assertSame($defaut->versTableau()['cartes'], $relus->versTableau()['cartes'], $cas);
            $this->assertSame(['annee', true, 'annee_en_cours'], [$relus->decoupage->value, $relus->cumul, $relus->periode->value], $cas.' : le reste est lu.');
        }

        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])->assertNotNotified();
    }

    /**
     * Le réglage d'un compte ne touche jamais celui d'un autre, ni celui du
     * même compte sur un autre écran : l'administrateur coché garde les
     * graphiques par défaut quand le propriétaire retire les siens, et le
     * panneau du magasin du propriétaire aussi.
     */
    public function test_le_reglage_d_un_compte_ne_touche_jamais_celui_d_un_autre(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $administrateur = $this->administrateur($lyon, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('retirerLeGraphique', Graphique::VentesEtAchatsParMois->value)
            ->call('retirerLeGraphique', Graphique::TvaAPayerParMois->value);

        $this->assertSame([Graphique::ChiffreDAffairesParMois, Graphique::ChiffreDAffairesParMagasin], $this->graphiquesDe($proprietaire, $lyon));
        $this->assertSame([Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::ChiffreDAffairesParMois, Graphique::ChiffreDAffairesParMagasin], $this->graphiquesDe($administrateur, $lyon));
        $reglagesDuMagasin = app(PreferencesDesGraphiques::class)->lire($proprietaire, app(PerimetreDesGraphiques::class)->duMagasin($proprietaire, $lyon));
        $this->assertSame([Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::ChiffreDAffairesParMois], array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, $reglagesDuMagasin->cartes));
    }

    /**
     * « Monter » et « Descendre » déplacent la carte parmi celles que la
     * grille montre : l'administrateur qui ne voit pas les achats de
     * Villeurbanne et l'a choisi dans le filtre y voit le chiffre
     * d'affaires puis les avoirs ; « Monter » sur les avoirs les passe
     * devant le chiffre d'affaires, « Ventes et achats », qu'il ne voit pas
     * ici, gardant sa place, et « Descendre » les remet après.
     */
    public function test_monter_et_descendre_deplacent_la_carte_parmi_celles_que_la_grille_montre(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $modele = [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR];
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => $modele,
            $villeurbanne->id => array_values(array_diff($modele, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW])),
        ]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        app(PreferencesDesGraphiques::class)->enregistrer($administrateur, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesEtAchatsParMois),
            CarteDuTableauDeBord::parDefaut(Graphique::AvoirsParMois),
        ], magasin: (string) $villeurbanne->uuid));
        $grille = Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value]);
        $grille->assertViewHas('cartes', fn (array $cartes): bool => array_column(array_column($cartes, 'carte'), 'graphique') === [Graphique::ChiffreDAffairesParMois, Graphique::AvoirsParMois]);

        $grille->call('monter', Graphique::AvoirsParMois->value)->assertHasNoErrors();

        $grille->assertViewHas('cartes', fn (array $cartes): bool => array_column(array_column($cartes, 'carte'), 'graphique') === [Graphique::AvoirsParMois, Graphique::ChiffreDAffairesParMois]);
        $this->assertSame([Graphique::AvoirsParMois, Graphique::VentesEtAchatsParMois, Graphique::ChiffreDAffairesParMois], $this->graphiquesDe($administrateur, $lyon));

        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        $grille->call('descendre', Graphique::AvoirsParMois->value)->assertHasNoErrors();

        $grille->assertViewHas('cartes', fn (array $cartes): bool => array_column(array_column($cartes, 'carte'), 'graphique') === [Graphique::ChiffreDAffairesParMois, Graphique::AvoirsParMois]);
        $this->assertSame([Graphique::ChiffreDAffairesParMois, Graphique::VentesEtAchatsParMois, Graphique::AvoirsParMois], $this->graphiquesDe($administrateur, $lyon));
    }

    /**
     * Les graphiques retenus de ce compte dans l'espace de la société.
     *
     * @return list<Graphique>
     */
    private function graphiquesDe(User $compte, Magasin $magasin): array
    {
        app(TenantContext::class)->clear();
        $reglages = app(PreferencesDesGraphiques::class)->lire($compte, app(PerimetreDesGraphiques::class)->deLaSociete($compte, $magasin->societe));

        return array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, $reglages->cartes);
    }

    /**
     * Lyon et Villeurbanne, deux magasins de la même société, le
     * 15 octobre 2026 ; aucun contexte n'est actif ensuite.
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function deuxMagasins(): array
    {
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();

        return [$lyon->fresh(), $villeurbanne->fresh()];
    }
}
