<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\EcartDesPrixDAchat\AchatsDeLaMaquette;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDeLaPage;
use Tests\TestCase;

/**
 * La carte « Écart des prix d'achat » dans la grille des graphiques
 * (décision de l'éditeur du 21/11/2026, D76, B6 et B7), au 13 novembre
 * 2026 : la page rendue avec les 122 achats de la maquette donne, pour
 * chaque affichage et chaque mois, le relevé de la maquette (texte,
 * classes, nombres, dessin) ; le menu « Afficher : » se retient, le mois
 * choisi non ; chaque valeur forgée est refusée sans rien écrire. Choisir
 * un mois, et changer l'affichage avec le menu « Afficher : », ne sont plus
 * des gestes qui redessinent la grille (décision de l'éditeur du
 * 23/11/2026) : la page écrit les deux affichages et les douze lectures du
 * mois, et les données Alpine `affichage` et `mois` de la carte choisissent
 * celle qu'elle montre ; le serveur retient seulement l'affichage, en
 * arrière-plan. Ce que font ces choix dans le navigateur, sans attendre le
 * serveur, est prouvé par tests/Browser/Parcours/EcartDesPrixDAchatTest.php
 * et GestesSansAttenteTest.php.
 */
class EcartDesPrixDAchatGrilleTest extends TestCase
{
    use RefreshDatabase;

    private const ECRAN = ['ecran' => 'societe'];

    private const CARTE = 'ecart_des_prix_d_achat';

    /**
     * La preuve que la page est la maquette : pour l'affichage par mois, au
     * mois de départ (le dernier, nov. 2026) ; pour chacun des douze mois,
     * que la page écrit tous dès son affichage, sans aucun geste (le titre du
     * mois, le grand chiffre, la pastille, le détail et le calcul achat par
     * achat de chaque mois sont ceux du relevé) ; pour les douze mois ; puis
     * de nouveau par mois. Le texte du contrôle de type (« Barres », B13 point
     * 4), la croix (l'icône de Filament, sans « × »), les cartes de chiffres
     * des douze mois et le dessin que chaque affichage cache (non écrits dans
     * la page, B6) sont les seuls écarts, écrits ici ; le dessin que le choix
     * d'un mois change dans le navigateur se prouve en navigateur, mois par
     * mois.
     */
    public function test_la_page_rendue_avec_les_achats_de_la_maquette_est_le_releve_de_la_maquette_pour_chaque_affichage_et_chaque_mois(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        AchatsDeLaMaquette::semer(self::responsableDeTest($magasin), Fournisseur::factory()->create(['nom' => 'Maison Verdier']));
        $grille = $this->grille($magasin);

        $this->assertConforme($grille, 'avant', 11, 'Au départ : le dernier mois de la période, nov. 2026.');

        $html = $grille->html();

        for ($mois = 0; $mois < 12; $mois++) {
            $ecart = LectureDeLaPage::premierEcartDuMois(LectureDeLaPage::lire($html, self::CARTE, $mois), $mois);

            $this->assertNull($ecart, "Le mois {$mois}, écrit dans la page dès son affichage. Premier écart avec la maquette : {$ecart}");
        }

        $this->assertSame(24, substr_count($html, 'data-ecart-mois="'), 'Douze lectures du mois et douze calculs, écrits dans la page.');

        $cachee = $this->lecture($grille, 'debut');
        $this->assertSame('avant', $cachee['mode'], 'Le menu dit « un total par mois » : les douze mois sont écrits, cachés.');
        $this->assertStringContainsString('Écart des prix d’achat cumulé sur 12 mois', $grille->html(), 'Le titre des douze mois est dans la page : le menu le choisit sans le serveur.');
        $this->assertNull(LectureDeLaPage::premierEcartAvecLaMaquette([...$cachee, 'mode' => 'debut', 'valeurMenu' => 'debut', 'titreCarte' => 'Écart des prix d’achat cumulé sur 12 mois'], 'debut', 11), 'Les douze mois, déjà écrits dans la page par mois, sont ceux de la maquette.');

        $grille->call('changerLAffichage', self::CARTE, 'debut')->assertNotNotified();
        $this->assertArrayNotHasKey('html', $grille->effects, 'Le menu « Afficher : » ne redessine pas la grille.');

        $rouverte = $this->grilleRouverte($grille);
        $this->assertConforme($rouverte, 'debut', 11, 'Les douze mois, retenus, que la page montre.');
        $this->assertSame('debut', $this->lecture($rouverte)['mode'], 'La page rouverte montre les douze mois sans autre geste.');
        $this->assertSame('debut', $this->lecture($rouverte, 'avant')['mode'], 'Par mois, caché, est lu dans la même page.');

        $rouverte->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertSame('avant', $this->lecture($this->grilleRouverte($rouverte))['mode'], 'De nouveau par mois.');
    }

