<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Les fenêtres « Chercher un client » et « Ajouter un produit » des pages
 * de vente, suivies par de vraies requêtes Livewire, comme le navigateur
 * les envoie : la page, l'ouverture de la fenêtre, les requêtes du
 * composant imbriqué qu'elle monte (voir SelecteurDeClients et
 * SelecteurDeProduits), puis l'événement qu'il envoie à la page. Aucun
 * Livewire::test() : il enregistrerait lui-même le composant et masquerait
 * un composant que Livewire ne retrouve pas par son nom, qui répond
 * 419 « Page Expired » à chaque clic dans la fenêtre (constat de
 * l'éditeur du 01/11/2026).
 */
class FenetresDesSelecteursTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Livewire retrouve chaque composant par le nom qu'il inscrit dans son
     * instantané : un composant ajouté plus tard, monté par sa classe sans
     * être enregistré, fait échouer ce test.
     */
    public function test_chaque_composant_livewire_de_l_application_se_retrouve_par_son_nom(): void
    {
        $registre = app(ComponentRegistry::class);
        $composants = $this->composantsDeLApplication();

        foreach ($composants as $classe) {
            $this->assertSame($classe, ltrim($registre->getClass($registre->getName($classe)), '\\'), $classe);
        }

        $this->assertContains(SelecteurDeClients::class, $composants);
        $this->assertContains(SelecteurDeProduits::class, $composants);
    }

    /**
     * Chaque composant qu'une vue monte (@livewire, <livewire:…>), par sa
     * classe ou par un nom, se retrouve ensuite par le nom que Livewire
     * inscrit dans son instantané, de l'application comme d'un paquet.
     */
    public function test_chaque_composant_monte_par_une_vue_se_retrouve_par_son_nom(): void
    {
        $registre = app(ComponentRegistry::class);
        $classesMontees = [];

        foreach (File::allFiles(resource_path('views')) as $vue) {
            preg_match_all('/@livewire\(\s*(?:\\\\?([A-Za-z0-9_\\\\]+)::class|[\'"]([^\'"]+)[\'"])|<livewire:([A-Za-z0-9_.\-]+)/', $vue->getContents(), $montages, PREG_SET_ORDER);

            foreach ($montages as $montage) {
                $classe = ($montage[1] ?? '') !== '' ? $montage[1] : ltrim($registre->getClass(($montage[2] ?? '') !== '' ? $montage[2] : $montage[3]), '\\');
                $classesMontees[] = $classe;

                $this->assertSame($classe, ltrim($registre->getClass($registre->getName($classe)), '\\'), $vue->getRelativePathname());
            }
        }

        $this->assertContains(SelecteurDeClients::class, $classesMontees);
        $this->assertContains(SelecteurDeProduits::class, $classesMontees);
    }

    /**
     * Chaque page qui saisit une vente, avec la loupe du champ « Client »
     * et « Ajouter un produit ».
     *
     * @return array<string, array{0: string}>
     */
    public static function pagesDeVente(): array
    {
        return [
            'création d\'un devis' => ['creation_devis'],
            'modification d\'un devis' => ['modification_devis'],
            'création d\'une facture' => ['creation_facture'],
            'modification d\'une facture' => ['modification_facture'],
            'caisse' => ['caisse'],
        ];
    }

    #[DataProvider('pagesDeVente')]
    public function test_un_clic_dans_la_fenetre_des_clients_pose_le_client_choisi_dans_la_page(string $page): void
    {
        $magasin = $this->ouvrirLeMagasinDuResponsable();
        $choisi = Client::factory()->create(['nom' => 'Jean Dupont']);
        [$adresse, $classe] = $this->pageDeVente($page, $magasin);
        $instantane = $this->instantaneLivewire($this->get($adresse)->assertOk()->getContent(), app(ComponentRegistry::class)->getName($classe));
        $fenetre = $this->requeteLivewire($instantane, [], 'mountFormComponentAction', ['data.client_id', 'chercherUnClient'])->assertOk();
        $selecteur = $this->instantaneLivewire((string) $fenetre->json('components.0.effects.html'), app(ComponentRegistry::class)->getName(SelecteurDeClients::class));

        $clic = $this->requeteLivewire($selecteur, [], 'choisir', [(string) $choisi->getKey()]);

        $clic->assertOk();
        $this->assertSame([['name' => SelecteurDeClients::EVENEMENT, 'params' => ['clientId' => $choisi->getKey()]]], $clic->json('components.0.effects.dispatches'));

        $reception = $this->recevoirLEvenement($fenetre, $clic);

        $reception->assertOk();
        $etat = $this->donneesDeLInstantane((string) $reception->json('components.0.snapshot'));
        $this->assertSame($choisi->getKey(), $etat['data']['client_id']);
        $this->assertSame([], $etat['mountedFormComponentActions']);
    }

    #[DataProvider('pagesDeVente')]
    public function test_un_clic_dans_la_fenetre_des_produits_puis_ajouter_pose_la_ligne_dans_la_page(string $page): void
    {
        $magasin = $this->ouvrirLeMagasinDuResponsable();
        $bougie = Produit::factory()->create(['nom' => 'Bougie parfumée', 'prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => false]);
        [$adresse, $classe] = $this->pageDeVente($page, $magasin);
        $instantane = $this->instantaneLivewire($this->get($adresse)->assertOk()->getContent(), app(ComponentRegistry::class)->getName($classe));
        $fenetre = $this->requeteLivewire($instantane, [], 'mountFormComponentAction', ['data.ajouterUnProduitAction', 'ajouterUnProduit'])->assertOk();
        $selecteur = $this->instantaneLivewire((string) $fenetre->json('components.0.effects.html'), app(ComponentRegistry::class)->getName(SelecteurDeProduits::class));

        $clic = $this->requeteLivewire($selecteur, [], 'ajouterUnArticle', [(string) $bougie->getKey()]);
        $ajout = $this->requeteLivewire((string) $clic->json('components.0.snapshot'), [], 'ajouterLaSelection');

        $clic->assertOk();
        $ajout->assertOk();
        $envoi = $this->donneesDeLInstantane((string) $ajout->json('components.0.snapshot'))['envoi'];
        $this->assertSame([['name' => SelecteurDeProduits::EVENEMENT, 'params' => ['selection' => [['produit_id' => $bougie->getKey(), 'quantite' => 1]], 'envoi' => $envoi]]], $ajout->json('components.0.effects.dispatches'));

        $reception = $this->recevoirLEvenement($fenetre, $ajout);

        $reception->assertOk();
        $etat = $this->donneesDeLInstantane((string) $reception->json('components.0.snapshot'));
        $lignesDeLaBougie = array_values(array_filter($etat['data']['lignes'], fn (array $ligne): bool => ($ligne['produit_id'] ?? null) === $bougie->getKey()));
        $this->assertCount(1, $lignesDeLaBougie);
        $this->assertSame(1, (int) $lignesDeLaBougie[0]['quantite']);
        $this->assertSame([], $etat['mountedFormComponentActions']);
    }

    /**
     * Les classes de composants Livewire de l'application (app/), sauf les
     * classes abstraites.
     *
     * @return list<class-string<Component>>
     */
    private function composantsDeLApplication(): array
    {
        $composants = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            $classe = 'App\\'.str_replace('/', '\\', substr($fichier->getRelativePathname(), 0, -4));

            if (class_exists($classe) && is_subclass_of($classe, Component::class) && ! (new ReflectionClass($classe))->isAbstract()) {
                $composants[] = $classe;
            }
        }

        return $composants;
    }

    /**
     * Le magasin, son responsable de test connecté : il vend, ce que le
     * propriétaire ne fait jamais.
     */
    private function ouvrirLeMagasinDuResponsable(): Magasin
    {
        $this->travelTo(self::aParis('2026-11-02 10:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));

        return $magasin;
    }

    /**
     * L'adresse de la page de vente et la classe de son composant ; pour
     * une modification, un brouillon d'une ligne du magasin.
     *
     * @return array{0: string, 1: class-string<Component>}
     */
    private function pageDeVente(string $page, Magasin $magasin): array
    {
        /** @var User $responsable */
        $responsable = auth()->user();
        $brouillon = fn (string $action) => app($action)->handle($responsable, Client::factory()->create(), [['produit_id' => Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->getKey(), 'quantite' => 1]]);

        return match ($page) {
            'creation_devis' => [DevisResource::getUrl('create', tenant: $magasin), CreateDevis::class],
            'modification_devis' => [DevisResource::getUrl('edit', ['record' => $brouillon(CreerDevisAction::class)], tenant: $magasin), EditDevis::class],
            'creation_facture' => [FactureResource::getUrl('create', tenant: $magasin), CreateFacture::class],
            'modification_facture' => [FactureResource::getUrl('edit', ['record' => $brouillon(CreerFactureAction::class)], tenant: $magasin), EditFacture::class],
            'caisse' => [Caisse::getUrl(tenant: $magasin), Caisse::class],
        };
    }

    /**
     * L'événement que le composant imbriqué a envoyé arrive à la page,
     * comme le navigateur le lui transmet (__dispatch), sur l'instantané
     * de la page à la fenêtre ouverte.
     */
    private function recevoirLEvenement(TestResponse $fenetre, TestResponse $envoi): TestResponse
    {
        $evenement = $envoi->json('components.0.effects.dispatches.0');

        return $this->requeteLivewire((string) $fenetre->json('components.0.snapshot'), [], '__dispatch', [$evenement['name'], $evenement['params']]);
    }

    /**
     * Les propriétés d'un composant telles que son instantané les porte,
     * sans les métadonnées de Livewire ([valeur, {"s": "arr"}] devient la
     * valeur).
     *
     * @return array<string, mixed>
     */
    private function donneesDeLInstantane(string $instantane): array
    {
        $deballer = function (mixed $valeur) use (&$deballer): mixed {
            if (is_array($valeur) && array_is_list($valeur) && count($valeur) === 2 && is_array($valeur[1]) && isset($valeur[1]['s'])) {
                $valeur = $valeur[0];
            }

            return is_array($valeur) ? array_map($deballer, $valeur) : $valeur;
        };

        return $deballer(json_decode($instantane, true)['data']);
    }
}
