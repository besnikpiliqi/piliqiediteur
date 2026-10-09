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
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
     * l'autre.
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
        $this->assertSame(0, Produit::query()->count(), 'Le produit naît avec la facture, pas avant.');

        $component->call('create')->assertHasNoFormErrors();

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

        $this->assertSame('15.00', Produit::query()->where('nom', 'Câble USB-C')->sole()->prix_vente_ttc);
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

    public function test_creer_une_facture_dachat_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        app(TenantContext::class)->set($magasin);
        $factureAchat = FactureAchat::factory()->create(['source' => SourceFactureAchat::Api]);

        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('edit', $factureAchat);
    }

    /**
     * La fiche d'un nouveau produit se remplit dans le formulaire de ligne
     * avec les mêmes informations que la page Produits (prix, TVA, stock),
     * et le produit est rattaché au fournisseur de la facture.
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
     * Le fournisseur de la facture est recopié sur les produits reçus : le
     * fournisseur d'un autre magasin, envoyé par une requête forgée, est
     * refusé et rien ne change, ni sur la facture ni sur le produit.
     */
    public function test_le_fournisseur_d_un_autre_magasin_est_refuse_a_la_modification(): void
    {
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
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

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