    /**
     * Par mois, la page écrit les douze lectures du mois, le dessin, les
     * légendes, la méthode et le tableau « Voir les chiffres » une seule fois,
     * et aucun geste du serveur pour choisir un mois : les douze colonnes
     * placent `mois` dans la page, et seuls « Monter », « Descendre », la
     * croix, le menu du type et celui de l'affichage appellent le serveur.
     * Sur les douze mois, la page n'écrit ni le dessin, ni le tableau « Voir
     * les chiffres », ni le calcul d'un autre affichage que le sien (B6, B8),
     * ni de lecture d'un mois ; la vue de la carte, qui garde le mois choisi
     * dans la page, y reste.
     */
    public function test_la_page_ecrit_les_deux_affichages_et_les_douze_mois_une_seule_fois_ce_qui_ne_change_pas_et_aucun_appel_du_serveur_pour_un_mois(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);

        $page = $grille->html();
        $this->assertSame(13, substr_count($page, 'data-ecart="mois-choisi"'), 'Douze lectures du mois et celle des douze mois.');
        $this->assertSame(13, substr_count($page, 'data-ecart="calcul-titre"'));
        $this->assertSame(2, substr_count($page, 'data-ecart-affichage="'), 'Les deux affichages sont dans la page.');
        $this->assertStringContainsString('Écart total du mois : sept. 2026', $page);
        $this->assertStringContainsString('Comment sept. 2026 est calculé, achat par achat', $page);
        $this->assertStringContainsString('Prix de départ de l’année', $page);
        $this->assertSame(1, substr_count($page, 'data-ecart="graphique"'), 'Le dessin est le même d\'un mois à l\'autre : écrit une fois.');
        $this->assertSame(1, substr_count($page, 'data-ecart="legende-plus"'));
        $this->assertSame(1, substr_count($page, 'data-ecart="chiffres"'));
        $this->assertSame(1, substr_count($page, 'data-ecart="stat-base"'));
        $this->assertSame(0, substr_count($page, '<tr><td'), 'Les lignes du calcul achat par achat ne sont pas écrites en HTML : elles sont en JSON, dans la page.');
        $this->assertSame(1, substr_count($page, 'data-ecart="calculs"'));
        $this->assertStringContainsString('x-data="{ mois: 11 }"', $page);
        $this->assertSame(['nov. 2026'], $this->moisMontres($page), 'Un seul mois est montré au départ, le dernier de la période.');

