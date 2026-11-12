<?php

namespace Tests\Feature\Editeur;

use App\Console\Commands\Editeur\AnnulerLaCession;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Support\GardesDuStock;
use App\Domain\Tenancy\Actions\AnnulerCessionAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Tests\TestCase;

/**
 * L'annulation par l'éditeur de la cession d'un magasin faite par erreur
 * (voir AnnulerCessionAction) : deux opérateurs, aucune opération après la
 * cession ; le stock sorti rentre au même coût moyen, celui de la seule
 * dernière cession quand une cession annulée a été refaite ; le magasin
 * retrouve son motif de fermeture d'avant, ou, cédé ouvert, devient fermé
 * temporairement, et la société le rouvre elle-même ; aucun document émis
 * ne change et la société garde un seul propriétaire actif. Le temps est
 * figé au 2 novembre 2026, à Paris, sauf où un test le fait avancer.
 */
class AnnulerCessionTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Cession saisie par erreur, acte de cession jamais signé';

    private const PIECE = 'DOSSIER-2026-0091';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    public function test_une_cession_annulee_rentre_le_stock_au_meme_cout_et_seulement_sans_operation_posterieure(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $carnet = $this->produitEnStock($magasin, 'CARNET', 10, '2.50');
        $this->ceder($magasin);
        $signatures = $this->signaturesDuRegistre($magasin);
        $this->travelTo(self::aParis('2026-11-02 11:00:00'));

        $motif = app(AnnulerCessionAction::class)->handle($magasin->fresh(), $this->editeur());

        $this->assertSame(MotifFermeture::Temporaire, $motif);
        $this->assertSame($signatures, array_intersect_key($this->signaturesDuRegistre($magasin), $signatures));
        $this->assertUnSeulProprietaireActif($magasin->societe);
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
        $this->ecrireUnMouvementApresLaCession($autre, $stylo);

        $this->assertThrows(fn () => app(AnnulerCessionAction::class)->handle($autre->fresh(), $this->editeur()), LogicException::class, AnnulerCessionAction::MESSAGE_OPERATION_POSTERIEURE);
        $this->assertTrue($autre->fresh()->aEteCede());
    }

    /**
     * Une cession annulée, le magasin rouvert puis cédé de nouveau après
     * la vente de trois carnets : la seconde annulation ne fait rentrer
     * que les sept carnets de la dernière cession, au même coût, et non une
     * seconde fois les dix que la première annulation avait déjà rendus.
     */
    public function test_une_seconde_annulation_ne_rentre_que_le_stock_de_la_derniere_cession(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $carnet = $this->produitEnStock($magasin, 'CARNET', 10, '2.50');
        $this->ceder($magasin);
        $this->travelTo(self::aParis('2026-11-02 11:00:00'));
        app(AnnulerCessionAction::class)->handle($magasin->fresh(), $this->editeur());
        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), 'password');
        app(TenantContext::class)->runAs($magasin, fn () => self::vendreAuComptant(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $carnet->id, 'quantite' => 3]]));
        $this->travelTo(self::aParis('2026-11-02 12:00:00'));
        $this->ceder($magasin);
        $this->travelTo(self::aParis('2026-11-02 13:00:00'));

        app(AnnulerCessionAction::class)->handle($magasin->fresh(), $this->editeur());

        app(TenantContext::class)->runAs($magasin, function () use ($carnet): void {
            $this->assertSame([7, '2.5000'], [$carnet->fresh()->stock_quantite, $carnet->fresh()->cmup_ht]);
            $this->assertSame([10, 7], MouvementStock::query()->where('type', TypeMouvementStock::EntreeAnnulationCession->value)->orderBy('id')->pluck('quantite')->all());
        });
        $annulations = $this->entreesDAudit($magasin->societe, ActionAuditee::CessionAnnulee);
        $this->assertSame(['motif' => 'temporaire', 'produits_rentres' => 1, 'valeur_stock_rentre_ht' => '17.50'], end($annulations)->nouvelles_valeurs);
    }

    /**
     * Par la commande de deux opérateurs distincts, la cession s'annule ;
     * sans la confirmation retapée, en mode non interactif, avec un
     * opérateur inconnu, le même opérateur deux fois ou un code de magasin
     * inconnu dans la société, rien ne change.
     */
    public function test_la_commande_annule_la_cession_et_refuse_sans_confirmation_ni_deux_operateurs_authentifies(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'BOUT']);
        $societe = $magasin->societe;
        $this->ceder($magasin);
        $parametres = ['magasin' => 'bout', '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => $societe->siren];

        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, [...$parametres, '--confirmer' => '000000018'], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, [...$parametres, '--confirmer' => null, '--no-interaction' => true], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, [...$parametres, '--no-interaction' => true], [])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, $parametres, [['mallory', 'secret-de-mallory']], 2)
            ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, $parametres, [['alice', 'secret-d-alice'], ['alice', 'secret-d-alice']], 2)
            ->expectsOutputToContain('Cette procédure exige deux opérateurs distincts : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $societe, [...$parametres, 'magasin' => 'AILLEURS'], [['alice', 'secret-d-alice'], ['bruno', 'secret-de-bruno']], 2)
            ->expectsOutputToContain(AnnulerLaCession::MESSAGE_MAGASIN_INTROUVABLE)
            ->assertFailed();
        $this->assertTrue($magasin->fresh()->aEteCede());
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::CessionAnnulee));

        $this->annulerParLaCommande($magasin)
            ->expectsOutputToContain('Cession du magasin BOUT annulée : son stock est rentré, et il est fermé temporairement.')
            ->assertSuccessful();

        $this->assertSame(MotifFermeture::Temporaire, $magasin->fresh()->motif_fermeture);
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::CessionAnnulee);
        $this->assertSame(['editeur', self::JUSTIFICATION, ['alice', 'bruno']], [$entree->origine, $entree->justification, $entree->operateur['operateurs']]);
        $this->assertUnSeulProprietaireActif($societe);
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
     * La commande de l'annulation de la cession du magasin, lancée par les
     * deux opératrices alice et bruno.
     */
    private function annulerParLaCommande(Magasin $magasin): PendingCommand
    {
        return $this->lancerUneProcedureDeLEditeur('editeur:magasin:annuler-cession', $magasin->societe, [
            'magasin' => (string) $magasin->code,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $magasin->societe->siren,
        ], [['alice', 'secret-d-alice'], ['bruno', 'secret-de-bruno']], 2);
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
     * quantité par l'inventaire d'ouverture de la fabrique.
     */
    private function produitEnStock(Magasin $magasin, string $reference, int $quantite, string $coutMoyen): Produit
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::factory()->create(['reference' => $reference, 'cmup_ht' => $coutMoyen, 'suivi_stock' => true, 'seuil_alerte' => 0, 'stock_quantite' => $quantite])->fresh() ?? throw new LogicException('Le produit en stock a disparu.'));
    }

    /**
     * Un mouvement du grand livre écrit après la cession du magasin, comme
     * le ferait un administrateur de la base : l'application n'en écrit
     * aucun, et la base le refuse sans le document qui le justifie (preuve
     * d'abord), puis le reçoit une fois sa garde levée le temps de la
     * transaction du test.
     */
    private function ecrireUnMouvementApresLaCession(Magasin $magasin, Produit $produit): void
    {
        $mouvement = [
            'magasin_id' => $magasin->id,
            'produit_id' => $produit->id,
            'type' => TypeMouvementStock::AjustementEntree->value,
            'quantite' => 1,
            'survenu_le' => now(),
            'created_at' => now(),
            'note' => 'Mouvement après la cession',
        ];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('mouvements_stock')->insert($mouvement)), QueryException::class, GardesDuStock::REFUS_SANS_JUSTIFICATIF);
        $this->retirerLeDeclencheur('mouvements_stock', 'mouvements_stock_justifies');
        DB::table('mouvements_stock')->insert($mouvement);
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
