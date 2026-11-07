<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * « Émettre et encaisser » (R1, décision de l'éditeur du 02/11/2026) : une
 * facture ou un ticket fait hors de la caisse s'émet et s'encaisse d'un
 * seul geste, en un ou plusieurs modes, en tout ou en partie, dans la même
 * transaction signée ; le règlement entre dans le contexte signé de
 * l'émission, s'imprime sur le PDF et compte dans la clôture du jour où il
 * est enregistré.
 */
class EmettreEtEncaisserTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']);
        $this->completerIdentiteLegale($magasin);

        return $magasin;
    }

    /**
     * Un brouillon de cette facture, de $quantite articles à ce prix TTC à
     * 20 %, fait par l'auteur.
     */
    private function brouillon(Client $client, float $prixTtc, int $quantite = 1, ?User $auteur = null): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAction::class)->handle($auteur ?? self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => $quantite]]);
    }

    /**
     * @param  list<PartDeReglement>  $parts
     */
    private static function emettreEtEncaisser(Facture $brouillon, array $parts, ?User $auteur = null, ?string $jeton = null): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur ?? self::auteur(), $brouillon, ReglementComptant::horsDeLaCaisse($parts), jeton: $jeton);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private static function paiementsDe(Facture $facture): array
    {
        return $facture->allocationsPaiement()->with('paiement')->orderBy('id')->get()
            ->map(fn ($allocation): array => [$allocation->paiement->mode_paiement->value, $allocation->montant_alloue, $allocation->paiement->reference, $allocation->paiement->recu_le])
            ->all();
    }

    /**
     * Les règlements que le contenu signé de l'émission a retenus, sans
     * l'identifiant de leur paiement.
     *
     * @return list<array{mode: string, reference: string|null, montant: int}>
     */
    private static function reglementsSignes(Facture $facture): array
    {
        return array_map(
            fn (array $reglement): array => array_intersect_key($reglement, array_flip(['mode', 'reference', 'montant'])),
            $facture->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['reglements'],
        );
    }

    public function test_une_facture_s_emet_et_s_encaisse_d_un_seul_geste_dans_un_seul_mode(): void
    {
        $magasin = $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(), 100);

        $facture = self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Virement, 10000, 'VIR-1')]);

        $this->assertSame([StatutFacture::Payee, 'FAC-LYON1-2026-000001', 0.0], [$facture->statut, $facture->numero, $facture->montantRestant()]);
        $this->assertSame([['virement', '100.00', 'VIR-1', '2026-10-09']], self::paiementsDe($facture));
        $this->assertSame([['mode' => 'virement', 'reference' => 'VIR-1', 'montant' => 10000]], self::reglementsSignes($facture));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Les cinq modes se proposent hors de la caisse, prélèvement compris :
     * 20,00 € en espèces, 30,00 € par carte et 50,00 € par prélèvement
     * règlent une facture de 100,00 €, chaque part étant un paiement signé.
     */
    public function test_un_brouillon_s_encaisse_en_plusieurs_modes_prelevement_compris(): void
    {
        $magasin = $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(), 100);

        $facture = self::emettreEtEncaisser($brouillon, [
            new PartDeReglement(ModePaiement::Especes, 2000),
            new PartDeReglement(ModePaiement::CarteBancaire, 3000, 'CB-778899'),
            new PartDeReglement(ModePaiement::Prelevement, 5000, 'SEPA-42'),
        ]);

        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame([
            ['especes', '20.00', null, '2026-10-09'],
            ['carte_bancaire', '30.00', 'CB-778899', '2026-10-09'],
            ['prelevement', '50.00', 'SEPA-42', '2026-10-09'],
        ], self::paiementsDe($facture));
        $this->assertSame(3, Paiement::query()->count());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un paiement partiel est permis : 40,00 € reçus par chèque sur une
     * facture de 100,00 €, le reste (60,00 €) reste dû avec l'échéance de
     * la facture, et le PDF imprime le chèque et ce qui restait dû à
     * l'émission.
     */
    public function test_un_paiement_partiel_laisse_le_reste_du_avec_l_echeance_de_la_facture(): void
    {
        $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(['delai_paiement_jours' => 30]), 100);

        $facture = self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Cheque, 4000, 'CHQ 1234567')]);

        $this->assertSame([StatutFacture::PartiellementPayee, 60.0, '2026-11-08'], [$facture->statut, $facture->montantRestant(), $facture->date_echeance]);
        $html = html_entity_decode(app(FacturePdfGenerator::class)->html($facture->fresh()), ENT_QUOTES);
        $this->assertStringContainsString('<td class="mode">Chèque</td>', $html);
        $this->assertStringContainsString('Restant dû à l\'émission', $html);
        $this->assertStringContainsString('60,00 €', $html);
        $this->assertStringContainsString('Échéance le 08/11/2026', $html);
    }

    /**
     * Le crédit d'avoir du client s'impute d'office, comme à toute
     * émission : sur une facture de 100,00 €, un client qui a 30,00 € de
     * crédit ne règle que 70,00 € ; 71,00 € de parts sont refusés sans rien
     * émettre ni encaisser, aucun numéro n'étant consommé.
     */
    public function test_le_credit_d_avoir_du_client_s_impute_d_abord_et_les_parts_ne_depassent_jamais_le_reste(): void
    {
        $magasin = $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $premiere = self::emettreEtEncaisser($this->brouillon($client, 30), [new PartDeReglement(ModePaiement::Especes, 3000)]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $premiere->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $brouillon = $this->brouillon($client, 100);

        $this->assertThrows(
            fn () => self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::CarteBancaire, 7100)]),
            LogicException::class,
            sprintf(EncaisserEnPlusieursModesAction::PARTS_EN_TROP, '71,00 €', '70,00 €'),
        );
        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);

        $facture = self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::CarteBancaire, 7000)]);

        $this->assertSame(['FAC-LYON1-2026-000002', StatutFacture::Payee, 70.0, 30.0], [$facture->numero, $facture->statut, $facture->montantPaye(), $facture->montantCrediteParAvoir()]);
        $this->assertSame([['mode' => 'carte_bancaire', 'reference' => null, 'montant' => 7000]], self::reglementsSignes($facture));
        $this->assertSame([['numero' => Avoir::query()->sole()->numero, 'montant' => 3000]], array_map(
            fn (array $credit): array => array_intersect_key($credit, array_flip(['numero', 'montant'])),
            $facture->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['credits_avoir'],
        ));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un règlement sans aucune part n'est pas un règlement : le geste
     * revient à « Émettre », sans paiement ni autorisation d'encaisser.
     */
    public function test_sans_aucune_part_le_geste_revient_a_emettre(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE]);
        $brouillon = $this->brouillon(Client::factory()->create(), 30, auteur: $vendeur);

        $ticket = self::emettreEtEncaisser($brouillon, [], $vendeur);

        $this->assertSame([StatutFacture::Emise, 30.0, 0], [$ticket->statut, $ticket->montantRestant(), Paiement::query()->count()]);
    }

    /**
     * Hors de la caisse, aucun bon d'avoir ne règle un brouillon (Q6).
     */
    public function test_aucun_bon_d_avoir_ne_regle_un_brouillon_hors_de_la_caisse(): void
    {
        $this->assertThrows(
            fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 1000)], ['AV-LYON1-2026-000001'], false),
            LogicException::class,
            Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE,
        );
    }

    /**
     * Il faut pouvoir émettre ce document et enregistrer un encaissement
     * dans ce magasin : un vendeur sans « Paiements : enregistrer » est
     * refusé sans rien émettre ; le propriétaire, qui ne vend jamais, et un
     * compte du cabinet lié, qui n'a aucune de ces autorisations, aussi.
     *
     * @return array<string, array{string}>
     */
    public static function comptesRefuses(): array
    {
        return [
            'vendeur sans l\'autorisation d\'encaisser' => ['vendeur sans encaissement'],
            'propriétaire' => ['propriétaire'],
            'compte du cabinet lié' => ['cabinet'],
        ];
    }

    #[DataProvider('comptesRefuses')]
    public function test_le_geste_est_refuse_a_qui_ne_peut_pas_emettre_et_encaisser(string $compte): void
    {
        $magasin = $this->magasin();
        $auteur = match ($compte) {
            'vendeur sans encaissement' => $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE]),
            'propriétaire' => $this->proprietaireDe($magasin),
            'cabinet' => $this->collaborateurDuCabinetLie($magasin),
        };
        app(TenantContext::class)->set($magasin->fresh());
        $brouillon = $this->brouillon(Client::factory()->create(), 30, auteur: self::responsableDeTest($magasin));

        $this->assertThrows(fn () => self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Especes, 3000)], $auteur), AuthorizationException::class);

        $this->assertFalse($auteur->can('emettreEtEncaisser', $brouillon));
        $this->assertSame([StatutFacture::Brouillon, 0], [$brouillon->fresh()->statut, Paiement::query()->count()]);
        $this->assertTrue(self::responsableDeTest($magasin)->can('emettreEtEncaisser', $brouillon));
    }

    /**
     * Un vendeur sans « voir celles de toute l'équipe » n'émet ni n'encaisse
     * le brouillon d'un collègue.
     */
    public function test_un_vendeur_n_emet_et_n_encaisse_que_ses_brouillons(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin->fresh());
        $celuiDuResponsable = $this->brouillon(Client::factory()->create(), 30, auteur: self::responsableDeTest($magasin));
        $leSien = $this->brouillon(Client::factory()->create(), 30, auteur: $vendeur);

        $this->assertThrows(fn () => self::emettreEtEncaisser($celuiDuResponsable, [new PartDeReglement(ModePaiement::Especes, 3000)], $vendeur), AuthorizationException::class, LecteurDesDroits::REFUS);
        $ticket = self::emettreEtEncaisser($leSien, [new PartDeReglement(ModePaiement::Especes, 3000)], $vendeur);

        $this->assertSame([StatutFacture::Brouillon, StatutFacture::Payee], [$celuiDuResponsable->fresh()->statut, $ticket->statut]);
        $this->assertSame([$vendeur->id], Paiement::query()->pluck('cree_par')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * R3 : la même demande, répétée avec le même jeton après une réponse
     * perdue, rend la facture déjà émise, sans second numéro ni second
     * paiement ; le même jeton avec d'autres parts est refusé.
     */
    public function test_la_meme_demande_repetee_n_emet_et_n_encaisse_qu_une_fois(): void
    {
        $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(), 100);
        $jeton = (string) Str::uuid();
        $parts = [new PartDeReglement(ModePaiement::Especes, 4000), new PartDeReglement(ModePaiement::CarteBancaire, 6000)];

        $premiere = self::emettreEtEncaisser($brouillon, $parts, jeton: $jeton);
        $repetee = self::emettreEtEncaisser($brouillon, $parts, jeton: $jeton);

        $this->assertTrue($premiere->is($repetee));
        $this->assertSame([1, 2], [Facture::query()->whereNotNull('numero')->count(), Paiement::query()->count()]);
        $this->assertThrows(fn () => self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Especes, 10000)], jeton: $jeton), LogicException::class, ActionsUniques::REFUS);
    }

    /**
     * Un document déjà émis et payé ne s'émet plus jamais : une facture
     * payée peut repasser « émise » quand un paiement est contre-passé, mais
     * une seconde émission (un second « Émettre et encaisser » du même
     * brouillon, ouvert dans un autre onglet) est refusée, sans second
     * numéro ni second paiement.
     */
    public function test_un_document_deja_emis_et_paye_ne_s_emet_plus(): void
    {
        $this->magasin();
        $brouillon = $this->brouillon(Client::factory()->create(), 30);
        $ticket = self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Especes, 3000)]);

        $this->assertThrows(
            fn () => self::emettreEtEncaisser($brouillon, [new PartDeReglement(ModePaiement::Especes, 3000)]),
            LogicException::class,
            sprintf(EmettreFactureAction::REFUS_DEJA_EMISE, $ticket->numero),
        );

        $this->assertSame([1, 1], [Facture::query()->whereNotNull('numero')->count(), Paiement::query()->count()]);
    }

    /**
     * Les paiements reçus à l'émission comptent dans la clôture du jour où
     * ils sont enregistrés, chacun dans son mode.
     */
    public function test_les_paiements_recus_a_l_emission_comptent_dans_la_cloture_du_jour(): void
    {
        $magasin = $this->magasin();
        self::emettreEtEncaisser($this->brouillon(Client::factory()->create(), 50, 2), [
            new PartDeReglement(ModePaiement::Especes, 4000),
            new PartDeReglement(ModePaiement::CarteBancaire, 6000),
        ]);

        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-09');

        $encaissements = collect(ClotureCaisse::query()->sole()->recapitulatif['encaissements'])->keyBy('mode');
        $this->assertSame([1, 4000], [$encaissements['especes']['paiements'], $encaissements['especes']['montant']]);
        $this->assertSame([1, 6000], [$encaissements['carte_bancaire']['paiements'], $encaissements['carte_bancaire']['montant']]);
    }

    /**
     * Depuis la liste des factures, à côté de « Émettre », le responsable
     * émet et encaisse un brouillon en deux modes ; la fenêtre propose
     * d'abord ce qui reste dû, dans le mode préféré du client.
     */
    public function test_le_responsable_emet_et_encaisse_depuis_la_liste_des_factures(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(['mode_paiement_prefere' => ModePaiement::Virement]), 100, auteur: $responsable);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $liste = Livewire::test(ListFactures::class)
            ->assertTableActionVisible('emettre', $brouillon)
            ->mountTableAction('emettre_et_encaisser', $brouillon);
        $premiere = (string) array_key_first($liste->get('mountedTableActionsData.0.parts'));
        $this->assertSame([ModePaiement::Virement->value, '100.00'], [$liste->get("mountedTableActionsData.0.parts.{$premiere}.mode"), $liste->get("mountedTableActionsData.0.parts.{$premiere}.montant")]);

        $liste->set('mountedTableActionsData.0.parts', [
            $premiere => [...$liste->get("mountedTableActionsData.0.parts.{$premiere}"), 'montant' => '60', 'reference' => 'VIR-60'],
            (string) Str::uuid() => ['mode' => ModePaiement::Cheque->value, 'montant' => '40', 'montant_saisi' => true, 'reference' => null],
        ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatutFacture::Payee, $brouillon->fresh()->statut);
        $this->assertSame([['virement', '60.00', 'VIR-60', '2026-10-09'], ['cheque', '40.00', null, '2026-10-09']], self::paiementsDe($brouillon->fresh()));
    }

    /**
     * La fiche d'un brouillon propose « Émettre » et « Émettre et
     * encaisser » ; des parts qui dépassent ce qui reste dû y sont
     * refusées par le formulaire, sans rien émettre ; une part partielle
     * émet la facture, que la fiche montre aussitôt partiellement payée.
     */
    public function test_la_fiche_d_un_brouillon_l_emet_et_l_encaisse_en_partie(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $brouillon = $this->brouillon(Client::factory()->professionnel()->create(), 100, auteur: $responsable);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $fiche = Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertActionVisible('emettre')
            ->mountAction('emettre_et_encaisser');
        $premiere = (string) array_key_first($fiche->get('mountedActionsData.0.parts'));

        $fiche->set("mountedActionsData.0.parts.{$premiere}.montant", '100.01')
            ->callMountedAction()
            ->assertHasActionErrors(['parts']);
        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);

        $fiche->set("mountedActionsData.0.parts.{$premiere}.mode", ModePaiement::CarteBancaire->value)
            ->set("mountedActionsData.0.parts.{$premiere}.montant", '25')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Partiellement payée');

        $this->assertSame([StatutFacture::PartiellementPayee, 75.0], [$brouillon->fresh()->statut, $brouillon->fresh()->montantRestant()]);
    }

    /**
     * L'action n'apparaît qu'à qui peut émettre et encaisser, jamais au
     * propriétaire ni à un compte du cabinet lié, ni sur un document déjà
     * émis.
     *
     * @return array<string, array{string, bool}>
     */
    public static function comptesDevantLaListe(): array
    {
        return [
            'responsable' => ['responsable', true],
            'vendeur' => ['vendeur', true],
            'vendeur sans l\'autorisation d\'encaisser' => ['vendeur sans encaissement', false],
            'propriétaire' => ['propriétaire', false],
            'compte du cabinet lié' => ['cabinet', false],
        ];
    }

    #[DataProvider('comptesDevantLaListe')]
    public function test_seul_qui_peut_emettre_et_encaisser_voit_l_action(string $compte, bool $visible): void
    {
        $magasin = $this->magasin();
        $auteur = match ($compte) {
            'responsable' => self::responsableDeTest($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut($magasin->societe->type_activite), PermissionRegistry::FACTURE_VIEW_TOUTES]),
            'vendeur sans encaissement' => $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE]),
            'propriétaire' => $this->proprietaireDe($magasin),
            'cabinet' => $this->collaborateurDuCabinetLie($magasin),
        };
        app(TenantContext::class)->set($magasin->fresh());
        $brouillon = $this->brouillon(Client::factory()->create(), 30, auteur: self::responsableDeTest($magasin));
        $emise = app(EmettreFactureAction::class)->handle(self::responsableDeTest($magasin), $this->brouillon(Client::factory()->create(), 30, auteur: self::responsableDeTest($magasin)));
        $this->actingAs($auteur);
        Filament::setTenant($magasin);

        $liste = Livewire::test(ListFactures::class);

        $visible ? $liste->assertTableActionVisible('emettre_et_encaisser', $brouillon) : $liste->assertTableActionHidden('emettre_et_encaisser', $brouillon);
        $liste->assertTableActionHidden('emettre_et_encaisser', $emise);
    }
}
