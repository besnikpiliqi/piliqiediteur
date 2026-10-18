<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le grand tableau des produits (point 13), qu'ouvre « Ajouter un
 * produit » à la caisse, sur une facture ou un devis.
 */
class SelecteurDeProduitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_tableau_affiche_cinquante_produits_puis_cinquante_de_plus(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $produits = collect(range(1, 55))->map(fn (int $numero): Produit => Produit::factory()->create(['nom' => sprintf('Produit %02d', $numero)]));

        $selecteur = Livewire::test(SelecteurDeProduits::class)
            ->assertCanSeeTableRecords($produits->take(50), inOrder: true)
            ->assertCanNotSeeTableRecords($produits->slice(50))
            ->assertSee('Voir 50 de plus');

        $selecteur->call('voirPlus')
            ->assertCanSeeTableRecords($produits, inOrder: true)
            ->assertDontSee('Voir 50 de plus');
    }

    public function test_la_recherche_trouve_par_nom_reference_et_code_barres_sans_accents(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $creme = Produit::factory()->create(['nom' => 'Crème solaire', 'reference' => 'SOL-1']);
        $etiquette = Produit::factory()->create(['nom' => 'Rouleau', 'reference' => 'ÉTIQ-9']);
        $ecran = Produit::factory()->create(['nom' => 'Écran 24 pouces', 'reference' => 'ECR-24', 'code_barres' => '3456789012345']);

        Livewire::test(SelecteurDeProduits::class)
            ->searchTable('CREME')
            ->assertCanSeeTableRecords([$creme])
            ->assertCanNotSeeTableRecords([$etiquette, $ecran])
            ->searchTable('etiq')
            ->assertCanSeeTableRecords([$etiquette])
            ->assertCanNotSeeTableRecords([$creme, $ecran])
            ->searchTable('3456789012345')
            ->assertCanSeeTableRecords([$ecran])
            ->assertCanNotSeeTableRecords([$creme, $etiquette]);
    }

    public function test_les_colonnes_se_choisissent_et_le_choix_reste_pour_la_session(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        Produit::factory()->create(['nom' => 'Câble USB', 'code_barres' => '3456789012345']);

        Livewire::test(SelecteurDeProduits::class)
            ->assertCanNotRenderTableColumn('code_barres')
            ->assertDontSee('3456789012345')
            ->set('toggledTableColumns.code_barres', true)
            ->assertCanRenderTableColumn('code_barres')
            ->assertSee('3456789012345');

        Livewire::test(SelecteurDeProduits::class)
            ->assertCanRenderTableColumn('code_barres')
            ->assertSee('3456789012345');
    }

    /**
     * Q9 (a) : le coût moyen, le bénéfice et le taux de marque ne se
     * montrent qu'à qui voit la valeur du stock. 25,00 € TTC à 20 % font
     * 20,83 € HT ; sur un coût moyen de 8,3333 €, 12,4967 € de bénéfice,
     * arrondi une fois : 12,50 €, soit 12,50 ÷ 20,83 = 60,01 % de marque.
     */
    public function test_le_cout_et_le_benefice_ne_s_affichent_qu_avec_l_autorisation_de_voir_la_valeur_du_stock(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        Produit::factory()->create(['nom' => 'T-shirt', 'prix_vente_ttc' => 25, 'taux_tva' => 20, 'cmup_ht' => '8.3333']);

        Livewire::test(SelecteurDeProduits::class)
            ->set('toggledTableColumns.cout_moyen', true)
            ->set('toggledTableColumns.taux_de_marque', true)
            ->assertSee('20,83 €')
            ->assertSee('8,3333 €')
            ->assertSee('12,50 €')
            ->assertSee('60,01 %');

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));

        Livewire::test(SelecteurDeProduits::class)
            ->set('toggledTableColumns.cout_moyen', true)
            ->set('toggledTableColumns.taux_de_marque', true)
            ->assertTableColumnHidden('cout_moyen')
            ->assertTableColumnHidden('benefice')
            ->assertTableColumnHidden('taux_de_marque')
            ->assertSee('20,83 €')
            ->assertDontSee('8,3333 €')
            ->assertDontSee('12,50 €')
            ->assertDontSee('60,01 %');

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK]));

        Livewire::test(SelecteurDeProduits::class)
            ->assertTableColumnVisible('benefice')
            ->assertSee('12,50 €');
    }

    /**
     * « Hors stock seulement » n'est proposé qu'à qui peut vendre sans
     * stock dans ce magasin (R1) ; un vendeur qui n'y est pas autorisé ne
     * l'applique pas, même par une requête forgée. « En stock seulement »
     * garde les produits qui ne suivent pas leur stock.
     */
    public function test_le_filtre_hors_stock_n_existe_que_pour_qui_peut_vendre_sans_stock(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        $enStock = Produit::factory()->create(['nom' => 'Câble USB', 'suivi_stock' => true, 'stock_quantite' => 4]);
        $epuise = Produit::factory()->create(['nom' => 'Chargeur', 'suivi_stock' => true, 'stock_quantite' => 0]);
        $negatif = Produit::factory()->create(['nom' => 'Housse', 'suivi_stock' => true, 'stock_quantite' => -2]);
        $sansSuivi = Produit::factory()->create(['nom' => 'Carte cadeau', 'suivi_stock' => false]);

        Livewire::test(SelecteurDeProduits::class)
            ->assertTableFilterVisible('hors_stock')
            ->filterTable('hors_stock')
            ->assertCanSeeTableRecords([$epuise, $negatif])
            ->assertCanNotSeeTableRecords([$enStock, $sansSuivi])
            ->removeTableFilters()
            ->filterTable('en_stock')
            ->assertCanSeeTableRecords([$enStock, $sansSuivi])
            ->assertCanNotSeeTableRecords([$epuise, $negatif]);

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));

        Livewire::test(SelecteurDeProduits::class)
            ->assertTableFilterHidden('hors_stock')
            ->set('tableFilters.hors_stock', ['isActive' => true])
            ->assertCanSeeTableRecords([$enStock, $epuise, $negatif, $sansSuivi]);
    }

    /**
     * Un produit à zéro, ou en stock négatif après une vente sans stock
     * (R1), est épuisé : sans l'autorisation de vendre sans stock, il reste
     * visible, « Épuisé » sous son nom, mais ne se choisit ni d'un clic, ni
     * par une sélection forgée, ni à la douchette. Le stock négatif garde
     * « À régulariser » pour tous.
     */
    public function test_un_produit_epuise_ne_se_selectionne_pas_sans_l_autorisation_meme_par_requete_forgee(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        $epuise = Produit::factory()->create(['nom' => 'Chargeur', 'reference' => 'CHG-1', 'suivi_stock' => true, 'stock_quantite' => 0]);
        $negatif = Produit::factory()->create(['nom' => 'Housse', 'reference' => 'HOU-1', 'suivi_stock' => true, 'stock_quantite' => -2]);
        $refus = '« Chargeur » est épuisé : seule une personne autorisée à vendre sans stock peut le vendre.';
        $refusDuNegatif = '« Housse » est épuisé : seule une personne autorisée à vendre sans stock peut le vendre.';

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur));

        Livewire::test(SelecteurDeProduits::class)
            ->assertCanSeeTableRecords([$epuise, $negatif])
            ->assertSee('Épuisé')
            ->assertTableColumnHasDescription('nom', 'Épuisé', $epuise)
            ->assertTableColumnHasDescription('nom', 'Épuisé', $negatif)
            ->assertTableColumnHasDescription('stock_quantite', 'À régulariser', $negatif)
            ->call('basculer', $epuise->getKey())
            ->assertNotified($refus)
            ->call('basculer', $negatif->getKey())
            ->assertNotified($refusDuNegatif)
            ->assertSet('selection', []);

        $caisse = Livewire::test(Caisse::class)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $epuise->getKey(), 'quantite' => 1]])
            ->assertNotified($refus)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $negatif->getKey(), 'quantite' => 1]])
            ->assertNotified($refusDuNegatif)
            ->set('data.douchette', 'CHG-1')
            ->call('scannerUneReference')
            ->assertNotified($refus);
        $this->assertSame([], $caisse->get('data.lignes'));

        $this->actingAs($magasin->societe->proprietaire()->firstOrFail());

        Livewire::test(SelecteurDeProduits::class)
            ->assertTableColumnDoesNotHaveDescription('nom', 'Épuisé', $negatif)
            ->assertTableColumnHasDescription('stock_quantite', 'À régulariser', $negatif)
            ->call('basculer', $epuise->getKey())
            ->assertSet('selection', [['produit_id' => $epuise->getKey(), 'quantite' => 1]]);
    }

    /**
     * La liste « Produit » d'une ligne déjà posée applique le même refus
     * que le tableau : sans l'autorisation de vendre sans stock, choisir un
     * produit épuisé le dit et la ligne garde son produit et son prix, puis
     * le brouillon s'enregistre avec eux ; une personne autorisée change le
     * produit de la ligne.
     */
    public function test_la_liste_produit_d_une_ligne_refuse_un_produit_epuise_sans_l_autorisation(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble', 'reference' => 'CAB-1', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 5]);
        $chargeur = Produit::factory()->create(['nom' => 'Chargeur', 'reference' => 'CHG-1', 'prix_vente_ttc' => '30.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $client = Client::factory()->create();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->getKey()])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $cle = array_key_first($page->get('data.lignes'));

        $page->set("data.lignes.{$cle}.produit_id", $chargeur->getKey())
            ->assertNotified('« Chargeur » est épuisé : seule une personne autorisée à vendre sans stock peut le vendre.')
            ->assertSet("data.lignes.{$cle}.produit_id", $cable->getKey())
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '12.00')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame([$cable->getKey()], Facture::query()->sole()->lignes->pluck('produit_id')->all());

        $this->actingAs($magasin->societe->proprietaire()->firstOrFail());

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->getKey()])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
        $cle = array_key_first($page->get('data.lignes'));

        $page->set("data.lignes.{$cle}.produit_id", $chargeur->getKey())
            ->assertSet("data.lignes.{$cle}.produit_id", $chargeur->getKey())
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '30.00');
    }

    /**
     * Le stock restant, le seuil d'alerte, le fournisseur et la date
     * d'entrée ne se montrent qu'à qui voit les fiches des produits : un
     * vendeur à qui « Produits et services : voir » est décoché vend
     * toujours, voit la référence, le nom et le prix, et « Épuisé » sous un
     * produit qu'il ne peut pas vendre, mais aucune de ces informations,
     * même en demandant leurs colonnes.
     */
    public function test_le_stock_et_le_fournisseur_ne_s_affichent_qu_a_qui_voit_les_fiches_des_produits(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste Secret']);
        $this->travelTo(CarbonImmutable::parse('2026-09-03 10:00'));
        $cable = Produit::factory()->create(['nom' => 'Câble', 'reference' => 'CAB-1', 'prix_vente_ttc' => '12.00', 'suivi_stock' => true, 'stock_quantite' => 37, 'seuil_alerte' => 11, 'fournisseur_id' => $fournisseur->getKey()]);
        $epuise = Produit::factory()->create(['nom' => 'Chargeur', 'suivi_stock' => true, 'stock_quantite' => 0]);
        $this->travelBack();
        $colonnes = ['stock_quantite', 'seuil_alerte', 'fournisseur.nom', 'created_at'];

        $tableau = Livewire::test(SelecteurDeProduits::class)
            ->set('toggledTableColumns', ['seuil_alerte' => true, 'fournisseur' => ['nom' => true], 'created_at' => true]);
        foreach ($colonnes as $colonne) {
            $tableau->assertTableColumnVisible($colonne);
        }
        $tableau->assertSee(['Grossiste Secret', '03/09/2026'])
            ->assertTableColumnFormattedStateSet('stock_quantite', '37', $cable)
            ->assertTableColumnFormattedStateSet('seuil_alerte', '11', $cable);

        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::PRODUIT_VIEW]))));

        $tableau = Livewire::test(SelecteurDeProduits::class)
            ->set('toggledTableColumns', ['seuil_alerte' => true, 'fournisseur' => ['nom' => true], 'created_at' => true]);
        foreach ($colonnes as $colonne) {
            $tableau->assertTableColumnHidden($colonne);
        }
        $tableau->assertCanSeeTableRecords([$cable, $epuise])
            ->assertSee(['CAB-1', 'Câble', '12,00 €'])
            ->assertTableColumnHasDescription('nom', 'Épuisé', $epuise)
            ->assertDontSee('Grossiste Secret')
            ->assertDontSee('03/09/2026')
            ->call('basculer', $cable->getKey())
            ->assertSet('selection', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);
    }

    /**
     * Le total de la sélection est celui du document une fois les produits
     * ajoutés, pour tout client et toute nature d'opération : 2 x 49,90 €
     * à 20 % font 99,80 ÷ 1,2 = 83,17 € HT, 3 x 4,20 € à 5,5 % font
     * 12,60 ÷ 1,055 = 11,94 € HT, soit 95,11 € HT et 112,40 € TTC, au
     * particulier de la caisse comme au professionnel d'une facture ; à
     * l'export, le prix sans TVA française, 95,11 € HT et TTC.
     */
    public function test_les_totaux_de_la_selection_egalent_ceux_du_document_apres_l_ajout(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '49.90', 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['nom' => 'Livre', 'prix_vente_ttc' => '4.20', 'taux_tva' => '5.5', 'suivi_stock' => false]);
        $professionnel = Client::factory()->create(['nom' => 'Atelier Martin', 'type_client' => TypeClient::Professionnel, 'siren' => '732829320']);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();

        $selection = $this->choisirDansLeTableau([$cable->getKey() => 2, $livre->getKey() => 3], $comptoir->getKey(), null)
            ->assertSee('2 produits sélectionnés · Total HT 95,11 € · Total TTC 112,40 €')
            ->get('selection');
        Livewire::test(Caisse::class)
            ->call('recevoirLesProduitsChoisis', $selection)
            ->assertSee('Total HT 95,11 € · TVA 17,29 € · Total TTC 112,40 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();
        $ticket = Facture::query()->where('client_id', $comptoir->getKey())->sole();
        $this->assertSame(['95.11', '17.29', '112.40'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);

        $selection = $this->choisirDansLeTableau([$cable->getKey() => 2, $livre->getKey() => 3], $professionnel->getKey(), NatureOperation::VenteEnFrance->value)
            ->assertSee('2 produits sélectionnés · Total HT 95,11 € · Total TTC 112,40 €')
            ->get('selection');
        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $professionnel->getKey()])
            ->call('recevoirLesProduitsChoisis', $selection)
            ->call('create')
            ->assertHasNoFormErrors();
        $facture = Facture::query()->where('client_id', $professionnel->getKey())->sole();
        $this->assertSame(['95.11', '17.29', '112.40'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);

        $selection = $this->choisirDansLeTableau([$cable->getKey() => 2, $livre->getKey() => 3], $professionnel->getKey(), NatureOperation::ExportHorsUe->value)
            ->assertSee('2 produits sélectionnés · Total HT 95,11 € · Total TTC 95,11 €')
            ->get('selection');
        Livewire::test(CreateDevis::class)
            ->fillForm(['client_id' => $professionnel->getKey(), 'nature_operation' => NatureOperation::ExportHorsUe->value])
            ->call('recevoirLesProduitsChoisis', $selection)
            ->call('create')
            ->assertHasNoFormErrors();
        $devis = Devis::query()->sole();
        $this->assertSame(['95.11', '0.00', '95.11'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
    }

    /**
     * « Ajouter » envoie la sélection à la page : une ligne par produit, au
     * prix et au taux de sa fiche, sans remise, avec sa quantité ; la ligne
     * vide du formulaire disparaît et la fenêtre se ferme.
     */
    public function test_ajouter_la_selection_cree_une_ligne_par_produit_au_prix_de_sa_fiche(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble', 'reference' => 'CAB-1', 'prix_vente_ttc' => '49.90', 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['nom' => 'Livre', 'reference' => 'LIV-1', 'prix_vente_ttc' => '4.20', 'taux_tva' => '5.5', 'suivi_stock' => false]);
        $client = Client::factory()->create();

        Livewire::test(SelecteurDeProduits::class, ['clientId' => $client->getKey()])
            ->call('basculer', $cable->getKey())
            ->call('basculer', $livre->getKey())
            ->call('changerLaQuantite', $livre->getKey(), '3')
            ->call('ajouterLaSelection')
            ->assertDispatched(SelecteurDeProduits::EVENEMENT, selection: [
                ['produit_id' => $cable->getKey(), 'quantite' => 1],
                ['produit_id' => $livre->getKey(), 'quantite' => 3],
            ]);

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->getKey()])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->call('recevoirLesProduitsChoisis', [
                ['produit_id' => $cable->getKey(), 'quantite' => 1],
                ['produit_id' => $livre->getKey(), 'quantite' => 3],
            ])
            ->assertFormComponentActionNotMounted('ajouterUnProduitAction', 'ajouterUnProduit');

        $this->assertSame([
            ['CAB-1', 'Câble', 1, '49.90', '20', '0'],
            ['LIV-1', 'Livre', 3, '4.20', '5.5', '0'],
        ], array_map(
            fn (array $ligne): array => [$ligne['reference'], $ligne['description'], $ligne['quantite'], $ligne['prix_unitaire_ttc'], $ligne['taux_tva'], $ligne['remise_pourcentage']],
            array_values($page->get('data.lignes')),
        ));

        $page->call('create')->assertHasNoFormErrors();
        $this->assertSame(
            [['49.90', 1], ['4.20', 3]],
            Facture::query()->sole()->lignes->map(fn ($ligne): array => [$ligne->prix_unitaire_ttc, $ligne->quantite])->all(),
        );
    }

    /**
     * Un produit déjà sur une ligne au prix de sa fiche, sans remise, voit
     * sa quantité augmenter ; une ligne remisée n'est jamais touchée (sa
     * quantité exigerait l'autorisation des remises) : le produit arrive
     * sur une nouvelle ligne.
     */
    public function test_un_produit_deja_present_sans_remise_voit_sa_quantite_augmenter(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false]);

        $caisse = Livewire::test(Caisse::class)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 2]]);
        $cle = array_key_first($caisse->get('data.lignes'));
        $this->assertSame([3], array_column($caisse->get('data.lignes'), 'quantite'));

        $caisse->set("data.lignes.{$cle}.remise_pourcentage", '10')
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $cable->getKey(), 'quantite' => 1]]);

        $this->assertSame(
            [[3, '10'], [1, '0']],
            array_map(fn (array $ligne): array => [$ligne['quantite'], $ligne['remise_pourcentage']], array_values($caisse->get('data.lignes'))),
        );
    }

    public function test_un_produit_archive_ou_d_un_autre_magasin_n_est_jamais_propose(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $autreMagasin = Produit::factory()->create(['nom' => 'Câble de la concurrence', 'reference' => 'CONC-1']);
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $actif = Produit::factory()->create(['nom' => 'Câble USB']);
        $archive = Produit::factory()->create(['nom' => 'Câble HDMI', 'reference' => 'HDMI-1']);
        $archive->delete();

        Livewire::test(SelecteurDeProduits::class)
            ->assertCanSeeTableRecords([$actif])
            ->assertCanNotSeeTableRecords([$archive, $autreMagasin])
            ->searchTable('cable')
            ->assertCanSeeTableRecords([$actif])
            ->assertCanNotSeeTableRecords([$archive, $autreMagasin])
            ->call('basculer', $autreMagasin->getKey())
            ->assertNotified(DevisResource::PRODUIT_INTROUVABLE)
            ->call('basculer', $archive->getKey())
            ->assertNotified(DevisResource::PRODUIT_INTROUVABLE)
            ->assertSet('selection', []);

        $caisse = Livewire::test(Caisse::class)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $autreMagasin->getKey(), 'quantite' => 1]])
            ->assertNotified(DevisResource::PRODUIT_INTROUVABLE)
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $archive->getKey(), 'quantite' => 1]])
            ->assertNotified(DevisResource::PRODUIT_INTROUVABLE)
            ->set('data.douchette', 'CONC-1')
            ->call('scannerUneReference')
            ->assertNotified('Aucun produit du magasin n\'a le code-barres ni la référence « CONC-1 ».')
            ->set('data.douchette', 'HDMI-1')
            ->call('scannerUneReference')
            ->assertNotified('Aucun produit du magasin n\'a le code-barres ni la référence « HDMI-1 ».');
        $this->assertSame([], $caisse->get('data.lignes'));
    }

    /**
     * « Les plus récents » du tableau trient par date d'entrée du produit ;
     * un tri forgé revient au tri par nom.
     */
    public function test_le_tri_plus_recents_d_abord_suit_la_date_d_entree_du_produit(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
        $ancien = Produit::factory()->create(['nom' => 'Agrafeuse']);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00'));
        $recent = Produit::factory()->create(['nom' => 'Zip']);
        $this->travelBack();

        Livewire::test(SelecteurDeProduits::class)
            ->assertCanSeeTableRecords([$ancien, $recent], inOrder: true)
            ->set('tri', SelecteurDeProduits::TRI_PLUS_RECENTS)
            ->assertCanSeeTableRecords([$recent, $ancien], inOrder: true)
            ->set('tri', 'stock_quantite desc; drop table produits')
            ->assertSet('tri', SelecteurDeProduits::TRI_PAR_NOM)
            ->assertCanSeeTableRecords([$ancien, $recent], inOrder: true);
    }

    public function test_le_nom_d_un_produit_s_affiche_echappe_dans_le_tableau_et_la_selection(): void
    {
        $this->ouvrirLeMagasinEnTantQueProprietaire();
        $piege = Produit::factory()->create(['nom' => '<script>alert("piège")</script>', 'reference' => 'PIEGE-1']);

        Livewire::test(SelecteurDeProduits::class)
            ->call('basculer', $piege->getKey())
            ->assertSee('&lt;script&gt;alert', escape: false)
            ->assertDontSee('<script>alert("piège")</script>', escape: false);
    }

    /**
     * Le tableau ne s'ouvre qu'à qui saisit une vente dans le magasin :
     * un observateur, qui ne crée ni facture ni devis, en est refusé.
     */
    public function test_le_tableau_ne_s_ouvre_qu_a_qui_saisit_une_vente(): void
    {
        $magasin = $this->ouvrirLeMagasinEnTantQueProprietaire();
        $this->actingAs($this->membre($magasin, RoleMagasin::Observateur));

        Livewire::test(SelecteurDeProduits::class)->assertForbidden();
    }

    private function ouvrirLeMagasinEnTantQueProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Le tableau ouvert pour ce client et cette nature, ces produits choisis
     * d'un clic, chacun avec sa quantité.
     *
     * @param  array<int, int>  $quantites  par identifiant de produit
     */
    private function choisirDansLeTableau(array $quantites, int $clientId, ?string $nature): Testable
    {
        $selecteur = Livewire::test(SelecteurDeProduits::class, ['clientId' => $clientId, 'nature' => $nature]);

        foreach ($quantites as $produitId => $quantite) {
            $selecteur->call('basculer', $produitId)->call('changerLaQuantite', $produitId, (string) $quantite);
        }

        return $selecteur;
    }
}
