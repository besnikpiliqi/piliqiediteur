<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Http\Responses\ReponseAUnePageExpiree;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Une requête refusée parce que la page n'est plus à jour (419) ou n'est
 * plus accessible au compte connecté (404) : jamais une page technique en
 * anglais. Dans chaque panneau, un avis en français remplace la question
 * de Livewire (voir la vue filament/requete-refusee), sans jamais prendre
 * le focus, rejoué par Node.js en attendant les tests en navigateur ; un
 * formulaire classique reçoit la page « Page expirée » en français, avec
 * un lien qui recharge le formulaire ; chaque 419 d'une requête Livewire
 * s'inscrit au journal, borné, sans jeton (voir ReponseAUnePageExpiree) ;
 * la page d'un magasin que le compte connecté n'ouvre pas reste
 * introuvable, en français. Le refus lui-même ne change jamais.
 */
class RequetesRefuseesTest extends TestCase
{
    use RefreshDatabase;

    private const JETON_PERIME = 'jeton-que-la-session-ne-connait-pas';

    private const TITRE_404 = 'Cette page n\'est plus accessible.';

    private const TITRE_419 = 'Cette page n\'est plus à jour.';

    /**
     * Une page de chaque panneau, ouverte par qui y entre : la connexion,
     * le tableau de bord d'un magasin, de l'espace d'une société et de
     * l'espace d'un cabinet.
     *
     * @return array<string, array{0: string}>
     */
    public static function panneaux(): array
    {
        return [
            'connexion' => ['connexion'],
            'magasin' => ['magasin'],
            'espace de la société' => ['societe'],
            'espace du cabinet' => ['cabinet'],
        ];
    }

    #[DataProvider('panneaux')]
    public function test_chaque_panneau_pose_l_avis_en_francais_des_requetes_refusees(string $panneau): void
    {
        $adresse = match ($panneau) {
            'connexion' => route('filament.app.auth.login'),
            'magasin' => $this->dansUnMagasin(),
            'societe' => $this->dansLEspaceDUneSociete(),
            'cabinet' => $this->dansLEspaceDUnCabinet(),
        };

        $page = $this->get($adresse);

        $this->assertStringContainsString(view('filament.requete-refusee')->render(), $page->assertOk()->getContent());
    }

    /**
     * Le sondage des notifications refusé toutes les 30 secondes pendant
     * que la personne écrit : l'avis s'affiche sans jamais prendre le
     * focus ni recharger la page, puis reste tel quel, le même, aux refus
     * suivants (son texte suit le dernier motif).
     */
    public function test_l_avis_ne_prend_jamais_le_focus_ni_ne_recharge_la_page_et_reste_tel_quel(): void
    {
        $constats = $this->rejouerLAvis([
            ['faire' => 'refus', 'statut' => 404, 'geste' => false],
            ['faire' => 'refus', 'statut' => 404, 'geste' => false],
            ['faire' => 'refus', 'statut' => 419, 'geste' => true],
        ]);

        $this->assertSame([
            'empeche' => true,
            'avis' => 1,
            'place' => 'page',
            'titre' => self::TITRE_404,
            'boutons' => ['Recharger la page', 'Fermer'],
            'focus' => 'champ',
            'rechargements' => 0,
        ], Arr::except($constats[0], 'numero'));
        $this->assertSame([1, $constats[0]['numero'], self::TITRE_404, 'champ', 0], [$constats[1]['avis'], $constats[1]['numero'], $constats[1]['titre'], $constats[1]['focus'], $constats[1]['rechargements']]);
        $this->assertSame([1, $constats[0]['numero'], self::TITRE_419, 'champ', 0], [$constats[2]['avis'], $constats[2]['numero'], $constats[2]['titre'], $constats[2]['focus'], $constats[2]['rechargements']]);
    }

