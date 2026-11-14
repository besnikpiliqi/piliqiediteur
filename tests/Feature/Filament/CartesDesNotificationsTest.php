<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\CartesAMontrer;
use App\Filament\CartesDesNotifications;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot DEMO-V (modèle A choisi par l'éditeur le 14/11/2026) : la
 * notification de la cloche qui s'affiche toute seule en carte. Une
 * notification non lue arrivée pendant qu'une page est ouverte se montre
 * une seule fois, même après une nouvelle session ; une notification lue
 * ou d'un autre compte jamais ; à l'ouverture d'une session, une carte au
 * plus, ensuite trois au plus à la fois. La carte ne fait qu'avertir :
 * elle n'écrit rien sur les notifications, et seul son bouton marque la
 * sienne comme lue, par la cloche. Chaque test fige l'horloge un vendredi
 * à 10 h à Paris ; une notification ne se montre qu'une fois sa seconde
 * d'arrivée finie (voir CartesAMontrer), d'où les voyages d'une seconde.
 */
class CartesDesNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private User $vendeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-13 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Épicerie Lemoine']);
        $this->vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, identite: ['name' => 'Julie Vendeuse']);
        $this->travel(1)->seconds();
    }

    public function test_une_notification_non_lue_arrivee_pendant_que_la_page_est_ouverte_se_montre_une_seule_fois(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);
        $page->assertDontSeeHtml('class="piliqi-carte"');

        $this->avertir($this->vendeur, 'Devis DEV-EPICERIE-2026-000002 accepté en ligne');
        $this->travel(1)->seconds();

        $this->assertSame(['Devis DEV-EPICERIE-2026-000002 accepté en ligne'], $this->titresDesCartes($page->call('regarder')));
        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
        $this->assertSame([], $this->titresDesCartes($this->ouvrirLaPageDansLaMemeSession()->call('regarder')));
    }

    public function test_une_notification_arrivee_dans_la_seconde_du_passage_se_montre_au_passage_suivant(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);

        $this->avertir($this->vendeur, 'Stock bas : Câble tressé');

        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
        $this->travel(1)->seconds();
        $this->assertSame(['Stock bas : Câble tressé'], $this->titresDesCartes($page->call('regarder')));
    }

    public function test_une_notification_deja_lue_ne_se_montre_pas(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);
        $notification = $this->avertir($this->vendeur, 'Facture FAC-EPICERIE-2026-000004 passée en retard');
        $notification->markAsRead();
        $this->travel(1)->seconds();

        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
    }

    public function test_une_notification_d_un_autre_compte_ne_se_montre_pas(): void
    {
        $collegue = $this->membre($this->magasin, RoleMagasin::Vendeur, identite: ['name' => 'Marc Collègue']);
        $page = $this->ouvrirLaPage($this->vendeur);
        $notification = $this->avertir($collegue, 'Devis DEV-EPICERIE-2026-000007 refusé en ligne');
        $this->travel(1)->seconds();

        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
        $this->assertNull($notification->fresh()?->read_at);
        $this->assertNull($collegue->fresh()?->cartes_montrees_jusqu_au);
    }

    public function test_a_l_ouverture_d_une_session_une_seule_carte_se_montre_la_plus_recente(): void
    {
        $this->avertir($this->vendeur, 'Première');
        $this->travel(1)->seconds();
        $this->avertir($this->vendeur, 'Deuxième');
        $this->travel(1)->seconds();
        $this->avertir($this->vendeur, 'Troisième');
        $this->travel(1)->seconds();

        $page = $this->ouvrirLaPage($this->vendeur);

        $this->assertSame(['Troisième'], $this->titresAuChargement($page));
        $this->travel(1)->seconds();
        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
        $this->assertSame(3, $this->vendeur->unreadNotifications()->count());
    }

    public function test_au_chargement_d_une_page_les_cartes_s_empilent_la_plus_recente_en_haut(): void
    {
        $this->ouvrirLaPage($this->vendeur);
        $this->avertir($this->vendeur, 'Première');
        $this->travel(1)->seconds();
        $this->avertir($this->vendeur, 'Deuxième');
        $this->travel(1)->seconds();

        $this->assertSame(['Deuxième', 'Première'], $this->titresAuChargement($this->ouvrirLaPageDansLaMemeSession()));
    }

    public function test_une_carte_montree_ne_revient_pas_apres_une_nouvelle_session(): void
    {
        $this->avertir($this->vendeur, 'Accès redonné');
        $this->travel(1)->seconds();
        $this->assertSame(['Accès redonné'], $this->titresAuChargement($this->ouvrirLaPage($this->vendeur)));

        $this->travel(1)->hours();

        $this->assertSame([], $this->titresAuChargement($this->ouvrirLaPage($this->vendeur->fresh() ?? $this->vendeur)));
    }

    public function test_au_plus_trois_cartes_se_montrent_a_la_fois_les_plus_recentes(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);

        foreach (['Un', 'Deux', 'Trois', 'Quatre', 'Cinq'] as $titre) {
            $this->avertir($this->vendeur, $titre);
            $this->travel(1)->seconds();
        }

        $this->assertSame(['Trois', 'Quatre', 'Cinq'], $this->titresDesCartes($page->call('regarder')));
        $this->assertSame([], $this->titresDesCartes($page->call('regarder')));
    }

    public function test_la_carte_montree_ne_change_rien_aux_notifications_de_la_cloche(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);
        $deja = $this->avertir($this->vendeur, 'Avis déjà là');
        $this->travel(1)->seconds();
        $page->call('regarder');
        $nouvelle = $this->avertir($this->vendeur, 'Devis accepté en ligne', 'https://piliqi.test/app/devis');
        $avant = DatabaseNotification::query()->orderBy('created_at')->get()->map->getAttributes()->all();
        $this->travel(1)->seconds();

        $this->assertSame(['Devis accepté en ligne'], $this->titresDesCartes($page->call('regarder')));

        $this->assertSame($avant, DatabaseNotification::query()->orderBy('created_at')->get()->map->getAttributes()->all());
        $this->assertSame(2, $this->vendeur->unreadNotifications()->count());
        $this->assertNull($deja->fresh()?->read_at);
        $this->assertNull($nouvelle->fresh()?->read_at);
    }

    public function test_le_bouton_de_la_carte_marque_sa_notification_comme_lue_par_la_cloche_et_rien_d_autre(): void
    {
        $collegue = $this->membre($this->magasin, RoleMagasin::Vendeur, identite: ['name' => 'Marc Collègue']);
        $page = $this->ouvrirLaPage($this->vendeur);
        $sienne = $this->avertir($this->vendeur, 'Devis accepté en ligne', 'https://piliqi.test/app/devis');
        $autre = $this->avertir($this->vendeur, 'Stock bas');
        $celleDuCollegue = $this->avertir($collegue, 'Stock négatif');

        $page->call('marquerCommeLue', $sienne->getKey())
            ->call('marquerCommeLue', $celleDuCollegue->getKey())
            ->call('marquerCommeLue', 'pas-un-identifiant')
            ->assertOk();

        $this->assertNotNull($sienne->fresh()?->read_at);
        $this->assertNull($autre->fresh()?->read_at);
        $this->assertNull($celleDuCollegue->fresh()?->read_at);
    }

    public function test_la_carte_porte_l_icone_le_titre_le_texte_nettoye_et_le_bouton_de_la_notification(): void
    {
        $page = $this->ouvrirLaPage($this->vendeur);
        $notification = $this->avertir($this->vendeur, 'Accès redonné', 'https://piliqi.test/app/equipe', 'Le texte <script>alert(1)</script><b>en gras</b>');
        $this->travel(1)->seconds();

        $carte = $this->cartes($page->call('regarder'))[0];

        $this->assertStringContainsString('Accès redonné', $carte);
        $this->assertStringContainsString('<b>en gras</b>', $carte);
        $this->assertStringNotContainsString('<script>', $carte);
        $this->assertStringContainsString('href="https://piliqi.test/app/equipe"', $carte);
        $this->assertStringContainsString('x-on:click="markAsRead()"', $carte);
        $this->assertStringContainsString('fi-no-notification-icon', $carte);
        $this->assertStringContainsString('notification: \''.$notification->getKey().'\'', $carte);
        $this->assertStringNotContainsString('data-carte="'.$notification->getKey().'"', $carte);
        $this->assertStringContainsString('duree: 8000', $carte);
    }

    public function test_les_cartes_se_montrent_dans_l_espace_de_la_societe_et_dans_celui_du_cabinet(): void
    {
        $proprietaire = $this->magasin->societe->proprietaire()->firstOrFail();
        $cabinet = $this->ouvrirUnCabinet();
        $collaborateur = $this->collaborateur($cabinet);
        $this->travel(1)->seconds();

        $this->dansLEspaceDeLaSociete($this->magasin->societe, $proprietaire);
        $societe = Livewire::test(CartesDesNotifications::class);
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        $espaceDuCabinet = Livewire::test(CartesDesNotifications::class);

        $this->avertir($proprietaire, 'Nomination d\'un responsable');
        $this->avertir($collaborateur, 'Nouveau client confié', societe: $cabinet);
        $this->travel(1)->seconds();

        $this->dansLEspaceDeLaSociete($this->magasin->societe, $proprietaire);
        $this->assertSame(['Nomination d\'un responsable'], $this->titresDesCartes($societe->call('regarder')));
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        $this->assertSame(['Nouveau client confié'], $this->titresDesCartes($espaceDuCabinet->call('regarder')));
    }

    public function test_de_deux_onglets_qui_regardent_en_meme_temps_un_seul_montre_la_carte(): void
    {
        $this->avertir($this->vendeur, 'Devis accepté en ligne');
        $this->travel(1)->seconds();
        $premierOnglet = User::query()->findOrFail($this->vendeur->getKey());
        $secondOnglet = User::query()->findOrFail($this->vendeur->getKey());
        $maintenant = CarbonImmutable::now();

        $montrees = app(CartesAMontrer::class)->prendre($premierOnglet, $premierOnglet->unreadNotifications(), false, $maintenant);
        $aussi = app(CartesAMontrer::class)->prendre($secondOnglet, $secondOnglet->unreadNotifications(), false, $maintenant);

        $this->assertCount(1, $montrees);
        $this->assertCount(0, $aussi);
    }

    public function test_chaque_page_d_un_panneau_monte_les_cartes_au_rythme_de_la_cloche(): void
    {
        config(['piliqi.intervalle_des_notifications_en_secondes' => 12]);
        $this->actingAs($this->vendeur);

        $this->get('/app/'.$this->magasin->uuid)
            ->assertOk()
            ->assertSee('wire:poll.12s="regarder"', false)
            ->assertSee('css/app/cartes-des-notifications.css', false)
            ->assertSee('Alpine.data(\'carteDeNotification\'', false);
        $this->assertFileEquals(resource_path('css/cartes-des-notifications.css'), public_path('css/app/cartes-des-notifications.css'));
        $this->assertSame('12s', CartesDesNotifications::intervalle());
        config(['piliqi.intervalle_des_notifications_en_secondes' => 0]);
        $this->assertSame('1s', CartesDesNotifications::intervalle());
    }

    /**
     * La page de ce compte, ouverte dans une nouvelle session, avec son
     * composant des cartes monté.
     */
    private function ouvrirLaPage(User $compte): Testable
    {
        $this->dansLeMagasin($this->magasin, $compte);

        return Livewire::test(CartesDesNotifications::class);
    }

    /**
     * Une autre page ouverte dans la session en cours.
     */
    private function ouvrirLaPageDansLaMemeSession(): Testable
    {
        return Livewire::test(CartesDesNotifications::class);
    }

    private function avertir(User $compte, string $titre, ?string $url = null, string $texte = 'Le texte de l\'avis.', ?Societe $societe = null): DatabaseNotification
    {
        $avant = $compte->notifications()->pluck('id')->all();
        $compte->notify(new AvisDeLEquipe($societe ?? $this->magasin->societe, $titre, $texte, $url));

        return $compte->notifications()->whereNotIn('id', $avant)->sole();
    }

    /**
     * Les cartes envoyées au navigateur par le dernier passage, en HTML.
     *
     * @return list<string>
     */
    private function cartes(Testable $page): array
    {
        $cartes = [];

        foreach ($page->effects['dispatches'] ?? [] as $evenement) {
            if ($evenement['name'] === CartesDesNotifications::EVENEMENT) {
                $cartes = array_merge($cartes, $evenement['params']['cartes']);
            }
        }

        return $cartes;
    }

    /**
     * Les titres des cartes du dernier passage, dans l'ordre où elles
     * s'empilent (de la plus ancienne, en bas, à la plus récente, en haut).
     *
     * @return list<string>
     */
    private function titresDesCartes(Testable $page): array
    {
        return array_map($this->titreDeLaCarte(...), $this->cartes($page));
    }

    /**
     * Les titres des cartes rendues avec la page, de haut en bas.
     *
     * @return list<string>
     */
    private function titresAuChargement(Testable $page): array
    {
        preg_match_all('/<div\s[^>]*class="piliqi-carte".*?(?=<div\s[^>]*class="piliqi-carte"|$)/s', $page->html(), $cartes);

        return array_map($this->titreDeLaCarte(...), $cartes[0]);
    }

    private function titreDeLaCarte(string $carte): string
    {
        preg_match('/<h3[^>]*>(.*?)<\/h3>/s', $carte, $titre);

        return html_entity_decode(trim($titre[1] ?? ''), ENT_QUOTES);
    }
}
