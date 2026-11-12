<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * « Transformer en facture » un devis accepté (décisions de l'éditeur du
 * 02/11/2026, lot DV) : la facture brouillon reprend exactement ses
 * lignes, ses prix, ses remises et ses taux, dit d'après quel devis elle
 * est établie, sur sa fiche et son PDF ; le devis reste, « Transformé en
 * facture », figé, et ne se transforme qu'une fois ; seul un compte
 * autorisé à vendre le transforme, le bouton restant visible, désactivé
 * et expliqué pour les autres. Le devis ne réserve aucun stock : ses
 * lignes et l'avis de son acceptation signalent un stock insuffisant.
 */
class TransformerUnDevisEnFactureTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
        app(TenantContext::class)->set($this->magasin);
    }

    public function test_la_facture_reprend_les_lignes_du_devis_accepte_et_dit_d_ou_elle_vient(): void
    {
        $devis = $this->devisAccepte(remise: '10');

        $facture = app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis);

        $this->assertSame([$devis->id, $devis->numero, '2026-11-12'], [$facture->devis_id, $facture->devis_numero, $facture->devis_accepte_le]);
        $this->assertSame(
            $devis->lignes->map(fn ($ligne): array => $ligne->only(['produit_id', 'quantite', 'prix_unitaire_ht', 'prix_unitaire_ttc', 'taux_tva', 'remise_pourcentage', 'montant_ht', 'montant_tva', 'montant_ttc']))->all(),
            $facture->lignes->map(fn (LigneFacture $ligne): array => $ligne->only(['produit_id', 'quantite', 'prix_unitaire_ht', 'prix_unitaire_ttc', 'taux_tva', 'remise_pourcentage', 'montant_ht', 'montant_tva', 'montant_ttc']))->all(),
        );
        $this->assertSame(StatutDevis::Converti, $devis->fresh()->statut);
        $this->assertSame("Établie d'après le devis n° {$devis->numero} accepté le 12/11/2026", $facture->mentionDuDevis());

        $emise = app(EmettreFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertStringContainsString("Établie d'après le devis n° {$devis->numero} accepté le 12/11/2026", html_entity_decode(app(FacturePdfGenerator::class)->html($emise), ENT_QUOTES | ENT_HTML5));
        $this->assertSame(StatutDevis::Converti, $devis->fresh()->statut);
    }

    public function test_un_devis_ne_se_transforme_qu_une_fois(): void
    {
        $devis = $this->devisAccepte();
        app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis);

        $this->assertThrows(fn () => app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis), LogicException::class, CreerFactureDepuisDevisAction::REFUS_DEJA_TRANSFORME);
        $this->assertSame(1, Facture::query()->count());
    }

    public function test_supprimer_le_brouillon_rend_au_devis_son_statut_accepte(): void
    {
        $devis = $this->devisAccepte();
        $facture = app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis);

        app(SupprimerFactureAction::class)->handle(self::auteur(), $facture);

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
        $this->assertSame($devis->numero, app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis->fresh())->devis_numero);
    }

    public function test_les_lignes_reprises_du_devis_ne_se_modifient_qu_avec_l_autorisation_des_remises_et_des_prix(): void
    {
        $devis = $this->devisAccepte();
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::DEVIS_VIEW]);
        $facture = app(CreerFactureDepuisDevisAction::class)->handle($vendeur, $devis);
        $ligne = $devis->lignes->first();
        $nouveau = Produit::factory()->create(['nom' => 'Prise murale', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $repris = ['produit_id' => $ligne->produit_id, 'quantite' => $ligne->quantite];

        $this->assertThrows(
            fn () => app(ModifierFactureAction::class)->handle($vendeur, $facture, $facture->client, [['produit_id' => $ligne->produit_id, 'quantite' => 5]]),
            AuthorizationException::class,
            "Modifier ou retirer la ligne « Câble tressé », reprise du devis {$devis->numero} accepté par le client, demande l'autorisation « accorder des remises et fixer le prix d'un service ».",
        );
        $this->assertThrows(
            fn () => app(ModifierFactureAction::class)->handle($vendeur, $facture, $facture->client, [['produit_id' => $nouveau->id, 'quantite' => 1]]),
            AuthorizationException::class,
        );

        $ajoutee = app(ModifierFactureAction::class)->handle($vendeur, $facture, $facture->client, [$repris, ['produit_id' => $nouveau->id, 'quantite' => 1]]);
        $this->assertSame([2, 1], [$ajoutee->lignes->first()->quantite, $ajoutee->lignes->count() - 1]);

        $modifiee = app(ModifierFactureAction::class)->handle(self::responsableDeTest($this->magasin), $ajoutee, $facture->client, [['produit_id' => $ligne->produit_id, 'quantite' => 5]]);
        $this->assertSame(5, $modifiee->lignes->sole()->quantite);
    }

    public function test_le_responsable_transforme_depuis_la_fiche_et_arrive_sur_la_facture_qui_mene_au_devis(): void
    {
        $devis = $this->devisAccepte();
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));

        $page = Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertActionVisible('convertir')
            ->assertActionEnabled('convertir')
            ->callAction('convertir');

        $facture = Facture::query()->sole();
        $page->assertRedirect(FactureResource::getUrl('view', ['record' => $facture]));
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSee("Établie d'après le devis n° {$devis->numero} accepté le 12/11/2026");
        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSee('Transformé en facture')
            ->assertSee('Facture brouillon')
            ->assertActionHidden('convertir');
    }

    public function test_un_vendeur_autorise_transforme_depuis_la_liste(): void
    {
        $devis = $this->devisAccepte();
        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::DEVIS_VIEW]);
        $this->dansLeMagasin($this->magasin, $vendeur);

        Livewire::test(ListDevis::class)
            ->assertTableActionEnabled('convertir', $devis)
            ->callTableAction('convertir', $devis);

        $this->assertSame([$devis->id, $vendeur->id], [Facture::query()->sole()->devis_id, Facture::query()->sole()->cree_par]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function comptesSansLaVente(): array
    {
        return [
            'le propriétaire dans un magasin réel' => ['proprietaire'],
            'un vendeur sans la création de facture' => ['vendeur'],
        ];
    }

    #[DataProvider('comptesSansLaVente')]
    public function test_le_bouton_reste_visible_desactive_et_explique_pour_qui_ne_peut_pas_creer_de_facture(string $compte): void
    {
        $devis = $this->devisAccepte();
        $connecte = $compte === 'proprietaire'
            ? $this->proprietaireDe($this->magasin)
            : $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::DEVIS_VIEW, PermissionRegistry::FACTURE_VIEW]);
        $this->dansLeMagasin($this->magasin, $connecte);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertActionVisible('convertir')
            ->assertActionDisabled('convertir')
            ->assertSee(CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
            ->callAction('convertir');
        Livewire::test(ListDevis::class)
            ->assertTableActionVisible('convertir', $devis)
            ->assertTableActionDisabled('convertir', $devis);

        $this->assertSame(0, Facture::query()->count());
        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
        $this->assertThrows(fn () => app(CreerFactureDepuisDevisAction::class)->handle($connecte, $devis), AuthorizationException::class);
    }

    public function test_le_proprietaire_transforme_dans_le_magasin_de_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        $this->dansLeMagasin($demonstration, $proprietaire);
        $produit = Produit::factory()->create(['nom' => 'Câble tressé', 'prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = app(EmettreDevisAction::class)->handle($proprietaire, app(CreerDevisAction::class)->handle($proprietaire, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        $devis = app(AccepterDevisAction::class)->handle($proprietaire, $devis);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertActionEnabled('convertir')
            ->assertDontSee(CreerFactureDepuisDevisAction::EXPLICATION_SANS_DROIT)
            ->callAction('convertir');

        $this->assertSame([$devis->id, $proprietaire->id], [Facture::query()->sole()->devis_id, Facture::query()->sole()->cree_par]);
        $this->assertSame(StatutDevis::Converti, $devis->fresh()->statut);
    }

    public function test_le_devis_montre_le_stock_de_chaque_produit_et_avertit_quand_il_ne_suffit_pas(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));

        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => Client::factory()->create()->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 0]]])
            ->assertSee('Stock disponible : 2.')
            ->assertDontSee('La quantité dépasse le stock')
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'quantite' => 4, 'remise_pourcentage' => 0]]])
            ->assertSee('Stock disponible : 2. La quantité dépasse le stock : le devis ne réserve rien, la facture le contrôlera.');

        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 4]]);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSee('« Lampe de bureau » : 4 demandés, 2 en stock.');
        $this->assertSame([['produit' => 'Lampe de bureau', 'demande' => 4, 'disponible' => 2]], $devis->stocksInsuffisants());
    }

    /**
     * Le stock sous la quantité d'une ligne ne se montre que dans le
     * formulaire d'un devis, jamais dans celui d'une facture ni à la
     * caisse, qui reprennent les mêmes lignes ; et une frappe dans un devis
     * de vingt lignes lit le catalogue autant de fois que dans un devis
     * d'une ligne : les stocks de toutes ses lignes se lisent ensemble.
     */
    public function test_le_stock_d_une_ligne_ne_se_montre_que_sur_un_devis_et_se_lit_une_fois_pour_toutes_ses_lignes(): void
    {
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));
        $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertDontSee('Stock disponible');

        $this->assertSame($this->lecturesDuCatalogueDUneFrappeDansUnDevisDe(1), $this->lecturesDuCatalogueDUneFrappeDansUnDevisDe(20));
    }

    public function test_l_acceptation_par_le_magasin_dit_quels_produits_n_ont_plus_assez_de_stock(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3]]));
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));

        Livewire::test(ListDevis::class)->callTableAction('accepter', $devis)
            ->assertNotified("Le devis {$devis->numero} est accepté, mais 1 produit n'a plus assez de stock.");

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    /**
     * Les lectures des produits que fait une frappe dans la quantité de la
     * première ligne (de 1 à 2) d'un devis de produits suivis en stock,
     * qui montre ensuite le stock de chaque ligne.
     */
    private function lecturesDuCatalogueDUneFrappeDansUnDevisDe(int $nombreDeLignes): int
    {
        $produits = Produit::factory()->count($nombreDeLignes)->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'stock_quantite' => 5]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), $produits
            ->map(fn (Produit $produit): array => ['produit_id' => $produit->id, 'quantite' => 1])
            ->all());
        $formulaire = Livewire::test(EditDevis::class, ['record' => $devis->getRouteKey()]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $formulaire
            ->set('data.lignes.'.array_key_first($formulaire->get('data.lignes')).'.quantite', '2')
            ->assertSee('Stock disponible : 5.');

        $lectures = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $requete): bool => preg_match('/from [`"]produits[`"]/', $requete) === 1)->count();
        DB::disableQueryLog();

        return $lectures;
    }

    /**
     * Un devis accepté de deux câbles à 60,00 € TTC l'un, envoyé puis
     * accepté le 12/11/2026 à 10 h, heure de Paris.
     */
    private function devisAccepte(string $remise = '0'): Devis
    {
        $produit = Produit::factory()->create(['nom' => 'Câble tressé', 'prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2, 'remise_pourcentage' => $remise]]);

        return app(AccepterDevisAction::class)->handle(self::auteur(), app(EmettreDevisAction::class)->handle(self::auteur(), $devis))->load('lignes');
    }
}
