<?php

namespace Tests\Feature\Notifications;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Facturation\Notifications\TransmissionNonAboutie;
use App\Domain\Stock\Notifications\StockNegatif;
use App\Domain\Stock\Notifications\StockSousLeSeuilDAlerte;
use App\Domain\Tenancy\Models\NotificationDePiliqi;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Support\LienRelatif;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\ProduitResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Une notification de la cloche se range avec des liens relatifs, sans
 * hôte ni port (demande de l'éditeur du 20/11/2026) : sur son poste, le
 * site tourne sur http://localhost:8001 alors que ses notifications portaient
 * http://localhost/app/… (APP_URL au moment du semis), et « Voir le
 * document » menait à « La connexion a échoué ». Quelle que soit la classe
 * qui l'écrit, la notification rangée ne porte aucune adresse ; ses
 * e-mails, eux, gardent une adresse absolue.
 */
class LiensRelatifsDesNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_lien_de_ce_site_perd_son_adresse_et_un_lien_d_ailleurs_reste_entier(): void
    {
        URL::forceRootUrl('http://localhost:8001');

        $this->assertSame('/app/abc/factures/12', LienRelatif::de('http://localhost:8001/app/abc/factures/12'));
        $this->assertSame('/app/abc/factures?tableFilters[statut]=payee#haut', LienRelatif::de('http://localhost:8001/app/abc/factures?tableFilters[statut]=payee#haut'));
        $this->assertSame('/?page=2', LienRelatif::de('http://localhost:8001?page=2'));
        $this->assertSame('/', LienRelatif::de('http://localhost:8001'));
        $this->assertSame('/app/abc', LienRelatif::de('/app/abc'), 'Un lien déjà relatif reste tel quel.');
        $this->assertSame('https://www.service-public.fr/professionnels-entreprises', LienRelatif::de('https://www.service-public.fr/professionnels-entreprises'));
        $this->assertSame('http://localhost:80010/app', LienRelatif::de('http://localhost:80010/app'), 'Le début d\'une autre adresse n\'est pas l\'adresse de ce site.');
        $this->assertSame('http://localhost:8001.exemple.test/app', LienRelatif::de('http://localhost:8001.exemple.test/app'));
        $this->assertSame(
            ['actions' => [['name' => 'voir', 'url' => '/app/x', 'externe' => 'https://exemple.test/a']], 'societe_id' => 4, 'lu' => false, 'body' => 'Voir http://localhost:8001/app/x ici'],
            LienRelatif::dans(['actions' => [['name' => 'voir', 'url' => 'http://localhost:8001/app/x', 'externe' => 'https://exemple.test/a']], 'societe_id' => 4, 'lu' => false, 'body' => 'Voir http://localhost:8001/app/x ici']),
            'Chaque texte qui est un lien de ce site, où qu\'il soit, devient relatif ; un texte qui en contient un reste tel quel.',
        );
    }

    /**
     * Les classes qui rangent un lien vers une fiche, une liste ou une page
     * d'équipe : la notification rangée, relue, n'a plus ni hôte ni port, et
     * chaque bouton mène à la page du site qui la lit.
     */
    public function test_aucune_notification_rangee_ne_porte_d_adresse_et_chaque_bouton_mene_a_une_page_du_site(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);
        URL::forceRootUrl('http://localhost:8001');
        $produit = Produit::factory()->create(['nom' => 'Câble USB', 'suivi_stock' => true]);
        $facture = Facture::factory()->create(['client_id' => Client::factory()->create()->id]);

        $proprietaire->notify(new StockNegatif($produit, 'FAC-LYON1-2026-000001'));
        $proprietaire->notify(new StockSousLeSeuilDAlerte($produit));
        $proprietaire->notify(new FacturesPasseesEnRetard($magasin, ['FAC-LYON1-2026-000001', 'FAC-LYON1-2026-000002']));
        $proprietaire->notify(new TransmissionNonAboutie($facture));
        $proprietaire->notify(new AvisDeLEquipe($magasin->societe, 'Propriété transmise', 'Un texte.', FactureResource::getUrl('index', panel: 'app', tenant: $magasin)));

        $notifications = $proprietaire->notifications()->get();
        $this->assertCount(5, $notifications);
        $this->assertContainsOnlyInstancesOf(NotificationDePiliqi::class, $notifications);

        foreach ($notifications as $notification) {
            $brut = (string) json_encode($notification->data);
            $this->assertStringNotContainsString('http://', $brut, 'Une notification rangée ne porte aucune adresse absolue.');
            $this->assertStringNotContainsString('localhost', $brut);
            $this->assertStringNotContainsString(':8001', $brut);

            foreach ($notification->data['actions'] ?? [] as $action) {
                $this->assertStringStartsWith('/app/', (string) $action['url']);
            }
        }

        $dansLaBase = (string) DB::table('notifications')->pluck('data')->implode(' ');
        $this->assertStringNotContainsString('http', $dansLaBase, 'La colonne elle-même ne porte aucune adresse.');
        $this->assertSame(
            [ProduitResource::getUrl('edit', ['record' => $produit], panel: 'app', tenant: $magasin, isAbsolute: false)],
            [$notifications->first(fn (NotificationDePiliqi $notification): bool => str_contains((string) $notification->data['title'], 'Câble USB') && ($notification->data['actions'][0]['url'] ?? null) !== null)?->data['actions'][0]['url']],
        );

        $this->get($notifications->first()->data['actions'][0]['url'])->assertOk();
    }

    /**
     * Le même compte, une fois l'adresse du site changée (nom de domaine,
     * port), lit encore ses notifications : leurs liens suivent le site qui
     * sert la page.
     */
    public function test_les_liens_ranges_suivent_le_site_qui_sert_la_page(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);
        URL::forceRootUrl('http://ancien-domaine.exemple.test');
        $proprietaire->notify(new AvisDeLEquipe($magasin->societe, 'Avis', 'Texte.', FactureResource::getUrl('index', panel: 'app', tenant: $magasin)));

        URL::forceRootUrl('http://nouveau-domaine.exemple.test:8443');
        $lien = (string) $proprietaire->notifications()->sole()->data['actions'][0]['url'];

        $this->assertSame('/app/'.$magasin->uuid.'/factures', $lien);
        $this->assertSame('http://nouveau-domaine.exemple.test:8443/app/'.$magasin->uuid.'/factures', url($lien));
    }

    public function test_seul_le_compte_destinataire_range_et_lit_ses_notifications(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $autre = User::factory()->create();
        $proprietaire->notify(new AvisDeLEquipe($magasin->societe, 'Avis', 'Texte.'));

        $this->assertSame(1, $proprietaire->notifications()->count());
        $this->assertSame(0, $autre->notifications()->count());
    }
}
