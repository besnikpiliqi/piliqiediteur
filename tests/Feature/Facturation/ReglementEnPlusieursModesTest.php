<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Points 1 et 23 : une vente se règle en un ou plusieurs modes, d'un seul
 * geste, à la caisse comme sur une facture, chaque part étant un paiement
 * signé ; le crédit d'avoir du client et les bons d'avoir s'affichent
 * comme le mode « Avoir » et s'enregistrent comme des imputations, jamais
 * comme un paiement d'un mode.
 */
class ReglementEnPlusieursModesTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        return $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    /**
     * @return list<array{produit_id: int, quantite: int}>
     */
    private static function article(float $prixTtc, int $quantite = 1): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => $quantite]];
    }

    /**
     * @param  list<PartDeReglement>  $parts
     * @param  list<string>  $bons
     */
    private static function vendre(Client $client, array $lignes, array $parts, array $bons = [], ?string $jeton = null): Facture
    {
        return app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, $lignes, new ReglementComptant($parts, $bons), jeton: $jeton);
    }

    /**
     * Un ticket du client comptoir de ce montant, repris en entier par un
     * avoir : le numéro de l'avoir vaut bon d'avoir de ce montant.
     */
    private function bonDAvoir(float $montant): Avoir
    {
        $ticket = self::vendreAuComptant(self::auteur(), $this->comptoir(), self::article($montant));

        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $this->comptoir(), [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
    }

    /**
     * Une facture émise de ce montant, sans règlement.
     */
    private function factureDue(Client $client, float $montant, ?User $auteur = null): Facture
    {
        $auteur ??= self::auteur();

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, self::article($montant)));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function paiementsDe(Facture $facture): array
    {
        return $facture->allocationsPaiement()->with('paiement')->orderBy('id')->get()
            ->map(fn ($allocation): array => [$allocation->paiement->mode_paiement->value, $allocation->montant_alloue])
            ->all();
    }

    public function test_un_ticket_se_regle_moitie_en_especes_moitie_par_carte(): void
    {
        $magasin = $this->magasin();

        $ticket = self::vendre($this->comptoir(), self::article(40, 2), [new PartDeReglement(ModePaiement::Especes, 4000), new PartDeReglement(ModePaiement::CarteBancaire, 4000, 'CB-123')]);

        $this->assertSame([StatutFacture::Payee, '80.00'], [$ticket->statut, $ticket->total_ttc]);
        $this->assertSame([['especes', '40.00'], ['carte_bancaire', '40.00']], self::paiementsDe($ticket));
        $this->assertSame(2, Paiement::query()->count());
        $this->assertSame([
            ['mode' => 'especes', 'reference' => null, 'montant' => 4000],
            ['mode' => 'carte_bancaire', 'reference' => 'CB-123', 'montant' => 4000],
        ], array_map(fn (array $reglement): array => array_intersect_key($reglement, array_flip(['mode', 'reference', 'montant'])), $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['reglements']));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function partsQuiNegalentPasLeReste(): array
    {
        return [
            '79,99 €' => [7999, 'Le total à payer a changé (crédit d\'avoir du client) : 0,01 € à répartir.'],
            '80,01 €' => [8001, 'Le total à payer a changé (crédit d\'avoir du client) : les parts le dépassent de 0,01 €.'],
        ];
    }

    #[DataProvider('partsQuiNegalentPasLeReste')]
    public function test_la_somme_des_parts_doit_egaler_le_reste_a_payer(int $carte, string $refus): void
    {
        $this->magasin();
        $lignes = self::article(40, 2);

        $this->assertThrows(fn () => self::vendre($this->comptoir(), $lignes, [new PartDeReglement(ModePaiement::CarteBancaire, $carte)]), LogicException::class, $refus);

        $this->assertSame([0, 0], [Facture::query()->count(), Paiement::query()->count()]);
    }

    /**
     * Un bon de 20,00 € puis 30,00 € en espèces et 30,00 € par carte
     * règlent un ticket de 80,00 € ; le bon est épuisé.
     */
    public function test_un_bon_d_avoir_et_deux_modes_reglent_un_meme_ticket(): void
    {
        $magasin = $this->magasin();
        $bon = $this->bonDAvoir(20);

        $ticket = self::vendre($this->comptoir(), self::article(80), [new PartDeReglement(ModePaiement::Especes, 3000), new PartDeReglement(ModePaiement::CarteBancaire, 3000)], [$bon->numero]);

        $this->assertSame([StatutFacture::Payee, 60.0, 20.0], [$ticket->statut, $ticket->montantPaye(), $ticket->montantCrediteParAvoir()]);
        $this->assertSame([['especes', '30.00'], ['carte_bancaire', '30.00']], self::paiementsDe($ticket));
        $this->assertSame(0, $bon->fresh()->creditEnCentimes());
        $this->assertSame([['avoir_id' => $bon->id, 'numero' => 'AV-MABOUT-2026-000001', 'montant' => 2000]], $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['credits_avoir']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Deux bons de 12,00 € et 20,00 € règlent un ticket de 25,00 €, dans
     * l'ordre saisi : le premier en entier, le second pour 13,00 €, et
     * garde 7,00 €.
     */
    public function test_deux_bons_d_avoir_reglent_une_meme_vente(): void
    {
        $magasin = $this->magasin();
        $premier = $this->bonDAvoir(12);
        $second = $this->bonDAvoir(20);

        $ticket = self::vendre(Client::factory()->create(), self::article(25), [], [$premier->numero, $second->numero]);

        $this->assertSame([StatutFacture::Payee, 0.0, 25.0], [$ticket->statut, $ticket->montantPaye(), $ticket->montantCrediteParAvoir()]);
        $this->assertSame([[$premier->id, '12.00'], [$second->id, '13.00']], $ticket->allocationsAvoir()->orderBy('id')->get()->map(fn (AvoirAllocation $allocation): array => [$allocation->avoir_id, $allocation->montant_alloue])->all());
        $this->assertSame([0, 700], [$premier->fresh()->creditEnCentimes(), $second->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Le crédit de 20,00 € de Mme Martin s'affiche comme le mode « Avoir »
     * de l'aperçu du règlement, et le ticket de 80,00 € l'impute d'office :
     * les parts règlent les 60,00 € qui restent.
     */
    public function test_le_credit_du_client_s_affiche_comme_avoir_et_s_impute_d_office(): void
    {
        $this->magasin();
        $martin = Client::factory()->create(['nom' => 'Mme Martin']);
        $achatRendu = self::vendreAuComptant(self::auteur(), $martin, self::article(20));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $apercu = ApercuDuReglement::aLaCaisse($martin, 8000, []);
        $ticket = self::vendre($martin, self::article(80), [new PartDeReglement(ModePaiement::Especes, 6000)]);

        $this->assertSame([8000, 2000, 6000], [$apercu->total, $apercu->creditDuClient, $apercu->resteARepartir]);
        $this->assertSame([StatutFacture::Payee, 60.0, 20.0], [$ticket->statut, $ticket->montantPaye(), $ticket->montantCrediteParAvoir()]);
        $this->assertSame([['avoir_id' => $avoir->id, 'numero' => 'AV-MABOUT-2026-000001', 'montant' => 2000]], $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['credits_avoir']);
    }

    /**
     * L'écran proposait 60,00 € (80,00 € moins 20,00 € de crédit) ; une
     * facture émise entre-temps a pris ce crédit : la vente est refusée,
     * le refus disant que le total a changé et ce qui reste à répartir,
     * sans rien enregistrer.
     */
    public function test_un_credit_consomme_entre_l_ecran_et_la_validation_refuse_la_vente_sans_rien_enregistrer(): void
    {
        $this->magasin();
        $martin = Client::factory()->create();
        $achatRendu = self::vendreAuComptant(self::auteur(), $martin, self::article(20));
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $achatRendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $partAffichee = ApercuDuReglement::aLaCaisse($martin, 8000, [])->resteARepartir;
        $autreVente = $this->factureDue($martin, 30);
        [$factures, $paiements] = [Facture::query()->count(), Paiement::query()->count()];

        $this->assertThrows(
            fn () => self::vendre($martin, self::article(80), [new PartDeReglement(ModePaiement::Especes, $partAffichee)]),
            LogicException::class,
            'Le total à payer a changé (crédit d\'avoir du client) : 20,00 € à répartir.',
        );

        $this->assertSame([$factures, $paiements], [Facture::query()->count(), Paiement::query()->count()]);
        $this->assertSame(20.0, $autreVente->fresh()->montantCrediteParAvoir());
    }

    /**
     * Pour 80,00 €, 30,00 € par carte et 50,00 € en espèces : seule la part
     * en espèces a un montant reçu, et la monnaie se rend sur elle seule.
     */
    public function test_seule_la_part_en_especes_rend_la_monnaie(): void
    {
        $magasin = $this->magasin();
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 80, 'taux_tva' => 20, 'suivi_stock' => false]);
        $caisse = Livewire::test(Caisse::class)
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ttc' => 80, 'taux_tva' => 20, 'remise_pourcentage' => 0]]]);
        $carte = (string) array_key_first($caisse->get('data.parts'));
        $caisse->set("data.parts.{$carte}.mode", ModePaiement::CarteBancaire->value)->set("data.parts.{$carte}.montant", '30');
        $caisse->callFormComponentAction('parts', 'add');
        $especes = (string) array_key_last($caisse->get('data.parts'));

        $caisse
            ->set("data.parts.{$especes}.mode", ModePaiement::Especes->value)
            ->set("data.parts.{$especes}.montant_recu", '60');

        $caisse
            ->assertFormSet(["parts.{$especes}.montant" => '50.00'])
            ->assertFormFieldIsHidden("parts.{$carte}.montant_recu")
            ->assertFormFieldIsVisible("parts.{$especes}.montant_recu")
            ->assertSee('10,00 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();
        $this->assertSame([['carte_bancaire', '30.00'], ['especes', '50.00']], self::paiementsDe(Facture::query()->where('numero', 'TK-MABOUT-2026-000001')->sole()));
    }

    public function test_une_facture_s_encaisse_en_deux_modes_d_un_seul_geste(): void
    {
        $magasin = $this->magasin();
        $facture = $this->factureDue(Client::factory()->create(), 100);

        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, [new PartDeReglement(ModePaiement::Especes, 4000), new PartDeReglement(ModePaiement::CarteBancaire, 6000, 'CB-9')], [], '2026-10-08');

        $this->assertSame([['especes', '40.00'], ['carte_bancaire', '60.00']], self::paiementsDe($facture));
        $this->assertSame(['2026-10-08', '2026-10-08'], Paiement::query()->orderBy('id')->get()->map(fn (Paiement $paiement): string => $paiement->recu_le->toDateString())->all());
        $this->assertSame([StatutFacture::Payee, 0.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Les parts ne dépassent jamais ce qui reste dû : un trop-perçu ne
     * devient pas un crédit. Un acompte reste permis.
     */
    public function test_les_parts_d_une_facture_ne_depassent_jamais_ce_qui_reste_du(): void
    {
        $this->magasin();
        $facture = $this->factureDue(Client::factory()->create(), 100);

        $this->assertThrows(
            fn () => app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, [new PartDeReglement(ModePaiement::Especes, 4000), new PartDeReglement(ModePaiement::Cheque, 6001)], [], '2026-10-09'),
            LogicException::class,
            'Les parts du règlement (100,01 €) dépassent ce qui reste dû (100,00 €).',
        );
        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, [new PartDeReglement(ModePaiement::Cheque, 3000)], [], '2026-10-09');

        $this->assertSame([StatutFacture::PartiellementPayee, 70.0, 1], [$facture->fresh()->statut, $facture->fresh()->montantRestant(), Paiement::query()->count()]);
    }

    /**
     * Règle « Vendeurs » : un vendeur sans « voir celles de toute l'équipe »
     * n'encaisse en plusieurs modes que ses propres factures.
     */
    public function test_un_vendeur_n_encaisse_en_plusieurs_modes_que_ses_factures(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $client = Client::factory()->create();
        $factureDuProprietaire = $this->factureDue($client, 50);
        $saFacture = $this->factureDue($client, 50, $vendeur);
        $parts = [new PartDeReglement(ModePaiement::Especes, 2000), new PartDeReglement(ModePaiement::CarteBancaire, 3000)];

        $this->assertThrows(fn () => app(EncaisserEnPlusieursModesAction::class)->handle($vendeur, $factureDuProprietaire, $parts, [], '2026-10-09'), AuthorizationException::class, LecteurDesDroits::REFUS);
        app(EncaisserEnPlusieursModesAction::class)->handle($vendeur, $saFacture, $parts, [], '2026-10-09');

        $this->assertSame([StatutFacture::Emise, StatutFacture::Payee], [$factureDuProprietaire->fresh()->statut, $saFacture->fresh()->statut]);
        $this->assertSame([$vendeur->id, $vendeur->id], Paiement::query()->pluck('cree_par')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * R3 : la même vente à la caisse, répétée avec le jeton de son panier,
     * rend le ticket déjà enregistré ; le même encaissement d'une facture,
     * répété avec son jeton, aussi : aucun second paiement.
     */
    public function test_la_meme_demande_rejouee_avec_le_meme_jeton_ne_cree_pas_de_second_paiement(): void
    {
        $this->magasin();
        $lignes = self::article(80);
        $parts = [new PartDeReglement(ModePaiement::Especes, 5000), new PartDeReglement(ModePaiement::CarteBancaire, 3000)];
        $jetonDeLaCaisse = (string) Str::uuid();
        $facture = $this->factureDue(Client::factory()->create(), 100);
        $jetonDeLEncaissement = (string) Str::uuid();

        $ticket = self::vendre($this->comptoir(), $lignes, $parts, jeton: $jetonDeLaCaisse);
        $rejoue = self::vendre($this->comptoir(), $lignes, $parts, jeton: $jetonDeLaCaisse);
        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, $parts, [], '2026-10-09', $jetonDeLEncaissement);
        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, $parts, [], '2026-10-09', $jetonDeLEncaissement);

        $this->assertSame($ticket->id, $rejoue->id);
        $this->assertSame(1, Facture::query()->whereKeyNot($facture->getKey())->count());
        $this->assertSame(4, Paiement::query()->count());
        $this->assertSame([StatutFacture::PartiellementPayee, 20.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
    }

    /**
     * R3 : le jeton de la caisse est lié à son panier et à son règlement :
     * le même jeton avec d'autres parts est refusé.
     */
    public function test_le_meme_jeton_avec_d_autres_parts_est_refuse(): void
    {
        $this->magasin();
        $lignes = self::article(80);
        $jeton = (string) Str::uuid();
        self::vendre($this->comptoir(), $lignes, [new PartDeReglement(ModePaiement::Especes, 8000)], jeton: $jeton);

        $this->assertThrows(fn () => self::vendre($this->comptoir(), $lignes, [new PartDeReglement(ModePaiement::Especes, 4000), new PartDeReglement(ModePaiement::CarteBancaire, 4000)], jeton: $jeton), LogicException::class, ActionsUniques::REFUS);

        $this->assertSame([1, 1], [Facture::query()->where('numero', 'like', 'TK-%')->count(), Paiement::query()->count()]);
    }

    /**
     * Point 1 : réglé par un bon d'avoir, le ticket ne reçoit aucun
     * paiement d'un mode : une imputation de crédit, signée avec lui.
     */
    public function test_un_reglement_par_avoir_ne_cree_aucun_paiement_d_un_mode(): void
    {
        $magasin = $this->magasin();
        $bon = $this->bonDAvoir(30);
        $paiementsAvant = Paiement::query()->count();

        $ticket = self::vendre($this->comptoir(), self::article(30), [], [$bon->numero]);

        $this->assertSame([StatutFacture::Payee, 0.0, 30.0], [$ticket->statut, $ticket->montantPaye(), $ticket->montantCrediteParAvoir()]);
        $this->assertSame($paiementsAvant, Paiement::query()->count());
        $this->assertSame([[null, '30.00']], $ticket->allocationsAvoir()->get()->map(fn (AvoirAllocation $allocation): array => [$allocation->paiement_id, $allocation->montant_alloue])->all());
        $this->assertSame([], $ticket->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['reglements']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Une part nulle ou négative, deux parts dans le même mode, le même bon
     * présenté deux fois et le prélèvement à la caisse sont refusés avant
     * tout enregistrement.
     *
     * @return array<string, array{0: Closure(): mixed, 1: string}>
     */
    public static function reglementsMalFormes(): array
    {
        return [
            'part nulle' => [fn () => new PartDeReglement(ModePaiement::Especes, 0), PartDeReglement::MONTANT_NON_POSITIF],
            'part négative' => [fn () => new PartDeReglement(ModePaiement::Especes, -500), PartDeReglement::MONTANT_NON_POSITIF],
            'même mode deux fois' => [fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 100), new PartDeReglement(ModePaiement::Especes, 100)]), 'Chaque mode ne règle qu\'une part du règlement.'],
            'même bon deux fois' => [fn () => new ReglementComptant([], ['AV-MABOUT-2026-000001', ' av-mabout-2026-000001 ']), PartDeReglement::BON_EN_DOUBLE],
            'prélèvement à la caisse' => [fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Prelevement, 100)]), 'Le prélèvement ne règle pas une vente à la caisse.'],
        ];
    }

    /**
     * @param  Closure(): mixed  $reglement
     */
    #[DataProvider('reglementsMalFormes')]
    public function test_un_reglement_mal_forme_est_refuse(Closure $reglement, string $refus): void
    {
        $this->assertThrows($reglement, LogicException::class, $refus);
    }

    /**
     * Q6 : un ticket du client comptoir resté dû accepte un bon d'avoir,
     * signé avec le paiement de la première part ; 20,00 € du bon et
     * 30,00 € par carte règlent les 50,00 € dus.
     */
    public function test_un_ticket_du_client_comptoir_reste_du_se_regle_avec_un_bon_d_avoir_et_une_part(): void
    {
        $magasin = $this->magasin();
        $bon = $this->bonDAvoir(20);
        $ticket = $this->factureDue($this->comptoir(), 50);

        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $ticket, [new PartDeReglement(ModePaiement::CarteBancaire, 3000)], [' av-mabout-2026-000001 '], '2026-10-09');

        $paiement = Paiement::query()->where('montant', '30.00')->sole();
        $this->assertSame([StatutFacture::Payee, 30.0, 20.0], [$ticket->fresh()->statut, $ticket->fresh()->montantPaye(), $ticket->fresh()->montantCrediteParAvoir()]);
        $this->assertSame([[$bon->id, $paiement->id, '20.00']], $ticket->allocationsAvoir()->get()->map(fn (AvoirAllocation $allocation): array => [$allocation->avoir_id, $allocation->paiement_id, $allocation->montant_alloue])->all());
        $this->assertSame(0, $bon->fresh()->creditEnCentimes());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Q6 : un bon d'avoir ne règle jamais une facture d'un client nommé
     * faite depuis « Factures », et seul un paiement d'un mode signe ce qui
     * règle un document après son émission : un bon se présente avec au
     * moins une part payée (limite écrite dans CLAUDE.md, en attendant la
     * décision de l'éditeur).
     */
    public function test_un_bon_d_avoir_ne_regle_hors_de_la_caisse_qu_un_ticket_du_client_comptoir_avec_une_part_payee(): void
    {
        $this->magasin();
        $bon = $this->bonDAvoir(20);
        $facture = $this->factureDue(Client::factory()->create(), 50);
        $ticket = $this->factureDue($this->comptoir(), 50);
        $paiementsAvant = Paiement::query()->count();

        $this->assertThrows(fn () => app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $facture, [new PartDeReglement(ModePaiement::Especes, 3000)], [$bon->numero], '2026-10-09'), LogicException::class, Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE);
        $this->assertThrows(fn () => app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $ticket, [], [$bon->numero], '2026-10-09'), LogicException::class, Avoir::BON_D_AVOIR_SANS_PART_PAYEE);

        $this->assertSame([$paiementsAvant, 2000], [Paiement::query()->count(), $bon->fresh()->creditEnCentimes()]);
        $this->assertSame([50.0, 50.0], [$facture->fresh()->montantRestant(), $ticket->fresh()->montantRestant()]);
    }

    /**
     * Un ticket du client comptoir doit encore 20,00 € : un bon de 30,00 €
     * ou de 20,00 € couvrirait tout ce reste, et aucune part payée ne
     * pourrait le signer (même 0,01 € dépasserait ce reste) ; le règlement
     * est refusé sans rien enregistrer, le ticket reste dû et le bon garde
     * son crédit.
     *
     * @return array<string, array{0: float, 1: list<int>}>
     */
    public static function bonsQuiCouvrentToutLeResteDu(): array
    {
        return [
            'bon de 30,00 € seul' => [30, []],
            'bon de 30,00 € et 0,01 €' => [30, [1]],
            'bon de 20,00 € et 0,01 €' => [20, [1]],
        ];
    }

    /**
     * @param  list<int>  $especes  les parts en espèces, en centimes
     */
    #[DataProvider('bonsQuiCouvrentToutLeResteDu')]
    public function test_un_bon_qui_couvre_tout_ce_que_doit_un_ticket_deja_emis_est_refuse_sans_rien_enregistrer(float $montantDuBon, array $especes): void
    {
        $magasin = $this->magasin();
        $bon = $this->bonDAvoir($montantDuBon);
        $ticket = $this->factureDue($this->comptoir(), 20);
        $paiementsAvant = Paiement::query()->count();

        $this->assertThrows(
            fn () => app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $ticket, array_map(fn (int $montant): PartDeReglement => new PartDeReglement(ModePaiement::Especes, $montant), $especes), [$bon->numero], '2026-10-09'),
            LogicException::class,
            Avoir::BON_D_AVOIR_SANS_PART_PAYEE,
        );

        $this->assertSame([$paiementsAvant, (int) ($montantDuBon * 100)], [Paiement::query()->count(), $bon->fresh()->creditEnCentimes()]);
        $this->assertSame([StatutFacture::Emise, 20.0], [$ticket->fresh()->statut, $ticket->fresh()->montantRestant()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un ticket du client comptoir doit 50,00 € ; il se règle d'un seul
     * geste par un bon de 20,00 €, 20,00 € par carte, première part, qui
     * signe le bon, et 10,00 € en espèces. Contre-passer la carte, saisie
     * dans le mauvais mode, rend au bon ses 20,00 € et le ticket redevient
     * dû de 40,00 € ; contre-passer plutôt les espèces laisse le bon imputé
     * et le ticket dû de 10,00 €. Comportement annoncé par la fenêtre de la
     * contre-passation, en attendant la décision de l'éditeur sur la
     * signature des bons présentés pour un ticket déjà émis.
     *
     * @return array<string, array{0: ModePaiement, 1: float, 2: int}>
     */
    public static function partsContrePassees(): array
    {
        return [
            'la carte, qui signe le bon' => [ModePaiement::CarteBancaire, 40.0, 2000],
            'les espèces' => [ModePaiement::Especes, 10.0, 0],
        ];
    }

    #[DataProvider('partsContrePassees')]
    public function test_contre_passer_la_part_qui_signe_le_bon_le_rend_et_contre_passer_une_autre_part_le_laisse_impute(ModePaiement $partErronee, float $resteDu, int $creditDuBon): void
    {
        $magasin = $this->magasin();
        $bon = $this->bonDAvoir(20);
        $ticket = $this->factureDue($this->comptoir(), 50);
        app(EncaisserEnPlusieursModesAction::class)->handle(self::auteur(), $ticket, [new PartDeReglement(ModePaiement::CarteBancaire, 2000), new PartDeReglement(ModePaiement::Especes, 1000)], [$bon->numero], '2026-10-09');
        $this->assertSame([StatutFacture::Payee, 0], [$ticket->fresh()->statut, $bon->fresh()->creditEnCentimes()]);

        $paiementErrone = Paiement::query()->where('mode_paiement', $partErronee)->whereHas('allocations', fn (Builder $allocations): Builder => $allocations->where('facture_id', $ticket->id))->sole();

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiementErrone, 'Saisi dans le mauvais mode');

        $this->assertSame([$resteDu, $creditDuBon], [$ticket->fresh()->montantRestant(), $bon->fresh()->creditEnCentimes()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }
}
