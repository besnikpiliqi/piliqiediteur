<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Un avoir fournisseur garde les montants de la note d'avoir du
 * fournisseur, qui prime (décision de l'éditeur du 02/11/2026, T1 ;
 * BOI-TVA-DECLA-30-20-20-20 § 260, voir ChiffrageDUnAvoirFournisseur) :
 * PILIQI propose le HT et la TVA de chaque retour, la note d'avoir les
 * remplace, avec les mêmes tolérances qu'une facture d'achat, la même
 * confirmation et la même marque au-delà ; l'avoir ne rend jamais plus que
 * la TVA restante du taux sur la facture d'achat, tolérance comprise.
 */
class TvaDesAvoirsFournisseurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Le HT d'une ligne et la TVA d'un taux qu'écrit la note d'avoir
     * s'acceptent sans rien demander dans la tolérance (0,02 € et 1
     * centime pour une ligne) : l'avoir les garde, et la TVA proposée par
     * PILIQI reste pour la preuve.
     */
    public function test_la_note_d_avoir_du_fournisseur_prime_dans_la_tolerance(): void
    {
        $factureAchat = $this->recevoir(5);

        $avoir = $this->retourner($factureAchat, [['quantite' => 2, 'montant_ht' => '40.02']], $this->tva('8.01'));

        $this->assertSame([['40.02', '40.02', '8.01', '48.03']], $this->montantsDesLignes($avoir));
        $this->assertSame([['20.00', '40.02', '8.01', '8.00', '8.01', null]], $this->montantsDesTaux($avoir));
        $this->assertSame(['40.02', '8.01', '48.03', false], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc, $avoir->tva_a_verifier]);
    }

    /**
     * Au-delà de la tolérance, rien n'est émis sans la confirmation, et le
     * refus ne laisse aucun brouillon ; confirmé, l'avoir garde le HT de
     * la note d'avoir et la TVA que PILIQI propose pour ce HT (9,00 € pour
     * 45,00 €), est marqué « TVA à vérifier », et la confirmation, la
     * marque et l'avoir s'inscrivent au journal d'audit.
     */
    public function test_un_ecart_au_dela_de_la_tolerance_se_confirme_et_marque_l_avoir(): void
    {
        $factureAchat = $this->recevoir(5);

        try {
            $this->retourner($factureAchat, [['quantite' => 2, 'montant_ht' => '45.00']]);
            $this->fail('Un HT écrit à 5,00 € de la proposition demande une confirmation.');
        } catch (ConfirmationDesEcartsRequiseException $refus) {
            $this->assertSame(
                '« Câble USB-C » : le HT saisi (45,00 €) s\'écarte de 5,00 € de la quantité × le PU HT, remise déduite (40,00 €). Vérifiez la facture. '.ConfirmationDesEcartsRequiseException::CONSIGNE,
                $refus->getMessage(),
            );
        }

        $this->assertSame(0, AvoirFournisseur::query()->count());

        $avoir = $this->retourner($factureAchat, [['quantite' => 2, 'montant_ht' => '45.00']], MontantsDuFournisseur::aucun()->avecLaConfirmation(true));

        $this->assertSame([['45.00', '45.00', '9.00', '54.00']], $this->montantsDesLignes($avoir));
        $this->assertSame([['20.00', '45.00', '9.00', '9.00', null, (int) self::auteur()->id]], $this->montantsDesTaux($avoir));
        $this->assertTrue($avoir->tva_a_verifier);

        $document = ['nature' => 'avoir_fournisseur', 'numero' => $avoir->numero, 'numero_fournisseur' => 'AV-12'];
        $this->assertSame([[
            'document' => $document,
            'ecarts' => [[
                'base' => 4500,
                'ecart' => 0,
                'lignes' => [['ecart' => 500, 'ht_calcule' => 4000, 'ht_saisi' => 4500, 'reference' => 'CABLE-USB']],
                'taux' => 2000,
                'tva_calculee' => 900,
                'tva_saisie' => 900,
            ]],
        ]], $this->valeursAuditees(ActionAuditee::EcartsDuFournisseurConfirmes));
        $this->assertSame([['document' => $document]], $this->valeursAuditees(ActionAuditee::TvaAVerifierPosee));
        $emis = $this->valeursAuditees(ActionAuditee::AvoirFournisseurEmis);
        $this->assertSame([true, [['base_ht' => '45.00', 'montant_tva' => '9.00', 'montant_tva_calcule' => '9.00', 'montant_tva_saisi' => null, 'taux_tva' => '20.00']]], [$emis[0]['tva_a_verifier'], $emis[0]['taux']]);
    }

    /**
     * La TVA que PILIQI propose suit le HT qu'écrit la note d'avoir, comme
     * celle d'une facture d'achat suit sa base, et PILIQI ne refuse jamais
     * sa propre proposition : 2 câbles proposés 40,00 € + 8,00 €, que la
     * note écrit 5,00 € HT, proposent 1,00 € de TVA, sans aucune TVA
     * saisie, et l'avoir s'émet une fois l'écart du HT confirmé. Le retour
     * suivant part de ce qui a été rendu : les 3 derniers câbles proposent
     * ce qui reste de la ligne (95,00 € + 19,00 €).
     */
    public function test_la_tva_proposee_suit_le_ht_qu_ecrit_la_note_d_avoir(): void
    {
        $factureAchat = $this->recevoir(5);

        $avoir = $this->retourner($factureAchat, [['quantite' => 2, 'montant_ht' => '5.00']], MontantsDuFournisseur::aucun()->avecLaConfirmation(true));

        $this->assertSame([['5.00', '5.00', '1.00', '6.00']], $this->montantsDesLignes($avoir));
        $this->assertSame([['20.00', '5.00', '1.00', '1.00', null, (int) self::auteur()->id]], $this->montantsDesTaux($avoir));
        $this->assertSame(['5.00', '1.00', '6.00', true], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc, $avoir->tva_a_verifier]);

        $reste = $this->retourner($factureAchat, [['quantite' => 3]]);

        $this->assertSame([['95.00', null, '19.00', '114.00']], $this->montantsDesLignes($reste));
        $this->assertSame(['95.00', '19.00', false], [$reste->total_ht, $reste->total_tva, $reste->tva_a_verifier]);
    }

    /**
     * Un avoir ne rend jamais plus que la TVA restante du taux sur la
     * facture d'achat (ses 20,00 € moins les 8,00 € déjà rendus), tolérance
     * de 1 centime par ligne de l'avoir comprise : 12,02 € est refusé,
     * 12,01 € s'accepte. Il n'a pas à égaler ce reste : une note d'avoir
     * qui en rend moins se confirme.
     */
    public function test_un_avoir_ne_rend_jamais_plus_que_la_tva_restante_du_taux(): void
    {
        $factureAchat = $this->recevoir(5);
        $this->retourner($factureAchat, [['quantite' => 2]]);

        try {
            $this->retourner($factureAchat, [['quantite' => 3]], $this->tva('12.02'));
            $this->fail('Rendre 12,02 € de TVA quand il en reste 12,00 € est refusé.');
        } catch (LogicException $refus) {
            $this->assertSame(
                sprintf(EmettreAvoirFournisseurAction::REFUS_TVA_EN_TROP, '12,02 €', '20 %', '12,00 €', $factureAchat->numero, '0,01 €'),
                $refus->getMessage(),
            );
        }

        $dernier = $this->retourner($factureAchat, [['quantite' => 3]], $this->tva('12.01'));
        $this->assertSame(['60.00', '12.01', false], [$dernier->total_ht, $dernier->total_tva, $dernier->tva_a_verifier]);

        $moins = $this->retourner($this->recevoir(5), [['quantite' => 5]], $this->tva('19.00', confirmation: true));
        $this->assertSame(['100.00', '19.00', true], [$moins->total_ht, $moins->total_tva, $moins->tva_a_verifier]);
    }

    /**
     * La TVA d'un taux auquel l'avoir n'a aucune ligne ne se saisit pas :
     * rien ne s'enregistre.
     */
    public function test_une_tva_ecrite_pour_un_taux_sans_ligne_de_l_avoir_est_refusee(): void
    {
        $factureAchat = $this->recevoir(5);

        $this->assertThrows(
            fn () => $this->retourner($factureAchat, [['quantite' => 1]], MontantsDuFournisseur::depuisLaSaisie(['taux' => [['taux_tva' => '5.5', 'montant_tva' => '0.22']]])),
            InvalidArgumentException::class,
            'Aucune ligne de la facture n\'est à 5,5 % : sa TVA ne se saisit pas.',
        );
        $this->assertSame(0, AvoirFournisseur::query()->count());
    }

    /**
     * Le brouillon garde ce qu'écrit la note d'avoir (le HT d'une ligne, la
     * TVA d'un taux) ; son émission les retient, et arrête ses montants.
     */
    public function test_le_brouillon_garde_la_note_d_avoir_que_l_emission_retient(): void
    {
        $factureAchat = $this->recevoir(5);
        $brouillon = app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [
            ['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 2, 'montant_ht' => '40.01'],
        ], 'Produit défectueux', 'AV-12', $this->tva('8.01'));

        $this->assertSame([['40.01', '40.01', '8.01', '48.02']], $this->montantsDesLignes($brouillon));
        $this->assertSame([['20.00', '40.01', '8.01', '8.00', '8.01', null]], $this->montantsDesTaux($brouillon));

        $avoir = app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), $brouillon);

        $this->assertNotNull($avoir->numero);
        $this->assertSame([['40.01', '40.01', '8.01', '48.02']], $this->montantsDesLignes($avoir));
        $this->assertSame(['40.01', '8.01', '48.02'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
    }

    /**
     * Un HT négatif, un total TTC de contrôle qui ne vaut pas HT + TVA : ce
     * qu'aucune note d'avoir valable ne porte est refusé.
     */
    public function test_ce_qu_aucune_note_d_avoir_valable_ne_porte_est_refuse(): void
    {
        $factureAchat = $this->recevoir(5);

        $this->assertRefus(CalculateurLigne::REFUS_HT_NEGATIF, fn () => $this->retourner($factureAchat, [['quantite' => 2, 'montant_ht' => '-1.00']]));
        $this->assertRefus(
            'Le total TTC saisi (48,01 €) ne vaut pas le total HT plus la TVA de la facture (40,00 € + 8,00 € = 48,00 €) : vérifiez les montants saisis.',
            fn () => $this->retourner($factureAchat, [['quantite' => 2]], MontantsDuFournisseur::depuisLaSaisie(['total_ttc' => '48.01'])),
        );
        $this->assertSame(0, AvoirFournisseur::query()->count());
    }

    /**
     * Une facture d'achat validée de 5 câbles à 20,00 € HT (20 %) : 100,00 €
     * HT et 20,00 € de TVA.
     */
    private function recevoir(int $quantite): FactureAchat
    {
        $produit = Produit::query()->where('reference', 'CABLE-USB')->first()
            ?? Produit::factory()->create(['nom' => 'Câble USB-C', 'reference' => 'CABLE-USB', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => '20.00'],
        ], ['date_facture' => '2026-01-15']));
    }

    /**
     * Crée et émet un avoir sur la seule ligne de la facture d'achat, comme
     * l'écran (voir EnregistrerAvoirFournisseurAction).
     *
     * @param  list<array{quantite: int, montant_ht?: string}>  $retours
     */
    private function retourner(FactureAchat $factureAchat, array $retours, ?MontantsDuFournisseur $montants = null): AvoirFournisseur
    {
        $ligneAchat = $factureAchat->lignes()->sole();

        return app(EnregistrerAvoirFournisseurAction::class)->handle(
            self::auteur(),
            $factureAchat,
            array_map(fn (array $retour): array => ['ligne_achat_id' => $ligneAchat->id, ...$retour], $retours),
            'Produit défectueux',
            'AV-12',
            null,
            $montants,
        );
    }

    private function tva(string $montant, bool $confirmation = false): MontantsDuFournisseur
    {
        return MontantsDuFournisseur::depuisLaSaisie(['taux' => [['taux_tva' => '20', 'montant_tva' => $montant]], 'confirmation' => $confirmation]);
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: string, 3: string}>
     */
    private function montantsDesLignes(AvoirFournisseur $avoir): array
    {
        return $avoir->lignes()->orderBy('ordre')->get()
            ->map(fn (LigneAvoirFournisseur $ligne): array => [$ligne->montant_ht, $ligne->montant_ht_saisi, $ligne->montant_tva, $ligne->montant_ttc])
            ->all();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: int|null}>
     */
    private function montantsDesTaux(AvoirFournisseur $avoir): array
    {
        return $avoir->taux()->orderBy('ordre')->get()
            ->map(fn (TauxAvoirFournisseur $taux): array => [$taux->taux_tva, $taux->base_ht, $taux->montant_tva, $taux->montant_tva_calcule, $taux->montant_tva_saisi, $taux->ecart_confirme_par === null ? null : (int) $taux->ecart_confirme_par])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valeursAuditees(ActionAuditee $action): array
    {
        return array_map(fn ($entree): array => $entree->nouvelles_valeurs, $this->entreesDAudit($this->societe(), $action));
    }

    private function societe(): Societe
    {
        return app(TenantContext::class)->magasin()->societe;
    }

    private function assertRefus(string $message, Closure $operation): void
    {
        try {
            $operation();
            $this->fail("Refus attendu : {$message}");
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());
        }
    }
}
