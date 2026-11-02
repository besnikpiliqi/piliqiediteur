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
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Une requête refusée parce que la page n'est plus à jour (419) ou n'est
 * plus accessible au compte connecté (404) : jamais une page technique en
 * anglais. Dans chaque panneau, un avis en français remplace la question
 * de Livewire (voir la vue filament/requete-refusee) ; un formulaire
 * classique reçoit la page « Page expirée » en français, avec un lien qui
 * recharge le formulaire ; chaque 419 d'une requête Livewire s'inscrit au
 * journal, sans jeton (voir ReponseAUnePageExpiree) ; la page d'un magasin
 * que le compte connecté n'ouvre pas reste introuvable, en français. Le
 * refus lui-même ne change jamais.
 */
class RequetesRefuseesTest extends TestCase
{
    use RefreshDatabase;

    private const JETON_PERIME = 'jeton-que-la-session-ne-connait-pas';

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
            'composants' => [$page],
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
