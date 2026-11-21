<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un magasin en franchise en base de TVA vend à 0 %, au même prix TTC,
 * avec la mention « TVA non applicable, art. 293 B du CGI » (B10). Le
 * document note la franchise à son enregistrement et la garde : l'émission
 * refuse un brouillon dont le régime du magasin a changé depuis, et la
 * mention se lit sur le document, jamais sur le régime actuel.
 */
class FranchiseEnBaseTest extends TestCase
{
    use RefreshDatabase;

    private const MENTION = 'TVA non applicable, art. 293 B du CGI';

    private const REFUS_REGIME_CHANGE = 'Le régime de TVA de la société a changé depuis l\'enregistrement de ce brouillon : ouvrez-le et enregistrez-le de nouveau pour recalculer ses lignes.';

    public function test_un_ticket_en_franchise_est_a_zero_pour_cent_au_meme_prix_ttc_avec_la_mention(): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);

        $ticket = $this->encaisser($this->produitA12Euros(), 3);

        $ligne = $ticket->lignes->sole();
        $this->assertSame(['0.00', '12.000000', '12.00'], [$ligne->taux_tva, $ligne->prix_unitaire_ht, $ligne->prix_unitaire_ttc]);
        $this->assertSame(['36.00', '0.00', '36.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertTrue($ticket->franchise_en_base);
        $this->assertStringContainsString(self::MENTION, $this->pdfDeLaFacture($ticket));
    }

    /**
     * Le même panier au réel : 30,00 € HT + 6,00 € de TVA. Le client paie
     * 36,00 € dans les deux cas.
     */
    public function test_le_meme_ticket_au_reel_coute_le_meme_prix_ttc_sans_la_mention(): void
    {
        $this->magasin(RegimeTva::ReelNormalMensuel);

        $ticket = $this->encaisser($this->produitA12Euros(), 3);

        $this->assertSame('20.00', $ticket->lignes->sole()->taux_tva);
        $this->assertSame(['30.00', '6.00', '36.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertFalse($ticket->franchise_en_base);
        $this->assertStringNotContainsString(self::MENTION, $this->pdfDeLaFacture($ticket));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function typesDeClient(): array
    {
        return [
            'professionnel' => [true],
            'particulier' => [false],
        ];
    }

    /**
     * En franchise, le client paie le même prix TTC quel que soit son type
     * (B10) : 3 x 9,99 € remisés de 50 % font 14,98 € (29,97 € moins une
     * remise de 14,985 € arrondie à 14,99 €), pour un professionnel comme
     * pour un particulier, et le retour d'un article crédite 4,99 € (9,99 €
     * moins 5,00 € de remise) dans les deux cas.
     */
    #[DataProvider('typesDeClient')]
    public function test_en_franchise_une_ligne_remisee_et_son_retour_coutent_le_meme_prix_a_tout_client(bool $professionnel): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);
        $client = $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'remise_pourcentage' => 50],
        ]));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);

        $this->assertSame(['14.98', '0.00', '14.98'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame(['4.99', '0.00', '4.99'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
    }

    public function test_une_ligne_de_service_saisie_a_10_pour_cent_est_enregistree_a_zero_en_franchise(): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => '25.00', 'taux_tva' => 10, 'quantite' => 1],
        ]);

        $ligne = $facture->lignes->sole();
        $this->assertSame(['0.00', '25.000000', '25.00', '0.00', '25.00'], [$ligne->taux_tva, $ligne->prix_unitaire_ht, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
    }

    public function test_un_avoir_sur_un_ticket_en_franchise_reprend_la_franchise_et_sa_mention(): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);
        $ticket = $this->encaisser($this->produitA12Euros(), 3);

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);

        $this->assertSame(['12.00', '0.00', '12.00'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame('0.00', $avoir->lignes->sole()->taux_tva);
        $this->assertTrue($avoir->franchise_en_base);
        $this->assertStringContainsString(self::MENTION, $this->pdfDeLAvoir($avoir));
    }

    public function test_un_brouillon_enregistre_au_reel_ne_s_emet_plus_une_fois_le_magasin_en_franchise(): void
    {
        $magasin = $this->magasin(RegimeTva::ReelNormalMensuel);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);

        $this->assertEmissionRefusee($brouillon);

        $brouillon->refresh();
        $this->assertSame([StatutFacture::Brouillon, null, false], [$brouillon->statut, $brouillon->numero, $brouillon->franchise_en_base]);
        $this->assertSame(['30.00', '6.00', '36.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
        $this->assertSame('20.00', $brouillon->lignes->sole()->taux_tva);
        $this->assertSame(0, EnregistrementFiscal::query()->where('document_type', $brouillon->getMorphClass())->count());
    }

    public function test_un_brouillon_enregistre_en_franchise_ne_s_emet_plus_une_fois_le_magasin_sorti_de_la_franchise(): void
    {
        $magasin = $this->magasin(RegimeTva::FranchiseEnBase);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::ReelNormalMensuel]);

        $this->assertEmissionRefusee($brouillon);

        $brouillon->refresh();
        $this->assertSame([StatutFacture::Brouillon, null, true], [$brouillon->statut, $brouillon->numero, $brouillon->franchise_en_base]);
        $this->assertSame(['36.00', '0.00', '36.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    /**
     * Ce que demande le refus : le brouillon réenregistré se recalcule au
     * nouveau régime, à 0 %, et s'émet avec le premier numéro de la série,
     * qu'aucune émission refusée n'a consommé.
     */
    public function test_le_brouillon_reenregistre_apres_le_passage_en_franchise_s_emet_a_zero_pour_cent(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:00'));
        $magasin = $this->magasin(RegimeTva::ReelNormalMensuel);
        $client = Client::factory()->create();
        $produit = $this->produitA12Euros();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 3]]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $this->assertEmissionRefusee($brouillon);

        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $client, [['produit_id' => $produit->id, 'quantite' => 3]]));

        $this->assertSame('TK-LYON1-2026-000001', $ticket->numero);
        $this->assertSame(['36.00', '0.00', '36.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertTrue($ticket->franchise_en_base);
    }

    public function test_un_ticket_emis_en_franchise_garde_sa_mention_apres_la_sortie_de_la_franchise(): void
    {
        $magasin = $this->magasin(RegimeTva::FranchiseEnBase);
        $ticket = $this->encaisser($this->produitA12Euros(), 1);

        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::ReelNormalMensuel]);

        $this->assertStringContainsString(self::MENTION, $this->pdfDeLaFacture($ticket->fresh()));
    }

    public function test_un_devis_en_franchise_est_a_zero_pour_cent_avec_la_mention(): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);

        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);

        $this->assertSame('0.00', $devis->lignes->sole()->taux_tva);
        $this->assertSame(['36.00', '0.00', '36.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertStringContainsString(self::MENTION, $this->pdfDuDevis($devis));
    }

    /**
     * @return array<string, array{RegimeTva, RegimeTva, list<string>}>
     */
    public static function changementsDeRegimeAvantLEnvoi(): array
    {
        return [
            'enregistré au réel, envoyé en franchise' => [RegimeTva::ReelNormalMensuel, RegimeTva::FranchiseEnBase, ['30.00', '6.00', '36.00']],
            'enregistré en franchise, envoyé au réel' => [RegimeTva::FranchiseEnBase, RegimeTva::ReelNormalMensuel, ['36.00', '0.00', '36.00']],
        ];
    }

    /**
     * Comme une facture : un devis enregistré avant que le magasin entre en
     * franchise, ou en sorte, ne part pas avec de la TVA et sans la mention,
     * ni à 0 % avec la mention ; il garde son statut, n'est pas numéroté et
     * ses montants restent ceux de son enregistrement.
     *
     * @param  list<string>  $totauxEnregistres
     */
    #[DataProvider('changementsDeRegimeAvantLEnvoi')]
    public function test_un_devis_ne_s_envoie_plus_une_fois_le_regime_du_magasin_change(RegimeTva $regimeALEnregistrement, RegimeTva $regimeALEnvoi, array $totauxEnregistres): void
    {
        $magasin = $this->magasin($regimeALEnregistrement);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);
        $this->reglerLaSociete($magasin, ['regime_tva' => $regimeALEnvoi]);

        try {
            app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
            $this->fail('L\'envoi aurait dû être refusé.');
        } catch (LogicException $refus) {
            $this->assertSame(self::REFUS_REGIME_CHANGE, $refus->getMessage());
        }

        $devis->refresh();
        $this->assertSame([StatutDevis::Brouillon, null, null], [$devis->statut, $devis->numero, $devis->date_emission]);
        $this->assertSame($totauxEnregistres, [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
    }

    public function test_un_devis_au_reel_ne_porte_aucune_mention_de_franchise(): void
    {
        $this->magasin(RegimeTva::ReelNormalMensuel);

        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);

        $this->assertSame(['30.00', '6.00', '36.00'], [$devis->total_ht, $devis->total_tva, $devis->total_ttc]);
        $this->assertStringNotContainsString(self::MENTION, $this->pdfDuDevis($devis));
    }

    /**
     * La facture tirée d'un devis reprend sa franchise avec ses lignes, et
     * s'émet tant que le magasin est resté en franchise.
     */
    public function test_la_facture_tiree_d_un_devis_en_franchise_reprend_la_franchise(): void
    {
        $this->magasin(RegimeTva::FranchiseEnBase);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $this->produitA12Euros()->id, 'quantite' => 3]]);
        app(AccepterDevisAction::class)->handle(self::auteur(), app(EmettreDevisAction::class)->handle(self::auteur(), $devis));

        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis->fresh()));

        $this->assertTrue($facture->franchise_en_base);
        $this->assertSame(['36.00', '0.00', '36.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertStringContainsString(self::MENTION, $this->pdfDeLaFacture($facture));
    }

    public function test_un_avoir_ne_melange_pas_des_factures_emises_en_franchise_et_d_autres(): void
    {
        $magasin = $this->magasin(RegimeTva::ReelNormalMensuel);
        $client = Client::factory()->create();
        $produit = $this->produitA12Euros();
        $ticketAuReel = $this->encaisser($produit, 1, $client);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $ticketEnFranchise = $this->encaisser($produit, 1, $client);

        try {
            app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
                ['ligne_facture_id' => $ticketAuReel->lignes->sole()->id, 'quantite' => 1],
                ['ligne_facture_id' => $ticketEnFranchise->lignes->sole()->id, 'quantite' => 1],
            ]);
            $this->fail('L\'avoir aurait dû être refusé.');
        } catch (InvalidArgumentException $refus) {
            $this->assertSame('Un avoir ne mélange pas des factures émises en franchise de TVA et d\'autres : faites deux avoirs.', $refus->getMessage());
        }

        $this->assertSame(0, Avoir::query()->count());
    }

    private function magasin(RegimeTva $regime): Magasin
    {
        $magasin = Magasin::factory()->avecLaSociete(['regime_tva' => $regime])->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function produitA12Euros(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function encaisser(Produit $produit, int $quantite, ?Client $client = null): Facture
    {
        return self::vendreAuComptant(self::auteur(), $client ?? Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => $quantite]], ModePaiement::Especes);
    }

    private function assertEmissionRefusee(Facture $brouillon): void
    {
        try {
            app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);
            $this->fail('L\'émission aurait dû être refusée.');
        } catch (LogicException $refus) {
            $this->assertSame(self::REFUS_REGIME_CHANGE, $refus->getMessage());
        }
    }

    private function pdfDeLaFacture(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
    }

    private function pdfDeLAvoir(Avoir $avoir): string
    {
        return app(AvoirPdfGenerator::class)->html($avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'));
    }

    private function pdfDuDevis(Devis $devis): string
    {
        return app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'));
    }
}
