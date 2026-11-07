<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ViewAvoirFournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Filament\App\Resources\InventaireOuvertureResource\Pages\CreateInventaireOuverture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les écrans de la TVA des achats (décision de l'éditeur du 02/11/2026, T1
 * et T2) : la facture d'achat propose la TVA de chaque taux, que la
 * personne remplace par celle de la facture du fournisseur ; au-delà de la
 * tolérance, une case demande de confirmer les montants, la facture est
 * marquée « TVA à vérifier », marque que la liste filtre et que la fiche
 * montre avec les deux TVA. Le PU HT d'achat se saisit avec six décimales
 * au plus, comme le coût d'un inventaire d'ouverture ; l'avoir fournisseur
 * recopie de même sa note d'avoir.
 */
class TvaDesAchatsResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une ligne ajoutée fait paraître la TVA de son taux, avec la base et
     * la proposition de PILIQI ; une TVA saisie dans la tolérance (1
     * centime pour une ligne) s'enregistre sans rien demander.
     */
    public function test_la_tva_du_fournisseur_se_saisit_par_taux_dans_la_tolerance(): void
    {
        $this->connecterLeProprietaire();

        $page = $this->factureDeDeuxCoques()
            ->assertSee('TVA de la facture du fournisseur')
            ->assertSee('Base HT 20,00 € · calcul depuis les lignes : 4,00 €. Laissez vide pour le garder.');
        $cle = array_key_first($page->get('data.taux'));
        $this->assertSame(['taux_tva' => '20.00', 'montant_tva' => null], $page->get("data.taux.{$cle}"));

        $page->set("data.taux.{$cle}.montant_tva", '4.01')
            ->assertFormFieldIsHidden('confirmer_les_ecarts')
            ->call('create')
            ->assertHasNoFormErrors();

        $factureAchat = FactureAchat::query()->sole();
        $this->assertSame(['4.01', '24.01', false], [$factureAchat->total_tva, $factureAchat->total_ttc, $factureAchat->tva_a_verifier]);
        $this->assertSame('4.01', $factureAchat->taux()->sole()->montant_tva_saisi);
    }

    /**
     * Au-delà de la tolérance, l'écran dit l'écart et demande de cocher la
     * confirmation, sans laquelle rien ne s'enregistre ; cochée, la facture
     * est marquée « TVA à vérifier ».
     */
    public function test_au_dela_de_la_tolerance_la_case_de_confirmation_est_exigee(): void
    {
        $this->connecterLeProprietaire();

        $page = $this->factureDeDeuxCoques();
        $cle = array_key_first($page->get('data.taux'));
        $page->set("data.taux.{$cle}.montant_tva", '4.50')
            ->assertSee('À 20 % : la TVA saisie (4,50 €) s&#039;écarte de 0,50 € de la base × taux (4,00 €).', false)
            ->assertFormFieldIsVisible('confirmer_les_ecarts')
            ->call('create')
            ->assertHasFormErrors(['confirmer_les_ecarts' => 'accepted']);
        $this->assertSame(0, FactureAchat::query()->count());

        $page->set('data.confirmer_les_ecarts', true)
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(['4.50', true], [FactureAchat::query()->sole()->total_tva, FactureAchat::query()->sole()->tva_a_verifier]);
    }

    /**
     * La marque « TVA à vérifier » paraît dans la liste, qui la filtre, et
     * sur la fiche, qui montre pour chaque taux la TVA du fournisseur, le
     * calcul de PILIQI et qui a confirmé l'écart.
     */
    public function test_la_marque_se_filtre_dans_la_liste_et_se_lit_sur_la_fiche(): void
    {
        $this->connecterLeProprietaire();
        $this->travelTo(self::aParis('2026-11-03 10:15:00'));
        $marquee = $this->facture('4.50', confirmation: true);
        $conforme = $this->facture(null);

        Livewire::test(ListFacturesAchat::class)
            ->assertCanSeeTableRecords([$marquee, $conforme])
            ->assertTableColumnStateSet('tva_a_verifier', FactureAchatResource::TVA_A_VERIFIER, $marquee)
            ->filterTable('tva_a_verifier')
            ->assertCanSeeTableRecords([$marquee])
            ->assertCanNotSeeTableRecords([$conforme]);

        Livewire::test(ViewFactureAchat::class, ['record' => $marquee->getRouteKey()])
            ->assertSee(FactureAchatResource::TVA_A_VERIFIER)
            ->assertSee('TVA du fournisseur')
            ->assertSee('4,50 €')
            ->assertSee('Calcul depuis les lignes : 4,00 €')
            ->assertSee('Confirmé par '.auth()->user()->name.' le 03/11/2026');
        Livewire::test(ViewFactureAchat::class, ['record' => $conforme->getRouteKey()])
            ->assertDontSee(FactureAchatResource::TVA_A_VERIFIER)
            ->assertSee('Dans la tolérance');
    }

    /**
     * Le PU HT d'une ligne se saisit tel que le fournisseur l'écrit, six
     * décimales au plus, et s'affiche tel qu'il est enregistré ; le HT de la
     * ligne qu'écrit la facture remplace la proposition. Une septième
     * décimale est refusée.
     */
    public function test_le_pu_ht_d_achat_se_saisit_a_six_decimales_et_le_ht_de_la_ligne_se_recopie(): void
    {
        $this->connecterLeProprietaire();
        $vis = Produit::factory()->create(['nom' => 'Vis M3', 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: ['produit_id' => $vis->id, 'quantite' => 1, 'prix_unitaire_ht' => '0.1234567'])
            ->assertHasFormComponentActionErrors(['prix_unitaire_ht' => SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS]);

        $page = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: ['produit_id' => $vis->id, 'quantite' => 1000, 'prix_unitaire_ht' => '0.1234', 'montant_ht' => '123.41'])
            ->assertHasNoFormComponentActionErrors()
            ->assertSee('1000 x 0,1234 € HT = 123,40 € HT ; HT de la facture : 123,41 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $ligne = FactureAchat::query()->sole()->lignes()->sole();
        $this->assertSame(['0.1234', '123.41', '123.41'], [$ligne->prix_unitaire_ht, $ligne->montant_ht_saisi, $ligne->montant_ht]);

        Livewire::test(ViewFactureAchat::class, ['record' => FactureAchat::query()->sole()->getRouteKey()])
            ->assertSee('0,1234 €')
            ->assertSee('HT de la facture du fournisseur ; calcul depuis le PU : 123,40 €');
        $page->assertOk();
    }

    /**
     * La page de modification rouvre la TVA saisie de chaque taux, et
     * jamais le total de la facture dans le champ de contrôle ; un écart
     * déjà confirmé ne se reconfirme pas.
     */
    public function test_la_modification_rouvre_la_tva_saisie_sans_redemander_un_ecart_deja_confirme(): void
    {
        $this->connecterLeProprietaire();
        $factureAchat = $this->facture('4.50', confirmation: true);

        $page = Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()]);
        $this->assertSame([['taux_tva' => '20.00', 'montant_tva' => '4.50']], array_values($page->get('data.taux')));
        $this->assertNull($page->get('data.total_ttc_controle'));

        $page->assertFormFieldIsHidden('confirmer_les_ecarts')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['4.50', true], [$factureAchat->fresh()->total_tva, $factureAchat->fresh()->tva_a_verifier]);
    }

    /**
     * L'avoir fournisseur recopie sa note d'avoir : le HT de chaque ligne,
     * la TVA de chaque taux ; au-delà de la tolérance, la case de
     * confirmation est exigée, et l'avoir émis porte la marque, que sa
     * liste filtre et sa fiche montre.
     */
    public function test_l_avoir_fournisseur_recopie_sa_note_d_avoir_et_confirme_ses_ecarts(): void
    {
        $this->connecterLeProprietaire();
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->facture(null));

        $page = Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->mountAction('creer_avoir_fournisseur');
        $ligne = array_key_first($page->get('mountedActionsData.0.lignes'));
        $page->set("mountedActionsData.0.lignes.{$ligne}.quantite", 1);
        // Les rangées de TVA suivent les lignes : leur clé se relit après
        // le changement de la quantité.
        $taux = array_key_first($page->get('mountedActionsData.0.taux'));
        $page->set("mountedActionsData.0.taux.{$taux}.montant_tva", '2.50')
            ->setActionData(['motif' => 'Coque fêlée', 'numero_fournisseur' => 'AV-12'])
            ->callMountedAction()
            ->assertHasActionErrors(['confirmer_les_ecarts' => 'accepted']);
        $this->assertSame(0, AvoirFournisseur::query()->count());

        $page->setActionData(['confirmer_les_ecarts' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $avoir = AvoirFournisseur::query()->sole();
        $this->assertSame(['2.50', true], [$avoir->total_tva, $avoir->tva_a_verifier]);

        Livewire::test(ListAvoirsFournisseur::class)
            ->filterTable('tva_a_verifier')
            ->assertCanSeeTableRecords([$avoir]);
        Livewire::test(ViewAvoirFournisseur::class, ['record' => $avoir->getRouteKey()])
            ->assertSee(FactureAchatResource::TVA_A_VERIFIER)
            ->assertSee('Calcul depuis les lignes : 2,00 €');
    }

    /**
     * Le coût d'un inventaire d'ouverture se saisit, comme un prix d'achat,
     * avec six décimales au plus.
     */
    public function test_le_cout_d_un_inventaire_d_ouverture_refuse_une_septieme_decimale(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(CreateInventaireOuverture::class)
            ->fillForm(['lignes' => ['une' => ['produit' => ['nom' => 'Rondelle', 'prix_vente_ttc' => '0.50', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '0.1234567']]])
            ->call('create')
            ->assertHasFormErrors(['lignes.une.cout_unitaire_ht' => SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS]);
    }

    /**
     * Le formulaire d'une facture de deux coques à 10,00 € HT (20 %),
     * ajoutées par le bouton de l'écran.
     */
    private function factureDeDeuxCoques(): Testable
    {
        $coque = Produit::factory()->create(['nom' => 'Coque', 'taux_tva' => 20]);

        return Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->callFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit', data: ['produit_id' => $coque->id, 'quantite' => 2, 'prix_unitaire_ht' => '10.00']);
    }

    /**
     * Une facture d'achat brouillon de deux coques à 10,00 € HT (20 %),
     * avec la TVA du fournisseur, s'il en écrit une.
     */
    private function facture(?string $tva, bool $confirmation = false): FactureAchat
    {
        $coque = Produit::factory()->create(['nom' => 'Coque', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $coque->id, 'quantite' => 2, 'prix_unitaire_ht' => '10.00'],
        ], [], MontantsDuFournisseur::depuisLaSaisie(['taux' => $tva === null ? [] : [['taux_tva' => '20', 'montant_tva' => $tva]], 'confirmation' => $confirmation]));
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