        $carte = $this->carte($grille);
        $this->assertSame(0, substr_count($carte, 'wire:click'), 'Aucun geste de la carte n\'est un clic du serveur.');
        $this->assertSame(0, preg_match('/choisirLeMois|wire:change/', $carte), 'Choisir un mois, comme le menu « Afficher : », n\'est pas un geste de la grille.');
        $this->assertSame(12, substr_count($carte, 'x-on:click="mois = '), 'Les douze colonnes placent `mois` dans la page.');
        $this->assertStringContainsString('$wire.changerLAffichage(', $carte, 'Le menu « Afficher : » retient son choix en arrière-plan.');
        $this->assertMatchesRegularExpression('/data-ecart-affichage="debut"\s+x-show="affichage === \'debut\'"\s+x-cloak/', $carte, 'Les douze mois sont cachés tant que le menu ne les choisit pas.');
    }

    /**
     * Le menu « Afficher : » se retient en base comme le type : retrouvé
     * à l'ouverture suivante, le mois choisi, lui, revient au dernier mois
     * de la période ; changer le type ne remet pas l'affichage au défaut.
     */
    public function test_l_affichage_choisi_est_retenu_en_base_le_mois_choisi_non_et_le_type_ne_le_remet_pas_au_defaut(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);

        $grille->call('changerLAffichage', self::CARTE, 'debut')->assertReturned(['ordre' => [self::CARTE], 'cartes' => [self::CARTE => ['type' => 'barres', 'affichage' => 'debut']]]);
        $grille->call('changerLeType', self::CARTE, 'barres')->assertNotNotified();

        $proprietaire = $this->proprietaireDe($lyon);
        $reglages = PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $proprietaire->id)->sole()->reglages;
        $this->assertEquals([['graphique' => self::CARTE, 'type' => 'barres', 'affichage' => 'debut']], $reglages['cartes'], 'Le mois choisi, qui vit dans la page, n\'est jamais écrit en base.');

        $this->flushSession();
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire->refresh());
        $rouvert = Livewire::test(GrilleDesGraphiques::class, self::ECRAN);

        $this->assertSame('debut', $this->lecture($rouvert)['valeurMenu']);
        $this->assertSame(['nov. 2026'], $this->moisMontres($rouvert->html()), 'Le mois de départ de la vue est le dernier mois de la période : un mois choisi ne survit pas à la page.');
        $rouvert->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertSame('avant', $this->reglagesDuCompte($proprietaire)->carte(Graphique::EcartDesPrixDAchat)?->affichage);
    }

    /**
     * Un geste forgé est refusé en alerte, sans rien enregistrer : un
     * affichage inconnu, l'affichage d'un autre graphique, un graphique
     * hors du catalogue.
     */
    public function test_un_geste_forge_est_refuse_et_rien_n_est_ecrit(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $proprietaire = $this->proprietaireDe($lyon);
        $grille = $this->grille($lyon);
        $avant = PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $proprietaire->id)->sole()->reglages;

        foreach ([
            ['changerLAffichage', [self::CARTE, 'hier']],
            ['changerLAffichage', [self::CARTE, '']],
            ['changerLAffichage', [Graphique::ChiffreDAffairesParMois->value, 'debut']],
            ['changerLAffichage', ['inconnu', 'debut']],
        ] as [$geste, $valeurs]) {
            $grille->call($geste, ...$valeurs)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR)->assertReturned(null);
        }

        $this->assertSame($avant, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $proprietaire->id)->sole()->reglages);

        $grille->call('retirerLeGraphique', self::CARTE)->call('changerLAffichage', self::CARTE, 'debut')->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
    }

    /**
     * Le mois de départ est le dernier mois de la période qui n'est pas à
     * venir : en année civile en cours au 13/11/2026, c'est le mois en cours
     * (nov. 2026, dixième mois de l'année) ; à une période toute passée, le
     * dernier mois. La vue de la carte, qui porte le mois choisi dans la page,
     * change de clé quand la période ou le magasin change : la page remet alors
     * le mois de départ ; la comparaison avec l'année précédente et le menu
     * « Afficher : » la gardent, et le mois choisi avec elle.
     */
    public function test_le_mois_de_depart_et_la_cle_de_la_vue_changent_avec_la_periode_et_le_magasin(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);
        $depart = $this->cleEtDepart($grille);

        $this->assertSame(11, $depart['depart']);
        $this->assertSame(['nov. 2026'], $this->moisMontres($grille->html()));

        $grille->call('comparer', true)->call('changerLAffichage', self::CARTE, 'debut')->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertSame($depart['cle'], $this->cleEtDepart($grille)['cle'], 'La comparaison et le menu « Afficher : » gardent la vue, donc le mois choisi dans la page.');

        $grille->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneeEnCours->value);
        $anneeEnCours = $this->cleEtDepart($grille);
        $this->assertSame(10, $anneeEnCours['depart'], 'Année civile en cours : le mois en cours, pas décembre, encore à venir.');
        $this->assertNotSame($depart['cle'], $anneeEnCours['cle'], 'Une autre période : une autre vue, le mois revient au départ.');
        $this->assertSame(['nov. 2026'], $this->moisMontres($grille->html()));

        $grille->call('choisirLeMagasin', (string) $villeurbanne->uuid);
        $unMagasin = $this->cleEtDepart($grille);
        $this->assertSame(10, $unMagasin['depart']);
        $this->assertNotSame($anneeEnCours['cle'], $unMagasin['cle'], 'Un autre magasin : une autre vue, le mois revient au départ.');
        $this->assertStringContainsString((string) $villeurbanne->uuid, $unMagasin['cle']);

        $grille->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneePrecedente->value);
        $this->assertSame(11, $this->cleEtDepart($grille)['depart'], 'Une période toute passée : son dernier mois.');
        $this->assertSame(['déc. 2025'], $this->moisMontres($grille->html()));
    }

    /**
     * Un mois à venir n'a pas d'achat : l'état « aucun achat » de la
     * maquette, sans état de plus ; un achat daté d'un mois à venir compte
     * comme tout achat.
     */
    public function test_un_mois_a_venir_est_un_mois_sans_achat_sauf_pour_un_achat_qui_y_est_date(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $lampe = $this->produit($magasin, 'Lampe');
        $this->acheter($magasin, $lampe, 2, '5.00', '2026-12-02');
        $this->acheter($magasin, $lampe, 3, '7.00', '2026-12-20');
        $grille = $this->grille($magasin);
        $grille->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneeEnCours->value);

        $html = $grille->html();

        $lecture = LectureDeLaPage::lire($html, self::CARTE, 11);
        $this->assertSame('Écart total du mois : déc. 2026', $lecture['moisChoisi']);
        $this->assertSame('+6,00 €', str_replace("\u{00A0}", ' ', $lecture['grand']['texte']));

        $lecture = LectureDeLaPage::lire($html, self::CARTE, 10);
        $this->assertSame('Écart total du mois : nov. 2026', $lecture['moisChoisi']);
        $this->assertSame('—', $lecture['grand']['texte']);
        $this->assertSame('Aucun achat ce mois-ci', $lecture['pastille']['texte']);
    }

    /**
     * Sans aucun achat : l'état de la maquette (« Aucun achat ce mois-ci »,
     * les douze mois à zéro) et un dessin dont aucun élément n'a de position
     * illisible : ni « NaN » dans la page, ni graduation, mais les douze
     * colonnes et leurs étiquettes.
     */
    public function test_sans_aucun_achat_la_carte_dit_aucun_achat_et_le_dessin_n_a_aucun_nan(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $grille = $this->grille($magasin);

        $lecture = $this->lecture($grille);
        $this->assertSame('—', $lecture['grand']['texte']);
        $this->assertSame('Aucun achat ce mois-ci', $lecture['pastille']['texte']);
        $this->assertSame('Rien n’a été acheté : pas d’écart à calculer.', $lecture['detail']);
        $this->assertStringNotContainsString('NaN', $grille->html());
        $this->assertCount(12, array_filter($lecture['dessin']['enfants'], fn (array $noeud): bool => $noeud['t'] === 'g'));
        $this->assertSame(12, substr_count($grille->html(), 'x-on:click="mois = '), 'Les douze colonnes placent le mois choisi dans la page.');
        $this->assertSame(13, substr_count($grille->html(), 'data-ecart="mois-choisi"'), 'Les douze mois et les douze mois cumulés sont écrits, sans achat comme avec.');

        $lecture = LectureDeLaPage::lire($grille->html(), self::CARTE, null, 'debut');
        $this->assertSame('0,00 €', str_replace("\u{00A0}", ' ', $lecture['grand']['texte']));
        $this->assertSame('Prix inchangés', $lecture['pastille']['texte']);
    }

    /**
     * La carte ne se montre jamais à un vendeur ni à un observateur, ni
     * dans une société de services (le menu « Ajouter un graphique » ne la
     * propose pas et l'ajout est refusé), ni à un responsable sans la case.
     */
    public function test_la_carte_n_est_montree_ni_a_un_vendeur_ni_a_un_observateur_ni_a_une_societe_de_services_ni_a_un_responsable_sans_la_case(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $vendeur = $this->membre($lyon, RoleMagasin::Vendeur);
        $observateur = $this->membre($lyon, RoleMagasin::Observateur);
        $responsable = $this->membre($lyon, RoleMagasin::Responsable);
        app(TenantContext::class)->clear();

        foreach ([$vendeur, $observateur, $responsable] as $compte) {
            $this->dansLeMagasin($lyon, $compte);
            Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'magasin'])->assertForbidden();
        }

        $this->dansLeMagasin($lyon, $this->proprietaireDe($lyon));
        $autorisee = $this->membre($lyon, RoleMagasin::Responsable, [PermissionRegistry::GRAPHIQUES_VOIR]);
        app(TenantContext::class)->clear();
        $this->dansLeMagasin($lyon, $autorisee);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'magasin'])
            ->assertSeeHtml('<option value="'.self::CARTE.'"')
            ->call('ajouterUnGraphique', self::CARTE)->assertNotNotified()
            ->assertSeeHtml('data-graphique="'.self::CARTE.'"');

        $services = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Conseil Vasseur', 'type' => TypeDActivite::Services]));
        $this->dansLEspaceDeLaSociete($services->societe, $this->proprietaireDe($services));
        Livewire::test(GrilleDesGraphiques::class, self::ECRAN)
            ->assertDontSeeHtml('<option value="'.self::CARTE.'"')
            ->call('ajouterUnGraphique', self::CARTE)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR)
            ->assertDontSeeHtml('data-graphique="'.self::CARTE.'"');
    }

    /**
     * Dans le panneau d'un magasin : jamais de nom de magasin, de filtre ni
     * de phrase des magasins non comptés ; l'espace de la société qui en
     * compte deux écrit « nom · magasin » et ne dit pas l'autre société.
     */
    public function test_le_panneau_d_un_magasin_ne_nomme_aucun_magasin_et_la_societe_qui_en_compte_deux_les_distingue(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasinsAvecDesAchats();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLeMagasin($lyon, $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Magasin, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));
        $panneau = Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'magasin']);
        $html = $panneau->html();

        $this->assertStringContainsString('Lampe', $html);
        $this->assertStringNotContainsString('Villeurbanne', $html);
        $this->assertStringNotContainsString('Lampe ·', $html);
        $this->assertStringNotContainsString('data-filtre="magasin"', $html);
        $this->assertStringNotContainsString('pas compté', $html);

        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));
        $societe = Livewire::test(GrilleDesGraphiques::class, self::ECRAN);
        $this->assertStringContainsString('Lampe · Lyon Centre', $societe->html());
        $this->assertStringContainsString('Lampe · Villeurbanne', $societe->html());

        $societe->call('choisirLeMagasin', (string) $villeurbanne->uuid);
        $this->assertStringContainsString('Lampe', $societe->html());
        $this->assertStringNotContainsString('Lampe · ', $societe->html(), 'Un seul magasin compté : le nom du produit seul.');
    }

    /**
     * Un magasin où l'on ne voit pas les achats sort du calcul et la phrase
     * grise de tous les graphiques, sous le paragraphe de méthode, le dit
     * sans le nommer.
     */
    public function test_la_phrase_grise_des_magasins_non_comptes_est_sous_le_paragraphe_de_methode_sans_nommer_le_magasin(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasinsAvecDesAchats();
        $modele = [...PermissionRegistry::modeleDeLAdministrateur($lyon->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR];
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => $modele,
            $villeurbanne->id => array_values(array_diff($modele, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW])),
        ]);
        app(TenantContext::class)->clear();
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        app(PreferencesDesGraphiques::class)->enregistrer($administrateur, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));
        $grille = Livewire::test(GrilleDesGraphiques::class, self::ECRAN);

        $html = $this->carte($grille);

        $grille->assertSee('1 magasin n\'est pas compté : vous n\'y voyez pas les achats.');
        $this->assertStringNotContainsString('Villeurbanne', $html);
        $this->assertMatchesRegularExpression('/data-ecart="methode".*data-ecart="note"/s', $html, 'La phrase grise est sous le paragraphe de méthode.');
        $this->assertStringContainsString('Lampe', $html);
        $this->assertStringNotContainsString('Lampe · ', $html, 'Un seul magasin compté : le nom du produit seul.');
    }

    /**
     * Les achats d'une autre société ne changent aucun nombre de la page.
     */
    public function test_les_achats_d_une_autre_societe_ne_changent_aucun_nombre_de_la_page(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);
        $avant = $this->lecture($grille);

        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $lampe = $this->produit($autre, 'Lampe');
        $this->acheter($autre, $lampe, 1, '1.00', '2026-11-02');
        $this->acheter($autre, $lampe, 50, '9.00', '2026-11-03');
        $this->dansLEspaceDeLaSociete($lyon->societe, $this->proprietaireDe($lyon));

        $this->assertSame($avant, $this->lecture(Livewire::test(GrilleDesGraphiques::class, self::ECRAN)));
    }

    /**
     * La page lue par un analyseur HTML, comparée au relevé de la maquette.
     *
     * @param  'avant'|'debut'  $affichage
     */
    private function assertConforme(Testable $grille, string $affichage, int $mois, string $message): void
    {
        $ecart = LectureDeLaPage::premierEcartAvecLaMaquette($this->lecture($grille, $affichage), $affichage, $mois);

        $this->assertNull($ecart, "{$message} Premier écart avec la maquette : {$ecart}");
    }

    /**
     * Le HTML de la section de la carte seule (le filtre des magasins de la
     * grille, hors de la carte, nomme les magasins de la portée).
     */
    private function carte(Testable $grille): string
    {
        $this->assertSame(1, preg_match('/<section\b[^>]*data-graphique="'.self::CARTE.'".*?<\/section>/s', $grille->html(), $carte));

        return $carte[0];
    }

    /**
     * La carte lue dans la page, pour l'affichage donné (par défaut celui que
     * la page montre).
     *
     * @param  'avant'|'debut'|null  $affichage
     * @return array<string, mixed>
     */
    private function lecture(Testable $grille, ?string $affichage = null): array
    {
        return LectureDeLaPage::lire($grille->html(), self::CARTE, null, $affichage);
    }

    /**
     * La grille de la même personne, rouverte : la page relit ce que le
     * serveur a retenu.
     */
    private function grilleRouverte(Testable $grille): Testable
    {
        return Livewire::test(GrilleDesGraphiques::class, self::ECRAN);
    }

    /**
     * La clé de la vue de la carte et le mois qu'elle montre au départ.
     *
     * @return array{cle: string, depart: int}
     */
    private function cleEtDepart(Testable $grille): array
    {
        $html = $grille->html();
        $this->assertSame(1, preg_match('/wire:key="ecart-vues-([^"]*)"[^>]*x-data="\{ mois: (\d+) \}"/', $html, $vue));

        return ['cle' => html_entity_decode($vue[1]), 'depart' => (int) $vue[2]];
    }

    /**
     * Les mois que la page montre au départ (sans `x-cloak`), par leur nom
     * dans le titre de la lecture du mois.
     *
     * @return list<string>
     */
    private function moisMontres(string $html): array
    {
        preg_match_all('/<div(?![^>]*x-cloak)[^>]*data-ecart-mois="\d+"[^>]*>\s*<div[^>]*data-ecart="mois-choisi">Écart total du mois : ([^<]+)</', $html, $mois);

        return $mois[1];
    }

    /**
     * La grille de l'espace de la société, ne montrant que cette carte, pour
     * le propriétaire de ce magasin.
     */
    private function grille(Magasin $magasin): Testable
    {
        $societe = $magasin->societe()->firstOrFail();
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));

        return Livewire::test(GrilleDesGraphiques::class, self::ECRAN);
    }

    private function reglagesDuCompte(User $compte): ReglagesDuTableauDeBord
    {
        $societe = $compte->societe()->firstOrFail();
        $this->dansLEspaceDeLaSociete($societe, $compte);

        return app(PreferencesDesGraphiques::class)->lire($compte, app(PerimetreDesGraphiques::class)->deLaSociete($compte, $societe));
    }

    /**
     * Deux magasins d'une société « Commerce ou atelier » au 13/11/2026 :
     * Lyon Centre (une lampe achetée 5,00 €, 6,00 € puis 4,00 € en
     * septembre, 5,00 € puis 8,00 € en mai) et Villeurbanne (une lampe
     * achetée 9,00 € puis 7,00 € en septembre).
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function deuxMagasinsAvecDesAchats(): array
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $lampeDeLyon = $this->produit($lyon, 'Lampe');
        $this->acheter($lyon, $lampeDeLyon, 2, '5.00', '2026-09-03');
        $this->acheter($lyon, $lampeDeLyon, 3, '6.00', '2026-09-12');
        $this->acheter($lyon, $lampeDeLyon, 4, '4.00', '2026-09-24');
        $this->acheter($lyon, $lampeDeLyon, 1, '5.00', '2026-05-04');
        $this->acheter($lyon, $lampeDeLyon, 2, '8.00', '2026-05-20');
        $lampeDeVilleurbanne = $this->produit($villeurbanne, 'Lampe');
        $this->acheter($villeurbanne, $lampeDeVilleurbanne, 1, '9.00', '2026-09-02');
        $this->acheter($villeurbanne, $lampeDeVilleurbanne, 5, '7.00', '2026-09-15');
        app(TenantContext::class)->clear();

        return [$lyon->fresh(), $villeurbanne->fresh()];
    }

    private function produit(Magasin $magasin, string $nom): Produit
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::factory()->create(['nom' => $nom, 'prix_vente_ttc' => '30.00', 'taux_tva' => 20, 'suivi_stock' => true]));
    }

    private function acheter(Magasin $magasin, Produit $produit, int $quantite, string $prix, string $jour): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $produit, $quantite, $prix, $jour): void {
            $auteur = self::responsableDeTest($magasin);
            $fournisseur = Fournisseur::query()->first() ?? Fournisseur::factory()->create(['nom' => 'Maison Verdier']);
            $achat = app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, [['produit_id' => $produit->getKey(), 'quantite' => $quantite, 'prix_unitaire_ht' => $prix, 'taux_tva' => 20]], ['date_facture' => $jour]);
            app(ValiderFactureAchatAction::class)->handle($auteur, $achat, (string) Str::uuid());
        });
    }
}
