<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Exceptions\StockInsuffisantException;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Notifications\StockNegatif;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Filament\App\Widgets\StockNegatifApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La vente au-delà du stock (R1) : refusée à qui n'est pas autorisé à
 * vendre sans stock dans le magasin ; pour qui l'est (le propriétaire et
 * les responsables toujours, un vendeur à qui on l'a cochée), elle ne
 * passe qu'après l'avertissement confirmé, renouvelé si le stock a encore
 * baissé ; confirmée, elle rend le stock négatif, alerte, se journalise et
 * reste visible jusqu'à sa régularisation. Les ventes simultanées sont
 * dans VenteSansStockConcurrenceTest.
 */
class VenteSansStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_l_autorisation_une_vente_au_dela_du_stock_est_refusee_sans_numero_ni_signature(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', 1);
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, $this->comptoir(), [['produit_id' => $cable->id, 'quantite' => 3]]);

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle($vendeur, $brouillon),
            StockInsuffisantException::class,
            'Stock insuffisant : Câble USB, reste 1, demandé 3.',
        );

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count());
        $this->assertSame([1, 0], [$cable->fresh()->stock_quantite, MouvementStock::query()->where('type', 'vente')->count()]);
    }

    public function test_avec_l_autorisation_la_vente_exige_la_confirmation_de_l_avertissement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cable = $this->produit('Câble USB', 1);

        $avertissement = $this->avertissementDe(fn () => $this->vendre($this->proprietaireDe($magasin), $cable, 3));

        $this->assertSame('Le stock de Câble USB passera à −2 : confirmer la vente ?', $avertissement->getMessage());
        $this->assertSame([0, 1], [Facture::query()->count(), $cable->fresh()->stock_quantite]);

        $ticket = $this->vendre($this->proprietaireDe($magasin), $cable, 3, ConfirmationVenteSansStock::de($avertissement->manques));

        $this->assertSame([StatutFacture::Payee, -2], [$ticket->statut, $cable->fresh()->stock_quantite]);
    }

    public function test_une_confirmation_devenue_insuffisante_redemande_l_avertissement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $cable = $this->produit('Câble USB', 1);
        $confirmation = ConfirmationVenteSansStock::de($this->avertissementDe(fn () => $this->vendre($proprietaire, $cable, 3))->manques);
        $this->vendre($proprietaire, $cable, 1);

        $nouvelAvertissement = $this->avertissementDe(fn () => $this->vendre($proprietaire, $cable, 3, $confirmation));

        $this->assertSame('Le stock de Câble USB passera à −3 : confirmer la vente ?', $nouvelAvertissement->getMessage());
        $this->assertSame([1, 0], [Facture::query()->count(), $cable->fresh()->stock_quantite]);
    }

    public function test_la_vente_confirmee_rend_le_stock_negatif_alerte_et_se_journalise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', 1);
        Notification::fake();

        $ticket = $this->vendre($proprietaire, $cable, 3, ConfirmationVenteSansStock::de($this->avertissementDe(fn () => $this->vendre($proprietaire, $cable, 3))->manques));

        $this->assertSame(-2, $cable->fresh()->stock_quantite);
        $this->assertSame("Vente facture {$ticket->numero} (vente sans stock confirmée : stock négatif à régulariser)", MouvementStock::query()->where('produit_id', $cable->id)->where('type', 'vente')->sole()->note);
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::VenteSansStock)[0];
        $this->assertSame(
            [$magasin->id, $proprietaire->id, ['produits' => [['quantite' => 3, 'reference' => 'CABLE-USB', 'stock_avant' => 1]], 'vente' => $ticket->numero]],
            [(int) $entree->magasin_id, (int) $entree->auteur_id, $entree->nouvelles_valeurs],
        );
        Notification::assertSentTo([$proprietaire, $responsable], StockNegatif::class, fn (StockNegatif $alerte): bool => $alerte->produit->is($cable) && $alerte->numeroDeLaVente === $ticket->numero);
        Notification::assertNotSentTo($vendeur, StockNegatif::class);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_le_stock_negatif_reste_visible_jusqu_a_un_achat_qui_le_regularise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $cable = $this->produit('Câble USB', 1);
        $enStock = $this->produit('Chargeur', 4);
        $this->vendre($proprietaire, $cable, 3, ConfirmationVenteSansStock::de($this->avertissementDe(fn () => $this->vendre($proprietaire, $cable, 3))->manques));
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'negatif')
            ->assertCanSeeTableRecords([$cable])
            ->assertCanNotSeeTableRecords([$enStock])
            ->assertSee('À régulariser');
        Livewire::test(StockNegatifApercu::class)->assertSee('Stock négatif')->assertSee('1 produit à régulariser');

        app(ValiderFactureAchatAction::class)->handle($proprietaire, app(CreerFactureAchatAction::class)->handle($proprietaire, Fournisseur::factory()->create(), [
            ['produit_id' => $cable->id, 'quantite' => 5, 'prix_unitaire_ht' => '4.00', 'taux_tva' => 20],
        ]));

        $this->assertSame(3, $cable->fresh()->stock_quantite);
        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'negatif')
            ->assertCanNotSeeTableRecords([$cable, $enStock])
            ->assertDontSee('À régulariser');
        $this->assertFalse(StockNegatifApercu::canView());
    }

    public function test_un_produit_sans_suivi_du_stock_n_est_jamais_bloque(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $service = Produit::factory()->create(['nom' => 'Carte cadeau', 'prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false, 'stock_quantite' => 0]);

        $ticket = $this->vendre($vendeur, $service, 3);

        $this->assertSame([StatutFacture::Payee, 0], [$ticket->statut, $service->fresh()->stock_quantite]);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::VenteSansStock));
    }

    /**
     * Le vendeur à qui l'on a coché « vendre sans stock » voit
     * l'avertissement ; la case lui est retirée avant qu'il ne le
     * confirme : sa confirmation, relue sur son compte en base, est
     * refusée, sans rien vendre.
     */
    public function test_une_autorisation_retiree_entre_l_ecran_et_la_validation_refuse_la_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cases = [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::STOCK_VENDRE_SANS_STOCK];
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, $cases);
        $cable = $this->produit('Câble USB', 1);
        $confirmation = ConfirmationVenteSansStock::de($this->avertissementDe(fn () => $this->vendre($vendeur, $cable, 3))->manques);
        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => RoleMagasin::Vendeur->permissionsParDefaut()]]);
        app(TenantContext::class)->set($magasin->fresh());

        $this->assertThrows(fn () => $this->vendre($vendeur, $cable, 3, $confirmation), StockInsuffisantException::class, 'Stock insuffisant : Câble USB, reste 1, demandé 3.');

        $this->assertSame([0, 1], [Facture::query()->count(), $cable->fresh()->stock_quantite]);
    }

    /**
     * Par la caisse : encaisser sans confirmation n'encaisse rien et ouvre
     * l'avertissement ; une confirmation forgée pour un stock plus haut que
     * le réel le garde ouvert ; un vendeur non autorisé est refusé quelle
     * que soit la confirmation qu'il envoie. Seule la confirmation de
     * l'avertissement montré vend.
     */
    public function test_une_requete_forgee_sans_confirmation_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', 1);
        $panier = ['client_id' => $this->comptoir()->id, 'lignes' => [['produit_id' => $cable->id, 'description' => $cable->nom, 'quantite' => 3]], 'mode_paiement' => 'especes'];

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        Livewire::test(Caisse::class)
            ->fillForm($panier)
            ->mountAction('confirmerLaVenteSansStock', ['confirmation' => [$cable->id => -100]])
            ->callMountedAction()
            ->assertNotified('Stock insuffisant : Câble USB, reste 1, demandé 3.');
        $this->assertSame(0, Facture::query()->whereNotNull('numero')->count());

        $this->actingAs($proprietaire);
        $caisse = Livewire::test(Caisse::class)
            ->fillForm($panier)
            ->call('encaisser')
            ->assertActionMounted('confirmerLaVenteSansStock')
            ->assertSee('Le stock de Câble USB passera à −2.')
            ->set('mountedActionsArguments.0.confirmation', [$cable->id => -1])
            ->callMountedAction()
            ->assertActionMounted('confirmerLaVenteSansStock');
        $this->assertSame([0, 1], [Facture::query()->whereNotNull('numero')->count(), $cable->fresh()->stock_quantite]);

        $caisse->callMountedAction();

        $this->assertSame([1, -2], [Facture::query()->whereNotNull('numero')->count(), $cable->fresh()->stock_quantite]);
    }

    /**
     * Depuis la liste des factures, « Émettre » une vente au-delà du stock
     * rouvre sa fenêtre avec l'avertissement ; confirmé, il émet.
     */
    public function test_la_liste_des_factures_avertit_avant_d_emettre_une_vente_sans_stock(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $cable = $this->produit('Câble USB', 1);
        $brouillon = app(CreerFactureAction::class)->handle($proprietaire, $this->comptoir(), [['produit_id' => $cable->id, 'quantite' => 3]]);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $liste = Livewire::test(ListFactures::class)
            ->callTableAction('emettre', $brouillon)
            ->assertTableActionMounted('emettre')
            ->assertSee('Le stock de Câble USB passera à −2.');
        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);

        $liste->callMountedTableAction();

        $this->assertSame([StatutFacture::Emise, -2], [$brouillon->fresh()->statut, $cable->fresh()->stock_quantite]);
    }

    public function test_un_responsable_vend_sans_stock_apres_l_avertissement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $cable = $this->produit('Câble USB', 0);

        $avertissement = $this->avertissementDe(fn () => $this->vendre($responsable, $cable, 2));
        $ticket = $this->vendre($responsable, $cable, 2, ConfirmationVenteSansStock::de($avertissement->manques));

        $this->assertSame([(int) $responsable->id, -2], [(int) $ticket->cree_par, $cable->fresh()->stock_quantite]);
    }

    public function test_un_vendeur_a_qui_le_responsable_coche_vendre_sans_stock_vend_apres_l_avertissement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', 0);
        $this->assertThrows(fn () => $this->vendre($vendeur, $cable, 1), StockInsuffisantException::class);
        app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::STOCK_VENDRE_SANS_STOCK]]]);
        app(TenantContext::class)->set($magasin->fresh());

        $avertissement = $this->avertissementDe(fn () => $this->vendre($vendeur, $cable, 1));
        $ticket = $this->vendre($vendeur, $cable, 1, ConfirmationVenteSansStock::de($avertissement->manques));

        $this->assertSame([(int) $vendeur->id, -1], [(int) $ticket->cree_par, $cable->fresh()->stock_quantite]);
    }

    /**
     * Ni le modèle de l'administrateur, ni ceux du vendeur et de
     * l'observateur ne cochent « vendre sans stock » ; l'expert-comptable
     * ne l'a jamais.
     */
    public function test_vendre_sans_stock_n_est_precoche_dans_aucun_modele_et_jamais_donne_a_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->assertTrue($this->proprietaireDe($magasin)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
        $this->assertTrue($this->responsable($magasin)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
        $this->assertFalse($this->administrateur($magasin)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
        $this->assertFalse($this->membre($magasin, RoleMagasin::Vendeur)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
        $this->assertFalse($this->membre($magasin, RoleMagasin::Observateur)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
        $this->assertFalse($this->expertComptable($magasin)->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK));
    }

    /**
     * Un produit suivi du magasin actif, avec cette quantité en stock,
     * amenée par un ajustement d'inventaire.
     */
    private function produit(string $nom, int $stock): Produit
    {
        $produit = Produit::factory()->create(['nom' => $nom, 'reference' => mb_strtoupper(str_replace(['â', ' '], ['a', '-'], $nom)), 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);

        if ($stock !== 0) {
            app(StockLedgerService::class)->ajuster($produit, $stock, 'Inventaire d\'ouverture');
        }

        return $produit->fresh();
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    private function vendre(User $auteur, Produit $produit, int $quantite, ?ConfirmationVenteSansStock $confirmation = null): Facture
    {
        app(TenantContext::class)->set(Magasin::query()->findOrFail($produit->magasin_id));

        return app(EnregistrerVenteCaisseAction::class)->handle($auteur, $this->comptoir(), [['produit_id' => $produit->id, 'quantite' => $quantite]], ModePaiement::Especes, confirmation: $confirmation);
    }

    /**
     * @param  callable(): mixed  $vente
     */
    private function avertissementDe(callable $vente): ConfirmationVenteSansStockRequiseException
    {
        try {
            $vente();
        } catch (ConfirmationVenteSansStockRequiseException $avertissement) {
            return $avertissement;
        }

        $this->fail('La vente aurait dû demander la confirmation de l\'avertissement.');
    }
}
