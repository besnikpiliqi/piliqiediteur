<?php

namespace Tests\Feature\Charges;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Services\PropositionsDeTva;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Services\ChiffresDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La TVA à payer réelle et estimée (décision de l'éditeur du 14/11/2026,
 * lot CHARGES ; voir TvaDeLaPeriode), sur des montants calculés à la main,
 * et les propositions de PILIQI (décisions de Claude, D76 ; voir
 * PropositionsDeTva).
 *
 * Le jeu de données (preparer()), en mai 2026, au 20 mai à midi à Paris :
 * - un ticket d'une lampe à 24,00 € TTC : 4,00 € de TVA collectée ;
 * - l'achat de 10 câbles à 5,00 € HT : 10,00 € de TVA déductible ;
 * - le loyer, 1 200,00 € HT à 20 %, validé : 240,00 € déductibles ;
 * - un abonnement d'un fournisseur établi hors de France, 100,00 € HT à
 *   20 %, validé : 20,00 € autoliquidés, dus et déductibles, sans effet ;
 * - l'électricité, 310,00 € HT à 20 %, à valider : 62,00 € déductibles ;
 * - le carburant d'un véhicule de tourisme, 72,50 € HT à 20 % (14,50 € de
 *   TVA), à valider : 80 % déductibles, 11,60 € ;
 * - une pièce sans chiffres et une charge d'une autre société, jamais
 *   comptées.
 * La TVA collectée est de 24,00 € (4,00 + 20,00), la déductible de 270,00 €
 * (10,00 + 240,00 + 20,00), la TVA à payer réelle de −246,00 €, et
 * l'estimation, avec les 73,60 € des charges à valider, de −319,60 €.
 */
class TvaDeLaPeriodeTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    public function test_la_tva_de_la_periode_compte_les_charges_validees_et_estime_avec_celles_a_valider(): void
    {
        $magasin = $this->preparer();

        $tva = app(TvaDeLaPeriode::class)->deLaSociete($magasin->societe()->firstOrFail(), '2026-05');

        $this->assertNotNull($tva);
        $this->assertSame(2400, $tva->collectee());
        $this->assertSame(1000, $tva->deductibleDesAchats);
        $this->assertSame(26000, $tva->deductibleDesChargesValidees);
        $this->assertSame(7360, $tva->deductibleDesChargesAValider);
        $this->assertSame(-24600, $tva->reelle());
        $this->assertSame(-31960, $tva->estimee());
        $this->assertSame(0, app(TvaDeLaPeriode::class)->deLaSociete($magasin->societe()->firstOrFail(), '2026-04')?->estimee());
    }

    public function test_l_autoliquidation_d_un_fournisseur_hors_de_france_est_sans_effet_sur_la_tva_a_payer(): void
    {
        $this->disqueDesCharges();
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->clear();
        $charge = $this->deposer($proprietaire, $magasin->societe, SaisieDeCharge::duFormulaire(['type' => 'piliqi:abonnements_logiciels', 'fournisseur' => 'Logiciel Ltd', 'numero_piece' => 'INV-1', 'date_piece' => '2026-05-03', 'fournisseur_hors_france' => true, 'lignes' => [['taux' => 2000, 'ht' => '100.00']]]));
        app(ValiderUneChargeAction::class)->handle($proprietaire, $magasin->societe, $charge->uuid);

        $tva = app(TvaDeLaPeriode::class)->deLaSociete($magasin->societe()->firstOrFail(), '2026-05');

        $this->assertSame([10000, 2000, 10000], [$charge->total_ht, $charge->total_tva, $charge->total_ttc]);
        $this->assertSame(2000, $tva?->autoliquideeValidee);
        $this->assertSame(2000, $tva?->deductibleDesChargesValidees);
        $this->assertSame(0, $tva?->reelle());
    }

    public function test_une_societe_en_franchise_n_a_pas_de_tva(): void
    {
        $magasin = $this->ouvrirUneSociete(['regime_tva' => RegimeTva::FranchiseEnBase]);

        $this->assertNull(app(TvaDeLaPeriode::class)->deLaSociete($magasin->societe()->firstOrFail(), '2026-05'));
    }

    public function test_les_propositions_de_tva_deductible_et_de_mois(): void
    {
        $this->assertSame(1160, PropositionsDeTva::tvaDeductible(TypeDeCharge::Carburant, false, 1450));
        $this->assertSame(1450, PropositionsDeTva::tvaDeductible(TypeDeCharge::Carburant, true, 1450));
        $this->assertSame(0, PropositionsDeTva::tvaDeductible(TypeDeCharge::VehiculeDeTourisme, false, 4000));
        $this->assertSame(0, PropositionsDeTva::tvaDeductible(TypeDeCharge::Hebergement, false, 4000));
        $this->assertSame(0, PropositionsDeTva::tvaDeductible(TypeDeCharge::TransportDePersonnes, false, 4000));
        $this->assertSame(0, PropositionsDeTva::tvaDeductible(TypeDeCharge::Cadeaux, false, 4000));
        $this->assertSame(PropositionsDeTva::CADEAUX, PropositionsDeTva::raisonDeLaDeduction(TypeDeCharge::Cadeaux, false));
        $this->assertSame(4000, PropositionsDeTva::tvaDeductible(TypeDeCharge::Loyer, false, 4000));
        $this->assertNull(PropositionsDeTva::raisonDeLaDeduction(TypeDeCharge::Loyer, false));

        $this->assertSame('2026-05', PropositionsDeTva::moisDeTva(NatureDeCharge::Biens, '2026-05-30', '2026-06-04'));
        $this->assertSame('2026-06', PropositionsDeTva::moisDeTva(NatureDeCharge::Services, '2026-05-30', '2026-06-04'));
        $this->assertSame('2026-05', PropositionsDeTva::moisDeTva(NatureDeCharge::Services, '2026-05-30', null));
        $this->assertTrue(PropositionsDeTva::aConfirmerAuPaiement(NatureDeCharge::Services, null));
        $this->assertFalse(PropositionsDeTva::aConfirmerAuPaiement(NatureDeCharge::Biens, null));
    }

    public function test_une_charge_de_services_prend_le_mois_de_son_paiement_et_le_carburant_80_pour_cent(): void
    {
        $this->disqueDesCharges();
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->clear();

        $electricite = $this->deposer($proprietaire, $magasin->societe, $this->saisie('piliqi:electricite', '310.00', champs: ['fournisseur' => 'EDF', 'numero_piece' => 'E-1', 'date_piece' => '2026-05-28', 'date_paiement' => '2026-06-05']));
        $carburant = $this->deposer($proprietaire, $magasin->societe, $this->saisie('piliqi:carburant', '72.50', champs: ['fournisseur' => 'Station', 'numero_piece' => 'T-1', 'date_piece' => '2026-05-28', 'date_paiement' => '2026-06-05']));
        $tourisme = $this->deposer($proprietaire, $magasin->societe, $this->saisie('piliqi:vehicule_tourisme', '300.00', champs: ['fournisseur' => 'Loueur', 'numero_piece' => 'V-1']));

        $this->assertSame('2026-06', $electricite->mois_tva);
        $this->assertSame('2026-05', $carburant->mois_tva);
        $this->assertSame(1160, $carburant->total_tva_deductible);
        $this->assertFalse($carburant->vehicule_utilitaire);
        $this->assertSame(0, $tourisme->total_tva_deductible);
    }

    public function test_le_tableau_de_bord_montre_la_tva_reelle_et_son_estimation_pour_qui_voit_les_charges(): void
    {
        $magasin = $this->preparer();
        $societe = $magasin->societe()->firstOrFail();
        $this->travelTo(self::aParis('2026-05-20 12:00:00'));
        $administrateur = $this->administrateur($magasin, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur($societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR]);
        app(TenantContext::class)->clear();

        $duProprietaire = $this->chiffres($this->proprietaireDe($magasin), $societe);
        $delAdministrateur = $this->chiffres($administrateur, $societe);

        $this->assertSame(['franchise' => false, 'a_payer' => -24600, 'collectee' => 2400, 'deductible' => 27000, 'estimation' => -31960, 'avec_les_charges' => true], $duProprietaire['tva']);
        $this->assertSame(['franchise' => false, 'a_payer' => -600, 'collectee' => 400, 'deductible' => 1000, 'estimation' => null, 'avec_les_charges' => false], $delAdministrateur['tva']);

        $carte = CarteDuTableauDeBord::parDefaut(Graphique::TvaAPayerParMois);
        $serie = app(SeriesDesGraphiques::class)->pour(new CalculDesSeries(app(PerimetreDesGraphiques::class)->deLaSociete($administrateur, $societe), new ReglagesDuTableauDeBord([$carte]), [$carte]))[Graphique::TvaAPayerParMois->value];
        $this->assertSame(SeriesDesGraphiques::SANS_LES_CHARGES, $serie->note);
        $this->assertSame(['TVA collectée', 'TVA déductible', 'TVA à payer'], array_column($serie->jeux, 'libelle'));
    }

    private function preparer(): Magasin
    {
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-05-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete());
        $responsable = self::responsableDeTest($magasin);
        $this->actingAs($responsable);
        $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $lampe = Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $grossiste = Fournisseur::factory()->create(['nom' => 'Grossiste Est']);

        $this->travelTo(self::aParis('2026-05-05 10:00:00'));
        app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, $grossiste, [
            ['produit_id' => $cable->id, 'quantite' => 10, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
        ], ['date_facture' => '2026-05-05']));

        $this->travelTo(self::aParis('2026-05-06 10:00:00'));
        self::vendreAuComptant($responsable, Client::factory()->create(), [['produit_id' => $lampe->id, 'quantite' => 1]]);

        $societe = $magasin->societe()->firstOrFail();
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->clear();
        $loyer = $this->deposer($proprietaire, $societe, $this->saisie(champs: ['numero_piece' => 'Q-05', 'date_paiement' => '2026-05-03']));
        $abonnement = $this->deposer($proprietaire, $societe, $this->saisie('piliqi:abonnements_logiciels', '100.00', champs: ['fournisseur' => 'Logiciel Ltd', 'numero_piece' => 'INV-1', 'date_paiement' => '2026-05-03', 'fournisseur_hors_france' => true]));
        app(ValiderUneChargeAction::class)->handle($proprietaire, $societe, $loyer->uuid);
        app(ValiderUneChargeAction::class)->handle($proprietaire, $societe, $abonnement->uuid);
        $this->deposer($proprietaire, $societe, $this->saisie('piliqi:electricite', '310.00', champs: ['fournisseur' => 'EDF', 'numero_piece' => 'E-05', 'date_paiement' => '2026-05-15']));
        $this->deposer($proprietaire, $societe, $this->saisie('piliqi:carburant', '72.50', champs: ['fournisseur' => 'Station', 'numero_piece' => 'T-05']));
        $this->deposer($proprietaire, $societe, $this->saisie('piliqi:fournitures', null));

        $autre = $this->ouvrirUneSociete(['nom' => 'Autre']);
        app(TenantContext::class)->clear();
        $this->deposer($this->proprietaireDe($autre), $autre->societe, $this->saisie(ht: '5000.00', champs: ['numero_piece' => 'AUTRE']));

        $this->travelTo(self::aParis('2026-05-20 12:00:00'));
        app(TenantContext::class)->clear();

        return $magasin;
    }

    /**
     * @return array<string, mixed>
     */
    private function chiffres(User $compte, Societe $societe): array
    {
        app(TenantContext::class)->clear();

        return app(ChiffresDuTableauDeBord::class)->pour(new CalculDesSeries(app(PerimetreDesGraphiques::class)->deLaSociete($compte, $societe), new ReglagesDuTableauDeBord([]), []));
    }
}
