<?php

namespace Tests\Feature\Societe;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
use App\Domain\Tenancy\Services\MessageDeCession;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Resources\MagasinResource\Pages\ModifierMagasin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le message qui précède la confirmation de la cession d'un magasin (P4.1 et
 * P4.2, voir MessageDeCession) : la valeur du stock au coût moyen, la
 * transmission du magasin avec son activité, sans TVA, que la loi cite selon
 * le jour (CGI jusqu'au 31/12/2026, CIBS ensuite), la vente du stock seul, à
 * facturer avec TVA avant la cession, et le renvoi à l'expert-comptable ; le
 * même avis part au propriétaire après la cession.
 */
class MessageDeLaCessionTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    /**
     * Un magasin dont le stock vaut 30,35 € HT au coût moyen : 10 carnets à
     * 2,50 € et 3 stylos à 1,25 €, plus un produit archivé qui compte
     * quand même (2 gommes à 0,80 €, soit 1,60 €) ; un service, qui ne suit
     * pas son stock, ne compte pas.
     */
    private function magasinAvecSonStock(): Magasin
    {
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        Produit::factory()->create(['reference' => 'CARNET', 'nom' => 'Carnet', 'cmup_ht' => '2.50', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 10]);
        Produit::factory()->create(['reference' => 'STYLO', 'nom' => 'Stylo', 'cmup_ht' => '1.25', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 3]);
        Produit::factory()->create(['reference' => 'LIVRAISON', 'suivi_stock' => false, 'cmup_ht' => 5]);
        Produit::factory()->create(['reference' => 'GOMME', 'nom' => 'Gomme', 'cmup_ht' => '0.80', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 2])->delete();

        return $magasin;
    }

    private function laFiche(Magasin $magasin): Testable
    {
        $this->dansLEspaceDeLaSociete($magasin->societe()->firstOrFail(), $this->proprietaireDe($magasin));

        return Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()]);
    }

    public function test_la_valeur_du_stock_est_celle_que_la_cession_sort(): void
    {
        $magasin = $this->magasinAvecSonStock();
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        app(TenantContext::class)->runAs($autre, fn () => Produit::factory()->create(['cmup_ht' => '9.00', 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => 50]));

        $this->assertSame(3035, app(MessageDeCession::class)->valeurDuStockEnCentimes($magasin->fresh()));
        $this->assertSame(45000, app(MessageDeCession::class)->valeurDuStockEnCentimes($autre));

        Notification::fake();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cession, self::MOT_DE_PASSE_DU_PROPRIETAIRE, 'Ma Boutique');

        Notification::assertSentTo($this->proprietaireDe($magasin), StockDuMagasinCede::class, fn (StockDuMagasinCede $avis): bool => $avis->valeurHt === '30.35' && $avis->jour === '2026-11-09');
    }

    public function test_le_message_distingue_la_transmission_sans_tva_de_la_vente_du_stock_seul(): void
    {
        $magasin = $this->magasinAvecSonStock();

        $paragraphes = app(MessageDeCession::class)->avantLaCession($magasin->societe, 3035, '2026-12-31');

        $this->assertSame([
            'Valeur du stock au coût moyen : 30,35 € HT (valeur comptable, pas un prix de vente).',
            'Si le magasin est cédé avec son activité à un repreneur redevable de la TVA, la cession se fait sans TVA (transmission d\'universalité, art. 257 bis du CGI) : son montant hors taxe se déclare en « Autres opérations non imposables », sans facture de TVA.',
            'Si le stock est vendu seul, ou à un acheteur qui n\'est pas redevable de la TVA, il se facture avec TVA avant de céder le magasin : vous ne vendez jamais, un compte autorisé à vendre dans ce magasin établit cette facture avec « Facturer le stock », dans la liste des factures du magasin, même une fois le magasin fermé. Le stock que vous ne facturez pas sort à la cession, sans facture.',
            'Faites vérifier ce choix par votre expert-comptable.',
        ], $paragraphes);
    }

    public function test_le_code_cite_est_celui_du_jour_de_la_cession(): void
    {
        $this->assertSame('transmission d\'universalité, art. 257 bis du CGI', MessageDeCession::referenceDeLaTransmission('2026-12-31'));
        $this->assertSame('transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS', MessageDeCession::referenceDeLaTransmission('2027-01-01'));
        $this->assertSame('transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS', MessageDeCession::referenceDeLaTransmission('2027-06-15'));
    }

    public function test_une_societe_en_franchise_facture_son_stock_sans_tva(): void
    {
        $magasin = $this->magasinAvecSonStock();
        $societe = $magasin->societe;
        $societe->forceFill(['regime_tva' => RegimeTva::FranchiseEnBase])->save();

        $message = implode(' ', app(MessageDeCession::class)->avantLaCession($societe->fresh(), 0, '2026-11-09'));

        $this->assertStringContainsString('il se facture avant de céder le magasin, sans TVA puisque votre société vend en franchise en base (art. 293 B du CGI)', $message);
        $this->assertStringNotContainsString('il se facture avec TVA', $message);
    }

    public function test_la_fenetre_de_la_cession_montre_le_message_avant_la_confirmation(): void
    {
        $magasin = $this->magasinAvecSonStock();

        $this->laFiche($magasin)
            ->mountAction('ceder')
            ->assertSeeText('Valeur du stock au coût moyen : 30,35 € HT (valeur comptable, pas un prix de vente).')
            ->assertSeeText('la cession se fait sans TVA (transmission d\'universalité, art. 257 bis du CGI)')
            ->assertSeeText('« Autres opérations non imposables »')
            ->assertSeeText('Si le stock est vendu seul, ou à un acheteur qui n\'est pas redevable de la TVA, il se facture avec TVA avant de céder le magasin')
            ->assertSeeText('« Facturer le stock »')
            ->assertSeeText('Faites vérifier ce choix par votre expert-comptable.')
            ->assertSeeText('le magasin est fermé et ne se rouvrira pas');

        $this->assertFalse($magasin->fresh()->estFerme());
    }

    public function test_la_fenetre_d_un_magasin_ferme_cite_le_cibs_a_partir_du_premier_janvier_2027(): void
    {
        $magasin = $this->magasinAvecSonStock();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->travelTo(self::aParis('2027-01-04 09:00:00'));

        $this->laFiche($magasin)
            ->mountAction('ceder')
            ->assertSeeText('le magasin, déjà fermé, ne se rouvrira plus')
            ->assertSeeText('la cession se fait sans TVA (transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS)')
            ->assertDontSeeText('art. 257 bis du CGI');
    }

    public function test_l_avis_apres_la_cession_reprend_la_valeur_et_la_regle_du_jour(): void
    {
        $magasin = $this->magasinAvecSonStock();
        $proprietaire = $this->proprietaireDe($magasin);

        $corps = (new StockDuMagasinCede($magasin, '30.35', ['GOMME Gomme (-2)'], '2027-01-04'))->toDatabase($proprietaire)['body'];

        $this->assertStringContainsString('Valeur du stock au coût moyen : 30,35 € HT (valeur comptable, pas un prix de vente). À rapprocher du prix de cession prévu à l\'acte.', $corps);
        $this->assertStringContainsString('art. L. 211-107 et L. 211-108 du CIBS', $corps);
        $this->assertStringContainsString('Produits restés en stock négatif, sans mouvement : GOMME Gomme (-2).', $corps);
    }
}
