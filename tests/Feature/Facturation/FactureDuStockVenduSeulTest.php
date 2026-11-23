<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FacturerLeStockAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Facturation\Services\VenteDuStockSeul;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * La facture du stock vendu seul d'un magasin que sa société a fermé sans
 * le céder (P4.1, voir VenteDuStockSeul et FacturerLeStockAction) : la
 * seule vente que ce magasin en lecture seule fasse encore, avec TVA, par
 * un compte autorisé à vendre, jamais le propriétaire, à un professionnel,
 * calculée comme toute vente, et jamais pour autre chose : ni un ticket, ni
 * un service, ni un autre document, ni un magasin cédé ou suspendu.
 */
class FactureDuStockVenduSeulTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    /**
     * Un magasin de Lyon dont l'identité légale est complète, avec deux
     * produits en stock (10 stylos à 9,99 € TTC et 3 carnets à 24,90 € TTC,
     * à 20 %), fermé temporairement par son propriétaire, après avoir fait,
     * si on le demande, un brouillon de facture ordinaire d'un article à
     * un client professionnel.
     */
    private function magasinFerme(MotifFermeture $motif = MotifFermeture::Temporaire, bool $avecUnBrouillonOrdinaire = false): Magasin
    {
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']);
        $this->completerIdentiteLegale($magasin);
        $this->produitEnStock('STYLO', 'Stylo', 10, '9.99', '5.00');
        $this->produitEnStock('CARNET', 'Carnet', 3, '24.90', '12.00');

        if ($avecUnBrouillonOrdinaire) {
            $article = Produit::factory()->create(['reference' => 'LIVRE', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['nom' => 'Librairie Part-Dieu']), [['produit_id' => $article->id, 'quantite' => 1]]);
        }

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), $motif, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        app(TenantContext::class)->set($magasin->fresh());

        return $magasin->fresh();
    }

    private function produitEnStock(string $reference, string $nom, int $quantite, string $prixTtc, string $coutMoyen): Produit
    {
        return Produit::factory()->create(['reference' => $reference, 'nom' => $nom, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'cmup_ht' => $coutMoyen, 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => $quantite]);
    }

    /**
     * @return list<int|string>
     */
    private function tousLesProduits(): array
    {
        return Produit::query()->orderBy('id')->pluck('id')->all();
    }

    private function facturer(?Client $client = null, ?array $produits = null, ?string $remise = null, ?User $auteur = null): Facture
    {
        return app(FacturerLeStockAction::class)->handle(
            $auteur ?? self::auteur(),
            $client ?? Client::factory()->professionnel()->create(['nom' => 'Papeteries du Rhône']),
            $produits ?? $this->tousLesProduits(),
            $remise,
        );
    }

    private function emettre(Facture $brouillon, ?ReglementComptant $reglement = null, ?User $auteur = null): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur ?? self::auteur(), $brouillon, $reglement);
    }

    public function test_un_magasin_ferme_facture_son_stock_avec_tva_ligne_par_ligne(): void
    {
        $magasin = $this->magasinFerme();
        $this->assertSame('Ce magasin est fermé', $magasin->motifDeLectureSeule());

        $brouillon = $this->facturer();

        $this->assertSame([StatutFacture::Brouillon, true, 'professionnel', null], [$brouillon->statut, $brouillon->vente_du_stock_seul, $brouillon->type_client->value, $brouillon->numero]);
        $this->assertSame(
            [['STYLO', 10, '9.99', '20.00'], ['CARNET', 3, '24.90', '20.00']],
            $brouillon->lignes()->get()->map(fn (LigneFacture $ligne): array => [$ligne->reference, $ligne->quantite, $ligne->prix_unitaire_ttc, $ligne->taux_tva])->all(),
        );
        // 10 x 9,99 = 99,90 TTC, TVA 16,65 ; 3 x 24,90 = 74,70 TTC, TVA 12,45 : 174,60 TTC, 29,10 de TVA.
        $this->assertSame(['145.50', '29.10', '174.60'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);

        $facture = $this->emettre($brouillon);

        $this->assertSame([StatutFacture::Emise, 'FAC-LYON1-2026-000001', true], [$facture->statut, $facture->numero, $facture->vente_du_stock_seul]);
        $this->assertSame(['145.50', '29.10', '174.60'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame([0, 0], Produit::query()->orderBy('id')->pluck('stock_quantite')->all());
        $this->assertSame(
            [[TypeMouvementStock::Vente->value, 10], [TypeMouvementStock::Vente->value, 3]],
            MouvementStock::query()->where('type', TypeMouvementStock::Vente->value)->orderBy('id')->get()->map(fn (MouvementStock $mouvement): array => [$mouvement->type->value, $mouvement->quantite])->all(),
        );
        $this->assertTrue((bool) Facture::query()->whereKey($facture->id)->value('vente_du_stock_seul'));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
        $this->assertTrue($magasin->fresh()->estFerme());
        $this->assertTrue($magasin->fresh()->estEnLectureSeule());
    }

    public function test_le_magasin_reste_en_lecture_seule_pour_toute_autre_vente_et_se_cede_ensuite(): void
    {
        $magasin = $this->magasinFerme(avecUnBrouillonOrdinaire: true);
        $client = Client::factory()->professionnel()->create();
        $this->emettre($this->facturer($client, [Produit::query()->where('reference', 'STYLO')->value('id')]));

        // Un brouillon ordinaire, fait avant la fermeture, ne s'émet pas : le contexte de la facture du stock s'est refermé.
        $ordinaire = Facture::query()->where('vente_du_stock_seul', false)->sole();
        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $ordinaire),
            VenteNonAutoriseeException::class,
            'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.',
        );
        $this->assertFalse(app(VenteDuStockSeul::class)->estEnCoursPour($magasin));
        $this->assertSame('Ce magasin est fermé', $magasin->fresh()->motifDeLectureSeule());
        $this->assertFalse(self::auteur()->can('emettre', $ordinaire));
        $this->assertFalse(self::auteur()->can('delete', $ordinaire));
        $this->assertFalse(app(VenteDuStockSeul::class)->concerne($ordinaire));

        // Le stock qui reste (3 carnets) sort ensuite à la cession, sans facture.
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cession, self::MOT_DE_PASSE_DU_PROPRIETAIRE, 'Ma Boutique');

        $cede = $magasin->fresh();
        app(TenantContext::class)->set($cede);
        $this->assertTrue($cede->aEteCede());
        $this->assertSame(
            [[TypeMouvementStock::SortieCession->value, 3]],
            MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->get()->map(fn (MouvementStock $mouvement): array => [$mouvement->type->value, $mouvement->quantite])->all(),
        );
    }

    public function test_le_proprietaire_ne_facture_jamais_et_aucune_autre_facture_n_est_permise(): void
    {
        $magasin = $this->magasinFerme();

        $this->assertThrows(fn () => $this->facturer(auteur: $this->proprietaireDe($magasin)), AuthorizationException::class);
        $this->assertSame(0, Facture::query()->count());
        $this->assertFalse($this->proprietaireDe($magasin)->can('facturerLeStock', Facture::class));
        $this->assertTrue(self::auteur()->can('facturerLeStock', Facture::class));
        $this->assertFalse(self::auteur()->can('create', Facture::class));
    }

    public function test_un_magasin_ouvert_ou_cede_ou_suspendu_ne_la_fait_pas(): void
    {
        $magasin = $this->magasinFerme();
        $client = Client::factory()->professionnel()->create();

        // Ouvert : une facture ordinaire.
        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertThrows(fn () => $this->facturer($client), LogicException::class, Magasin::REFUS_STOCK_D_UN_MAGASIN_OUVERT);
        $this->assertFalse(self::auteur()->can('facturerLeStock', Facture::class));

        // Suspendu par l'éditeur : en lecture seule, même fermé.
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertThrows(fn () => $this->facturer($client), LogicException::class, 'Ce magasin est suspendu : seules la consultation et les téléchargements restent possibles.');
        $this->assertFalse(self::auteur()->can('facturerLeStock', Facture::class));
        $magasin->forceFill(['suspendu_le' => null])->save();

        // Cédé : son stock est sorti, il ne facture plus rien, quel que soit le contexte.
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cession, self::MOT_DE_PASSE_DU_PROPRIETAIRE, 'Ma Boutique');
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertThrows(fn () => $this->facturer($client), LogicException::class, Magasin::REFUS_STOCK_D_UN_MAGASIN_CEDE);
        $this->assertFalse($magasin->fresh()->peutFacturerSonStock());
        $this->assertTrue(app(VenteDuStockSeul::class)->commeSiOuvert($magasin->fresh(), fn (): bool => $magasin->fresh()->estEnLectureSeule()));
    }

    public function test_une_societe_suspendue_ne_la_fait_pas(): void
    {
        $magasin = $this->magasinFerme();
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        app(TenantContext::class)->set($magasin->fresh());

        $this->assertThrows(fn () => $this->facturer(), LogicException::class);
        $this->assertSame(0, Facture::query()->count());
    }

    public function test_le_client_est_un_professionnel_du_magasin(): void
    {
        $magasin = $this->magasinFerme();

        $this->assertThrows(fn () => $this->facturer(Client::factory()->create(['nom' => 'Jeanne Moreau'])), LogicException::class, VenteDuStockSeul::REFUS_CLIENT);
        $this->assertThrows(fn () => $this->facturer(Client::query()->where('est_comptoir', true)->sole()), LogicException::class, VenteDuStockSeul::REFUS_CLIENT);

        $archive = Client::factory()->professionnel()->create();
        $archive->delete();
        $this->assertThrows(fn () => $this->facturer($archive), LogicException::class);
        $this->assertSame(0, Facture::query()->count());

        // Le client d'un autre magasin de la société est refusé.
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        app(TenantContext::class)->set($autre);
        $etranger = Client::factory()->professionnel()->create();
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertThrows(fn () => $this->facturer($etranger), LogicException::class);
    }

    public function test_les_produits_se_choisissent_parmi_ceux_en_stock_sans_service_ni_archive_ni_stock_negatif(): void
    {
        $magasin = $this->magasinFerme();
        $service = Produit::factory()->create(['reference' => 'SERV', 'prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
        $sansStock = $this->produitEnStock('VIDE', 'Vide', 0, '5.00', '2.00');
        $archive = $this->produitEnStock('VIEUX', 'Vieux', 4, '5.00', '2.00');
        $archive->delete();

        foreach ([[], [$service->id], [$sansStock->id], [$archive->id], [999999], [Produit::query()->where('reference', 'STYLO')->value('id'), $service->id]] as $choix) {
            $this->assertThrows(fn () => $this->facturer(produits: $choix), LogicException::class);
        }

        $this->assertSame(0, Facture::query()->count());

        $facture = $this->facturer(produits: [Produit::query()->where('reference', 'CARNET')->value('id')]);

        $this->assertSame([['CARNET', 3]], $facture->lignes()->get()->map(fn (LigneFacture $ligne): array => [$ligne->reference, $ligne->quantite])->all());
    }

    public function test_une_seule_facture_du_stock_attend_d_etre_emise(): void
    {
        $this->magasinFerme();
        $premier = $this->facturer();

        $this->assertThrows(fn () => $this->facturer(), LogicException::class, VenteDuStockSeul::REFUS_DEJA_UN_BROUILLON);

        app(SupprimerFactureAction::class)->handle(self::auteur(), $premier);

        $this->assertSame(0, Facture::query()->count());
        $this->assertSame(StatutFacture::Brouillon, $this->facturer()->statut);
    }

    public function test_une_remise_sur_chaque_ligne_exige_l_autorisation_d_en_accorder(): void
    {
        $magasin = $this->magasinFerme();
        $sansRemise = $this->membre($magasin, RoleMagasin::Vendeur);
        $client = Client::factory()->professionnel()->create();

        $this->assertThrows(fn () => $this->facturer($client, remise: '10', auteur: $sansRemise), AuthorizationException::class);
        $this->assertSame(0, Facture::query()->count());

        $brouillon = $this->facturer($client, remise: '10');

        // 10 x 9,99 - 10 % = 89,91 TTC ; 3 x 24,90 - 10 % = 67,23 TTC.
        $this->assertSame('157.14', $brouillon->total_ttc);
        $this->assertSame(['10.00', '10.00'], $brouillon->lignes()->pluck('remise_pourcentage')->all());
    }

    public function test_un_vendeur_autorise_a_vendre_facture_et_un_vendeur_sans_autorisation_de_vente_non(): void
    {
        $magasin = $this->magasinFerme();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        $this->assertTrue($vendeur->can('facturerLeStock', Facture::class));
        $this->assertFalse($observateur->can('facturerLeStock', Facture::class));
        $this->assertThrows(fn () => $this->facturer(auteur: $observateur), AuthorizationException::class);

        $facture = $this->emettre($this->facturer(auteur: $vendeur), auteur: $vendeur);

        $this->assertSame($vendeur->id, $facture->cree_par);
    }

    public function test_l_emission_refuse_une_ligne_qui_n_est_plus_un_produit_en_stock(): void
    {
        $this->magasinFerme();
        $brouillon = $this->facturer();
        $stylo = $brouillon->lignes()->where('reference', 'STYLO')->firstOrFail();

        // Plus que le stock, même pour qui vend sans stock (le responsable de test) : aucun avertissement, un refus.
        $stylo->forceFill(['quantite' => 11])->save();
        $this->assertThrows(fn () => $this->emettre($brouillon), LogicException::class, sprintf(VenteDuStockSeul::REFUS_STOCK_CHANGE, 'Stylo'));

        // Un service ne s'y vend pas.
        $stylo->forceFill(['quantite' => 10])->save();
        $service = Service::factory()->create();
        $brouillon->lignes()->create(['service_id' => $service->id, 'produit_id' => null, 'nature' => 'service', 'description' => 'Livraison', 'quantite' => 1, 'prix_unitaire_ht' => '1.000000', 'prix_unitaire_ttc' => '1.20', 'taux_tva' => '20.00', 'taux_tva_du_prix' => '20.00', 'montant_ht' => '1.00', 'montant_tva' => '0.20', 'montant_ttc' => '1.20', 'ordre' => 9]);
        $this->assertThrows(fn () => $this->emettre($brouillon), LogicException::class, VenteDuStockSeul::REFUS_SERVICE);

        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);
        $this->assertSame([10, 3], Produit::query()->orderBy('id')->pluck('stock_quantite')->all());
    }

    public function test_emettre_et_encaisser_puis_encaisser_plus_tard_en_plusieurs_modes(): void
    {
        $magasin = $this->magasinFerme();

        $facture = $this->emettre($this->facturer(), ReglementComptant::horsDeLaCaisse([new PartDeReglement(ModePaiement::Virement, 10000, 'VIR-8841')]));

        $this->assertSame([StatutFacture::PartiellementPayee, 7460], [$facture->statut, $facture->montantRestantEnCentimes()]);

        $facture = app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, [new PartDeReglement(ModePaiement::Cheque, 5000, 'CHQ-120'), new PartDeReglement(ModePaiement::Virement, 2460, 'VIR-8977')], [], '2026-11-09');

        $this->assertSame([StatutFacture::Payee, 0], [$facture->statut, $facture->montantRestantEnCentimes()]);
        $this->assertSame(['virement', 'cheque', 'virement'], $facture->allocationsPaiement()->with('paiement')->orderBy('id')->get()->map(fn ($allocation): string => $allocation->paiement->mode_paiement->value)->all());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    public function test_les_autres_gestes_sur_la_facture_du_stock_restent_refuses_tant_que_le_magasin_est_ferme(): void
    {
        $this->magasinFerme();
        $facture = $this->emettre($this->facturer(), ReglementComptant::horsDeLaCaisse([new PartDeReglement(ModePaiement::Virement, 5000, 'VIR-1')]));
        $paiement = $facture->allocationsPaiement()->with('paiement')->firstOrFail()->paiement;

        $this->assertThrows(fn () => app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, 'Erreur de saisie'), VenteNonAutoriseeException::class);
        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'achats@papeteries-du-rhone.test', null), VenteNonAutoriseeException::class);
        $this->assertFalse(self::auteur()->can('update', $facture));
        $this->assertFalse(self::auteur()->can('create', Facture::class));
        $this->assertFalse(self::auteur()->can('create', Avoir::class));
    }

    public function test_la_base_refuse_une_facture_du_stock_qui_n_est_pas_a_un_professionnel_et_son_drapeau_ne_change_plus(): void
    {
        $this->magasinFerme();
        $brouillon = $this->facturer();
        $colonnes = $brouillon->getAttributes();

        $this->assertThrows(
            fn () => DB::transaction(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['type_client' => 'particulier'])),
            QueryException::class,
            'factures_vente_du_stock_seul_coherente',
        );
        $this->assertThrows(
            fn () => DB::transaction(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['vente_du_stock_seul' => false])),
            QueryException::class,
            'factures.vente_du_stock_seul : valeur figée',
        );
        $this->assertSame('professionnel', $colonnes['type_client']);
    }

    public function test_la_preparation_et_l_emission_s_inscrivent_au_journal_d_audit(): void
    {
        $magasin = $this->magasinFerme();
        $brouillon = $this->facturer(Client::factory()->professionnel()->create(['nom' => 'Papeteries du Rhône']));
        $facture = $this->emettre($brouillon);

        $preparation = $this->entreesDAudit($magasin->societe, ActionAuditee::FactureDuStockPreparee)[0];
        $emission = $this->entreesDAudit($magasin->societe, ActionAuditee::FactureDuStockEmise)[0];

        $this->assertSame([2, '174.60', 'Papeteries du Rhône'], [$preparation->nouvelles_valeurs['produits'], $preparation->nouvelles_valeurs['total_ttc'], $preparation->nouvelles_valeurs['client']['nom']]);
        $this->assertSame([$facture->numero, '174.60'], [$emission->nouvelles_valeurs['numero'], $emission->nouvelles_valeurs['total_ttc']]);
    }
}
