<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * « Facturer le stock » dans la liste des factures d'un magasin que sa
 * société a fermé sans le céder (voir ListFactures et FacturerLeStockAction),
 * puis les gestes ordinaires de la fiche du brouillon qui ne se refusent plus
 * pour cette seule facture : l'émettre, l'émettre en l'encaissant,
 * l'encaisser, la supprimer.
 */
class FacturerLeStockEcranTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    private function magasinFerme(): Magasin
    {
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']);
        $this->completerIdentiteLegale($magasin);
        Produit::factory()->create(['reference' => 'STYLO', 'nom' => 'Stylo', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'cmup_ht' => '5.00', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 10]);
        Produit::factory()->create(['reference' => 'CARNET', 'nom' => 'Carnet', 'prix_vente_ttc' => '24.90', 'taux_tva' => 20, 'cmup_ht' => '12.00', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 3]);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        return $magasin->fresh();
    }

    public function test_le_bouton_s_offre_a_qui_vend_dans_un_magasin_ferme_et_a_personne_d_autre(): void
    {
        $magasin = $this->magasinFerme();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        $this->dansLeMagasin($magasin, $vendeur);
        Livewire::test(ListFactures::class)->assertActionVisible('facturerLeStock')->assertActionHidden('create');
        $this->dansLeMagasin($magasin, $observateur);
        Livewire::test(ListFactures::class)->assertActionHidden('facturerLeStock');
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        Livewire::test(ListFactures::class)->assertActionHidden('facturerLeStock');

        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->dansLeMagasin($magasin->fresh(), $vendeur);
        Livewire::test(ListFactures::class)->assertActionHidden('facturerLeStock')->assertActionVisible('create');
    }

    public function test_la_fenetre_prepare_la_facture_du_stock_qui_s_emet_s_encaisse_et_se_supprime_depuis_sa_fiche(): void
    {
        $magasin = $this->magasinFerme();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $client = Client::factory()->professionnel()->create(['nom' => 'Papeteries du Rhône']);
        Client::factory()->create(['nom' => 'Jeanne Moreau']);
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(ListFactures::class)
            ->mountAction('facturerLeStock')
            ->assertSeeText('Ce magasin est fermé : cette facture du stock vendu seul, avec TVA, est la seule vente qu\'il fasse encore.')
            ->assertSeeText('Client professionnel')
            ->assertSeeText('Produits à facturer')
            ->assertSeeText('STYLO — Stylo (10 en stock)')
            ->assertSeeText('CARNET — Carnet (3 en stock)')
            ->assertDontSeeText('Remise sur chaque ligne (%)')
            ->setActionData(['client_id' => $client->id, 'produits' => Produit::query()->orderBy('id')->pluck('id')->all()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('La facture du stock est prête : relisez-la, puis émettez-la.');

        $brouillon = Facture::query()->sole();
        $this->assertSame([StatutFacture::Brouillon, true, '174.60'], [$brouillon->statut, $brouillon->vente_du_stock_seul, $brouillon->total_ttc]);

        Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertSeeText('Vente du stock d\'un magasin fermé, facturée seule, avec TVA.')
            ->assertActionHidden('edit')
            ->assertActionVisible('emettre')
            ->assertActionVisible('emettre_et_encaisser')
            ->assertActionHidden('encaisser')
            ->callAction('emettre')
            ->assertSeeText('Émise');

        $facture = $brouillon->fresh();
        $this->assertSame([StatutFacture::Emise, 'FAC-LYON1-2026-000001', 17460], [$facture->statut, $facture->numero, $facture->montantRestantEnCentimes()]);

        $encaissement = Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertActionVisible('encaisser')
            ->assertActionHidden('avoir')
            ->mountAction('encaisser');
        $part = (string) array_key_first($encaissement->get('mountedActionsData.0.parts'));
        $encaissement->set("mountedActionsData.0.parts.{$part}.mode", ModePaiement::Virement->value)
            ->set("mountedActionsData.0.parts.{$part}.montant", '100')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([StatutFacture::PartiellementPayee, 7460], [$facture->fresh()->statut, $facture->fresh()->montantRestantEnCentimes()]);
    }

    public function test_un_brouillon_de_la_facture_du_stock_se_supprime_depuis_la_liste_et_la_facture_se_refait(): void
    {
        $magasin = $this->magasinFerme();
        $client = Client::factory()->professionnel()->create(['nom' => 'Papeteries du Rhône']);
        $this->dansLeMagasin($magasin, $this->responsable($magasin));

        Livewire::test(ListFactures::class)->callAction('facturerLeStock', ['client_id' => $client->id, 'produits' => Produit::query()->pluck('id')->all()]);
        $brouillon = Facture::query()->sole();

        Livewire::test(ListFactures::class)
            ->assertTableActionVisible('delete', $brouillon)
            ->callTableAction('delete', $brouillon);

        $this->assertSame(0, Facture::query()->count());

        Livewire::test(ListFactures::class)->callAction('facturerLeStock', ['client_id' => $client->id, 'produits' => Produit::query()->pluck('id')->all()]);

        $this->assertSame(1, Facture::query()->count());
    }

    public function test_une_remise_se_propose_a_qui_peut_en_accorder_et_la_facture_la_porte(): void
    {
        $magasin = $this->magasinFerme();
        $responsable = $this->responsable($magasin);
        $client = Client::factory()->professionnel()->create();
        $this->dansLeMagasin($magasin, $responsable);

        Livewire::test(ListFactures::class)
            ->mountAction('facturerLeStock')
            ->assertSeeText('Remise sur chaque ligne (%)')
            ->setActionData(['client_id' => $client->id, 'produits' => Produit::query()->pluck('id')->all(), 'remise_pourcentage' => '10'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('157.14', Facture::query()->sole()->total_ttc);
    }

    public function test_un_client_particulier_ou_aucun_produit_est_refuse_par_la_fenetre(): void
    {
        $magasin = $this->magasinFerme();
        $particulier = Client::factory()->create(['nom' => 'Jeanne Moreau']);
        $this->dansLeMagasin($magasin, $this->responsable($magasin));

        Livewire::test(ListFactures::class)
            ->callAction('facturerLeStock', ['client_id' => $particulier->id, 'produits' => Produit::query()->pluck('id')->all()])
            ->assertNotified('La facture du stock se fait à un client professionnel du magasin, jamais à un particulier ni au client comptoir.');
        Livewire::test(ListFactures::class)
            ->callAction('facturerLeStock', ['client_id' => $particulier->id, 'produits' => []])
            ->assertHasActionErrors(['produits']);

        $this->assertSame(0, Facture::query()->count());
    }
}
