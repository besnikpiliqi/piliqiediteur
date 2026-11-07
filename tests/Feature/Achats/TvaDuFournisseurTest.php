<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Achats\Services\EcartsDUnTaux;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * La TVA d'une facture d'achat est celle qu'écrit la facture du fournisseur
 * (décision de l'éditeur du 02/11/2026, T1 et T2, voir TvaDuFournisseur) :
 * son PU HT, six décimales au plus, le HT de chaque ligne et la TVA de
 * chaque taux qu'elle écrit, acceptés sans rien demander dans la
 * tolérance (0,02 € par ligne pour le HT, 1 centime par ligne du taux pour
 * la TVA), confirmés au-delà, ce qui marque la facture « TVA à vérifier » ;
 * refusés seulement quand aucune facture valable ne les porterait. Sans
 * montant écrit, le calcul de PILIQI reste celui d'avant la décision.
 */
class TvaDuFournisseurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Sans montant écrit, rien ne change : chaque ligne reçoit la TVA
     * arrondie du cumul des HT de son taux, moins celle des lignes
     * précédentes (2,00 €, 2,00 € et 2,01 € pour 3 x 10,01 € HT), et le taux
     * garde la TVA que PILIQI calcule comme TVA du fournisseur, sans saisie
     * ni marque.
     */
    public function test_sans_montant_ecrit_la_facture_garde_le_calcul_de_piliqi(): void
    {
        $factureAchat = $this->creer([
            $this->ligne('COQUE-1', 1, '10.01'),
            $this->ligne('COQUE-2', 1, '10.01'),
            $this->ligne('COQUE-3', 1, '10.01'),
        ]);

        $this->assertSame([['10.01', null, '2.00'], ['10.01', null, '2.00'], ['10.01', null, '2.01']], $this->montantsDesLignes($factureAchat));
        $this->assertSame([['20.00', '30.03', '6.01', '6.01', null, null]], $this->montantsDesTaux($factureAchat));
        $this->assertSame(['30.03', '6.01', '36.04', false], [$factureAchat->total_ht, $factureAchat->total_tva, $factureAchat->total_ttc, $factureAchat->tva_a_verifier]);
        $this->assertSame([], $this->entreesDAudit($this->societe(), ActionAuditee::EcartsDuFournisseurConfirmes));
    }

    /**
     * Un PU HT s'écrit tel que le fournisseur l'écrit, six décimales au
     * plus, et se garde ainsi ; le HT proposé est la quantité x ce PU,
     * arrondi une fois : 1 000 x 0,1234 € font 123,40 €, le coût moyen du
     * produit 0,1234 € ; 3 x 8,333333 € font 25,00 €. Une septième décimale
     * est refusée, jamais arrondie en silence.
     */
    public function test_un_pu_ht_a_six_decimales_se_garde_tel_quel_et_fait_le_cout_moyen(): void
    {
        $vis = Produit::factory()->create(['reference' => 'VIS-M3', 'taux_tva' => 20, 'suivi_stock' => true]);
        $ecrous = Produit::factory()->create(['reference' => 'ECROU-M3', 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->creer([
            ['produit_id' => $vis->id, 'quantite' => 1000, 'prix_unitaire_ht' => '0.1234'],
            ['produit_id' => $ecrous->id, 'quantite' => 3, 'prix_unitaire_ht' => '8.333333'],
        ]);

        $this->assertSame(
            [['0.1234', '123.40', '24.68'], ['8.333333', '25.00', '5.00']],
            $factureAchat->lignes()->orderBy('ordre')->get()->map(fn (LigneAchat $ligne): array => [$ligne->prix_unitaire_ht, $ligne->montant_ht, $ligne->montant_tva])->all(),
        );

        app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);
        $this->assertSame([1000, '0.1234'], [$vis->fresh()->stock_quantite, $vis->fresh()->cmup_ht]);
        $this->assertSame([3, '8.3333'], [$ecrous->fresh()->stock_quantite, $ecrous->fresh()->cmup_ht]);

        $this->assertRefus(SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS, fn () => $this->creer([['produit_id' => $vis->id, 'quantite' => 1, 'prix_unitaire_ht' => '0.1234567']]));
        $this->assertSame(1, FactureAchat::query()->count());
    }

    /**
     * Le HT qu'écrit la facture pour une ligne remplace la proposition :
     * jusqu'à 0,02 € d'écart, sans rien demander ; à 0,03 €, rien ne
     * s'enregistre sans la confirmation, que le refus demande en disant
     * l'écart.
     */
    public function test_un_ht_de_ligne_s_accepte_jusqu_a_deux_centimes_d_ecart(): void
    {
        $dansLaTolerance = $this->creer([[...$this->ligne('COQUE-16', 3, '10.01'), 'montant_ht' => '30.05']]);

        $this->assertSame([['30.05', '30.05', '6.01']], $this->montantsDesLignes($dansLaTolerance));
        $this->assertSame([['20.00', '30.05', '6.01', '6.01', null, null]], $this->montantsDesTaux($dansLaTolerance));
        $this->assertFalse($dansLaTolerance->tva_a_verifier);

        try {
            $this->creer([[...$this->ligne('COQUE-16', 3, '10.01'), 'montant_ht' => '30.06']]);
            $this->fail('Un HT écrit à 0,03 € de la proposition demande une confirmation.');
        } catch (ConfirmationDesEcartsRequiseException $refus) {
            $this->assertSame(
                '« Coque COQUE-16 » : le HT saisi (30,06 €) s\'écarte de 0,03 € de la quantité × le PU HT, remise déduite (30,03 €). Vérifiez la facture. '.ConfirmationDesEcartsRequiseException::CONSIGNE,
                $refus->getMessage(),
            );
        }

        $this->assertSame(1, FactureAchat::query()->count(), 'Sans la confirmation, rien ne s\'enregistre.');
    }

    /**
     * La TVA qu'écrit la facture pour un taux s'accepte sans rien demander
     * jusqu'à 1 centime d'écart par ligne du taux (2 centimes pour 2
     * lignes), et la fiche garde les deux montants ; à 3 centimes, la
     * confirmation est demandée.
     */
    public function test_une_tva_s_accepte_jusqu_a_un_centime_par_ligne_du_taux(): void
    {
        $lignes = [$this->ligne('COQUE-1', 1, '10.00'), $this->ligne('COQUE-2', 1, '10.00')];
        $dansLaTolerance = $this->creer($lignes, $this->tva(['20' => '4.02']));

        $this->assertSame([['10.00', null, '2.01'], ['10.00', null, '2.01']], $this->montantsDesLignes($dansLaTolerance));
        $this->assertSame([['20.00', '20.00', '4.02', '4.00', '4.02', null]], $this->montantsDesTaux($dansLaTolerance));
        $this->assertSame(['20.00', '4.02', '24.02', false], [$dansLaTolerance->total_ht, $dansLaTolerance->total_tva, $dansLaTolerance->total_ttc, $dansLaTolerance->tva_a_verifier]);

        try {
            $this->creer($lignes, $this->tva(['20' => '4.03']));
            $this->fail('Une TVA à 3 centimes du calcul, pour 2 lignes, demande une confirmation.');
        } catch (ConfirmationDesEcartsRequiseException $refus) {
            $this->assertSame(
                'À 20 % : la TVA saisie (4,03 €) s\'écarte de 0,03 € de la base × taux (4,00 €). Vérifiez la facture. Si sa TVA est fausse, demandez une facture rectificative au fournisseur : une TVA facturée à tort n\'est pas déductible. '.ConfirmationDesEcartsRequiseException::CONSIGNE,
                $refus->getMessage(),
            );
            $this->assertSame([['taux' => 2000, 'base' => 2000, 'tva_saisie' => 403, 'tva_calculee' => 400, 'ecart' => 3, 'lignes' => []]], array_map(fn (EcartsDUnTaux $ecart): array => $ecart->enTableau(), $refus->ecarts));
        }

        $this->assertSame(1, FactureAchat::query()->count());
    }

    /**
     * Confirmé, l'écart s'enregistre : la TVA du fournisseur se répartit sur
     * les lignes au prorata de leur HT, par cumul ; la confirmation (son
     * auteur, son instant, l'empreinte de l'écart) reste sur le taux ; elle
     * s'inscrit au journal d'audit avec les montants et l'écart, et la
     * marque « TVA à vérifier », posée, aussi.
     */
    public function test_un_ecart_confirme_s_enregistre_marque_la_facture_et_s_inscrit_au_journal_d_audit(): void
    {
        $this->travelTo(self::aParis('2026-11-03 10:15:00'));
        $factureAchat = $this->creer(
            [$this->ligne('COQUE-1', 1, '10.00'), [...$this->ligne('COQUE-2', 1, '10.00'), 'montant_ht' => '10.05']],
            $this->tva(['20' => '4.03'], confirmation: true),
            ['numero_fournisseur' => 'F-2026-118'],
        );

        // Le calcul donne 2,00 € et 2,01 € (4,01 €) ; l'écart de 2 centimes
        // se répartit au prorata des HT, par cumul : 1 centime chacune.
        $this->assertSame([['10.00', null, '2.01'], ['10.05', '10.05', '2.02']], $this->montantsDesLignes($factureAchat));
        $this->assertSame([['20.00', '20.05', '4.03', '4.01', '4.03', (int) self::auteur()->id]], $this->montantsDesTaux($factureAchat));
        $this->assertTrue($factureAchat->tva_a_verifier);
        $taux = $factureAchat->taux()->sole();
        $this->assertSame([self::auteur()->name, self::aParis('2026-11-03 10:15:00')->getTimestamp()], [$taux->ecart_confirme_par_nom, $taux->ecart_confirme_le->getTimestamp()]);

        $document = ['nature' => 'facture_achat', 'numero' => null, 'numero_fournisseur' => 'F-2026-118'];
        $confirmations = $this->entreesDAudit($this->societe(), ActionAuditee::EcartsDuFournisseurConfirmes);
        $this->assertCount(1, $confirmations);
        $this->assertSame([
            'document' => $document,
            'ecarts' => [[
                'base' => 2005,
                'ecart' => 2,
                'lignes' => [['ecart' => 5, 'ht_calcule' => 1000, 'ht_saisi' => 1005, 'reference' => 'COQUE-2']],
                'taux' => 2000,
                'tva_calculee' => 401,
                'tva_saisie' => 403,
            ]],
        ], $confirmations[0]->nouvelles_valeurs);
        $this->assertSame([(int) self::auteur()->id, $factureAchat->getMorphClass(), (int) $factureAchat->id], [(int) $confirmations[0]->auteur_id, $confirmations[0]->cible_type, (int) $confirmations[0]->cible_id]);
        $marques = $this->entreesDAudit($this->societe(), ActionAuditee::TvaAVerifierPosee);
        $this->assertSame([['document' => $document]], array_map(fn ($entree): array => $entree->nouvelles_valeurs, $marques));
    }

    /**
     * Le même écart, enregistré de nouveau sans la confirmation, ne se
     * reconfirme pas : sa confirmation reste, rien de plus ne s'inscrit au
     * journal d'audit. Un écart différent se confirme de nouveau. Corrigée
     * dans la tolérance, la facture perd sa confirmation et sa marque, dont
     * la levée s'inscrit au journal d'audit.
     */
    public function test_le_meme_ecart_ne_se_reconfirme_pas_et_la_marque_se_leve_dans_la_tolerance(): void
    {
        $lignes = [$this->ligne('COQUE-1', 1, '10.00'), $this->ligne('COQUE-2', 1, '10.00')];
        $factureAchat = $this->creer($lignes, $this->tva(['20' => '4.10'], confirmation: true));

        $factureAchat = $this->modifier($factureAchat, $lignes, $this->tva(['20' => '4.10']));
        $this->assertTrue($factureAchat->tva_a_verifier);
        $this->assertNotNull($factureAchat->taux()->sole()->ecart_confirme_empreinte);
        $this->assertCount(1, $this->entreesDAudit($this->societe(), ActionAuditee::EcartsDuFournisseurConfirmes));

        $this->assertRefusDeConfirmation(fn () => $this->modifier($factureAchat, $lignes, $this->tva(['20' => '4.20'])));
        $this->modifier($factureAchat, $lignes, $this->tva(['20' => '4.20'], confirmation: true));
        $this->assertCount(2, $this->entreesDAudit($this->societe(), ActionAuditee::EcartsDuFournisseurConfirmes));

        $corrigee = $this->modifier($factureAchat, $lignes, $this->tva(['20' => '4.01']));
        $this->assertFalse($corrigee->tva_a_verifier);
        $this->assertSame([['20.00', '20.00', '4.01', '4.00', '4.01', null]], $this->montantsDesTaux($corrigee));
        $this->assertNull($corrigee->taux()->sole()->ecart_confirme_empreinte);
        $this->assertCount(1, $this->entreesDAudit($this->societe(), ActionAuditee::TvaAVerifierPosee));
        $this->assertCount(1, $this->entreesDAudit($this->societe(), ActionAuditee::TvaAVerifierLevee));
    }

    /**
     * L'écart se répartit sur les lignes au prorata de leur HT, par cumul,
     * dans leur ordre ; leur somme vaut toujours exactement la TVA retenue,
     * et aucune ligne ne reçoit une TVA négative ni plus grande que son HT :
     * une TVA égale à la base donne à chaque ligne son HT, une TVA nulle
     * n'en donne à aucune.
     */
    public function test_la_tva_retenue_se_repartit_sans_ligne_negative_ni_au_dela_de_son_ht(): void
    {
        $lignes = [$this->ligne('A', 1, '100.00'), $this->ligne('B', 1, '0.01'), $this->ligne('C', 1, '50.00')];

        $moins = $this->creer($lignes, $this->tva(['20' => '29.00'], confirmation: true));
        $this->assertSame([['100.00', null, '19.33'], ['0.01', null, '0.00'], ['50.00', null, '9.67']], $this->montantsDesLignes($moins));
        $this->assertSame('29.00', $moins->total_tva);

        $egaleALaBase = $this->creer($lignes, $this->tva(['20' => '150.01'], confirmation: true));
        $this->assertSame([['100.00', null, '100.00'], ['0.01', null, '0.01'], ['50.00', null, '50.00']], $this->montantsDesLignes($egaleALaBase));

        $nulle = $this->creer($lignes, $this->tva(['20' => '0.00'], confirmation: true));
        $this->assertSame([['100.00', null, '0.00'], ['0.01', null, '0.00'], ['50.00', null, '0.00']], $this->montantsDesLignes($nulle));
    }

    /**
     * Seul ce qu'aucune facture valable ne porterait est refusé : une TVA
     * négative, une TVA à 0 %, une TVA plus grande que sa base, la TVA d'un
     * taux sans ligne, un montant plus précis que le centime, un total TTC
     * de contrôle qui ne vaut pas HT + TVA, une base écrite (l'API) qui
     * n'est pas la somme des HT de ses lignes. Rien ne s'enregistre.
     */
    public function test_les_montants_qu_aucune_facture_valable_ne_porte_sont_refuses(): void
    {
        $vingt = [$this->ligne('A', 1, '10.00')];
        $zero = [[...$this->ligne('B', 1, '10.00'), 'taux_tva' => 0]];

        $this->assertRefus('La TVA à 20 % ne peut pas être négative.', fn () => $this->creer($vingt, $this->tva(['20' => '-0.01'])));
        $this->assertRefus('Une ligne à 0 % n\'a pas de TVA : la TVA à 0 % vaut 0,00 €.', fn () => $this->creer($zero, $this->tva(['0' => '0.50'])));
        $this->assertRefus('La TVA à 20 % (10,01 €) ne peut pas dépasser sa base (10,00 €).', fn () => $this->creer($vingt, $this->tva(['20' => '10.01'], confirmation: true)));
        $this->assertRefus('Aucune ligne de la facture n\'est à 5,5 % : sa TVA ne se saisit pas.', fn () => $this->creer($vingt, $this->tva(['5.5' => '0.55'])));
        $this->assertRefus(SaisieDecimale::REFUS_MONTANT_TROP_PRECIS, fn () => $this->creer($vingt, $this->tva(['20' => '2.005'])));
        $this->assertRefus(SaisieDecimale::REFUS_MONTANT_TROP_PRECIS, fn () => $this->creer([[...$this->ligne('A', 1, '10.00'), 'montant_ht' => '10.001']]));
        $this->assertRefus(
            'Le total TTC saisi (12,01 €) ne vaut pas le total HT plus la TVA de la facture (10,00 € + 2,00 € = 12,00 €) : vérifiez les montants saisis.',
            fn () => $this->creer($vingt, MontantsDuFournisseur::depuisLaSaisie(['total_ttc' => '12.01'])),
        );
        $this->assertRefus(
            'La base à 20 % écrite sur la facture (10,01 €) n\'est pas la somme des HT de ses lignes (10,00 €) : saisissez le HT de chaque ligne tel que la facture l\'écrit.',
            fn () => $this->creer($vingt, MontantsDuFournisseur::depuisLaSaisie(['taux' => [['taux_tva' => '20', 'base_ht' => '10.01', 'montant_tva' => '2.00']]]), ['source' => SourceFactureAchat::Api]),
        );

        $this->assertSame(0, FactureAchat::query()->count());
        $this->assertSame(0, TauxFactureAchat::query()->count());
    }

    /**
     * L'API recopie la base et la TVA de chaque taux et le total TTC de la
     * facture reçue ; dans la tolérance, elle s'enregistre sans rien
     * demander. Au-delà, sans la confirmation, sa demande reçoit une
     * erreur 422 qui dit chaque écart ; confirmée, la facture est marquée.
     */
    public function test_l_api_recopie_les_montants_du_fournisseur_et_recoit_une_erreur_422_sans_confirmation(): void
    {
        $lignes = [[...$this->ligne('A', 3, '10.01'), 'montant_ht' => '30.03']];
        $recue = $this->creer($lignes, MontantsDuFournisseur::depuisLaSaisie([
            'taux' => [['taux_tva' => '20.00', 'base_ht' => '30.03', 'montant_tva' => '6.01']],
            'total_ttc' => '36.04',
        ]), ['source' => SourceFactureAchat::Api]);
        $this->assertSame([['20.00', '30.03', '6.01', '6.01', '6.01', null]], $this->montantsDesTaux($recue));

        $ecrite = ['taux' => [['taux_tva' => '20.00', 'base_ht' => '30.03', 'montant_tva' => '6.50']], 'total_ttc' => '36.53'];

        try {
            $this->creer($lignes, MontantsDuFournisseur::depuisLaSaisie($ecrite), ['source' => SourceFactureAchat::Api]);
            $this->fail('Une TVA reçue au-delà de la tolérance demande une confirmation.');
        } catch (ConfirmationDesEcartsRequiseException $refus) {
            $reponse = $refus->render(Request::create('/api/factures-achat', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
            $this->assertSame(422, $reponse?->getStatusCode());
            $this->assertSame([
                'message' => $refus->getMessage(),
                'ecarts' => [['taux' => 2000, 'base' => 3003, 'tva_saisie' => 650, 'tva_calculee' => 601, 'ecart' => 49, 'lignes' => []]],
            ], $reponse->getData(true));
            $this->assertNull($refus->render(Request::create('/app')), 'Hors de l\'API, l\'écran dit le refus.');
        }

        $confirmee = $this->creer($lignes, MontantsDuFournisseur::depuisLaSaisie([...$ecrite, 'confirmation' => true]), ['source' => SourceFactureAchat::Api]);
        $this->assertSame(['6.50', '36.53', true], [$confirmee->total_tva, $confirmee->total_ttc, $confirmee->tva_a_verifier]);
    }

    /**
     * La validation d'une facture d'achat s'inscrit au journal d'audit avec
     * ses montants retenus : notre numéro, celui du fournisseur, ses
     * totaux, la TVA de chaque taux, sa marque et chaque ligne ; ni le
     * fournisseur ni les notes.
     */
    public function test_la_validation_d_une_facture_d_achat_s_inscrit_au_journal_d_audit(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->creer(
            [$this->ligne('COQUE-16', 3, '10.01')],
            attributs: ['numero_fournisseur' => 'F-2026-118', 'notes' => 'Livraison partielle'],
        ));

        $entrees = $this->entreesDAudit($this->societe(), ActionAuditee::FactureAchatValidee);
        $this->assertCount(1, $entrees);
        $this->assertSame([null, [
            'facture_achat' => $factureAchat->numero,
            'lignes' => [self::ligneAuditee('30.03', null, '6.01')],
            'numero_fournisseur' => 'F-2026-118',
            'taux' => [['base_ht' => '30.03', 'montant_tva' => '6.01', 'montant_tva_calcule' => '6.01', 'montant_tva_saisi' => null, 'taux_tva' => '20.00']],
            'total_ht' => '30.03',
            'total_ttc' => '36.04',
            'total_tva' => '6.01',
            'tva_a_verifier' => false,
        ]], [$entrees[0]->anciennes_valeurs, $entrees[0]->nouvelles_valeurs]);
    }

    /**
     * Corriger une facture d'achat validée ne réécrit jamais sans trace sa
     * base ni sa TVA (CGI, art. 271, II-3) : la correction s'inscrit au
     * journal d'audit avec les anciennes et les nouvelles valeurs de ce
     * qui a changé, lignes, TVA de chaque taux et totaux.
     */
    public function test_la_correction_d_une_facture_d_achat_validee_s_inscrit_avec_ses_anciennes_et_nouvelles_valeurs(): void
    {
        $lignes = [$this->ligne('COQUE-16', 3, '10.01')];
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->creer($lignes));

        $this->modifier($factureAchat, [[...$lignes[0], 'montant_ht' => '30.04']], $this->tva(['20' => '6.01']));

        $entrees = $this->entreesDAudit($this->societe(), ActionAuditee::FactureAchatModifiee);
        $this->assertCount(1, $entrees);
        $this->assertSame([
            'lignes' => [self::ligneAuditee('30.03', null, '6.01')],
            'taux' => [['base_ht' => '30.03', 'montant_tva' => '6.01', 'montant_tva_calcule' => '6.01', 'montant_tva_saisi' => null, 'taux_tva' => '20.00']],
            'total_ht' => '30.03',
            'total_ttc' => '36.04',
        ], $entrees[0]->anciennes_valeurs);
        $this->assertSame([
            'lignes' => [self::ligneAuditee('30.04', '30.04', '6.01')],
            'taux' => [['base_ht' => '30.04', 'montant_tva' => '6.01', 'montant_tva_calcule' => '6.01', 'montant_tva_saisi' => '6.01', 'taux_tva' => '20.00']],
            'total_ht' => '30.04',
            'total_ttc' => '36.05',
        ], $entrees[0]->nouvelles_valeurs);
    }

    /**
     * Le coût unitaire d'un inventaire d'ouverture s'écrit, comme un prix
     * d'achat, avec six décimales au plus, et se garde ainsi : 10 articles
     * à 0,123456 € valent 1,23 € de stock, et le coût moyen du produit né
     * de l'inventaire est 0,1235 €, au dix-millième. Une septième décimale
     * est refusée.
     */
    public function test_le_cout_d_un_inventaire_d_ouverture_se_garde_a_six_decimales(): void
    {
        $ligne = ['produit' => ['nom' => 'Rondelle', 'reference' => 'RONDELLE', 'prix_vente_ttc' => '0.50', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '0.123456'];
        $inventaire = app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [$ligne]));

        $this->assertSame(['0.123456', '1.23'], [$inventaire->lignes->sole()->cout_unitaire_ht, $inventaire->valeur_ht]);
        $this->assertSame('0.1235', Produit::query()->where('reference', 'RONDELLE')->sole()->cmup_ht);

        $this->assertRefus(SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS, fn () => app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [[...$ligne, 'cout_unitaire_ht' => '0.1234567']]));
    }

    /**
     * Une ligne d'achat de la référence donnée (un produit du catalogue au
     * taux de 20 %), sa quantité et son PU HT.
     *
     * @return array{produit_id: int, quantite: int, prix_unitaire_ht: string}
     */
    private function ligne(string $reference, int $quantite, string $prixUnitaireHt): array
    {
        $produit = Produit::query()->where('reference', $reference)->first()
            ?? Produit::factory()->create(['nom' => "Coque {$reference}", 'reference' => $reference, 'taux_tva' => 20, 'suivi_stock' => false]);

        return ['produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire_ht' => $prixUnitaireHt];
    }

    /**
     * La TVA écrite de chaque taux (« 20 » => « 4.02 »).
     *
     * @param  array<string, string>  $tvaParTaux
     */
    private function tva(array $tvaParTaux, bool $confirmation = false): MontantsDuFournisseur
    {
        return MontantsDuFournisseur::depuisLaSaisie([
            'taux' => array_map(fn (string $taux, string $tva): array => ['taux_tva' => $taux, 'montant_tva' => $tva], array_keys($tvaParTaux), $tvaParTaux),
            'confirmation' => $confirmation,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function creer(array $lignes, ?MontantsDuFournisseur $montants = null, array $attributs = []): FactureAchat
    {
        return app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), $lignes, $attributs, $montants);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function modifier(FactureAchat $factureAchat, array $lignes, MontantsDuFournisseur $montants): FactureAchat
    {
        return app(ModifierFactureAchatAction::class)->handle(self::auteur(), $factureAchat, $factureAchat->fournisseur, $lignes, [], $montants);
    }

    /**
     * Le HT, le HT écrit et la TVA de chaque ligne, dans leur ordre.
     *
     * @return list<array{0: string, 1: string|null, 2: string}>
     */
    private function montantsDesLignes(FactureAchat $factureAchat): array
    {
        return $factureAchat->lignes()->orderBy('ordre')->get()
            ->map(fn (LigneAchat $ligne): array => [$ligne->montant_ht, $ligne->montant_ht_saisi, $ligne->montant_tva])
            ->all();
    }

    /**
     * Le taux, la base, la TVA retenue, la TVA calculée, la TVA saisie et
     * l'auteur de la confirmation de chaque taux, dans leur ordre.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: int|null}>
     */
    private function montantsDesTaux(FactureAchat $factureAchat): array
    {
        return $factureAchat->taux()->orderBy('ordre')->get()
            ->map(fn (TauxFactureAchat $taux): array => [$taux->taux_tva, $taux->base_ht, $taux->montant_tva, $taux->montant_tva_calcule, $taux->montant_tva_saisi, $taux->ecart_confirme_par === null ? null : (int) $taux->ecart_confirme_par])
            ->all();
    }

    /**
     * La ligne COQUE-16 (3 x 10,01 € HT) telle que le journal d'audit la
     * retient, avec son HT retenu, son HT écrit et sa TVA.
     *
     * @return array<string, mixed>
     */
    private static function ligneAuditee(string $montantHt, ?string $montantHtSaisi, string $montantTva): array
    {
        return [
            'montant_ht' => $montantHt,
            'montant_ht_saisi' => $montantHtSaisi,
            'montant_tva' => $montantTva,
            'prix_unitaire_ht' => '10.01',
            'quantite' => 3,
            'reference' => 'COQUE-16',
            'remise_pourcentage' => '0.00',
            'taux_tva' => '20.00',
        ];
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

    private function assertRefusDeConfirmation(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Une confirmation était attendue.');
        } catch (ConfirmationDesEcartsRequiseException $refus) {
            $this->assertNotSame([], $refus->ecarts);
        }
    }
}