    /**
     * Fermé par la personne, l'avis ne revient pas pour une requête de fond
     * refusée pour le même motif, qui ne montre pas non plus la question de
     * Livewire ; il revient pour un autre motif, ou pour un geste de la
     * personne, qui doit savoir que ce geste n'a pas abouti.
     */
    public function test_un_avis_ferme_ne_revient_que_pour_un_autre_motif_ou_un_geste_de_la_personne(): void
    {
        $constats = $this->rejouerLAvis([
            ['faire' => 'refus', 'statut' => 404, 'geste' => false],
            ['faire' => 'fermer'],
            ['faire' => 'refus', 'statut' => 404, 'geste' => false],
            ['faire' => 'refus', 'statut' => 419, 'geste' => false],
            ['faire' => 'fermer'],
            ['faire' => 'refus', 'statut' => 419, 'geste' => false],
            ['faire' => 'refus', 'statut' => 419, 'geste' => true],
        ]);

        $this->assertSame(
            [[1, self::TITRE_404], [0, null], [0, null], [1, self::TITRE_419], [0, null], [0, null], [1, self::TITRE_419]],
            array_map(fn (array $constat): array => [$constat['avis'], $constat['titre']], $constats),
        );
        $this->assertTrue($constats[2]['empeche']);
        $this->assertTrue($constats[5]['empeche']);
        $this->assertSame(['champ'], array_values(array_unique(array_column($constats, 'focus'))));
    }

    /**
     * Une fenêtre de Filament ouverte (« Chercher un client ») garde le
     * clavier pour elle : l'avis s'y place, sans prendre le focus de la
     * ligne cliquée, et revient dans la page une fois la fenêtre fermée ;
     * une fenêtre fermée ne le reçoit jamais.
     */
    public function test_dans_une_fenetre_ouverte_l_avis_se_place_sous_son_verrou_de_focus(): void
    {
        $constats = $this->rejouerLAvis([
            ['faire' => 'ouvrirLaFenetre'],
            ['faire' => 'refus', 'statut' => 419, 'geste' => true],
            ['faire' => 'fermerLaFenetre'],
            ['faire' => 'refus', 'statut' => 419, 'geste' => true],
        ]);

        $this->assertSame([1, 'fenetre', 'ligne de la fenêtre'], [$constats[1]['avis'], $constats[1]['place'], $constats[1]['focus']]);
        $this->assertSame([1, 'page', 'champ', $constats[1]['numero']], [$constats[3]['avis'], $constats[3]['place'], $constats[3]['focus'], $constats[3]['numero']]);
    }

    /**
     * Toute autre erreur (500) garde le comportement de Livewire : aucun
     * avis.
     */
    public function test_une_autre_erreur_garde_le_comportement_de_livewire(): void
    {
        $constats = $this->rejouerLAvis([
            ['faire' => 'refus', 'statut' => 500, 'geste' => true],
        ]);

        $this->assertSame([false, 0], [$constats[0]['empeche'], $constats[0]['avis']]);
    }

