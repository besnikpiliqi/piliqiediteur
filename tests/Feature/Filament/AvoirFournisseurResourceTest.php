<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ViewAvoirFournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AvoirFournisseurResourceTest extends TestCase
{
    use RefreshDatabase;

    private function receptionnerAchatDepuisLePanneau(Fournisseur $fournisseur, Produit $produit, int $quantite): FactureAchat
    {
        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => $quantite, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->latest('id')->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        return $factureAchat->fresh();
    }

    public function test_laction_avoir_fournisseur_cree_et_emet_un_retour_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchatDepuisLePanneau($fournisseur, $produit, 5);
        $this->assertSame(5, $produit->fresh()->stock_quantite);

        Livewire::test(ListFacturesAchat::class)
            ->callTableAction('avoir_fournisseur', $factureAchat, data: ['motif' => 'Marchandise non conforme']);

        $avoir = AvoirFournisseur::query()->where('facture_achat_id', $factureAchat->id)->firstOrFail();
        $this->assertNotNull($avoir->numero);
        $this->assertSame($factureAchat->total_ttc, $avoir->total_ttc);
        $this->assertSame(0, $produit->fresh()->stock_quantite);

        Livewire::test(ListAvoirsFournisseur::class)->assertCanSeeTableRecords([$avoir]);
    }

    /**
     * Exigence explicite du produit : un produit déjà vendu à un client ne
     * doit pas pouvoir être inclus dans l'avoir fournisseur. L'action ne
     * pré-remplit donc que la quantité encore en stock, pas la quantité
     * d'origine de la ligne d'achat.
     */
    public function test_laction_avoir_fournisseur_ne_propose_que_la_quantite_encore_en_stock(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'cmup_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchatDepuisLePanneau($fournisseur, $produit, 5);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        // 3 unités vendues à un client : il n'en reste que 2 disponibles
        // au retour fournisseur.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);

        $this->assertSame(2, $produit->fresh()->stock_quantite);

        // Remplissage complet du formulaire de l'action (voir
        // AvoirResourceTest pour l'explication du pourquoi un "data" partiel
        // fusionné via callTableAction duplique l'item par défaut).
        Livewire::test(ListFacturesAchat::class)
            ->mountTableAction('avoir_fournisseur', $factureAchat->fresh())
            ->fillForm([
                'lignes' => [['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2]],
                'motif' => 'Retour du surplus non vendu',
            ], 'mountedTableActionForm')
            ->callMountedTableAction();

        $avoir = AvoirFournisseur::query()->where('facture_achat_id', $factureAchat->id)->firstOrFail();
        $this->assertSame(0, $produit->fresh()->stock_quantite);
        $this->assertSame(0, $ligneAchat->fresh()->quantiteRetournableRestante());
    }

    /**
     * L'avoir fournisseur a sa fiche, comme l'avoir client : chaque article
     * retourné avec son PU HT, son HT, sa TVA et son TTC (un achat n'a pas
     * de PU TTC), et le récapitulatif par taux.
     */
    public function test_la_fiche_de_lavoir_fournisseur_montre_chaque_ligne_et_la_tva_par_taux(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $produit = Produit::factory()->create(['nom' => 'Ecran Iphone 18', 'taux_tva' => 20, 'suivi_stock' => false]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => '33.65', 'taux_tva' => 20],
        ]));
        $avoir = app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Écran fêlé'));

        Livewire::test(ListAvoirsFournisseur::class)->assertTableActionVisible('view', $avoir);

        Livewire::test(ViewAvoirFournisseur::class, ['record' => $avoir->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Ecran Iphone 18')
            ->assertSee('33,65')
            ->assertSee('6,73')
            ->assertSee('40,38')
            ->assertDontSee('PU TTC')
            ->assertSee('TVA par taux')
            ->assertSee('20 %');
    }

    private const CHOISIR_UNE_FACTURE_RETOURNABLE = 'Choisissez une facture d\'achat validée du magasin dont des produits restent à retourner.';

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Une facture d'achat validée, une ligne par produit et PU HT donnés.
     *
     * @param  array<int, array{0: Produit, 1: int, 2: string}>  $lignes  produit, quantité, PU HT
     */
    private function recevoir(array $lignes, ?Fournisseur $fournisseur = null): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(
            self::auteur(),
            $fournisseur ?? Fournisseur::factory()->create(),
            array_map(fn (array $ligne): array => ['produit_id' => $ligne[0]->id, 'quantite' => $ligne[1], 'prix_unitaire_ht' => $ligne[2]], $lignes),
        ));
    }

    private function retourner(FactureAchat $factureAchat, int $indexDeLaLigne, int $quantite): AvoirFournisseur
    {
        return app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes[$indexDeLaLigne]->id, 'quantite' => $quantite],
        ], 'Défaut constaté'));
    }

    /**
     * L'essai de l'analyse du point 19 : 10 câbles reçus à 10,00 € HT puis
     * 10 à 20,00 € HT (coût moyen 15,0000 €), 4 retournés sur la première
     * facture depuis le menu : l'avoir crédite 40,00 € HT + 8,00 € de TVA,
     * le stock passe à 16 et le coût moyen à (300 - 40) / 16 = 16,2500 €.
     */
    public function test_un_avoir_fournisseur_se_cree_depuis_le_menu_avoirs_fournisseur(): void
    {
        $this->travelTo('2026-10-17 10:32:00');
        $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $premiere = $this->recevoir([[$cable, 10, '10.00']]);
        $seconde = $this->recevoir([[$cable, 10, '20.00']]);
        $this->assertSame('15.0000', $cable->fresh()->cmup_ht);
        $component = Livewire::test(ListAvoirsFournisseur::class)
            ->assertActionVisible('creer_avoir_fournisseur')
            ->mountAction('creer_avoir_fournisseur')
            ->assertFormFieldExists('facture_achat_id', 'mountedActionForm', fn (Select $champ): bool => array_keys($champ->getOptions()) === [$seconde->id, $premiere->id])
            ->setActionData(['facture_achat_id' => $premiere->id]);
        $lignes = $component->get('mountedActionsData.0.lignes');
        $this->assertSame([['ligne_achat_id' => $premiere->lignes->sole()->id, 'description' => 'Câble USB-C', 'quantite' => 10]], array_values($lignes));

        $component
            ->set('mountedActionsData.0.lignes.'.array_key_first($lignes).'.quantite', 4)
            ->setActionData(['numero_fournisseur' => 'AV-2026-0042', 'motif' => 'Cartons abîmés'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->title('Avoir fournisseur AF-MABOUT-2026-000001 émis.')->success());

        $avoir = AvoirFournisseur::query()->with('lignes')->sole();
        $this->assertSame(
            ['AF-MABOUT-2026-000001', 'AV-2026-0042', 'Cartons abîmés', $premiere->id, ['40.00', '8.00', '48.00']],
            [$avoir->numero, $avoir->numero_fournisseur, $avoir->motif, (int) $avoir->facture_achat_id, [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]],
        );
        $this->assertSame([[(int) $premiere->lignes->sole()->id, 4]], $avoir->lignes->map(fn ($ligne): array => [(int) $ligne->ligne_achat_id, $ligne->quantite])->all());
        $this->assertSame([16, '16.2500'], [$cable->fresh()->stock_quantite, $cable->fresh()->cmup_ht]);
        $this->assertSame([10, 10], [$premiere->lignes->sole()->fresh()->quantite, $seconde->lignes->sole()->fresh()->quantite]);
    }

    /**
     * Sur la fiche d'une facture d'achat, « Créer un avoir fournisseur »
     * propose chaque ligne encore en stock, et « Retourner ce produit »
     * n'apparaît que sur une ligne retournable et ne propose qu'elle : les 3
     * chargeurs ont tous été vendus.
     */
    public function test_un_avoir_fournisseur_se_cree_depuis_la_fiche_de_la_facture_d_achat(): void
    {
        $this->travelTo('2026-10-17 10:32:00');
        $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'taux_tva' => 20, 'suivi_stock' => true]);
        $ecran = Produit::factory()->create(['nom' => 'Écran', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir([[$cable, 5, '12.00'], [$chargeur, 3, '7.00'], [$ecran, 2, '30.00']]);
        app(StockLedgerService::class)->enregistrer($chargeur, TypeMouvementStock::Vente, 3);
        $page = Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->mountAction('creer_avoir_fournisseur');
        $this->assertSame(
            [['ligne_achat_id' => $factureAchat->lignes[0]->id, 'description' => 'Câble USB-C', 'quantite' => 5], ['ligne_achat_id' => $factureAchat->lignes[2]->id, 'description' => 'Écran', 'quantite' => 2]],
            array_values($page->get('mountedActionsData.0.lignes')),
        );
        $page->unmountAction()
            ->assertInfolistActionVisible('lignes.0.retourner_ce_produitAction', 'retourner_ce_produit')
            ->assertInfolistActionVisible('lignes.2.retourner_ce_produitAction', 'retourner_ce_produit')
            ->assertDontSeeHtml('lignes.1.retourner_ce_produitAction')
            ->mountInfolistAction('lignes.1.retourner_ce_produitAction', 'retourner_ce_produit')
            ->assertSet('mountedInfolistActions', [])
            ->mountInfolistAction('lignes.2.retourner_ce_produitAction', 'retourner_ce_produit');
        $lignes = $page->get('mountedInfolistActionsData.0.lignes');
        $this->assertSame([['ligne_achat_id' => $factureAchat->lignes[2]->id, 'description' => 'Écran', 'quantite' => 2]], array_values($lignes));

        $page
            ->set('mountedInfolistActionsData.0.lignes.'.array_key_first($lignes).'.quantite', 1)
            ->setInfolistActionData(['numero_fournisseur' => 'AV-77', 'motif' => 'Écran fêlé'])
            ->callMountedInfolistAction()
            ->assertHasNoInfolistActionErrors()
            ->assertNotified(Notification::make()->title('Avoir fournisseur AF-MABOUT-2026-000001 émis.')->success());

        $avoir = AvoirFournisseur::query()->with('lignes')->sole();
        $this->assertSame([$factureAchat->id, 'AV-77', ['30.00', '6.00', '36.00']], [(int) $avoir->facture_achat_id, $avoir->numero_fournisseur, [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]]);
        $this->assertSame([[(int) $factureAchat->lignes[2]->id, 1]], $avoir->lignes->map(fn ($ligne): array => [(int) $ligne->ligne_achat_id, $ligne->quantite])->all());
        $this->assertSame([5, 0, 1], [$cable->fresh()->stock_quantite, $chargeur->fresh()->stock_quantite, $ecran->fresh()->stock_quantite]);
    }

    /**
     * Le menu ne propose que les factures d'achat validées du magasin dont
     * il reste des articles à retourner : ni un brouillon, ni une facture
     * déjà retournée en entier, ni celle dont tout a été vendu, ni une
     * facture d'un autre magasin de la société, qu'une requête forgée ne
     * fait pas accepter non plus.
     */
    public function test_le_choix_d_une_facture_d_achat_se_limite_au_magasin_et_aux_factures_validees_retournables(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'taux_tva' => 20, 'suivi_stock' => true]);
        $ecran = Produit::factory()->create(['nom' => 'Écran', 'taux_tva' => 20, 'suivi_stock' => true]);
        $retournable = $this->recevoir([[$cable, 5, '12.00']]);
        $brouillon = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 2, 'prix_unitaire_ht' => '12.00']]);
        $retourneeEnEntier = $this->recevoir([[$chargeur, 3, '7.00']]);
        $this->retourner($retourneeEnEntier, 0, 3);
        $vendue = $this->recevoir([[$ecran, 2, '30.00']]);
        app(StockLedgerService::class)->enregistrer($ecran, TypeMouvementStock::Vente, 2);
        $autreMagasin = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique de Lyon', 'code' => 'LYON1']);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureDeLyon = $this->recevoir([[$lampe, 4, '9.00']]);
        app(TenantContext::class)->set($magasin);
        $component = Livewire::test(ListAvoirsFournisseur::class)
            ->mountAction('creer_avoir_fournisseur')
            ->assertFormFieldExists('facture_achat_id', 'mountedActionForm', fn (Select $champ): bool => array_keys($champ->getOptions()) === [$retournable->id]
                && array_keys($champ->getSearchResults('FA-')) === [$retournable->id]
                && $champ->getSearchResults($factureDeLyon->numero) === []);

        foreach ([$factureDeLyon, $brouillon, $retourneeEnEntier, $vendue] as $refusee) {
            $component
                ->setActionData(['facture_achat_id' => $refusee->id, 'motif' => 'Requête forgée'])
                ->set('mountedActionsData.0.lignes', ['forgee' => ['ligne_achat_id' => $refusee->lignes()->withoutGlobalScopes()->value('id'), 'quantite' => 1]])
                ->callMountedAction();

            $this->assertSame([self::CHOISIR_UNE_FACTURE_RETOURNABLE], $component->errors()->get('mountedActionsData.0.facture_achat_id'), $refusee->numero ?? 'brouillon');
        }
        $this->assertSame([(int) $retourneeEnEntier->id], AvoirFournisseur::query()->withoutGlobalScopes()->pluck('facture_achat_id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([5, 0, 0, 4], [$cable->fresh()->stock_quantite, $chargeur->fresh()->stock_quantite, $ecran->fresh()->stock_quantite, $lampe->fresh()->stock_quantite]);
        $this->assertSame((int) $autreMagasin->id, (int) $factureDeLyon->magasin_id);
    }

    /**
     * Sous chaque ligne de la fiche d'une facture d'achat, en orange comme
     * le crédit d'avoir sous un client, une mention par avoir fournisseur
     * émis, dont le numéro mène à sa fiche ; « Total après avoirs
     * fournisseur » retire leurs totaux TTC de celui de la facture :
     * 97,20 - 28,80 - 14,40 = 54,00 €.
     */
    public function test_chaque_retour_s_affiche_en_orange_sous_sa_ligne_avec_le_numero_de_l_avoir(): void
    {
        $this->travelTo('2026-10-17 10:32:00');
        $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir([[$cable, 5, '12.00'], [$chargeur, 3, '7.00']]);
        $premier = $this->retourner($factureAchat, 0, 2);
        $second = $this->retourner($factureAchat, 0, 1);

        $page = Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()]);

        foreach ([[$premier, 2], [$second, 1]] as [$avoir, $quantite]) {
            $page->assertSeeHtml('<span style="color: #c2410c; font-weight: 600;">Retourné : '.$quantite.' (avoir <a href="'.e(AvoirFournisseurResource::getUrl('view', ['record' => $avoir])).'" style="text-decoration: underline;">'.$avoir->numero.'</a> du 17/10/2026)</span>');
        }
        $this->assertSame(['AF-MABOUT-2026-000001', 'AF-MABOUT-2026-000002'], [$premier->numero, $second->numero]);
        $this->assertSame(2, substr_count($page->html(), 'Retourné :'));
        $page->assertSeeInOrder(['Total après avoirs fournisseur', '54,00 €'])
            ->assertInfolistActionVisible('lignes.0.retourner_ce_produitAction', 'retourner_ce_produit')
            ->assertInfolistActionVisible('lignes.1.retourner_ce_produitAction', 'retourner_ce_produit');
    }

    /**
     * Un vendeur qui voit les achats et les avoirs fournisseur, sans
     * l'autorisation d'en créer : aucun bouton, ni au menu, ni sur la liste
     * ou la fiche des factures d'achat, ni sur leurs lignes, et une
     * demande forgée n'ouvre rien ; il voit les retours, et le numéro de
     * l'avoir ne mène à sa fiche que pour qui peut la voir.
     */
    public function test_un_vendeur_sans_l_autorisation_ne_voit_pas_le_bouton(): void
    {
        $this->travelTo('2026-10-17 10:32:00');
        $magasin = $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir([[$cable, 5, '12.00']]);
        $avoir = $this->retourner($factureAchat, 0, 2);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW]);
        $sansLesAvoirs = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_ACHAT_VIEW]);
        $this->actingAs($vendeur);

        Livewire::test(ListAvoirsFournisseur::class)
            ->assertActionHidden('creer_avoir_fournisseur')
            ->call('mountAction', 'creer_avoir_fournisseur')
            ->assertSet('mountedActions', []);
        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('avoir_fournisseur', $factureAchat);
        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertActionHidden('creer_avoir_fournisseur')
            ->assertDontSee('Retourner ce produit')
            ->assertSeeHtml('(avoir <a href="'.e(AvoirFournisseurResource::getUrl('view', ['record' => $avoir])).'"')
            ->mountInfolistAction('lignes.0.retourner_ce_produitAction', 'retourner_ce_produit')
            ->assertSet('mountedInfolistActions', []);
        $this->actingAs($sansLesAvoirs);
        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertSeeHtml('Retourné : 2 (avoir AF-MABOUT-2026-000001 du 17/10/2026)')
            ->assertDontSeeHtml('/avoir-fournisseurs/');

        $this->assertSame(1, AvoirFournisseur::query()->count());
        $this->assertSame(3, $cable->fresh()->stock_quantite);
    }

    /**
     * Le n° de l'avoir du fournisseur est réclamé tant qu'il manque, dans
     * le formulaire puis dans l'avis, sans que l'avoir soit refusé.
     */
    public function test_un_avoir_sans_numero_du_fournisseur_avertit_sans_refuser(): void
    {
        $this->travelTo('2026-10-17 10:32:00');
        $this->connecterLeProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->recevoir([[$cable, 5, '12.00']]);

        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->mountAction('creer_avoir_fournisseur')
            ->assertSee(FactureAchatResource::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR)
            ->setActionData(['numero_fournisseur' => 'AV-1'])
            ->assertDontSee(FactureAchatResource::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR)
            ->setActionData(['numero_fournisseur' => '', 'motif' => 'Erreur de livraison'])
            ->assertSee(FactureAchatResource::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR)
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->title('Avoir fournisseur AF-MABOUT-2026-000001 émis.')->warning()->body(FactureAchatResource::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR));

        $avoir = AvoirFournisseur::query()->sole();
        $this->assertSame(['AF-MABOUT-2026-000001', null, '72.00'], [$avoir->numero, $avoir->numero_fournisseur, $avoir->total_ttc]);
        $this->assertSame(0, $cable->fresh()->stock_quantite);
    }
}
