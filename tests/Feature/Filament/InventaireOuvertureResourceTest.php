<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\CreateInventaireOuverture;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\ListInventairesOuverture;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\ViewInventaireOuverture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les écrans de l'inventaire d'ouverture : la saisie d'un brouillon, sa
 * fiche et sa validation, le menu et le bouton « Nouveau » jusqu'à la
 * première vente du magasin, pour qui peut valider les achats et créer des
 * produits.
 */
class InventaireOuvertureResourceTest extends TestCase
{
    use RefreshDatabase;

    private function magasinDuProprietaire(): Magasin
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        return $magasin;
    }

    /**
     * La page de création, sa première ligne remplie de ces valeurs.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function creationAvecUneLigne(array $ligne): Testable
    {
        $page = Livewire::test(CreateInventaireOuverture::class);
        $cle = array_key_first($page->get('data.lignes'));

        foreach ($ligne as $champ => $valeur) {
            $page->set("data.lignes.{$cle}.{$champ}", $valeur);
        }

        return $page;
    }

    /**
     * Le propriétaire saisit le brouillon (un nouveau produit, sans
     * référence, et un produit du catalogue jamais reçu), qui s'ouvre sur
     * sa fiche sans rien créer ; il le valide depuis cette fiche, et les
     * produits entrent en stock, le nouveau avec sa référence générée.
     * 10 x 2,00 € + 4 x 3,25 € = 33,00 € HT.
     */
    public function test_le_proprietaire_saisit_puis_valide_un_inventaire_depuis_ses_ecrans(): void
    {
        $this->magasinDuProprietaire();
        $tasse = Produit::factory()->create(['nom' => 'Tasse', 'reference' => 'TASSE', 'stock_quantite' => 0]);
        $page = $this->creationAvecUneLigne([
            'produit.nom' => 'Carnet A5',
            'produit.prix_vente_ttc' => '4.90',
            'produit.taux_tva' => '20',
            'quantite' => 10,
            'cout_unitaire_ht' => '2.00',
        ]);
        $page->set('data.lignes.seconde', ['produit_id' => $tasse->id, 'produit' => [], 'quantite' => 4, 'cout_unitaire_ht' => '3.25']);

        $page->call('create')->assertHasNoFormErrors();

        $inventaire = InventaireOuverture::query()->sole();
        $page->assertRedirect(InventaireOuvertureResource::getUrl('view', ['record' => $inventaire]));
        $this->assertSame([StatutInventaireOuverture::Brouillon, '33.00', 1], [$inventaire->statut, $inventaire->valeur_ht, Produit::query()->count()]);

        Livewire::test(ViewInventaireOuverture::class, ['record' => $inventaire->getRouteKey()])
            ->assertSee('Carnet A5')
            ->assertSee('Générée à la validation')
            ->callAction('valider')
            ->assertHasNoActionErrors()
            ->assertNotified("Inventaire d'ouverture {$inventaire->fresh()->numero} validé.")
            ->assertRedirect(InventaireOuvertureResource::getUrl('view', ['record' => $inventaire]));

        $this->assertSame(StatutInventaireOuverture::Valide, $inventaire->fresh()->statut);
        $this->assertSame(
            [['PRD-000001', 10, '2.0000'], ['TASSE', 4, '3.2500']],
            Produit::query()->orderBy('reference')->get()->map(fn (Produit $produit): array => [$produit->reference, $produit->stock_quantite, $produit->cmup_ht])->all(),
        );
    }

    /**
     * Réponse de l'éditeur à Q11-a : avant la première vente, le menu et le
     * bouton « Nouveau » s'offrent ; après, le menu disparaît s'il n'y a
     * aucun inventaire à consulter, et ni « Nouveau » ni la validation d'un
     * brouillon resté ne s'offrent plus.
     */
    public function test_le_menu_et_le_bouton_nouveau_disparaissent_apres_la_premiere_vente(): void
    {
        $this->magasinDuProprietaire();
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->assertTrue(InventaireOuvertureResource::shouldRegisterNavigation());
        Livewire::test(ListInventairesOuverture::class)->assertActionVisible('create');

        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $vendu->id, 'quantite' => 1]]);

        $this->assertFalse(InventaireOuvertureResource::shouldRegisterNavigation());
        Livewire::test(ListInventairesOuverture::class)->assertActionHidden('create');
    }

    /**
     * Un brouillon saisi avant la première vente reste consultable après
     * elle, menu compris, mais ne se valide plus.
     */
    public function test_un_brouillon_reste_consultable_apres_la_premiere_vente_sans_se_valider(): void
    {
        $this->magasinDuProprietaire();
        $brouillon = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $vendu = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $vendu->id, 'quantite' => 1]]);

        $this->assertTrue(InventaireOuvertureResource::shouldRegisterNavigation());
        $this->forgerLAction(
            Livewire::test(ViewInventaireOuverture::class, ['record' => $brouillon->getRouteKey()])->assertSee('Carnet A5')->assertActionHidden('valider'),
            'valider',
        );

        $this->assertSame([StatutInventaireOuverture::Brouillon, 1], [$brouillon->fresh()->statut, Produit::query()->count()]);
    }

    /**
     * Un membre qui voit les factures d'achat consulte les inventaires sans
     * pouvoir en saisir : ni « Nouveau » ni la validation ne s'offrent, et
     * une requête forgée ne crée ni ne valide rien.
     */
    public function test_un_membre_sans_les_droits_ne_saisit_ni_ne_valide_un_inventaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = app(CreerInventaireOuvertureAction::class)->handle($this->proprietaireDe($magasin), [
            ['produit' => ['nom' => 'Carnet A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]);
        $this->dansLeMagasin($magasin, $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_VIEW]));

        $this->forgerLAction(Livewire::test(ListInventairesOuverture::class)->assertActionHidden('create'), 'create');
        $this->forgerLAction(
            Livewire::test(ViewInventaireOuverture::class, ['record' => $brouillon->getRouteKey()])->assertSee('Carnet A5')->assertActionHidden('valider'),
            'valider',
        );
        $this->get(InventaireOuvertureResource::getUrl('create', ['tenant' => $magasin]))->assertForbidden();

        $this->assertSame([1, StatutInventaireOuverture::Brouillon, 0], [InventaireOuverture::query()->count(), $brouillon->fresh()->statut, Produit::query()->count()]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function coutsHorsLimites(): array
    {
        return [
            'coût négatif' => ['-5', 'min'],
            'coût démesuré' => ['99999999999', 'max'],
        ];
    }

    /**
     * Le coût unitaire HT d'une ligne n'est jamais négatif ni démesuré :
     * l'écran le refuse, et rien n'est enregistré.
     */
    #[DataProvider('coutsHorsLimites')]
    public function test_un_cout_negatif_ou_demesure_est_refuse(string $cout, string $regle): void
    {
        $this->magasinDuProprietaire();
        $page = $this->creationAvecUneLigne([
            'produit.nom' => 'Carnet A5',
            'produit.prix_vente_ttc' => '4.90',
            'produit.taux_tva' => '20',
            'quantite' => 10,
            'cout_unitaire_ht' => $cout,
        ]);
        $cle = array_key_first($page->get('data.lignes'));

        $page->call('create')->assertHasFormErrors(["lignes.{$cle}.cout_unitaire_ht" => $regle]);

        $this->assertSame(0, InventaireOuverture::query()->count());
    }

    /**
     * Seul un produit jamais reçu se choisit sur une ligne : un produit déjà
     * reçu par un achat, glissé dans une requête forgée, est refusé avec un
     * message, et rien n'est enregistré.
     */
    public function test_un_produit_deja_recu_ne_se_choisit_pas(): void
    {
        $this->magasinDuProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20]);
        app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 2, 'prix_unitaire_ht' => 1]]));
        $page = $this->creationAvecUneLigne(['produit_id' => $cable->id, 'quantite' => 1, 'cout_unitaire_ht' => '1.00']);
        $cle = array_key_first($page->get('data.lignes'));

        $page->call('create')->assertHasFormErrors(["lignes.{$cle}.produit_id" => fn (array $reglesEnEchec, array $messages): bool => $messages === ['Choisissez un produit du magasin jamais reçu.']]);

        $this->assertSame(0, InventaireOuverture::query()->count());
    }
}
