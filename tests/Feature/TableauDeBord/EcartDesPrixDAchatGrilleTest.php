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
use Illuminate\Support\Facades\DB;
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
 * chaque affichage et chaque mois choisi, le relevé de la maquette (texte,
 * classes, nombres, dessin) ; le menu « Afficher : » se retient, le mois
 * choisi non ; chaque valeur forgée est refusée sans rien écrire.
 */
class EcartDesPrixDAchatGrilleTest extends TestCase
{
    use RefreshDatabase;

    private const ECRAN = ['ecran' => 'societe'];

    private const CARTE = 'ecart_des_prix_d_achat';

    /**
     * La preuve que la page est la maquette : pour l'affichage par mois, au
     * mois de départ (le dernier, nov. 2026) puis pour chacun des douze
     * mois choisis par un clic ; pour les douze mois ; puis de nouveau par
     * mois, où le mois choisi a été gardé. Le texte du contrôle de type
     * (« Barres », B13 point 4), la croix (l'icône de Filament, sans « × »),
     * les cartes de chiffres des douze mois et le dessin que chaque
     * affichage cache (non écrits dans la page, B6) sont les seuls écarts,
     * écrits ici.
     */
    public function test_la_page_rendue_avec_les_achats_de_la_maquette_est_le_releve_de_la_maquette_pour_chaque_affichage_et_chaque_mois(): void
    {
        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        AchatsDeLaMaquette::semer(self::responsableDeTest($magasin), Fournisseur::factory()->create(['nom' => 'Maison Verdier']));
        $grille = $this->grille($magasin);

        $this->assertConforme($grille, 'avant', 11, 'Au départ : le dernier mois de la période, nov. 2026.');

        for ($mois = 0; $mois < 12; $mois++) {
            $grille->call('choisirLeMois', self::CARTE, $mois);
            $this->assertConforme($grille, 'avant', $mois, "Mois {$mois} choisi par un clic.");
        }

        $grille->call('changerLAffichage', self::CARTE, 'debut')->assertNotNotified();
        $this->assertConforme($grille, 'debut', 11, 'Les douze mois.');

        $grille->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertConforme($grille, 'avant', 11, 'De nouveau par mois : le mois choisi est gardé.');
    }

    /**
     * Le mois choisi reste celui qu'on a cliqué quand l'affichage passe aux
     * douze mois, puis revient ; et l'affichage des douze mois ne montre ni
     * le dessin, ni le tableau « Voir les chiffres », ni le calcul d'un
     * autre affichage que le sien (B6, B8).
     */
    public function test_le_mois_choisi_est_garde_d_un_affichage_a_l_autre_et_la_page_n_ecrit_que_l_affichage_choisi(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);

        $grille->call('choisirLeMois', self::CARTE, 9);
        $parMois = $grille->html();
        $this->assertStringContainsString('Écart total du mois : sept. 2026', $parMois);
        $this->assertStringContainsString('data-ecart="graphique"', $parMois);
        $this->assertStringContainsString('data-ecart="chiffres"', $parMois);
        $this->assertStringNotContainsString('data-ecart="stat-base"', $parMois);
        $this->assertStringNotContainsString('Prix de départ de l’année', $parMois);

        $grille->call('changerLAffichage', self::CARTE, 'debut');
        $surDouzeMois = $grille->html();
        $this->assertStringContainsString('data-ecart="stat-base"', $surDouzeMois);
        $this->assertStringNotContainsString('data-ecart="graphique"', $surDouzeMois);
        $this->assertStringNotContainsString('data-ecart="chiffres"', $surDouzeMois);
        $this->assertStringNotContainsString('data-ecart="legende-plus"', $surDouzeMois);
        $this->assertStringNotContainsString('Prix de départ du mois', $surDouzeMois);
        $this->assertStringContainsString('Prix de départ de l’année', $surDouzeMois);
        $this->assertStringNotContainsString('Écart total du mois', $surDouzeMois, 'Les douze mois n\'ont pas de mois choisi à dire.');

        $grille->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertStringContainsString('Écart total du mois : sept. 2026', $grille->html(), 'Le mois choisi est revenu avec l\'affichage par mois.');
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

        $grille->call('changerLAffichage', self::CARTE, 'debut')->call('choisirLeMois', self::CARTE, 3);
        $grille->call('changerLeType', self::CARTE, 'barres')->assertNotNotified();