    public function test_un_formulaire_au_jeton_perime_montre_la_page_expiree_en_francais_qui_recharge_le_formulaire(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();
        $this->get(route('code.afficher'))->assertOk();

        $reponse = $this->post(route('code.saisir'), ['_token' => self::JETON_PERIME, 'siren' => '732829320', 'code' => 'ABCD-EFGH-JKLM']);

        $reponse->assertStatus(419)
            ->assertSee('Page expirée')
            ->assertSee('votre envoi n\'a pas été enregistré')
            ->assertSee('<a class="bouton" href="'.route('code.afficher').'">Recharger la page</a>', false)
            ->assertDontSee('Page Expired');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_un_formulaire_envoye_sous_la_page_qui_l_affiche_recharge_cette_page(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $jeton = str_repeat('a', 64);

        $reponse = $this->post(route('cabinets.invitation.refuser', ['jeton' => $jeton]), ['_token' => self::JETON_PERIME]);

        $reponse->assertStatus(419)
            ->assertSee('<a class="bouton" href="'.route('cabinets.invitation.afficher', ['jeton' => $jeton]).'">Recharger la page</a>', false);
    }

    public function test_un_envoi_sans_aucune_page_sur_son_chemin_renvoie_a_l_accueil(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $reponse = $this->post('/livewire/update', ['_token' => self::JETON_PERIME]);

        $reponse->assertStatus(419)
            ->assertSee('<a class="bouton" href="'.url('/').'">Recharger la page</a>', false);
    }

    /**
     * Une page restée ouverte pendant une mise à jour du logiciel : Livewire
     * refuse sa requête (419), qui s'inscrit au journal avec le nom de ses
     * composants, un nom mal formé devenant « ? », sans rien de la session.
     */
    public function test_un_419_de_livewire_s_inscrit_au_journal_avec_le_nom_de_ses_composants(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        $page = app(ComponentRegistry::class)->getName(CreateDevis::class);
        $instantane = json_decode($this->instantaneLivewire($this->get(DevisResource::getUrl('create', tenant: $magasin))->getContent(), $page), true);
        $instantane['memo']['release'] = 'version-precedente';
        Log::spy();

        $reponse = $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', ['components' => [
            ['snapshot' => json_encode($instantane), 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]],
            ['snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'x', 'name' => "nom\ninjecté"], 'checksum' => 'x']), 'updates' => [], 'calls' => []],
        ]]);

        $reponse->assertStatus(419);
        Log::shouldHaveReceived('warning')->once()->with(ReponseAUnePageExpiree::MESSAGE_DU_JOURNAL, [
            'classe' => LivewireReleaseTokenMismatchException::class,
            'cause' => null,
            'nombre_de_composants' => 2,
            'composants' => [$page, '?'],
        ]);
    }

    /**
     * Une requête Livewire dont le jeton CSRF n'est plus celui de la
     * session (session expirée, autre compte connecté dans ce navigateur) :
     * le refus de Laravel s'inscrit au journal avec sa cause.
     */
    public function test_le_refus_du_jeton_d_une_requete_livewire_s_inscrit_au_journal_avec_sa_cause(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        $page = app(ComponentRegistry::class)->getName(CreateDevis::class);
        $instantane = $this->instantaneLivewire($this->get(DevisResource::getUrl('create', tenant: $magasin))->getContent(), $page);
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();

        $reponse = $this->requeteLivewire($instantane, [], '$refresh');

        $reponse->assertStatus(419);
        Log::shouldHaveReceived('warning')->once()->with(ReponseAUnePageExpiree::MESSAGE_DU_JOURNAL, [
            'classe' => HttpException::class,
            'cause' => TokenMismatchException::class,
            'nombre_de_composants' => 1,
            'composants' => [$page],
        ]);
    }

    /**
     * Une requête fabriquée, sans connexion ni jeton valide, dont les
     * instantanés ne sont pas des chaînes : le refus reste un 419, chaque
     * nom illisible devient « ? », jamais une erreur du serveur.
     */
    public function test_un_instantane_qui_n_est_pas_une_chaine_garde_le_refus_et_s_inscrit_sans_nom(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();

        $reponse = $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', ['_token' => self::JETON_PERIME, 'components' => [
            ['snapshot' => ['a']],
            ['snapshot' => ['memo' => ['name' => 'x']]],
            ['snapshot' => 12],
            'pas-un-composant',
        ]]);

        $reponse->assertStatus(419);
        Log::shouldHaveReceived('warning')->once()->with(ReponseAUnePageExpiree::MESSAGE_DU_JOURNAL, [
            'classe' => HttpException::class,
            'cause' => TokenMismatchException::class,
            'nombre_de_composants' => 4,
            'composants' => ['?'],
        ]);
    }

    /**
     * Une requête fabriquée, sans connexion ni jeton valide, qui porte de
     * très nombreux composants : la ligne du journal ne garde que le nombre
     * de composants et les noms distincts des dix premiers.
     */
    public function test_le_journal_d_un_419_de_livewire_reste_borne_quel_que_soit_le_nombre_de_composants(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $composants = array_map(
            fn (int $rang): array => ['snapshot' => json_encode(['memo' => ['name' => 'composant-'.intdiv($rang, 2)]])],
            range(0, 999),
        );
        Log::spy();

        $reponse = $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', ['_token' => self::JETON_PERIME, 'components' => $composants]);

        $reponse->assertStatus(419);
        Log::shouldHaveReceived('warning')->once()->with(ReponseAUnePageExpiree::MESSAGE_DU_JOURNAL, [
            'classe' => HttpException::class,
            'cause' => TokenMismatchException::class,
            'nombre_de_composants' => 1000,
            'composants' => ['composant-0', 'composant-1', 'composant-2', 'composant-3', 'composant-4'],
        ]);
    }

    /**
     * Un onglet resté ouvert sur le magasin d'un compte, quand un autre
     * compte s'est connecté dans le même navigateur : la page de ce
     * magasin, que le compte connecté n'ouvre pas, reste introuvable (le
     * refus de Filament, qui ne dit pas si le magasin existe), en français.
     */
    public function test_la_page_d_un_magasin_que_le_compte_connecte_n_ouvre_pas_reste_introuvable_en_francais(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $second = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $this->actingAs($this->responsableDeTest($second));

        $reponse = $this->get(DevisResource::getUrl('create', tenant: $premier));

        $reponse->assertNotFound()
            ->assertSee('Page introuvable')
            ->assertSee('<a class="bouton" href="'.url('/').'">Revenir à l\'accueil</a>', false)
            ->assertDontSee('Ma Boutique')
            ->assertDontSee('Not Found');
    }

    /**
     * Le script de l'avis (vue filament/requete-refusee), rejoué par Node.js
     * dans la page réduite de tests/Fixtures/avis-de-requete-refusee.js :
     * ce que la page montre après chaque étape (nombre d'avis, numéro de
     * l'élément de l'avis, élément qui le contient, titre, boutons, élément
     * qui a le focus, rechargements, et pour un refus, si la question de
     * Livewire est empêchée).
     *
     * @param  list<array<string, mixed>>  $etapes
     * @return list<array<string, mixed>>
     */
    private function rejouerLAvis(array $etapes): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js est absent : le script de l\'avis ne se rejoue pas.');
        }

        preg_match('#<script>(.*)</script>#s', view('filament.requete-refusee')->render(), $script);
        $fichierDuScript = (string) tempnam(sys_get_temp_dir(), 'avis-script-');
        $fichierDesEtapes = (string) tempnam(sys_get_temp_dir(), 'avis-etapes-');

        try {
            file_put_contents($fichierDuScript, $script[1]);
            file_put_contents($fichierDesEtapes, json_encode($etapes, JSON_THROW_ON_ERROR));
            $rejeu = Process::run([$node, base_path('tests/Fixtures/avis-de-requete-refusee.js'), $fichierDuScript, $fichierDesEtapes]);
        } finally {
            unlink($fichierDuScript);
            unlink($fichierDesEtapes);
        }

        $this->assertTrue($rejeu->successful(), $rejeu->errorOutput());

        return json_decode($rejeu->output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Le responsable d'un magasin connecté ; l'adresse du tableau de bord
     * de ce magasin.
     */
    private function dansUnMagasin(): string
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));

        return Dashboard::getUrl(panel: 'app', tenant: $magasin);
    }

    /**
     * Le propriétaire d'une société connecté ; l'adresse du tableau de bord
     * de l'espace de cette société.
     */
    private function dansLEspaceDUneSociete(): string
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));

        return TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe);
    }

    /**
     * Le propriétaire d'un cabinet d'expertise comptable connecté ;
     * l'adresse du tableau de bord de l'espace de ce cabinet.
     */
    private function dansLEspaceDUnCabinet(): string
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->actingAs($this->proprietaireDuCabinet($cabinet));

        return TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet);
    }
}
