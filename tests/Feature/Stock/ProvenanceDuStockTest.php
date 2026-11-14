<?php

namespace Tests\Feature\Stock;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Exceptions\MouvementDeStockInjustifieException;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Stock\Support\GardesDuStock;
use App\Domain\Tenancy\Actions\AnnulerCessionAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La provenance de chaque mouvement du grand livre, tenue par la base
 * elle-même (voir GardesDuStock::poserLaProvenance() et
 * poserLAuteurDesMouvements()), quel que soit le code qui écrit, et
 * contrôlée d'abord par l'application (voir ProvenanceDesMouvements) :
 * chaque mouvement est justifié par le document de son magasin que son
 * type désigne, dans l'état voulu, qui porte son produit (C13), dans la
 * limite de ce document (C14 à C16), jamais sans document (C18), à
 * l'instant de son inscription et au nom de l'auteur de son document
 * (C20). Un mouvement refusé laisse le grand livre et le stock tels quels.
 * Le temps est figé au 10 octobre 2026, 10 h, à Paris.
 */
class ProvenanceDuStockTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
        $this->travelTo(self::aParis('2026-10-10 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $this->produit = Produit::factory()->create(['reference' => 'CARNET', 'nom' => 'Carnet', 'cmup_ht' => '2.00', 'prix_vente_ttc' => 6, 'taux_tva' => 20, 'suivi_stock' => true]);
    }

    /**
     * Une ligne du grand livre telle qu'un administrateur de la base
     * l'écrirait : à l'instant figé, au nom du responsable de test du
     * magasin, pour le produit du test, sans source par défaut.
     *
     * @param  array<string, mixed>  $colonnes
     * @return array<string, mixed>
     */
    private function mouvement(array $colonnes): array
    {
        return [
            'magasin_id' => $this->magasin->id,
            'produit_id' => $this->produit->id,
            'type' => TypeMouvementStock::ReceptionAchat->value,
            'quantite' => 1,
            'cout_unitaire_ht' => null,
            'source_type' => null,
            'source_id' => null,
            'survenu_le' => now(),
            'created_at' => now(),
            'cree_par' => self::auteur()->id,
            'note' => null,
            ...$colonnes,
        ];
    }

    /**
     * L'insertion de ce mouvement, dans un point de sauvegarde.
     *
     * @param  array<string, mixed>  $colonnes
     */
    private function inserer(array $colonnes): Closure
    {
        return $this->dansUnPointDeSauvegarde(fn (): bool => DB::table('mouvements_stock')->insert($this->mouvement($colonnes)));
    }

    /**
     * Ce mouvement, inséré directement, est refusé par la base avec ce
     * message ; le grand livre et le stock ne changent pas.
     *
     * @param  array<string, mixed>  $colonnes
     */
    private function assertMouvementRefuse(array $colonnes, string $refus): void
    {
        $avant = $this->instantaneDesTables('mouvements_stock', 'produits');

        $this->assertThrows($this->inserer($colonnes), QueryException::class, $refus);

        $this->assertSame($avant, $this->instantaneDesTables('mouvements_stock', 'produits'));
    }

    /**
     * Une facture d'achat de ce produit, validée par son action, au nom du
     * responsable de test : sa réception fait entrer le stock.
     */
    private function receptionner(int $quantite, string $prixUnitaireHt = '2'): FactureAchat
    {
        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $this->produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => $prixUnitaireHt],
        ], ['date_facture' => '2026-10-09']));
    }

    /**
     * Les mouvements d'un type sans aucun document, ou d'un document
     * inventé.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mouvementsSansDocument(): array
    {
        $sansDocument = [];

        foreach (TypeMouvementStock::cases() as $type) {
            $sansDocument["{$type->value} sans document"] = [['type' => $type->value, 'cout_unitaire_ht' => $type->porteSonCout() ? '2.0000' : null, 'note' => 'Motif']];
        }

        return [
            ...$sansDocument,
            'source inventée' => [['source_type' => 'TypeInexistantXYZ', 'source_id' => 123456789]],
        ];
    }

    /**
     * C13, C18 : un mouvement sans document, ou dont le document n'existe
     * pas, est refusé, même un ajustement avec son motif.
     *
     * @param  array<string, mixed>  $colonnes
     */
    #[DataProvider('mouvementsSansDocument')]
    public function test_la_base_refuse_un_mouvement_sans_document(array $colonnes): void
    {
        $this->receptionner(10);

        $this->assertMouvementRefuse($colonnes, GardesDuStock::REFUS_SANS_JUSTIFICATIF);
    }

    /**
     * C13 : une réception justifiée par la facture d'achat validée d'un
     * autre magasin de la société, puis d'une autre société, est refusée ;
     * de même une vente justifiée par un brouillon, par une facture qui ne
     * porte pas le produit ou par une facture rectificative, et un retour
     * client justifié par la ligne d'un avoir de régularisation.
     */
    public function test_la_base_refuse_un_mouvement_justifie_par_un_document_qui_ne_le_justifie_pas(): void
    {
        $achatDUnAutreMagasin = app(TenantContext::class)->runAs($this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Autre', 'code' => 'AUTR']), fn (): Model => $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, Produit::factory()->create(), 10));
        $achatDUneAutreSociete = app(TenantContext::class)->runAs($this->ouvrirUneSociete(['nom' => 'Ailleurs']), fn (): Model => $this->justificatifDuStock(TypeMouvementStock::ReceptionAchat, Produit::factory()->create(), 10));
        app(TenantContext::class)->set($this->magasin);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit->id, 'quantite' => 3]]);
        $sansLeProduit = $this->justificatifDuStock(TypeMouvementStock::Vente, Produit::factory()->create(['stock_quantite' => 5]), 1);
        $rectificative = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit->id, 'quantite' => 3]]);
        DB::table('factures')->where('id', $rectificative->id)->update(['facture_rectifiee_id' => $this->vente(3)->id, 'numero' => 'FAC-BOUT-2026-000901', 'statut' => 'emise', 'date_emission' => now()]);
        $retourne = Produit::factory()->create(['stock_quantite' => 5]);
        $venteRetournee = $this->justificatifDuStock(TypeMouvementStock::Vente, $retourne, 1);
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($retourne, TypeMouvementStock::Vente, 1, $venteRetournee));
        $regularisation = app(CreerAvoirAction::class)->handle(self::auteur(), $venteRetournee->client()->firstOrFail(), [['ligne_facture_id' => $venteRetournee->lignes()->sole()->id, 'quantite' => 1]]);
        DB::table('avoirs')->where('id', $regularisation->id)->update(['motif_regularisation' => 'siren_corrige', 'numero' => 'AV-BOUT-2026-000901', 'statut' => 'emise', 'date_emission' => now()]);
        $ligneDeRegularisation = $regularisation->lignes()->sole();
        $this->receptionner(10);

        foreach ([
            [TypeMouvementStock::ReceptionAchat, $achatDUnAutreMagasin],
            [TypeMouvementStock::ReceptionAchat, $achatDUneAutreSociete],
            [TypeMouvementStock::Vente, $brouillon],
            [TypeMouvementStock::Vente, $sansLeProduit],
            [TypeMouvementStock::Vente, $rectificative],
        ] as [$type, $document]) {
            $this->assertMouvementRefuse(['type' => $type->value, 'source_type' => $document->getMorphClass(), 'source_id' => $document->getKey()], GardesDuStock::REFUS_SANS_JUSTIFICATIF);
        }
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::RetourClient->value, 'produit_id' => $ligneDeRegularisation->getAttribute('produit_id'), 'source_type' => $ligneDeRegularisation->getMorphClass(), 'source_id' => $ligneDeRegularisation->getKey()], GardesDuStock::REFUS_SANS_JUSTIFICATIF);
    }

    /**
     * Une vente de ce produit émise sans mouvement (voir
     * justificatifDuStock()).
     */
    private function vente(int $quantite): Facture
    {
        $vente = $this->justificatifDuStock(TypeMouvementStock::Vente, $this->produit, $quantite);

        return $vente instanceof Facture ? $vente : throw new LogicException('La vente attendue est une facture.');
    }

    /**
     * C16 : un document ne fait jamais bouger plus que ses lignes : une
     * vente de 5 au-delà de 5, une réception de 3 de plus sur un achat de 5
     * corrigé à 8, le retour de la vente d'un produit qui ne suivait pas
     * son stock (rien n'est sorti), un retour de plus sur un avoir qui a
     * déjà rendu ses 2 articles.
     */
    public function test_la_base_refuse_un_mouvement_au_dela_de_son_document(): void
    {
        $achat = $this->receptionner(5);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat->fresh() ?? $achat, $achat->fournisseur()->firstOrFail(), [['produit_id' => $this->produit->id, 'quantite' => 8, 'prix_unitaire_ht' => '2']]);
        $this->assertSame(8, $this->produit->fresh()?->stock_quantite);
        $vente = $this->vente(5);
        $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($this->produit, TypeMouvementStock::Vente, 5, $vente));
        $sansSuivi = Produit::factory()->create(['suivi_stock' => false]);
        $venteSansSuivi = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $sansSuivi->id, 'quantite' => 2]]);
        $sansSuivi->update(['suivi_stock' => true]);
        $avoirSansSuivi = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $venteSansSuivi->client()->firstOrFail(), [['ligne_facture_id' => $venteSansSuivi->lignes()->sole()->id, 'quantite' => 2]]);
        $venduPuisRendu = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit->id, 'quantite' => 2]]);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $venduPuisRendu->client()->firstOrFail(), [['ligne_facture_id' => $venduPuisRendu->lignes()->sole()->id, 'quantite' => 2]]);
        $this->assertSame(1, MouvementStock::query()->where('type', TypeMouvementStock::RetourClient->value)->count());

        $this->assertMouvementRefuse(['type' => TypeMouvementStock::Vente->value, 'source_type' => $vente->getMorphClass(), 'source_id' => $vente->id], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
        $this->assertMouvementRefuse(['quantite' => 3, 'source_type' => $achat->getMorphClass(), 'source_id' => $achat->id], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::RetourClient->value, 'produit_id' => $sansSuivi->id, 'quantite' => 2, 'source_type' => 'ligne_avoir', 'source_id' => $avoirSansSuivi->lignes()->sole()->id], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::RetourClient->value, 'source_type' => 'ligne_avoir', 'source_id' => $avoir->lignes()->sole()->id], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
    }

    /**
     * C14 : un ajustement ne fait que corriger la facture d'achat validée
     * qu'il désigne, avec son motif, pour ramener ce qu'elle a fait entrer
     * à ses lignes : 100 000 articles sans motif ni auteur, ou un article
     * de plus au nom du propriétaire, avec un motif, sont refusés ; la
     * correction de 10 à 12 par son action ajoute 2 articles.
     */
    public function test_un_ajustement_ne_ramene_une_facture_d_achat_qu_a_ses_lignes(): void
    {
        $achat = $this->receptionner(10);
        $parLAchat = ['source_type' => $achat->getMorphClass(), 'source_id' => $achat->id];

        $this->assertMouvementRefuse(['type' => TypeMouvementStock::AjustementEntree->value, 'quantite' => 100_000, 'cree_par' => null, ...$parLAchat], GardesDuStock::REFUS_SANS_JUSTIFICATIF);
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::AjustementEntree->value, 'cree_par' => $this->proprietaireDe($this->magasin)->id, 'note' => 'Correction', ...$parLAchat], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
        $this->assertThrows(fn () => $this->commeAuteurDuDocument(fn () => app(StockLedgerService::class)->enregistrer($this->produit, TypeMouvementStock::ReceptionAchat, 1, $achat)), MouvementDeStockInjustifieException::class, MouvementDeStockInjustifieException::AU_DELA_DU_DOCUMENT);

        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat->fresh() ?? $achat, $achat->fournisseur()->firstOrFail(), [['produit_id' => $this->produit->id, 'quantite' => 12, 'prix_unitaire_ht' => '2']]);

        $ajustement = MouvementStock::query()->where('type', TypeMouvementStock::AjustementEntree->value)->sole();
        $this->assertSame([2, $achat->id, self::auteur()->id], [$ajustement->quantite, (int) $ajustement->source_id, (int) $ajustement->cree_par]);
        $this->assertSame(12, $this->produit->fresh()?->stock_quantite);
        $this->assertTrue(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
    }

    /**
     * C15 : la sortie de cession et sa rentrée ne valent que pour un
     * magasin cédé ; la cession sort tout le stock, au coût moyen, au nom
     * du propriétaire ; sa rentrée reprend la même quantité au même coût,
     * une seule fois, par la procédure de l'éditeur.
     */
    public function test_la_cession_et_son_annulation_ne_sortent_et_ne_rentrent_que_tout_le_stock_une_fois(): void
    {
        $this->receptionner(11);
        $parLeMagasin = ['source_type' => $this->magasin->getMorphClass(), 'source_id' => $this->magasin->id, 'quantite' => 11, 'cout_unitaire_ht' => '2.0000'];
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::SortieCession->value, ...$parLeMagasin], GardesDuStock::REFUS_SANS_JUSTIFICATIF);
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::EntreeAnnulationCession->value, 'cree_par' => null, ...$parLeMagasin], GardesDuStock::REFUS_SANS_JUSTIFICATIF);

        app(TenantContext::class)->clear();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($this->magasin), $this->magasin->fresh() ?? $this->magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');
        app(TenantContext::class)->set($this->magasin);
        $sortie = MouvementStock::query()->where('type', TypeMouvementStock::SortieCession->value)->sole();
        $this->assertSame([11, '2.0000', $this->proprietaireDe($this->magasin)->id], [$sortie->quantite, $sortie->cout_unitaire_ht, (int) $sortie->cree_par]);
        $this->assertTrue(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
        foreach ([['quantite' => 5], ['cout_unitaire_ht' => '3.0000']] as $autre) {
            $this->assertMouvementRefuse(['type' => TypeMouvementStock::EntreeAnnulationCession->value, 'cree_par' => null, ...$parLeMagasin, ...$autre], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
        }

        $this->travelTo(self::aParis('2026-10-10 11:00:00'));
        app(TenantContext::class)->clear();
        app(AnnulerCessionAction::class)->handle($this->magasin->fresh() ?? $this->magasin, new InterventionDeLEditeur('Cession saisie par erreur', 'DOSSIER-2026-0091', ['alice', 'bruno']));
        app(TenantContext::class)->set($this->magasin);

        $this->assertSame(11, $this->produit->fresh()?->stock_quantite);
        $this->assertTrue(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
        DB::table('magasins')->where('id', $this->magasin->id)->update(['motif_fermeture' => MotifFermeture::Cession->value]);
        $this->assertMouvementRefuse(['type' => TypeMouvementStock::EntreeAnnulationCession->value, 'cree_par' => null, ...$parLeMagasin], GardesDuStock::REFUS_AU_DELA_DU_DOCUMENT);
    }

    /**
     * C20 : un mouvement survient à l'instant de son inscription, jamais
     * avant son document, et au nom de l'auteur de son document, que la
     * base contrôle à la validation de la transaction (le test force ce
     * contrôle) ; le compte de cet auteur ne se supprime pas.
     */
    public function test_un_mouvement_survient_a_l_instant_de_son_document_au_nom_de_son_auteur(): void
    {
        $sansSuivi = $this->produit;
        $sansSuivi->update(['suivi_stock' => false]);
        $vente = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $sansSuivi->id, 'quantite' => 3]]);
        $sansSuivi->update(['suivi_stock' => true]);
        $parLaVente = ['type' => TypeMouvementStock::Vente->value, 'source_type' => $vente->getMorphClass(), 'source_id' => $vente->id];
        $controlerLAuteur = fn (): bool => DB::statement('SET CONSTRAINTS '.GardesDuStock::AUTEUR_DES_MOUVEMENTS.' IMMEDIATE');
        $this->assertTrue($controlerLAuteur());

        $this->assertMouvementRefuse(['survenu_le' => self::enUtc('2020-01-01 00:00:00'), 'created_at' => self::enUtc('2020-01-01 00:00:00'), ...$parLaVente], GardesDuStock::REFUS_INSTANT);
        $this->assertMouvementRefuse(['survenu_le' => self::enUtc('2026-10-10 08:30:00'), ...$parLaVente], GardesDuStock::REFUS_INSTANT);
        $this->assertMouvementRefuse(['cree_par' => $this->membre($this->magasin, RoleMagasin::Vendeur)->id, ...$parLaVente], GardesDuStock::REFUS_AUTEUR);
        $this->assertMouvementRefuse(['cree_par' => null, ...$parLaVente], GardesDuStock::REFUS_AUTEUR);

        $this->inserer(['quantite' => 3, ...$parLaVente])();
        $this->assertSame(-3, $this->produit->fresh()?->stock_quantite);
        $auteur = self::auteur();
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): int => DB::table('users')->where('id', $auteur->id)->delete()), QueryException::class);
        $this->assertTrue(User::query()->whereKey($auteur->id)->exists());
    }

    /**
     * Contrôle positif d'ensemble : un inventaire d'ouverture, une vente à
     * la caisse, une facture, un avoir, la validation puis la correction
     * d'un achat et un avoir fournisseur, faits par leurs actions,
     * inscrivent des mouvements que la base accepte, auteurs compris ; le
     * stock de chaque produit est la somme signée de son grand livre.
     */
    public function test_les_actions_inscrivent_des_mouvements_que_la_base_accepte(): void
    {
        $inventaire = app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [['produit' => ['nom' => 'Stylo', 'reference' => 'STYLO', 'prix_vente_ttc' => '2.40', 'taux_tva' => '20'], 'quantite' => 6, 'cout_unitaire_ht' => '1.00']]);
        app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), $inventaire);
        $achat = $this->receptionner(10);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $achat->fresh() ?? $achat, $achat->fournisseur()->firstOrFail(), [['produit_id' => $this->produit->id, 'quantite' => 9, 'prix_unitaire_ht' => '2']]);
        $avoirFournisseur = app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $achat->fresh() ?? $achat, [['ligne_achat_id' => $achat->lignes()->sole()->id, 'quantite' => 1]], 'Abîmé');
        app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), $avoirFournisseur);
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit->id, 'quantite' => 3]]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $ticket->client()->firstOrFail(), [['ligne_facture_id' => $ticket->lignes()->sole()->id, 'quantite' => 1]]);

        $this->assertTrue(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
        $this->assertSame(
            ['inventaire_ouverture', 'reception_achat', 'ajustement_sortie', 'retour_fournisseur', 'vente', 'retour_client'],
            MouvementStock::query()->orderBy('id')->pluck('type')->map(fn (TypeMouvementStock $type): string => $type->value)->all(),
        );
        foreach (Produit::query()->get() as $produit) {
            $this->assertSame($produit->stock_quantite, MouvementStock::query()->where('produit_id', $produit->id)->get()->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite)));
        }
        $this->assertSame(6, $this->produit->fresh()?->stock_quantite);
    }
}
