<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Complément 1 : à la contre-passation d'un paiement, le crédit d'avoir
 * disponible du client s'impute aussitôt sur ce qui reste dû, d'abord sur
 * les factures que chaque avoir corrige, puis sur ses autres factures
 * dues, de la plus ancienne à la plus récente, jamais pour le client
 * comptoir ; chaque imputation désigne la contre-passation, qui la signe.
 * Les imputations se relisent toujours dans l'ordre de leur
 * enregistrement.
 */
class ReimputationALaContrePassationTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $this->travelTo('2026-10-09 10:00:00');

        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
    }

    /**
     * Une facture émise d'une ligne de ces articles au prix TTC donné, à
     * 20 %.
     */
    private function facture(Client $client, float $prixTtc, int $quantite = 1): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => $quantite]]));
    }

    /**
     * Un paiement par virement de toutes ces factures, chacune pour tout
     * son total.
     */
    private function payerParVirement(Client $client, Facture ...$factures): Paiement
    {
        $allocations = array_map(static fn (Facture $facture): array => ['facture_id' => $facture->id, 'montant' => $facture->total_ttc], $factures);

        return app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => number_format(array_sum(array_map(static fn (Facture $facture): float => (float) $facture->total_ttc, $factures)), 2, '.', ''),
            'reference' => null,
            'recu_le' => today()->toDateString(),
        ], $allocations);
    }

    /**
     * L'avoir qui reprend ce nombre d'articles de la seule ligne de la
     * facture.
     */
    private function avoirSur(Facture $facture, int $quantite = 1): Avoir
    {
        /** @var Client $client */
        $client = $facture->client;

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => $quantite]], 'Retour');
    }

    private function contrePasser(Paiement $paiement): Paiement
    {
        return app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Virement rejeté par la banque');
    }

    /**
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function creditsImputesPar(Paiement $paiement): array
    {
        return AvoirAllocation::query()->where('paiement_id', $paiement->id)->orderBy('id')->get()
            ->map(fn (AvoirAllocation $credit): array => [$credit->avoir_id, $credit->facture_id, $credit->montant_alloue])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function donneesSignees(Paiement $paiement): array
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Encaissement->value)
            ->where('document_type', $paiement->getMorphClass())
            ->where('document_id', $paiement->getKey())
            ->sole()
            ->contenuSigne()['donnees'];
    }

    /**
     * F de 100,00 € payée par virement, puis un avoir de 100,00 € qui la
     * reprend entièrement : son crédit reste disponible, F étant réglée. La
     * contre-passation du virement rouvre F, que le crédit règle aussitôt.
     */
    public function test_le_credit_d_avoir_disponible_s_impute_sur_la_facture_rouverte_par_la_contre_passation(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client, 100);
        $virement = $this->payerParVirement($client, $facture);
        $avoir = $this->avoirSur($facture);
        $this->assertSame(10000, $client->soldeCrediteurEnCentimes());

        $contrePassation = $this->contrePasser($virement);

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, 0.0, 100.0, 0.0], [$facture->statut, $facture->montantPaye(), $facture->montantCrediteParAvoir(), $facture->montantRestant()]);
        $this->assertSame([[$avoir->id, $facture->id, '100.00']], self::creditsImputesPar($contrePassation));
        $this->assertSame(0, $client->soldeCrediteurEnCentimes());
    }

    /**
     * Des avoirs de 30,00 € puis de 50,00 € corrigent G1 et G2, déjà
     * entièrement réglées : leur première étape n'impute rien. F1 de
     * 60,00 € puis F2 de 40,00 €, sans lien avec eux, rouvertes par la
     * contre-passation : 30,00 € et 30,00 € sur F1, 20,00 € sur F2, qui
     * reste due de 20,00 €.
     */
    public function test_le_credit_s_impute_de_la_plus_ancienne_a_la_plus_recente(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $g1 = $this->facture($client, 30);
        $g2 = $this->facture($client, 50);
        $this->payerParVirement($client, $g1, $g2);
        $f1 = $this->facture($client, 60);
        $f2 = $this->facture($client, 40);
        $virement = $this->payerParVirement($client, $f1, $f2);
        $avoirDe30 = $this->avoirSur($g1);
        $avoirDe50 = $this->avoirSur($g2);

        $contrePassation = $this->contrePasser($virement);

        $this->assertSame([[$avoirDe30->id, $f1->id, '30.00'], [$avoirDe50->id, $f1->id, '30.00'], [$avoirDe50->id, $f2->id, '20.00']], self::creditsImputesPar($contrePassation));
        $this->assertSame([StatutFacture::Payee, 0.0], [$f1->fresh()->statut, $f1->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::PartiellementPayee, 20.0], [$f2->fresh()->statut, $f2->fresh()->montantRestant()]);
        $this->assertSame(0, $client->soldeCrediteurEnCentimes());
    }

    /**
     * Les imputations se relisent dans l'ordre où elles ont été
     * enregistrées, même quand leurs lignes sont rangées à l'envers dans
     * leur table (voir inverserLOrdrePhysiqueDesLignes()) : celles d'un
     * paiement (F1 puis F2), les paiements de F1 (le virement, puis sa
     * contre-passation), les crédits d'avoir imputés sur F1 (l'avoir de
     * 30,00 €, puis celui de 50,00 €) et les factures sur lesquelles
     * s'impute l'avoir de 50,00 € (F1, puis F2). La base de données ne
     * garantit aucun ordre sans tri : les relations les trient (voir
     * Facture::allocationsPaiement(), Facture::allocationsAvoir(),
     * Avoir::allocations() et Paiement::allocations()).
     */
    public function test_les_imputations_se_relisent_dans_l_ordre_de_leur_enregistrement_quel_que_soit_l_ordre_des_lignes_en_base(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $g1 = $this->facture($client, 30);
        $g2 = $this->facture($client, 50);
        $this->payerParVirement($client, $g1, $g2);
        $f1 = $this->facture($client, 60);
        $f2 = $this->facture($client, 40);
        $virement = $this->payerParVirement($client, $f1, $f2);
        $avoirDe30 = $this->avoirSur($g1);
        $avoirDe50 = $this->avoirSur($g2);
        $this->contrePasser($virement);

        $this->inverserLOrdrePhysiqueDesLignes('paiement_allocations', 'avoir_allocations');

        $this->assertSame([$f1->id, $f2->id], $virement->allocations()->pluck('facture_id')->all());
        $this->assertSame(['60.00', '-60.00'], $f1->allocationsPaiement()->pluck('montant_alloue')->all());
        $this->assertSame([$avoirDe30->id, $avoirDe50->id], $f1->allocationsAvoir()->pluck('avoir_id')->all());
        $this->assertSame([$f1->id, $f2->id], $avoirDe50->allocations()->pluck('facture_id')->all());
    }

    /**
     * F1 de 60,00 € puis F2 de 40,00 €, réglées par un même virement de
     * 100,00 € ; un avoir de 20,00 € sur F2, émis ensuite, laisse un
     * crédit disponible. La contre-passation du virement l'impute sur F2,
     * qu'il corrige, et non sur F1, plus ancienne.
     */
    public function test_le_credit_s_impute_d_abord_sur_la_facture_rouverte_qu_il_corrige(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $f1 = $this->facture($client, 60);
        $f2 = $this->facture($client, 20, 2);
        $virement = $this->payerParVirement($client, $f1, $f2);
        $avoir = $this->avoirSur($f2);
        $this->assertSame(2000, $client->soldeCrediteurEnCentimes());

        $contrePassation = $this->contrePasser($virement);

        $this->assertSame([[$avoir->id, $f2->id, '20.00']], self::creditsImputesPar($contrePassation));
        $this->assertSame([StatutFacture::Emise, 60.0], [$f1->fresh()->statut, $f1->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::PartiellementPayee, 20.0], [$f2->fresh()->statut, $f2->fresh()->montantRestant()]);
    }

    /**
     * Chaque imputation de la réimputation désigne la contre-passation, qui
     * la signe dans la chaîne des encaissements (credits_imputes) ; le
     * vérificateur la retrouve, et détecte qu'on en change le montant en
     * base.
     */
    public function test_la_reimputation_est_signee_avec_la_contre_passation_et_le_verificateur_la_retrouve(): void
    {
        $magasin = $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client, 100);
        $virement = $this->payerParVirement($client, $facture);
        $avoir = $this->avoirSur($facture);

        $contrePassation = $this->contrePasser($virement);

        $this->assertSame([[
            'avoir_id' => $avoir->id,
            'avoir_numero' => 'AV-MABOUT-2026-000001',
            'facture_id' => $facture->id,
            'facture_numero' => 'FAC-MABOUT-2026-000001',
            'montant' => 10000,
        ]], self::donneesSignees($contrePassation)['credits_imputes']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());

        $this->retirerLeDeclencheur('avoir_allocations', 'avoir_allocations_ajout_seul_update');
        DB::table('avoir_allocations')->where('magasin_id', $magasin->id)->where('paiement_id', $contrePassation->id)->update(['montant_alloue' => '90.00']);

        $this->assertContains(
            "Le paiement (identifiant interne {$contrePassation->id}) ne correspond plus à ce qui a été signé à son enregistrement (credits_imputes).",
            array_map(fn (AnomalieFiscale $anomalie): string => $anomalie->message, app(VerificateurIntegrite::class)->verifier($magasin)->anomalies()),
        );
    }

    /**
     * Le bon d'avoir de 20,00 € d'un ticket du client comptoir n'appartient
     * qu'à qui en présente le numéro : la contre-passation qui rouvre un
     * autre ticket du comptoir ne l'y impute pas, ni sur un ticket resté
     * dû ; seul l'avoir qui corrige le ticket rouvert s'y réimpute.
     */
    public function test_le_credit_d_un_bon_d_avoir_du_client_comptoir_ne_s_impute_jamais_sur_une_autre_vente(): void
    {
        $magasin = $this->magasin();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $bon = $this->avoirSur(self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]));
        $resteDu = $this->facture($comptoir, 30);
        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 2]], ModePaiement::CarteBancaire);
        $retourPartiel = $this->avoirSur($ticket);
        $this->assertSame([2000, 2500], [$bon->fresh()->creditEnCentimes(), $retourPartiel->fresh()->creditEnCentimes()]);

        $contrePassation = $this->contrePasser($ticket->allocationsPaiement()->sole()->paiement);

        $this->assertSame([[$retourPartiel->id, $ticket->id, '25.00']], self::creditsImputesPar($contrePassation));
        $this->assertSame([2000, 0], [$bon->fresh()->creditEnCentimes(), $retourPartiel->fresh()->creditEnCentimes()]);
        $this->assertSame([25.0, 30.0], [$ticket->fresh()->montantRestant(), $resteDu->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Sans crédit d'avoir disponible, la contre-passation ne fait que
     * rouvrir la facture : aucune imputation, rien de signé dans
     * credits_imputes.
     */
    public function test_une_contre_passation_sans_credit_disponible_ne_change_rien(): void
    {
        $magasin = $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client, 100);
        $virement = $this->payerParVirement($client, $facture);

        $contrePassation = $this->contrePasser($virement);

        $this->assertSame(0, AvoirAllocation::query()->count());
        $this->assertSame([], self::donneesSignees($contrePassation)['credits_imputes']);
        $this->assertSame([StatutFacture::Emise, 100.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
