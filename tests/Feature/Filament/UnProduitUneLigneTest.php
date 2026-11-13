<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un produit = une ligne, à l'écran (décision de l'éditeur du 26/10/2026,
 * série 9) : partout où l'on ajoute des produits à une vente, le choisir de
 * nouveau, par le tableau des produits ou la douchette, augmente la
 * quantité de sa ligne ; jamais une seconde ligne. Une ligne remisée ou à
 * un autre prix change de quantité selon le droit des remises ; un service
 * suit la même règle, sauf un service sans prix de référence saisi à un
 * autre prix.
 */
class UnProduitUneLigneTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-27 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->connecter(self::responsableDeTest($this->magasin));
    }

    /**
     * Un vendeur qui voit les factures de toute l'équipe, et donc les
     * brouillons d'un responsable, sans l'autorisation des remises.
     */
    private function vendeurQuiVoitToutesLesFactures(): User
    {
        return $this->membre($this->magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES]);
    }

    private function connecter(User $compte): void
    {
        $this->actingAs($compte);
        Filament::setTenant($this->magasin);
    }

    private function cable(array $attributs = []): Produit
    {
        return Produit::factory()->create(['nom' => 'Câble', 'reference' => 'CAB-1', 'code_barres' => '3760000000017', 'prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false, ...$attributs]);
    }

    /**
     * @param  array<array-key, mixed>  $lignes
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    private static function lignes(array $lignes): array
    {
        return array_map(fn (array $ligne): array => [$ligne['description'] ?? null, $ligne['quantite'] ?? null, (string) ($ligne['remise_pourcentage'] ?? '')], array_values($lignes));
    }

    public function test_une_nouvelle_facture_un_nouveau_devis_et_la_caisse_s_ouvrent_sans_ligne(): void
    {
        Livewire::test(CreateFacture::class)->assertSet('data.lignes', []);
        Livewire::test(CreateDevis::class)->assertSet('data.lignes', []);
        Livewire::test(Caisse::class)->assertSet('data.lignes', []);
    }

    /**
     * Le second clic sur une rangée du tableau ajoute 1 à la quantité
     * choisie, il ne retire pas le produit ; la croix le retire.
     */
    public function test_chaque_clic_sur_un_produit_ajoute_un_a_sa_quantite_choisie(): void
    {
        $cable = $this->cable();

        Livewire::test(SelecteurDeProduits::class)
            ->call('ajouterUnArticle', $cable->getKey())
            ->call('ajouterUnArticle', $cable->getKey())
            ->call('ajouterUnArticle', $cable->getKey())
            ->assertSet('selection', [['produit_id' => $cable->getKey(), 'quantite' => 3]])
            ->assertSee('Ajouter le produit')
            ->call('retirer', $cable->getKey())
            ->assertSet('selection', []);
    }

    /**
     * « Ajouter » envoie la sélection une fois, puis la vide : un second
     * clic, parti avant que la fenêtre ne se ferme, n'envoie rien.
     */
    public function test_un_second_envoi_de_la_selection_n_ajoute_pas_les_produits_deux_fois(): void
    {
        $cable = $this->cable();

        Livewire::test(SelecteurDeProduits::class)
            ->call('ajouterUnArticle', $cable->getKey())
            ->call('ajouterLaSelection')
            ->assertDispatched(SelecteurDeProduits::EVENEMENT)
            ->assertSet('selection', [])
            ->call('ajouterLaSelection')
            ->assertNotDispatched(SelecteurDeProduits::EVENEMENT)
            ->assertNotNotified();
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function pagesDeVente(): array
    {
        return [
            'caisse' => [Caisse::class],
            'nouvelle facture' => [CreateFacture::class],
            'nouveau devis' => [CreateDevis::class],
        ];
    }

    /**
     * Par le tableau puis par la douchette, le même produit fait une seule
     * ligne, qui garde sa place, et le message le dit ; le document
     * s'enregistre avec cette ligne.
     *
     * @param  class-string  $page
     */
    #[DataProvider('pagesDeVente')]
    public function test_le_meme_produit_ajoute_deux_fois_donne_une_seule_ligne(string $page): void
    {
        $cable = $this->cable();
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'prix_vente_ttc' => '20.00', 'taux_tva' => 20, 'suivi_stock' => false]);

        $formulaire = Livewire::test($page)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1], ['produit_id' => $chargeur->getKey(), 'quantite' => 1]])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->set('data.douchette', '3760000000017')
            ->call('scannerUneReference')
            ->assertNotified('Déjà sur une ligne : « Câble » (3), sa quantité a augmenté.');

        $this->assertSame([['Câble', 3, '0'], ['Chargeur', 1, '0']], self::lignes($formulaire->get('data.lignes')));
    }

    /**
     * Sans l'autorisation « accorder des remises et fixer le prix d'un
     * service », un produit dont la ligne porte une remise ou un autre prix
     * que sa fiche n'est pas ajouté, avec la raison ; jamais une seconde
     * ligne, et la ligne ne change pas.
     */
    public function test_sans_l_autorisation_l_ajout_a_une_ligne_remisee_ou_a_un_autre_prix_est_refuse(): void
    {
        $cable = $this->cable();
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'prix_vente_ttc' => '20.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $cable->getKey(), 'quantite' => 1, 'remise_pourcentage' => '10'],
            ['produit_id' => $chargeur->getKey(), 'quantite' => 1, 'prix_unitaire_ttc' => '18.00'],
        ]);
        $this->connecter($this->vendeurQuiVoitToutesLesFactures());

        $page = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->assertNotified('« Câble » a une remise sur sa ligne : demandez à un responsable de changer sa quantité.')
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $chargeur->getKey(), 'quantite' => 1]])
            ->assertNotified('« Chargeur » est sur sa ligne à un autre prix que celui de sa fiche : demandez à un responsable de changer sa quantité.');

        $this->assertSame([['Câble', 1, '10'], ['Chargeur', 1, '0']], self::lignes($page->get('data.lignes')));
    }

    /**
     * Une ligne reprise du devis accepté dont le brouillon de facture est
     * issu ne change pas de quantité sans l'autorisation des remises ; avec
     * elle, sa quantité augmente.
     */
    public function test_une_ligne_reprise_du_devis_ne_change_de_quantite_qu_avec_l_autorisation(): void
    {
        $cable = $this->cable();
        $devis = app(AccepterDevisAction::class)->handle(self::auteur(), app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $cable->getKey(), 'quantite' => 2]])));
        $brouillon = app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis);

        $autorise = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $this->assertSame([['Câble', 3, '0']], self::lignes($autorise->get('data.lignes')));

        $this->connecter($this->vendeurQuiVoitToutesLesFactures());
        $page = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->assertNotified('« Câble » est sur une ligne reprise du devis accepté par le client : demandez à un responsable de changer sa quantité.');

        $this->assertSame([['Câble', 2, '0']], self::lignes($page->get('data.lignes')));
    }

    /**
     * Pendant la frappe d'une quantité (champ vide), un nouvel ajout du
     * même produit est refusé avec la raison, sans seconde ligne.
     */
    public function test_un_ajout_pendant_la_frappe_d_une_quantite_ne_cree_pas_de_seconde_ligne(): void
    {
        $cable = $this->cable();
        $caisse = Livewire::test(Caisse::class)->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $cle = array_key_first($caisse->get('data.lignes'));

        $caisse->set("data.lignes.{$cle}.quantite", '')
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->assertNotified('Terminez d\'abord la ligne « Câble » : sa quantité, son prix ou sa remise n\'est pas encore un nombre valable.');

        $this->assertCount(1, $caisse->get('data.lignes'));
    }

    /**
     * À la caisse, un vendeur sans l'autorisation des remises ajoute un
     * article dont la fiche a changé de prix depuis l'ajout de sa ligne :
     * la ligne reprend le prix du catalogue, puis sa quantité augmente.
     */
    public function test_apres_un_changement_du_prix_de_la_fiche_l_article_ajoute_rejoint_sa_ligne_au_nouveau_prix(): void
    {
        $cable = $this->cable();
        $this->connecter($this->membre($this->magasin, RoleMagasin::Vendeur));
        $caisse = Livewire::test(Caisse::class)->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $cable->forceFill(['prix_vente_ttc' => '12.00'])->save();

        $caisse->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);

        $ligne = array_values($caisse->get('data.lignes'));
        $this->assertSame([[2, '12.00']], array_map(fn (array $ligne): array => [$ligne['quantite'], $ligne['prix_unitaire_ttc']], $ligne));
    }

    /**
     * Augmenter la quantité d'une ligne est un ajout : rien ne s'inscrit au
     * journal des événements de la caisse.
     */
    public function test_completer_une_ligne_n_inscrit_rien_au_journal_des_evenements(): void
    {
        $cable = $this->cable();
        $avant = $this->evenementsDuMagasin();

        Livewire::test(Caisse::class)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 2]]);

        $this->assertSame($avant, $this->evenementsDuMagasin());
    }

    /**
     * Un service à prix de référence coché de nouveau ajoute 1 à la
     * quantité de sa ligne ; un service sans prix de référence arrive
     * toujours sur une nouvelle ligne, dont le prix se saisit (60,00 € puis
     * 80,00 €) ; deux lignes de ce service au même prix et au même taux ne
     * partent pas, avec la raison sous les lignes.
     */
    public function test_un_service_n_a_qu_une_ligne_par_prix_et_par_taux(): void
    {
        $pose = Service::factory()->auPrixDe('45.00')->create(['nom' => 'Pose']);
        $reparation = Service::factory()->create(['nom' => 'Réparation']);
        $page = Livewire::test(CreateFacture::class)->fillForm(['client_id' => Client::factory()->create()->id]);

        foreach ([$pose, $pose, $reparation, $reparation] as $service) {
            $page->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]]);
        }

        $this->assertSame([['Pose', 2, '0'], ['Réparation', 1, '0'], ['Réparation', 1, '0']], self::lignes($page->get('data.lignes')));
        [, $premiere, $seconde] = array_keys($page->get('data.lignes'));

        $page->set("data.lignes.{$premiere}.prix_unitaire_ttc", '60.00')
            ->set("data.lignes.{$seconde}.prix_unitaire_ttc", '60.00')
            ->call('create')
            ->assertHasFormErrors(['lignes']);
        $this->assertSame(0, Facture::query()->count());

        $page->set("data.lignes.{$seconde}.prix_unitaire_ttc", '80.00')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame([['Pose', 2], ['Réparation', 1], ['Réparation', 1]], Facture::query()->sole()->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all());
    }

    /**
     * Dans une société de services, qui ne vend que des services, cocher un
     * service déjà présent ajoute 1 à sa quantité.
     */
    public function test_dans_une_societe_de_services_cocher_un_service_deja_present_ajoute_un_a_sa_quantite(): void
    {
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services]);
        $this->connecter(self::responsableDeTest($this->magasin));
        $pose = Service::factory()->auPrixDe('45.00')->create(['nom' => 'Pose']);
        $caisse = Livewire::test(Caisse::class);

        $caisse->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$pose->id]])
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$pose->id]]);

        $this->assertSame([['Pose', 2, '0']], self::lignes($caisse->get('data.lignes')));
    }

    /**
     * Le produit d'un autre magasin, forgé dans le champ caché d'une ligne,
     * ne passe pas la validation du formulaire : aucune facture.
     */
    public function test_un_produit_d_un_autre_magasin_force_dans_une_ligne_est_refuse(): void
    {
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $ailleurs = app(TenantContext::class)->runAs($autre, fn (): Produit => $this->cable(['reference' => 'AIL-1', 'code_barres' => null]));
        $this->connecter(self::responsableDeTest($this->magasin));
        app(TenantContext::class)->set($this->magasin);
        $cable = $this->cable();

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => Client::factory()->create()->id])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $cle = array_key_first($page->get('data.lignes'));

        $page->set("data.lignes.{$cle}.produit_id", $ailleurs->getKey())
            ->call('create')
            ->assertHasFormErrors(["lignes.{$cle}.produit_id" => 'exists']);
        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un brouillon rouvert garde ses lignes telles qu'enregistrées : rien
     * ne s'y réunit d'office.
     */
    public function test_un_brouillon_rouvert_garde_ses_lignes(): void
    {
        $reparation = Service::factory()->create(['nom' => 'Réparation']);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '60.00', 'taux_tva' => '20'],
            ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '80.00', 'taux_tva' => '20'],
        ]);

        $page = Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()]);

        $this->assertSame(['60.00', '80.00'], array_column(array_values($page->get('data.lignes')), 'prix_unitaire_ttc'));
    }

    /**
     * @return list<int>
     */
    private function evenementsDuMagasin(): array
    {
        return EnregistrementFiscal::query()
            ->where('magasin_id', $this->magasin->getKey())
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->pluck('id')
            ->all();
    }
}
