<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\AnnulerCessionAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * L'annulation par l'éditeur de la cession d'un magasin faite par erreur
 * (voir AnnulerCessionAction) : deux opérateurs, aucune opération après la
 * cession ; le stock sorti rentre au même coût moyen ; le magasin retrouve
 * son motif de fermeture d'avant, ou, cédé ouvert, devient fermé
 * temporairement, et la société le rouvre elle-même.
 */
class AnnulerCessionTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Cession saisie par erreur, acte de cession jamais signé';

    private const PIECE = 'DOSSIER-2026-0091';

    public function test_une_cession_annulee_rentre_le_stock_au_meme_cout_et_seulement_sans_operation_posterieure(): void
    {
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $carnet = $this->produitEnStock($magasin, 'CARNET', 10, '2.50');
        $this->ceder($magasin);
        $this->travelTo(self::aParis('2026-11-02 11:00:00'));

        $motif = app(AnnulerCessionAction::class)->handle($magasin->fresh(), $this->editeur());

        $this->assertSame(MotifFermeture::Temporaire, $motif);
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Temporaire], [$magasin->fresh()->statut, $magasin->fresh()->motif_fermeture]);
        app(TenantContext::class)->runAs($magasin, function () use ($carnet): void {
            $this->assertSame([10, '2.5000'], [$carnet->fresh()->stock_quantite, $carnet->fresh()->cmup_ht]);
            $this->assertSame([[10, '2.5000']], MouvementStock::query()->where('type', TypeMouvementStock::EntreeAnnulationCession->value)->get()->map(fn (MouvementStock $entree): array => [$entree->quantite, $entree->cout_unitaire_ht])->all());
        });
        $this->assertContains(['parametre' => 'motif_fermeture', 'ancienne_valeur' => 'cession', 'nouvelle_valeur' => 'temporaire'], $this->parametresJournalises($magasin));
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::CessionAnnulee);
        $this->assertSame(['editeur', ['motif' => 'temporaire', 'produits_rentres' => 1, 'valeur_stock_rentre_ht' => '25.00']], [$entree->origine, $entree->nouvelles_valeurs]);

        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), 'password');
        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);

        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique', 'code' => 'AUTR']);
        $stylo = $this->produitEnStock($autre, 'STYLO', 4, '1.25');
        $this->ceder($autre);
        $this->travelTo(self::aParis('2026-11-02 12:00:00'));
        app(TenantContext::class)->runAs($autre, fn () => app(StockLedgerService::class)->ajuster($stylo->fresh(), 1, 'Mouvement après la cession'));

        $this->assertThrows(fn () => app(AnnulerCessionAction::class)->handle($autre->fresh(), $this->editeur()), LogicException::class, AnnulerCessionAction::MESSAGE_OPERATION_POSTERIEURE);
        $this->assertTrue($autre->fresh()->aEteCede());
    }

    /**
     * Un magasin fermé pour cessation, puis cédé, retrouve à l'annulation
     * de sa cession sa fermeture pour cessation.
     */
    public function test_l_annulation_rend_au_magasin_ferme_avant_sa_cession_son_motif_d_avant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, 'password');
        $this->ceder($magasin);

        $this->assertSame(MotifFermeture::Cessation, app(AnnulerCessionAction::class)->handle($magasin->fresh(), $this->editeur()));

        $this->assertTrue($magasin->fresh()->estFermePourCessation());
    }

    /**
     * Avec un seul opérateur, ou pour un magasin qui n'a pas été cédé,
     * rien ne change.
     */
    public function test_deux_operateurs_et_une_cession_sont_exiges(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ouvert = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ouvert']);
        $this->ceder($magasin);

        $this->assertThrows(fn () => app(AnnulerCessionAction::class)->handle($magasin->fresh(), new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice'])), LogicException::class, AnnulerCessionAction::MESSAGE_DEUX_OPERATEURS);
        $this->assertThrows(fn () => app(AnnulerCessionAction::class)->handle($ouvert->fresh(), $this->editeur()), LogicException::class, AnnulerCessionAction::MESSAGE_PAS_CEDE);

        $this->assertTrue($magasin->fresh()->aEteCede());
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::CessionAnnulee));
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice', 'bruno']);
    }

    /**
     * Le propriétaire cède le magasin, ouvert ou déjà fermé, en retapant
     * son nom.
     */
    private function ceder(Magasin $magasin): void
    {
        app(TenantContext::class)->clear();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cession, 'password', $magasin->nom);
        app(TenantContext::class)->clear();
    }

    /**
     * Un produit suivi en stock du magasin, à ce coût moyen, amené à cette
     * quantité par un ajustement d'inventaire.
     */
    private function produitEnStock(Magasin $magasin, string $reference, int $quantite, string $coutMoyen): Produit
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($reference, $quantite, $coutMoyen): Produit {
            $produit = Produit::factory()->create(['reference' => $reference, 'cmup_ht' => $coutMoyen, 'suivi_stock' => true, 'seuil_alerte' => 0]);
            app(StockLedgerService::class)->ajuster($produit, $quantite, 'Inventaire d\'ouverture');

            return $produit->fresh();
        });
    }

    /**
     * Les paramètres de conformité inscrits au journal des événements du
     * magasin (JET 270), dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function parametresJournalises(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => array_intersect_key($evenement->contenuSigne()['donnees']['details'], array_flip(['parametre', 'ancienne_valeur', 'nouvelle_valeur'])))
            ->all());
    }
}