        $proprietaire = $this->proprietaireDe($lyon);
        $reglages = PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $proprietaire->id)->sole()->reglages;
        $this->assertEquals([['graphique' => self::CARTE, 'type' => 'barres', 'affichage' => 'debut']], $reglages['cartes']);
        $this->assertStringNotContainsString('moisChoisis', json_encode($reglages, JSON_THROW_ON_ERROR), 'Le mois choisi n\'est jamais écrit en base.');

        $this->flushSession();
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire->refresh());
        $rouvert = Livewire::test(GrilleDesGraphiques::class, self::ECRAN);

        $this->assertSame('debut', $this->lecture($rouvert)['valeurMenu']);
        $rouvert->call('changerLAffichage', self::CARTE, 'avant');
        $this->assertSame('Écart total du mois : nov. 2026', $this->lecture($rouvert)['moisChoisi'], 'Un mois choisi ne survit pas à la page.');
        $this->assertSame('avant', $this->reglagesDuCompte($proprietaire)->carte(Graphique::EcartDesPrixDAchat)?->affichage);
    }

    /**
     * Un geste forgé est refusé en alerte, sans rien enregistrer : un
     * affichage inconnu, l'affichage d'un autre graphique, un graphique
     * hors du catalogue, un mois hors de 0 à 11 (ou d'une autre carte, ou
     * d'une carte non affichée), un mois forgé dans l'état de la page.
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
            ['choisirLeMois', [self::CARTE, 12]],
            ['choisirLeMois', [self::CARTE, -1]],
            ['choisirLeMois', [Graphique::ChiffreDAffairesParMois->value, 3]],
            ['choisirLeMois', ['inconnu', 3]],
        ] as [$geste, $valeurs]) {
            $grille->call($geste, ...$valeurs)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        }

        $this->assertSame($avant, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->where('user_id', $proprietaire->id)->sole()->reglages);

        $grille->call('retirerLeGraphique', self::CARTE)->call('choisirLeMois', self::CARTE, 3)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);

        $autre = $this->grille($lyon);
        $autre->set('moisChoisis', [self::CARTE => 99]);
        $this->assertSame('Écart total du mois : nov. 2026', $this->lecture($autre)['moisChoisi'], 'Un mois forgé dans l\'état de la page est ignoré.');
    }

    /**
     * Un clic sur un mois ne touche pas la base : il vit dans l'écran.
     */
    public function test_choisir_un_mois_n_ecrit_rien_en_base(): void
    {
        [$lyon] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);

        DB::enableQueryLog();
        $grille->call('choisirLeMois', self::CARTE, 5)->assertNotNotified();
        $ecritures = array_values(array_filter(array_map(fn (array $requete): string => (string) $requete['query'], DB::getQueryLog()), fn (string $requete): bool => preg_match('/^\s*(insert|update|delete)\b/i', $requete) === 1));
        DB::disableQueryLog();

        $this->assertSame([], $ecritures);
        $this->assertSame('Écart total du mois : mai 2026', $this->lecture($grille)['moisChoisi']);
    }

    /**
     * Le mois choisi revient au dernier mois de la période qui n'est pas à
     * venir quand la période ou le magasin change : en année civile en
     * cours au 13/11/2026, c'est le mois en cours (nov. 2026, dixième mois
     * de l'année) ; et à une période toute passée, le dernier mois.
     */
    public function test_le_mois_choisi_revient_au_defaut_quand_la_periode_ou_le_magasin_change(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasinsAvecDesAchats();
        $grille = $this->grille($lyon);

        $grille->call('choisirLeMois', self::CARTE, 2);
        $this->assertSame('Écart total du mois : févr. 2026', $this->lecture($grille)['moisChoisi']);

        $grille->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneeEnCours->value);
        $this->assertSame('Écart total du mois : nov. 2026', $this->lecture($grille)['moisChoisi'], 'Année civile en cours : le mois en cours, pas décembre, encore à venir.');

        $grille->call('choisirLeMois', self::CARTE, 0);
        $this->assertSame('Écart total du mois : janv. 2026', $this->lecture($grille)['moisChoisi']);
        $grille->call('choisirLeMagasin', (string) $villeurbanne->uuid);
        $this->assertSame('Écart total du mois : nov. 2026', $this->lecture($grille)['moisChoisi'], 'Un autre magasin : le mois revient au défaut.');

        $grille->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneePrecedente->value);
        $this->assertSame('Écart total du mois : déc. 2025', $this->lecture($grille)['moisChoisi'], 'Une période toute passée : son dernier mois.');
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

        $grille->call('choisirLeMois', self::CARTE, 11);
        $lecture = $this->lecture($grille);
        $this->assertSame('Écart total du mois : déc. 2026', $lecture['moisChoisi']);
        $this->assertSame('+6,00 €', str_replace("\u{00A0}", ' ', $lecture['grand']['texte']));

        $grille->call('choisirLeMois', self::CARTE, 10);
        $lecture = $this->lecture($grille);
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
        $this->assertSame(12, substr_count($grille->html(), 'wire:click="choisirLeMois('));

        $grille->call('changerLAffichage', self::CARTE, 'debut');
        $lecture = $this->lecture($grille);
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
            ->assertSeeHtml('<option value="'.self::CARTE.'">')
            ->call('ajouterUnGraphique', self::CARTE)->assertNotNotified()
            ->assertSeeHtml('data-graphique="'.self::CARTE.'"');

        $services = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Conseil Vasseur', 'type' => TypeDActivite::Services]));
        $this->dansLEspaceDeLaSociete($services->societe, $this->proprietaireDe($services));
        Livewire::test(GrilleDesGraphiques::class, self::ECRAN)
            ->assertDontSeeHtml('<option value="'.self::CARTE.'">')
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
        $panneau->call('choisirLeMois', self::CARTE, 8)->call('changerLAffichage', self::CARTE, 'debut');
        $html = $panneau->html();

        $this->assertStringContainsString('Lampe', $html);
        $this->assertStringNotContainsString('Villeurbanne', $html);
        $this->assertStringNotContainsString('Lampe ·', $html);
        $this->assertStringNotContainsString('data-filtre="magasin"', $html);
        $this->assertStringNotContainsString('pas compté', $html);

        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        app(PreferencesDesGraphiques::class)->enregistrer($proprietaire, EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat)]));
        $societe = Livewire::test(GrilleDesGraphiques::class, self::ECRAN);
        $societe->call('changerLAffichage', self::CARTE, 'debut');
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

        $grille->call('choisirLeMois', self::CARTE, 9);
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
        $ecart = LectureDeLaPage::premierEcartAvecLaMaquette($this->lecture($grille), $affichage, $mois);

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
     * @return array<string, mixed>
     */
    private function lecture(Testable $grille): array
    {
        return LectureDeLaPage::lire($grille->html());
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
