<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FactureAchatResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Même bug que sur FactureResource (voir son test équivalent) : un
     * RepeatableEntry nourri par un state() arbitraire ne doit jamais
     * s'appuyer sur la résolution implicite de Filament pour ses champs
     * imbriqués -- ici pour "Avoirs fournisseur émis".
     */
    public function test_la_facture_dachat_affiche_le_detail_des_avoirs_fournisseur_emis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $factureAchat = FactureAchat::query()->where('fournisseur_id', $fournisseur->id)->latest('id')->firstOrFail();
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $ligneAchat = $factureAchat->lignes->firstOrFail();
        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 5],
        ], 'Marchandise non conforme');
        app(EmettreAvoirFournisseurAction::class)->handle($avoir);

        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Avoirs fournisseur émis')
            ->assertSee($avoir->fresh()->numero)
            ->assertSee('Marchandise non conforme');
    }

    /**
     * Même raisonnement que le test équivalent sur FactureResource ("avoir"
     * sans aucune ligne) : le Repeater de lignes à retourner est
     * "deletable(true)", un vendeur peut donc les retirer toutes avant de
     * valider. CreerAvoirFournisseurAction refuse alors de créer un avoir
     * sans aucune ligne -- avant la correction, cette LogicException
     * remontait telle quelle jusqu'à une page d'erreur brute.
     */
    public function test_laction_avoir_fournisseur_sans_aucune_ligne_affiche_une_notification(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $factureAchat = FactureAchat::query()->where('fournisseur_id', $fournisseur->id)->latest('id')->firstOrFail();
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        Livewire::test(ListFacturesAchat::class)
            ->callTableAction('avoir_fournisseur', $factureAchat->fresh(), data: ['lignes' => [], 'motif' => 'Oups'])
            ->assertNotified('Un avoir fournisseur doit porter sur au moins une ligne.');

        $this->assertSame(0, AvoirFournisseur::query()->count());
    }

    /**
     * Bug rapporté par un magasin : 0,47 € HT de prix d'achat puis 10 € TTC
     * de prix de vente, et la ligne passait à 8,33 € HT -- le prix de vente
     * était pris pour le TTC de l'achat. Les deux prix se saisissent dans le
     * formulaire de ligne, chacun dans son champ, et aucun ne recalcule
     * l'autre : le brouillon les garde tels quels, et le produit les prend à
     * la validation.
     */
    public function test_le_prix_de_vente_saisi_ne_change_jamais_le_prix_dachat(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();

        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: [
                'origine' => 'nouveau',
                'reference' => 'CBL-USBC',
                'nom' => 'Câble USB-C',
                'quantite' => 3,
                'prix_unitaire_ht' => '0.47',
                'prix_vente_ttc' => '10',
                'taux_tva' => '20',
            ])
            ->assertHasNoFormComponentActionErrors();

        $ligne = collect($component->get('data.lignes'))->sole();
        $this->assertSame('0.47', $ligne['prix_unitaire_ht']);
        $this->assertSame('10.00', $ligne['produit']['prix_vente_ttc']);
        $component->call('create')->assertHasNoFormErrors();

        $this->assertSame(0, Produit::query()->count(), 'Le produit naît à la validation de la facture, pas avant.');
        $factureAchat = FactureAchat::query()->sole();
        $this->assertSame(['0.47', '1.41', '10.00'], [$factureAchat->lignes->sole()->prix_unitaire_ht, $factureAchat->lignes->sole()->montant_ht, $factureAchat->lignes->sole()->nouveau_produit['prix_vente_ttc']]);

        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        $produit = Produit::query()->sole();
        $this->assertSame('Câble USB-C', $produit->nom);
        $this->assertSame($fournisseur->id, $produit->fournisseur_id);
        $this->assertSame(['0.4700', '10.00', '8.33'], [$produit->cmup_ht, $produit->prix_vente_ttc, $produit->prix_vente_ht]);

        $ligneAchat = FactureAchat::query()->sole()->lignes->sole();
        $this->assertSame(['0.47', '1.41'], [$ligneAchat->prix_unitaire_ht, $ligneAchat->montant_ht]);
    }

    /**
     * Une coque achetée 0,47 € HT et vendue 12 € TTC : la ligne montre
     * d'un côté ce qu'elle coûte au magasin (0,47 € HT + 0,09 € de TVA =
     * 0,56 € TTC payés au fournisseur), de l'autre son prix de vente et ce
     * qu'il rapporte (10,00 € HT - 0,47 € = 9,53 € par article) -- sans
     * jamais laisser croire que 0,56 € est un prix de vente.
     */
    public function test_la_ligne_separe_le_montant_paye_au_fournisseur_du_prix_de_vente(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: [
                'origine' => 'nouveau',
                'nom' => 'Coque Iphone 16',
                'quantite' => 1,
                'prix_unitaire_ht' => '0.47',
                'prix_vente_ttc' => '12',
                'taux_tva' => '20',
            ])
            ->assertSee('Montant payé au fournisseur')
            ->assertSee('0,56 € TTC')
            ->assertSee('1 x 0,47 € HT = 0,47 € HT + 0,09 € de TVA')
            ->assertSee('Vente en magasin')
            ->assertSee("12,00 € TTC l'article · bénéfice 9,53 € HT par article")
            ->assertSee("Total de la facture d'achat (à payer au fournisseur)")
            ->assertSee('Total HT 0,47 € · TVA 0,09 € · Total TTC 0,56 €');
    }

    /**
     * Le bénéfice affiché part du coût exact : 2,01 € remisés de 50 %
     * coûtent 1,005 € ; vendu 13,20 € TTC (11,00 € HT), l'article rapporte
     * 9,995 € -> 10,00 €, et non 11,00 € - 1,01 € = 9,99 €.
     */
    public function test_le_benefice_affiche_part_du_cout_exact(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: [
                'origine' => 'nouveau',
                'nom' => 'Câble USB-C',
                'quantite' => 1,
                'prix_unitaire_ht' => '2.01',
                'remise_pourcentage' => '50',
                'prix_vente_ttc' => '13.20',
                'taux_tva' => '20',
            ])
            ->assertSee("13,20 € TTC l'article · bénéfice 10,00 € HT par article");
    }

    /**
     * Facture signalée par le magasin : 10 x 1,18 € HT montrait 2,40 € de
     * TVA (10 x 1,42 €, le PU TTC arrondi) ; la TVA est 20 % des 11,80 € HT
     * de la ligne.
     */
    public function test_la_tva_dune_ligne_dachat_est_celle_de_son_total_ht(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: [
                'origine' => 'nouveau',
                'nom' => 'Coque Iphone 16',
                'quantite' => 10,
                'prix_unitaire_ht' => '1.18',
                'prix_vente_ttc' => '12',
                'taux_tva' => '20',
            ])
            ->assertSee('14,16 € TTC')
            ->assertSee('10 x 1,18 € HT = 11,80 € HT + 2,36 € de TVA')
            ->assertSee('Total HT 11,80 € · TVA 2,36 € · Total TTC 14,16 €');
    }

    public function test_choisir_un_produit_du_catalogue_reprend_sa_fiche_et_son_dernier_prix_dachat(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Coque iPhone 15', 'cmup_ht' => 2.1, 'prix_vente_ttc' => 15, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->assertFormComponentActionDataSet(['nom' => 'Coque iPhone 15', 'prix_unitaire_ht' => '2.10', 'prix_vente_ttc' => '15.00', 'taux_tva' => '20'])
            ->setFormComponentActionData(['quantite' => 4])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors()
            ->call('create')
            ->assertHasNoFormErrors();

        $ligne = FactureAchat::query()->sole()->lignes->sole();
        $this->assertSame([$produit->id, 4, '2.10', '8.40'], [$ligne->produit_id, $ligne->quantite, $ligne->prix_unitaire_ht, $ligne->montant_ht]);
        $this->assertSame(1, Produit::query()->count());
    }

    /**
     * Une fois le barème du magasin activé (voir
     * BaremePrixDeVente::parDefaut()), le prix de vente TTC est proposé dès
     * que le prix d'achat est saisi : 0,47 € d'achat HT + 10 € de bénéfice
     * = 10,47 € HT, soit 12,56 € TTC, TVA de 2,09 € comprise. La personne
     * qui enregistre la facture d'achat peut toujours changer ce prix.
     */
    public function test_le_bareme_du_magasin_propose_le_prix_de_vente_dun_nouveau_produit(): void
    {
        $this->connecterLeProprietaire()->update(['prix_vente_automatique' => true]);

        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Câble USB-C', 'prix_unitaire_ht' => '0.47'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '12.56'])
            ->assertSee('bénéfice 10,00 € HT par article')
            ->setFormComponentActionData(['prix_vente_ttc' => '15'])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();

        $this->assertSame('15.00', collect($component->get('data.lignes'))->sole()['produit']['prix_vente_ttc']);

        $component->call('create')->assertHasNoFormErrors();

        $this->assertSame('15.00', FactureAchat::query()->sole()->lignes->sole()->nouveau_produit['prix_vente_ttc']);
    }

    /**
     * La remise du fournisseur et la TVA font aussi recalculer la
     * proposition : 10 € HT + 35 € de bénéfice = 45 € HT, soit 54,00 € TTC ;
     * remisés de 50 %, 5 € HT + 30 € de bénéfice = 35 € HT, soit 42,00 €
     * TTC à 20 % et 36,93 € TTC à 5,5 %.
     */
    public function test_la_remise_et_la_tva_font_recalculer_le_prix_de_vente_propose(): void
    {
        $this->connecterLeProprietaire()->update(['prix_vente_automatique' => true]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Coque', 'prix_unitaire_ht' => '10'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '54.00'])
            ->setFormComponentActionData(['remise_pourcentage' => '50'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '42.00'])
            ->setFormComponentActionData(['taux_tva' => '5.5'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '36.93']);
    }

    /**
     * Une TVA vidée n'est pas un taux de 0 % : le barème ne propose rien
     * (2 € d'achat + 15 € de bénéfice donneraient 17,00 € au lieu de
     * 20,40 € TTC), et la ligne la demande avant d'être ajoutée.
     */
    public function test_une_tva_videe_ne_fait_rien_proposer_et_est_demandee(): void
    {
        $this->connecterLeProprietaire()->update(['prix_vente_automatique' => true]);

        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Câble USB-C', 'taux_tva' => ''])
            ->setFormComponentActionData(['prix_unitaire_ht' => '2'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => null])
            ->setFormComponentActionData(['taux_tva' => '20'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '20.40'])
            ->setFormComponentActionData(['taux_tva' => ''])
            ->callMountedFormComponentAction()
            ->assertHasFormComponentActionErrors(['taux_tva' => 'required']);

        $this->assertSame([], $component->get('data.lignes'));
    }

    /**
     * Au-delà du dernier palier, sans bénéfice prévu, le barème ne propose
     * rien : une proposition faite pour un prix d'achat précédent est
     * retirée (vide pour un nouveau produit, prix de sa fiche pour un
     * produit du catalogue), mais un prix changé à la main reste.
     */
    public function test_au_dela_du_dernier_palier_la_proposition_precedente_est_retiree(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $magasin->update([
            'prix_vente_automatique' => true,
            'bareme_prix_vente' => new BaremePrixDeVente([['jusqu_a' => 99.0, 'benefice' => 50.0]], null, false),
        ]);
        $produit = Produit::factory()->create(['cmup_ht' => 100, 'prix_vente_ttc' => 200, 'taux_tva' => 20]);
        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id]);

        $component
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Écran', 'prix_unitaire_ht' => '15'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '78.00'])
            ->setFormComponentActionData(['prix_unitaire_ht' => '150'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => null])
            ->setFormComponentActionData(['prix_unitaire_ht' => '15'])
            ->setFormComponentActionData(['prix_vente_ttc' => '80'])
            ->setFormComponentActionData(['prix_unitaire_ht' => '150'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '80'])
            ->unmountFormComponentAction();

        $component
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->setFormComponentActionData(['prix_unitaire_ht' => '12'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '74.40'])
            ->setFormComponentActionData(['prix_unitaire_ht' => '120'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '200.00']);
    }

    /**
     * Choisir un produit du catalogue garde son prix de vente ; c'est un
     * nouveau prix d'achat qui fait recalculer le barème.
     */
    public function test_un_nouveau_prix_dachat_recalcule_le_prix_de_vente_dun_produit_du_catalogue(): void
    {
        $this->connecterLeProprietaire()->update(['prix_vente_automatique' => true]);
        $produit = Produit::factory()->create(['cmup_ht' => 2.1, 'prix_vente_ttc' => 15, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->assertFormComponentActionDataSet(['prix_unitaire_ht' => '2.10', 'prix_vente_ttc' => '15.00'])
            ->setFormComponentActionData(['prix_unitaire_ht' => '3'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '21.60']);
    }

    /**
     * Rouverte avec « Modifier », une ligne garde la proposition du barème
     * (2 € d'achat + 5 € de bénéfice, soit 8,40 € TTC) : passée à 20 €
     * d'achat, au-delà du dernier palier, la proposition est retirée comme
     * à la première ouverture.
     */
    public function test_rouvrir_une_ligne_garde_la_proposition_du_bareme(): void
    {
        $this->connecterLeProprietaire()->update([
            'prix_vente_automatique' => true,
            'bareme_prix_vente' => new BaremePrixDeVente([['jusqu_a' => 10.0, 'benefice' => 5.0]], null, false),
        ]);
        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Coque', 'prix_unitaire_ht' => '2'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '8.40'])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->mountFormComponentAction('lignes', 'modifierLaLigne', arguments: ['item' => $cle])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => '8.40'])
            ->setFormComponentActionData(['prix_unitaire_ht' => '20']);

        $component->assertFormComponentActionDataSet(['prix_vente_ttc' => null]);
    }

    /**
     * Tant que le magasin n'a pas activé son barème (désactivé au départ),
     * le prix de vente se saisit à la main.
     */
    public function test_sans_calcul_automatique_le_prix_de_vente_se_saisit_a_la_main(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['origine' => 'nouveau', 'nom' => 'Câble USB-C', 'prix_unitaire_ht' => '0.47'])
            ->assertFormComponentActionDataSet(['prix_vente_ttc' => null]);
    }

    /**
     * Une ligne ne se corrige jamais en direct dans la facture : ses champs
     * sont en lecture seule, et « Modifier » rouvre son formulaire, rempli,
     * pour la remplacer -- sans en ajouter une autre.
     */
    public function test_une_ligne_se_modifie_par_son_formulaire_et_non_en_direct(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Écran iPhone 13', 'cmup_ht' => 40, 'prix_vente_ttc' => 120, 'taux_tva' => 20]);

        $component = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: ['produit_id' => $produit->id, 'quantite' => 2]);

        $cle = array_key_first($component->get('data.lignes'));

        foreach (['quantite', 'prix_unitaire_ht', 'remise_pourcentage', 'taux_tva', 'produit.prix_vente_ttc'] as $champ) {
            $component->assertFormFieldIsDisabled("lignes.{$cle}.{$champ}");
        }

        $component
            ->mountFormComponentAction('lignes', 'modifierLaLigne', arguments: ['item' => $cle])
            ->assertFormComponentActionDataSet(['origine' => 'existant', 'produit_id' => $produit->id, 'nom' => 'Écran iPhone 13', 'quantite' => 2, 'prix_unitaire_ht' => '40.00'])
            ->setFormComponentActionData(['quantite' => 5, 'prix_unitaire_ht' => '38.50', 'nom' => 'Écran iPhone 13 OLED'])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();

        $lignes = $component->get('data.lignes');
        $this->assertSame([$cle], array_keys($lignes));
        $this->assertSame([5, '38.50', 'Écran iPhone 13 OLED'], [$lignes[$cle]['quantite'], $lignes[$cle]['prix_unitaire_ht'], $lignes[$cle]['produit']['nom']]);

        $component->call('create')->assertHasNoFormErrors();

        $this->assertSame('192.50', FactureAchat::query()->sole()->total_ht);
        $this->assertSame('Écran iPhone 13 OLED', $produit->fresh()->nom, 'La fiche du produit se corrige depuis sa facture d\'achat.');
    }

    public function test_une_facture_dachat_sans_produit_est_refusee(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->call('create')
            ->assertHasFormErrors(['lignes' => 'required']);

        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * Un fournisseur se crée dans la page Fournisseurs, jamais depuis une
     * facture d'achat.
     */
    public function test_un_fournisseur_ne_se_cree_pas_depuis_une_facture_dachat(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateFactureAchat::class)
            ->assertFormComponentActionDoesNotExist('fournisseur_id', 'createOption');
    }

    /**
     * Un fournisseur archivé n'est plus proposé pour une nouvelle facture
     * d'achat.
     */
    public function test_un_fournisseur_archive_n_est_plus_propose(): void
    {
        $this->connecterLeProprietaire();
        $proposable = Fournisseur::factory()->create();
        Fournisseur::factory()->trashed()->create();

        Livewire::test(CreateFactureAchat::class)
            ->assertFormFieldExists('fournisseur_id', fn (Select $champ): bool => array_keys($champ->getOptions()) === [$proposable->id]);
    }

    /**
     * Une requête forgée qui envoie malgré tout un fournisseur archivé est
     * refusée, et aucune facture d'achat n'est créée.
     */
    public function test_un_fournisseur_archive_est_refuse_a_la_creation_d_une_facture_dachat(): void
    {
        $this->connecterLeProprietaire();
        $archive = Fournisseur::factory()->trashed()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => $archive->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['fournisseur_id' => 'exists']);

        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * Une facture d'achat garde son fournisseur archivé depuis (voir
     * FactureAchat::fournisseur()) : sa fiche l'affiche, et sa
     * modification le garde.
     */
    public function test_le_fournisseur_archive_reste_celui_de_sa_facture_dachat(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $fournisseur->delete();

        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertSee('Grossiste fermé');
        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertFormFieldExists('fournisseur_id', fn (Select $champ): bool => $champ->getOptionLabel() === 'Grossiste fermé')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($fournisseur->id, $factureAchat->fresh()->fournisseur_id);
    }

    /**
     * Ce que le fournisseur dit d'un produit reçu par API (référence, nom)
     * reste affiché mais figé ; le magasin garde la main sur son prix de
     * vente.
     */
    public function test_la_fiche_dun_produit_recu_par_api_ne_se_corrige_pas_depuis_une_ligne(): void
    {
        $magasin = $this->connecterLeProprietaire();
        app(TenantContext::class)->set($magasin);
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 5, 'taux_tva' => 20]);
        app(CreerFactureAchatAction::class)->handle($fournisseur, [['produit_id' => $produit->id, 'quantite' => 1]], ['source' => SourceFactureAchat::Api]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->assertFormFieldIsDisabled('nom', 'mountedFormComponentActionForm0')
            ->assertFormFieldIsDisabled('reference', 'mountedFormComponentActionForm0')
            ->assertFormFieldIsEnabled('prix_vente_ttc', 'mountedFormComponentActionForm0')
            ->assertSee('Reçu sur une facture d\'achat récupérée automatiquement');
    }

    /**
     * Sans droit sur les produits, la ligne reprend la fiche telle quelle :
     * la facture s'enregistre sans rien refuser.
     */
    public function test_sans_droit_sur_les_produits_un_produit_du_catalogue_s_achete_tel_quel(): void
    {
        $this->connecterUnMembreQuiSaisitLesAchatsSansDroitProduit();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Coque iPhone 15', 'cmup_ht' => 2.1, 'prix_vente_ttc' => 15, 'taux_tva' => 20, 'seuil_alerte' => 3]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: ['produit_id' => $produit->id, 'quantite' => 4])
            ->assertHasNoFormComponentActionErrors()
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($produit->id, FactureAchat::query()->sole()->lignes->sole()->produit_id);
        $produit->refresh();
        $this->assertSame(['Coque iPhone 15', '15.00', 3], [$produit->nom, $produit->prix_vente_ttc, $produit->seuil_alerte]);
    }

    /**
     * Une ligne forgée qui change la fiche d'un produit est refusée, avec un
     * message, quand la personne ne peut pas modifier les produits.
     */
    public function test_une_ligne_forgee_ne_change_pas_le_prix_d_un_produit_sans_droit_de_le_modifier(): void
    {
        $this->connecterUnMembreQuiSaisitLesAchatsSansDroitProduit();
        $produit = Produit::factory()->create(['nom' => 'Écran iPhone 13', 'prix_vente_ttc' => 150, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => Fournisseur::factory()->create()->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'produit' => ['nom' => 'Écran iPhone 13', 'prix_vente_ttc' => '1.00'], 'quantite' => 1, 'prix_unitaire_ht' => '40.00', 'remise_pourcentage' => 0, 'taux_tva' => '20'],
                ],
            ])
            ->call('create')
            ->assertNotified('Vous ne pouvez pas modifier la fiche du produit « Écran iPhone 13 » : seule la ligne se modifie ici.');

        $this->assertSame('150.00', $produit->fresh()->prix_vente_ttc);
        $this->assertSame(0, FactureAchat::query()->count());
    }

    public function test_creer_une_facture_dachat_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => $fournisseur->id,
                'numero_fournisseur' => 'INV-2026-42',
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $factureAchat = FactureAchat::query()->firstOrFail();
        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->statut);
        $this->assertSame('INV-2026-42', $factureAchat->numero_fournisseur);
        $this->assertSame('100.00', $factureAchat->total_ht);
    }

    public function test_lescompte_saisi_sur_la_facture_dachat_est_enregistre(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => $fournisseur->id,
                'escompte_pourcentage' => 2,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2.00', FactureAchat::query()->sole()->escompte_pourcentage);
    }

    public function test_valider_une_facture_dachat_depuis_le_panneau_incremente_le_stock(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        $factureAchat->refresh();
        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->statut);
        $this->assertNotNull($factureAchat->numero);
        $this->assertSame(5, $produit->fresh()->stock_quantite);
    }

    public function test_un_brouillon_reste_modifiable_et_supprimable(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();

        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('edit', $factureAchat);
        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('delete', $factureAchat);

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm([
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            // Filament reste par défaut sur le formulaire après sauvegarde :
            // une fois la facture d'achat corrigée, le vendeur doit revenir
            // directement à la liste.
            ->assertRedirect(FactureAchatResource::getUrl('index'));

        $this->assertSame('40.00', $factureAchat->fresh()->total_ht);
    }

    /**
     * Exigence explicite : une facture d'achat saisie manuellement (donc
     * depuis ce panneau) reste modifiable même une fois validée -- seule
     * sa suppression redevient interdite sans exception.
     */
    public function test_une_facture_dachat_saisie_manuellement_reste_modifiable_apres_validation(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('edit', $factureAchat->fresh());
        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('delete', $factureAchat->fresh());

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm([
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 8, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8, $produit->fresh()->stock_quantite, 'La correction doit recalculer le stock déjà incrémenté à la validation.');
    }

    public function test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        app(TenantContext::class)->set($magasin);
        $factureAchat = FactureAchat::factory()->create(['source' => SourceFactureAchat::Api]);

        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('edit', $factureAchat);
    }

    /**
     * La fiche d'un nouveau produit se remplit dans le formulaire de ligne
     * avec les mêmes informations que la page Produits (prix, TVA, stock),
     * et le produit naît à la validation, rattaché au fournisseur de la
     * facture.
     */
    public function test_un_nouveau_produit_recu_a_la_meme_fiche_que_dans_la_page_produits(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => $fournisseur->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: [
                'origine' => 'nouveau',
                'reference' => 'ECR-IP17-PRO',
                'code_barres' => '3700000000017',
                'nom' => 'Ecran Iphone 17 Pro',
                'quantite' => 2,
                'prix_unitaire_ht' => '80',
                // 180 € TTC à 20 % de TVA = 150 € HT (voir
                // ProduitResource::prixVenteTtcSchema() : seul le TTC est
                // saisi, le HT en est dérivé).
                'prix_vente_ttc' => '180',
                'taux_tva' => '20',
                'suivi_stock' => true,
                'seuil_alerte' => 1,
            ])
            ->assertHasNoFormComponentActionErrors()
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', FactureAchat::query()->sole());

        $this->assertDatabaseHas('produits', [
            'reference' => 'ECR-IP17-PRO',
            'code_barres' => '3700000000017',
            'nom' => 'Ecran Iphone 17 Pro',
            'fournisseur_id' => $fournisseur->id,
            'cmup_ht' => 80,
            'prix_vente_ht' => 150,
            'seuil_alerte' => 1,
        ]);
    }

    /**
     * Rouvert, un brouillon garde la fiche de son nouveau produit, pas
     * encore créé : sa fiche le dit, et le formulaire de sa ligne la
     * retrouve telle que saisie.
     */
    public function test_rouvrir_un_brouillon_affiche_son_nouveau_produit(): void
    {
        $this->connecterLeProprietaire();
        $brouillon = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit' => ['nom' => 'Câble USB-C', 'reference' => 'CBL-USBC', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 2], 'quantite' => 3, 'prix_unitaire_ht' => '0.47'],
        ]);

        Livewire::test(ViewFactureAchat::class, ['record' => $brouillon->getRouteKey()])
            ->assertSee('Câble USB-C')
            ->assertSee('Nouveau produit, créé à la validation');
        $component = Livewire::test(EditFactureAchat::class, ['record' => $brouillon->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->mountFormComponentAction('lignes', 'modifierLaLigne', arguments: ['item' => $cle])
            ->assertFormComponentActionDataSet(['origine' => 'nouveau', 'produit_id' => null, 'reference' => 'CBL-USBC', 'nom' => 'Câble USB-C', 'prix_vente_ttc' => '10.00', 'taux_tva' => '20', 'seuil_alerte' => 2, 'quantite' => 3, 'prix_unitaire_ht' => '0.47'])
            ->callMountedFormComponentAction()
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Produit::query()->count());
        $this->assertSame(['Câble USB-C', '1.41'], [$brouillon->lignes()->sole()->nouveau_produit['nom'], $brouillon->fresh()->total_ht]);
    }

    /**
     * Un membre qui ne peut pas créer de produits rouvre le brouillon où le
     * propriétaire a saisi un nouveau produit, corrige la quantité de
     * l'autre ligne, puis le valide : la fiche gardée, relue et renvoyée par
     * le formulaire, n'a pas changé (le suivi du stock et le seuil d'alerte
     * que le propriétaire n'avait pas donnés prennent leur valeur par
     * défaut), et la Coque naît à la validation.
     */
    public function test_sans_droit_de_creer_des_produits_un_brouillon_qui_garde_le_nouveau_produit_d_un_collegue_se_corrige_et_se_valide(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $existant = Produit::factory()->create(['nom' => 'Écran iPhone 13', 'taux_tva' => 20, 'suivi_stock' => true]);
        $brouillon = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $existant->id, 'quantite' => 1, 'prix_unitaire_ht' => 2],
            ['produit' => ['nom' => 'Coque', 'prix_vente_ttc' => 15, 'taux_tva' => 20], 'quantite' => 4, 'prix_unitaire_ht' => 3],
        ]);
        $this->actingAs($this->membre($magasin, RoleMagasin::Observateur, [
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::FACTURE_ACHAT_CREER,
            PermissionRegistry::FACTURE_ACHAT_VALIDER,
            PermissionRegistry::PRODUIT_VIEW,
        ]));
        Filament::setTenant($magasin);
        $component = Livewire::test(EditFactureAchat::class, ['record' => $brouillon->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->mountFormComponentAction('lignes', 'modifierLaLigne', arguments: ['item' => $cle])
            ->setFormComponentActionData(['quantite' => 2])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors()
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Vous ne pouvez pas créer de produit : « Coque » doit d\'abord être ajouté au catalogue.');
        $this->assertSame([2, 4], $brouillon->lignes()->orderBy('ordre')->pluck('quantite')->all());
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $brouillon);

        $coque = Produit::query()->where('nom', 'Coque')->sole();
        $this->assertSame(['15.00', true, 0, 4], [$coque->prix_vente_ttc, $coque->suivi_stock, $coque->seuil_alerte, $coque->stock_quantite]);
        $this->assertSame(2, $existant->fresh()->stock_quantite);
        $this->assertSame(['16.00', '19.20'], [$brouillon->fresh()->total_ht, $brouillon->fresh()->total_ttc]);
    }

    /**
     * Corriger une facture d'achat validée, c'est corriger une réception
     * déjà entrée en stock : sans le droit de valider les factures d'achat,
     * sa page de modification est refusée et le stock ne bouge pas.
     */
    public function test_sans_droit_de_valider_une_facture_dachat_validee_ne_se_corrige_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => 5],
        ]));
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]));
        Filament::setTenant($magasin);

        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('edit', $factureAchat);
        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])->assertForbidden();

        $this->assertSame(2, $produit->fresh()->stock_quantite);
    }

    /**
     * Le fournisseur de la facture est recopié sur les produits reçus : le
     * fournisseur d'un autre magasin, envoyé par une requête forgée, est
     * refusé et rien ne change, ni sur la facture ni sur le produit.
     */
    public function test_le_fournisseur_d_un_autre_magasin_est_refuse_a_la_modification(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $fournisseurDUnAutreMagasin = Fournisseur::factory()->create();
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]));

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm(['fournisseur_id' => $fournisseurDUnAutreMagasin->id])
            ->call('save')
            ->assertHasFormErrors(['fournisseur_id' => 'exists']);

        $this->assertSame($fournisseur->id, $factureAchat->fresh()->fournisseur_id);
        $this->assertSame($fournisseur->id, $produit->fresh()->fournisseur_id);
    }

    /**
     * Sur sa page de modification, seul FactureAchatPolicy::delete() cache
     * le bouton Supprimer : une facture validée, dont le stock est déjà
     * entré, ne se supprime pas.
     */
    public function test_une_facture_dachat_validee_ne_se_supprime_pas_depuis_sa_modification(): void
    {
        $this->connecterLeProprietaire();
        $produit = Produit::factory()->create(['cmup_ht' => 20, 'taux_tva' => 20]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]));

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        $this->assertFalse(auth()->user()->can('delete', $factureAchat));
    }

    /**
     * Deux lignes du même produit (5 + 5 reçus), 4 vendus : l'avoir
     * fournisseur propose 5 puis 1, et refuse d'en retourner 5 + 5 -- il
     * n'en reste que 6 en stock.
     */
    public function test_l_avoir_fournisseur_partage_le_stock_entre_deux_lignes_du_meme_produit(): void
    {
        $this->connecterLeProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
        ]));
        app(StockLedgerService::class)->enregistrer($produit, TypeMouvementStock::Vente, 4);
        $component = Livewire::test(ListFacturesAchat::class)->mountTableAction('avoir_fournisseur', $factureAchat);
        $lignes = $component->get('mountedTableActionsData.0.lignes');
        $this->assertSame([5, 1], array_column(array_values($lignes), 'quantite'));
        [$premiere, $seconde] = array_keys($lignes);

        $component
            ->set("mountedTableActionsData.0.lignes.{$seconde}.quantite", 5)
            ->set('mountedTableActionsData.0.motif', 'Marchandise non conforme')
            ->callMountedTableAction();

        $this->assertSame(
            ['« Câble USB-C » : ces lignes en retournent 10 au total, mais il n\'en reste que 6 en stock (les autres ont déjà été vendus ou retournés).'],
            $component->errors()->get("mountedTableActionsData.0.lignes.{$premiere}.quantite"),
        );
        $this->assertSame(0, AvoirFournisseur::query()->count());
        $this->assertSame(6, $produit->fresh()->stock_quantite);
    }

    /**
     * Le produit d'une réception validée a été archivé : enregistrer sa
     * correction affiche pourquoi elle est refusée, au lieu d'une page
     * d'erreur.
     */
    public function test_corriger_une_reception_dont_le_produit_est_archive_affiche_pourquoi(): void
    {
        $this->connecterLeProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]));
        $produit->delete();

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->call('save')
            ->assertNotified('Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue avant de corriger cette facture d\'achat.');

        $this->assertSame(5, Produit::withTrashed()->find($produit->id)->stock_quantite);
    }

    /**
     * Le fournisseur d'une réception validée a été archivé : enregistrer sa
     * correction affiche pourquoi elle est refusée, et le stock ne bouge
     * pas.
     */
    public function test_corriger_une_reception_dont_le_fournisseur_est_archive_affiche_pourquoi(): void
    {
        $this->connecterLeProprietaire();
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste fermé']);
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]));
        $fournisseur->delete();

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->call('save')
            ->assertNotified('Le fournisseur « Grossiste fermé » a été archivé : restaurez-le depuis la liste des fournisseurs avant de corriger cette facture d\'achat.');

        $this->assertSame(5, $produit->fresh()->stock_quantite);
    }

    /**
     * Rouverte, la ligne d'un produit archivé depuis la saisie du brouillon
     * reste celle de ce produit : sa référence n'est pas prise pour celle
     * d'un autre. C'est l'enregistrement qui la refuse, avec un message.
     */
    public function test_rouvrir_la_ligne_d_un_produit_archive_n_affiche_aucune_erreur_de_reference(): void
    {
        $this->connecterLeProprietaire();
        $produit = Produit::factory()->create(['nom' => 'Câble USB-C', 'reference' => 'CAB-1', 'taux_tva' => 20]);
        $brouillon = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 2],
        ]);
        $produit->delete();
        $component = Livewire::test(EditFactureAchat::class, ['record' => $brouillon->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->mountFormComponentAction('lignes', 'modifierLaLigne', arguments: ['item' => $cle])
            ->setFormComponentActionData(['quantite' => 4])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();

        $component
            ->call('save')
            ->assertNotified('Le produit « Câble USB-C » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture.');
        $this->assertSame(4, $component->get("data.lignes.{$cle}.quantite"));
        $this->assertSame(5, $brouillon->fresh()->lignes->sole()->quantite);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Un observateur à qui le propriétaire a confié la saisie des achats,
     * sans aucun droit de créer ou de modifier les produits.
     */
    private function connecterUnMembreQuiSaisitLesAchatsSansDroitProduit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, [
            'name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'mot-de-passe-sur',
        ], RoleMagasin::Observateur);
        $membre->givePermissionTo(PermissionRegistry::FACTURE_ACHAT_CREER);
        $this->actingAs($membre);
        Filament::setTenant($magasin);
    }
}
