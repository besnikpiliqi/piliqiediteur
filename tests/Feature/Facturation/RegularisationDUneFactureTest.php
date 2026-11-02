<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FaireLAvoirDeRegularisationAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\AvoirResource\Pages\CreateAvoir;
use App\Filament\App\Resources\AvoirResource\Pages\ViewAvoir;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La régularisation d'une facture après la correction exceptionnelle du
 * SIREN de la société par l'éditeur (voir RegularisationDUneFacture ;
 * décision de l'éditeur du 01/11/2026, réponse 1a) : seules les factures
 * que désigne le dernier examen se régularisent, une fois ; l'avoir de
 * régularisation se fait comme tout avoir (le propriétaire compris), la
 * facture rectificative par un compte autorisé à vendre, jamais par le
 * propriétaire ni par un compte d'un cabinet lié ; ni l'un ni l'autre ne
 * bouge le stock ; le crédit de l'avoir ne règle que la facture puis sa
 * rectificative, quel que soit l'ordre des gestes, sans jamais faire payer
 * deux fois ni rien déclarer comme un encaissement. La concurrence est
 * essayée dans RegularisationDUneFactureConcurrenceTest.
 */
class RegularisationDUneFactureTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'SIREN d\'une filiale saisi à la place de celui de la société';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
    }

    /**
     * L'avoir de régularisation, fait par le propriétaire, imprime
     * l'émetteur que la facture a signé (l'ancien SIREN et l'ancien SIRET)
     * et la mention de la régularisation avec le SIREN correct, dans le
     * pied de son PDF ; la facture rectificative, émise par le responsable,
     * imprime l'identité corrigée et la mention de la facture qu'elle
     * rectifie, la date de livraison de l'opération d'origine ; l'une et
     * l'autre se signent au registre, qui reste intègre.
     */
    public function test_l_avoir_de_regularisation_porte_l_emetteur_choisi_par_l_editeur(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $ancienSiren = (string) $facture->emetteur()->siren;
        $ancienSiret = (string) $facture->emetteur()->siret;
        $sirenCorrect = (string) $magasin->societe->fresh()->siren;

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture);
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $mention = "Avoir de régularisation émis après correction de l'identité du vendeur (SIREN correct : {$sirenCorrect})";
        $this->assertSame([true, $ancienSiren, $ancienSiret, $mention], [$avoir->estUneRegularisation(), $avoir->emetteur()->siren, $avoir->emetteur()->siret, $avoir->mentionDeRegularisation()]);
        $this->assertContains($mention, PiedDePage::pour($avoir, $avoir->emetteur())->lignes);
        $this->assertSame('Régularisation de la facture FAC-LYON-2026-000001 après la correction du SIREN du vendeur', $avoir->motif);
        $this->assertSame([$sirenCorrect, (string) $magasin->fresh()->siret], [$rectificative->emetteur()->siren, $rectificative->emetteur()->siret]);
        $this->assertNotSame($ancienSiret, (string) $magasin->fresh()->siret);
        $this->assertSame([$facture->id, '2026-10-15', 'FAC-LYON-2026-000002'], [$rectificative->facture_rectifiee_id, $rectificative->livraison_date, $rectificative->numero]);
        $this->assertContains('Facture rectificative de la facture n° FAC-LYON-2026-000001 du 15/10/2026', PiedDePage::pour($rectificative, $rectificative->emetteur())->lignes);
        $signeDeLaRectificative = $this->contenuSigne($rectificative)['donnees'];
        $this->assertSame($facture->id, $signeDeLaRectificative['document']['facture_rectifiee_id']);
        $this->assertSame(sprintf(MentionsFiscales::RECTIFICATIVE, 'FAC-LYON-2026-000001', '15/10/2026'), $signeDeLaRectificative['contexte']['mentions'][0]);
        $signeDeLAvoir = $this->contenuSigne($avoir)['donnees'];
        $this->assertSame([true, $ancienSiren, $mention], [$signeDeLAvoir['document']['regularisation'], $signeDeLAvoir['contexte']['emetteur']['siren'], $signeDeLAvoir['contexte']['regularisation']]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
        [$audit] = $this->entreesDAudit($magasin->societe, ActionAuditee::AvoirDeRegularisationEmis);
        $this->assertSame(['avoir' => $avoir->numero, 'facture' => 'FAC-LYON-2026-000001', 'siren_correct' => $sirenCorrect, 'total_ttc' => 24000], $audit->nouvelles_valeurs);
        [$auditDeLaRectificative] = $this->entreesDAudit($magasin->societe, ActionAuditee::FactureRectificativeEmise);
        $this->assertSame(['facture' => 'FAC-LYON-2026-000002', 'facture_rectifiee' => 'FAC-LYON-2026-000001', 'total_ttc' => 24000], $auditDeLaRectificative->nouvelles_valeurs);
    }

    /**
     * Ni l'avoir de régularisation ne rend au stock les articles vendus, ni
     * la facture rectificative ne les sort une seconde fois, ni ne contrôle
     * le stock que la vente d'origine a épuisé : le stock du produit et ses
     * mouvements ne bougent pas.
     */
    public function test_la_regularisation_ne_fait_aucun_mouvement_de_stock(): void
    {
        [$magasin, $facture, , $produit] = $this->factureDUneSocieteCorrigee(stock: 2);
        $mouvements = MouvementStock::query()->count();

        app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture);
        app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $this->assertSame([0, $mouvements, 2], [$produit->fresh()->stock_quantite, MouvementStock::query()->count(), $facture->fresh()->quantiteSortieDuStock($produit)]);
    }

    /**
     * La rectificative d'une livraison intracommunautaire reproduit son
     * opération : elle garde la vérification VIES de la facture, sans en
     * exiger une du jour de son émission, et sa livraison, date comprise ;
     * elle s'émet à 0 %, avec la mention de son exonération.
     */
    public function test_la_rectificative_d_une_livraison_intracommunautaire_garde_sa_verification_vies_et_sa_livraison(): void
    {
        $magasin = $this->magasinDeLyon();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $consultation = ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => self::aParis('2026-10-15 09:00:00'),
            'reponse' => '{"valid":true}',
        ]);
        $facture = $this->facturer($magasin, $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'livraison_date' => '2026-10-12',
            'transport_par' => TransportPar::Acquereur,
        ]);
        $this->corrigerLeSirenDeLaSociete($magasin);

        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $this->assertSame([$consultation->id, NatureOperation::LivraisonIntracommunautaire, '2026-10-12', 'Berlin', TransportPar::Acquereur], [$rectificative->consultation_vies_id, $rectificative->nature_operation, $rectificative->livraison_date, $rectificative->livraison_ville, $rectificative->transport_par]);
        $this->assertSame(['250.00', '0.00', '250.00'], [$rectificative->total_ht, $rectificative->total_tva, $rectificative->total_ttc]);
        $this->assertContains(NatureOperation::LivraisonIntracommunautaire->mention(), app(MentionsFiscales::class)->pour($rectificative, $rectificative->emetteur()));
    }

    /**
     * La rectificative ne corrige que l'identité du vendeur : quand la fiche
     * du client a changé depuis la facture (nom, adresse, SIREN retiré,
     * devenu particulier), elle reprend la copie du client que la facture a
     * figée, reste une facture à un professionnel, et s'émet sans demander
     * de réenregistrer un brouillon ni de renseigner le SIREN du client.
     */
    public function test_la_rectificative_reprend_le_client_fige_par_la_facture_meme_si_sa_fiche_a_change(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee();
        $client->forceFill(['nom' => 'Dupont et Fils', 'adresse' => '9 rue Neuve', 'type_client' => TypeClient::Particulier, 'siren' => null])->save();

        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $this->assertSame([TypeClient::Professionnel, 'Dupont SAS'], [$rectificative->type_client, $rectificative->client_nom]);
        $this->assertSame($facture->copieClient()->enTableau(), $rectificative->copieClient()->enTableau());
    }

    /**
     * Une facture de 240,00 € payée 100,00 € : l'avoir de régularisation
     * règle les 140,00 € qui en restent dus et garde 100,00 € pour la
     * rectificative, qui en reste due de 140,00 € ; le client paie ces
     * 140,00 €, et a payé en tout 240,00 €, une seule fois.
     */
    public function test_la_regularisation_ne_fait_jamais_payer_deux_fois(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee();
        $this->payer($facture, '100.00');

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $this->assertSame([[$facture->id, '140.00'], [$rectificative->id, '100.00']], $this->imputationsDe($avoir));
        $this->assertSame([StatutFacture::Payee, 0, 14000], [$facture->fresh()->statut, $avoir->fresh()->creditEnCentimes(), $rectificative->fresh()->resteDuEnCentimes()]);
        $this->payer($rectificative->fresh(), '140.00');
        $this->assertSame([StatutFacture::Payee, ['100.00', '140.00']], [$rectificative->fresh()->statut, Paiement::query()->orderBy('id')->pluck('montant')->all()]);
        $this->assertSame(0, $client->fresh()->soldeCrediteurEnCentimes());
    }

    /**
     * Une facture de prestations payée en entier, régularisée le jour même :
     * la clôture de la journée ne compte que le paiement de la facture, et
     * l'aperçu de son e-reporting ne déclare rien ; la régularisation
     * n'enregistre aucun paiement ni aucun encaissement au registre.
     */
    public function test_une_regularisation_ne_declare_aucun_encaissement_supplementaire(): void
    {
        $this->travelTo(self::aParis('2026-11-02 09:00:00'));
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee(prestation: true);
        $this->payer($facture, '240.00');
        $encaissements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->count();

        app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        $this->assertSame([StatutFacture::Payee, 1, $encaissements], [$rectificative->fresh()->statut, Paiement::query()->count(), EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->count()]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::responsableDeTest($magasin), $magasin->fresh(), '2026-11-02');
        $apercu = app(EReportingDesParticuliers::class)->apercu($cloture);
        $this->assertSame([24000, false, [], []], [$cloture->totalEncaisseEnCentimes(), $apercu->aDeclarer(), $apercu->transactions, $apercu->encaissementsDeServices]);
    }

    /**
     * Seules les factures que désigne le dernier examen se régularisent :
     * pas avant la correction du SIREN, pas un ticket, pas une facture
     * émise après l'examen (après la correction, qui n'en accepte aucune
     * entre les deux), et plus aucune quand un examen plus récent n'a rien
     * trouvé à régulariser ; chaque refus le dit, sans rien émettre.
     */
    public function test_seules_les_factures_listees_par_l_examen_se_regularisent(): void
    {
        $magasin = $this->magasinDeLyon();
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        $designee = $this->facturer($magasin, $client);
        $ticket = $this->facturer($magasin, Client::query()->where('est_comptoir', true)->sole());
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        app(TenantContext::class)->set($magasin->fresh());
        $avantLaCorrection = app(RegularisationDUneFacture::class)->refusDeLAvoir($designee->fresh());
        $this->corriger($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        $this->travelTo(self::aParis('2026-11-02 10:05:00'));
        $apresLExamen = $this->facturer($magasin->fresh(), $client);
        $regularisation = app(RegularisationDUneFacture::class);

        $this->assertSame([RegularisationDUneFacture::REFUS_NON_DESIGNEE, null], [$avantLaCorrection, $regularisation->refusDeLAvoir($designee->fresh())]);
        foreach ([$ticket, $apresLExamen] as $nonDesignee) {
            $this->assertSame([RegularisationDUneFacture::REFUS_NON_DESIGNEE, RegularisationDUneFacture::REFUS_NON_DESIGNEE], [$regularisation->refusDeLAvoir($nonDesignee->fresh()), $regularisation->refusDeLaRectificative($nonDesignee->fresh())]);
            $this->assertThrows(fn () => app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $nonDesignee->fresh()), LogicException::class, RegularisationDUneFacture::REFUS_NON_DESIGNEE);
            $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $nonDesignee->fresh()), LogicException::class, RegularisationDUneFacture::REFUS_NON_DESIGNEE);
        }
        $this->examiner($magasin->societe, SocieteFactory::sirenFictif(), DecisionExamenSiren::RienARegulariser);
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertSame(RegularisationDUneFacture::REFUS_NON_DESIGNEE, $regularisation->refusDeLAvoir($designee->fresh()));

        $this->assertSame([0, 3], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Une facture déjà régularisée ne se régularise plus : un second avoir
     * de régularisation et une seconde rectificative sont refusés, et la
     * facture rectifiée ne reçoit plus d'avoir ordinaire, un retour se
     * faisant sur sa rectificative : sa fiche ne propose plus « Avoir », et
     * « Nouvel avoir » refuse son numéro en le disant.
     */
    public function test_une_facture_deja_regularisee_ne_se_regularise_plus(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee(quantite: 3);
        $responsable = self::responsableDeTest($magasin);
        app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture);

        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh()), LogicException::class, RegularisationDUneFacture::REFUS_RECTIFICATIVE_DEJA_EMISE);
        $this->assertThrows(fn () => app(EnregistrerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => 1]], 'Retour'), LogicException::class, sprintf(CreerAvoirAction::REFUS_FACTURE_RECTIFIEE, 'FAC-LYON-2026-000001'));
        $this->dansLeMagasin($magasin->fresh(), $responsable);
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])->assertActionHidden('avoir');
        $recherche = Livewire::test(CreateAvoir::class)->fillForm(['recherche' => $facture->numero])->callFormComponentAction('recherche', 'chercher');
        $this->assertSame([sprintf(CreerAvoirAction::REFUS_FACTURE_RECTIFIEE, 'FAC-LYON-2026-000001')], $recherche->errors()->get('data.recherche'));
        app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $this->assertThrows(fn () => app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh()), LogicException::class, RegularisationDUneFacture::REFUS_AVOIR_DEJA_EMIS);

        $this->assertSame([1, 2], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Une facture de trois articles dont un a été rendu par un avoir
     * ordinaire avant la régularisation : l'avoir de régularisation crédite
     * les deux autres, et la rectificative les facture, aux mêmes montants,
     * au centime.
     */
    public function test_un_retour_fait_avant_la_regularisation_reste_hors_de_l_avoir_et_de_la_rectificative(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee(quantite: 3);
        $responsable = self::responsableDeTest($magasin);
        app(EnregistrerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => 1]], 'Retour');

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh());

        $this->assertSame([2, '240.00', '200.00', '40.00'], [$avoir->lignes()->sole()->quantite, $avoir->total_ttc, $avoir->total_ht, $avoir->total_tva]);
        $this->assertSame([2, '240.00', '200.00', '40.00'], [$rectificative->lignes()->sole()->quantite, $rectificative->total_ttc, $rectificative->total_ht, $rectificative->total_tva]);
    }

    /**
     * Une facture de trois lignes à deux taux (2 × 25,00 € et 1 × 25,00 €
     * à 20 %, 1 × 10,55 € à 5,5 %), dont un article de la première ligne a
     * été rendu avant la régularisation : l'avoir de régularisation crédite
     * chaque ligne de ce qui en reste, au centime de sa répartition par
     * taux (la première garde 20,84 € HT, ce que le retour n'a pas rendu),
     * et la rectificative reprend ces lignes dans leur ordre, avec leurs
     * prix et ces mêmes montants.
     */
    public function test_une_facture_de_plusieurs_lignes_a_deux_taux_se_regularise_ligne_par_ligne_au_centime(): void
    {
        $magasin = $this->magasinDeLyon();
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        $article = fn (string $reference, int|float $prix, int|float $taux): int => Produit::factory()->create(['reference' => $reference, 'prix_vente_ttc' => $prix, 'taux_tva' => $taux, 'suivi_stock' => false])->id;
        $facture = $this->facturer($magasin, $client, [
            ['produit_id' => $article('LAMPE', 25, 20), 'quantite' => 2],
            ['produit_id' => $article('THE', 10.55, 5.5), 'quantite' => 1],
            ['produit_id' => $article('VASE', 25, 20), 'quantite' => 1],
        ]);
        $responsable = self::responsableDeTest($magasin);
        app(EnregistrerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $facture->lignes()->orderBy('ordre')->first()->id, 'quantite' => 1]], 'Retour');
        $this->corrigerLeSirenDeLaSociete($magasin);

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh());

        $attendues = [[1, '20.84', '4.16', '25.00'], [1, '10.00', '0.55', '10.55'], [1, '20.83', '4.17', '25.00']];
        $this->assertSame($attendues, $avoir->lignes()->orderBy('id')->get()->map(fn ($ligne): array => [$ligne->quantite, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all());
        $this->assertSame(['51.67', '8.88', '60.55'], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        $this->assertSame([['LAMPE', '25.00', 1, '20.84', '4.16', '25.00'], ['THE', '10.55', 1, '10.00', '0.55', '10.55'], ['VASE', '25.00', 1, '20.83', '4.17', '25.00']], $rectificative->lignes()->orderBy('ordre')->get()->map(fn ($ligne): array => [$ligne->reference, $ligne->prix_unitaire_ttc, $ligne->quantite, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all());
        $this->assertSame(['51.67', '8.88', '60.55'], [$rectificative->total_ht, $rectificative->total_tva, $rectificative->total_ttc]);
        $this->assertTrue($avoir->factureRegularisee()->is($facture));
        $this->assertSame([StatutFacture::Payee, 6055], [$facture->fresh()->statut, $rectificative->fresh()->resteDuEnCentimes()]);
    }

    /**
     * Le propriétaire fait l'avoir de régularisation, comme tout avoir, mais
     * jamais la facture rectificative : la fiche ne la lui propose pas, une
     * demande forgée n'émet rien, l'action la lui refuse, et la base refuse
     * une facture rectificative à son nom.
     */
    public function test_le_proprietaire_fait_l_avoir_de_regularisation_mais_jamais_la_facture_rectificative(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin->fresh(), $proprietaire);
        $fiche = Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertActionVisible('faireLAvoirDeRegularisation')
            ->assertActionHidden('emettreLaFactureRectificative');

        $this->forgerLAction($fiche, 'emettreLaFactureRectificative', ['jeton' => (string) Str::uuid()]);
        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle($proprietaire, $facture->fresh()), AuthorizationException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->insert([
            'magasin_id' => $magasin->id,
            'client_id' => $facture->client_id,
            'type_client' => 'professionnel',
            'cree_par' => $proprietaire->id,
            'facture_rectifiee_id' => $facture->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])), QueryException::class, GardesDeLaVente::MESSAGE);
        $this->assertSame(0, Facture::query()->whereNotNull('facture_rectifiee_id')->count());

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($proprietaire, $facture->fresh());

        $this->assertSame([$proprietaire->id, true], [$avoir->cree_par, $avoir->estUneRegularisation()]);
    }

    /**
     * Un compte du cabinet lié, qui consulte le magasin avec toutes les
     * autorisations par défaut, ne fait ni l'avoir de régularisation ni la
     * facture rectificative : la fiche ne les lui propose pas, et les
     * actions les lui refusent, sans rien émettre.
     */
    public function test_un_compte_d_un_cabinet_lie_ne_fait_ni_l_avoir_ni_la_rectificative(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $collaborateur = $this->collaborateurDuCabinetLie($magasin->fresh());
        $this->dansLeMagasin($magasin->fresh(), $collaborateur);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertActionHidden('faireLAvoirDeRegularisation')
            ->assertActionHidden('emettreLaFactureRectificative');
        $this->assertThrows(fn () => app(FaireLAvoirDeRegularisationAction::class)->handle($collaborateur, $facture->fresh()), AuthorizationException::class);
        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle($collaborateur, $facture->fresh()), AuthorizationException::class);

        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Un vendeur qui ne voit que ses factures, même autorisé à faire les
     * avoirs, ne fait ni l'avoir de régularisation ni la facture
     * rectificative d'une facture du responsable, comme pour tout avoir ;
     * avec « voir celles de toute l'équipe », il émet la rectificative, en
     * son nom.
     */
    public function test_un_vendeur_ne_regularise_que_les_factures_qu_il_voit(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $sesVentes = $this->membre($magasin->fresh(), RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::AVOIR_VIEW, PermissionRegistry::AVOIR_CREER]);
        $toutes = $this->membre($magasin->fresh(), RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES]);

        $this->assertThrows(fn () => app(FaireLAvoirDeRegularisationAction::class)->handle($sesVentes, $facture->fresh()), AuthorizationException::class);
        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle($sesVentes, $facture->fresh()), AuthorizationException::class);
        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);

        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($toutes, $facture->fresh());

        $this->assertSame([$toutes->id, $facture->id], [$rectificative->cree_par, $rectificative->facture_rectifiee_id]);
    }

    /**
     * Tant que la rectificative manque, le crédit de l'avoir de
     * régularisation lui reste réservé : ni une autre facture due du
     * client, ni une facture émise ensuite, ni une facture que rouvre la
     * contre-passation de son paiement ne le reçoivent, et il ne compte pas
     * dans le crédit disponible du client ; la fiche de l'avoir le dit
     * réservé, ses lignes régularisées sans retour en stock, avec la
     * mention de la régularisation ; la rectificative le reçoit à son
     * émission.
     */
    public function test_tant_que_la_rectificative_manque_le_credit_lui_reste_reserve(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee();
        $this->payer($facture, '240.00');
        $this->travelTo(self::aParis('2026-11-02 10:05:00'));
        $autreDue = $this->facturer($magasin->fresh(), $client);

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $emiseEnsuite = $this->facturer($magasin->fresh(), $client);
        $paiementRejete = $this->payer($emiseEnsuite->fresh(), '120.00');
        app(ContrePasserPaiementAction::class)->handle($this->proprietaireDe($magasin), $paiementRejete, 'Virement rejeté par la banque');

        $this->assertSame([24000, 0, 12000, 12000], [$avoir->fresh()->creditEnCentimes(), $client->fresh()->soldeCrediteurEnCentimes(), $autreDue->fresh()->resteDuEnCentimes(), $emiseEnsuite->fresh()->resteDuEnCentimes()]);
        $this->assertFalse(Client::query()->avecUnCreditDAvoir()->exists());
        $this->dansLeMagasin($magasin->fresh(), $this->proprietaireDe($magasin));
        Livewire::test(ViewAvoir::class, ['record' => $avoir->getRouteKey()])
            ->assertSee(['Crédit réservé à sa facture rectificative', '240,00', 'Lignes régularisées, sans retour en stock', $avoir->fresh()->mentionDeRegularisation()])
            ->assertDontSee(['Crédit encore disponible', 'Produits retournés']);
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());
        $this->assertSame([[$rectificative->id, '240.00']], $this->imputationsDe($avoir));
        $this->assertSame([StatutFacture::Payee, 0], [$rectificative->fresh()->statut, $avoir->fresh()->creditEnCentimes()]);
    }

    /**
     * Quel que soit l'ordre des deux gestes, le crédit de l'avoir de
     * régularisation d'une facture payée règle sa rectificative, dans la
     * transaction signée du geste qui le rend possible : la rectificative
     * signe ce crédit quand l'avoir la précède, l'avoir signe son
     * imputation quand la rectificative le précède.
     */
    #[DataProvider('ordresDesGestes')]
    public function test_quel_que_soit_l_ordre_des_gestes_le_credit_regle_la_rectificative(bool $avoirDAbord): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $this->payer($facture, '240.00');
        $avoirDeRegularisation = fn (): Avoir => app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($magasin), $facture->fresh());
        $rectificativeDe = fn (): Facture => app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($magasin), $facture->fresh());

        [$avoir, $rectificative] = $avoirDAbord ? [$avoirDeRegularisation(), $rectificativeDe()] : array_reverse([$rectificativeDe(), $avoirDeRegularisation()]);

        $this->assertSame([[$rectificative->id, '240.00']], $this->imputationsDe($avoir));
        $this->assertSame([StatutFacture::Payee, 0], [$rectificative->fresh()->statut, $avoir->fresh()->creditEnCentimes()]);
        $creditSigneParLaRectificative = $this->contenuSigne($rectificative)['donnees']['contexte']['credits_avoir'];
        $imputationsSigneesParLAvoir = $this->contenuSigne($avoir)['donnees']['contexte']['imputations'];
        $this->assertSame(
            $avoirDAbord ? [[['avoir_id' => $avoir->id, 'numero' => $avoir->numero, 'montant' => 24000]], []] : [[], [['facture_id' => $rectificative->id, 'numero' => $rectificative->numero, 'montant' => 24000]]],
            [$creditSigneParLaRectificative, $imputationsSigneesParLAvoir],
        );
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function ordresDesGestes(): array
    {
        return ['l\'avoir puis la rectificative' => [true], 'la rectificative puis l\'avoir' => [false]];
    }

    /**
     * Une demande répétée après une réponse perdue, avec le même jeton, rend
     * l'avoir ou la rectificative déjà émis, sans second document ; le
     * même jeton pour l'autre geste est refusé.
     */
    public function test_une_demande_repetee_ne_regularise_qu_une_fois(): void
    {
        [$magasin, $facture] = $this->factureDUneSocieteCorrigee();
        $jetonDeLAvoir = (string) Str::uuid();
        $jetonDeLaRectificative = (string) Str::uuid();
        $responsable = self::responsableDeTest($magasin);

        $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle($responsable, $facture, $jetonDeLAvoir);
        $repetition = app(FaireLAvoirDeRegularisationAction::class)->handle($responsable, $facture->fresh(), $jetonDeLAvoir);
        $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh(), $jetonDeLaRectificative);
        $repetitionDeLaRectificative = app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh(), $jetonDeLaRectificative);

        $this->assertSame([$avoir->id, $rectificative->id], [$repetition->id, $repetitionDeLaRectificative->id]);
        $this->assertSame([1, 2], [Avoir::query()->count(), Facture::query()->count()]);
        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle($responsable, $facture->fresh(), $jetonDeLAvoir), LogicException::class);
    }

    /**
     * Sur la fiche d'une facture désignée, le responsable trouve les deux
     * gestes : chacun s'émet par sa fenêtre et ouvre le document émis ;
     * ensuite, ni l'un ni l'autre ne s'y propose plus, ni un avoir
     * ordinaire, et la fiche dit quelle facture la rectifie et que les
     * lignes de son avoir sont régularisées, sans retour en stock. La fiche
     * d'une facture qui n'est pas désignée ne les propose pas.
     */
    public function test_la_fiche_d_une_facture_designee_propose_les_deux_gestes_a_qui_peut_les_faire(): void
    {
        [$magasin, $facture, $client] = $this->factureDUneSocieteCorrigee();
        $this->travelTo(self::aParis('2026-11-02 10:05:00'));
        $nonDesignee = $this->facturer($magasin->fresh(), $client);
        $this->dansLeMagasin($magasin->fresh(), self::responsableDeTest($magasin));

        Livewire::test(ViewFacture::class, ['record' => $nonDesignee->getRouteKey()])
            ->assertActionHidden('faireLAvoirDeRegularisation')
            ->assertActionHidden('emettreLaFactureRectificative');
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->callAction('faireLAvoirDeRegularisation')
            ->assertHasNoActionErrors()
            ->assertRedirect(AvoirResource::getUrl('view', ['record' => Avoir::query()->sole()]));
        $fiche = Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertActionHidden('faireLAvoirDeRegularisation')
            ->callAction('emettreLaFactureRectificative')
            ->assertHasNoActionErrors();

        $rectificative = Facture::query()->whereNotNull('facture_rectifiee_id')->sole();
        $fiche->assertRedirect(FactureResource::getUrl('view', ['record' => $rectificative]));
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertActionHidden('emettreLaFactureRectificative')
            ->assertActionHidden('avoir')
            ->assertSee([$rectificative->numero, 'Lignes régularisées, sans retour en stock'])
            ->assertDontSee('Produits retournés');
        $this->assertSame([$facture->id, StatutFacture::Emise], [$rectificative->facture_rectifiee_id, $rectificative->statut]);
    }

    /**
     * La régularisation reste dans son magasin et dans sa société : la
     * facture d'un autre magasin de la société ne se régularise pas depuis
     * celui-ci, et la facture d'une autre société, qui n'a pas été
     * examinée, n'est jamais désignée.
     */
    public function test_la_regularisation_reste_dans_son_magasin_et_dans_sa_societe(): void
    {
        $lyon = $this->magasinDeLyon();
        $villeurbanne = $this->completerIdentiteLegale($this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'code' => 'VILL']));
        $factureDeVilleurbanne = $this->facturer($villeurbanne, Client::factory()->professionnel()->create(['nom' => 'Martin SARL']));
        $autre = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre', 'code' => 'AUTRE']));
        $factureDUneAutreSociete = $this->facturer($autre, Client::factory()->professionnel()->create(['nom' => 'Durand SA']));
        $sirenCorrect = SocieteFactory::sirenFictif();
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $this->examiner($lyon->societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->corriger($lyon->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001'), 'VILL' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002')]);
        app(TenantContext::class)->set($lyon->fresh());

        $this->assertThrows(fn () => app(FaireLAvoirDeRegularisationAction::class)->handle($this->proprietaireDe($lyon), $factureDeVilleurbanne), LogicException::class, Facture::REFUS_INTROUVABLE);
        $this->assertThrows(fn () => app(EmettreLaFactureRectificativeAction::class)->handle(self::responsableDeTest($lyon), $factureDeVilleurbanne), LogicException::class, Facture::REFUS_INTROUVABLE);
        app(TenantContext::class)->set($autre->fresh());
        $this->assertFalse(app(RegularisationDUneFacture::class)->estDesignee($factureDUneAutreSociete->fresh()));
        app(TenantContext::class)->set($villeurbanne->fresh());
        $this->assertTrue(app(RegularisationDUneFacture::class)->estDesignee($factureDeVilleurbanne->fresh()));

        app(TenantContext::class)->clear();
        $this->assertSame(0, Avoir::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Facture::query()->withoutGlobalScopes()->whereNotNull('facture_rectifiee_id')->count());
    }

    /**
     * Le magasin de Lyon d'une société validée, son identité légale
     * complète, magasin actif.
     */
    private function magasinDeLyon(): Magasin
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon', 'code' => 'LYON']));
        app(TenantContext::class)->set($magasin->fresh());

        return $magasin->fresh();
    }

    /**
     * Une facture de ce nombre d'articles à 120,00 € TTC (20 %), d'un
     * produit qui en a ce stock avant la vente, ou de prestations à ce
     * prix, émise le 15/10/2026 par le responsable du magasin de Lyon à un
     * client professionnel, puis le SIREN de la société corrigé par
     * l'éditeur (voir corrigerLeSirenDeLaSociete()) : rend le magasin, la
     * facture, son client et le produit vendu. Le magasin est le magasin
     * actif.
     *
     * @return array{0: Magasin, 1: Facture, 2: Client, 3: Produit|null}
     */
    private function factureDUneSocieteCorrigee(int $quantite = 2, bool $prestation = false, int $stock = 10): array
    {
        $magasin = $this->magasinDeLyon();
        $client = Client::factory()->professionnel()->create(['nom' => 'Dupont SAS']);
        $produit = $prestation ? null : Produit::factory()->create(['reference' => 'ART-120', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => $stock]);
        $ligne = $prestation
            ? ['service_id' => Service::factory()->auPrixDe('120.00')->create()->id, 'quantite' => $quantite]
            : ['produit_id' => $produit->id, 'quantite' => $quantite];
        $facture = $this->facturer($magasin, $client, [$ligne]);
        $this->corrigerLeSirenDeLaSociete($magasin);

        return [$magasin->fresh(), $facture->fresh(), $client->fresh(), $produit?->fresh()];
    }

    /**
     * Le SIREN de la société du magasin corrigé par l'éditeur le 02/11/2026
     * à 10 h (ou à l'heure figée, si elle est déjà ce jour-là), après un
     * examen qui désigne ses factures déjà émises, avec un nouveau SIRET
     * pour le magasin de Lyon. Rend le SIREN correct ; le magasin redevient
     * le magasin actif.
     */
    private function corrigerLeSirenDeLaSociete(Magasin $magasin): string
    {
        $sirenCorrect = SocieteFactory::sirenFictif();

        if (now()->lessThan(self::aParis('2026-11-02 00:00:00'))) {
            $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        }

        $this->examiner($magasin->societe, $sirenCorrect, DecisionExamenSiren::Erreur);
        $this->corriger($magasin->societe, $sirenCorrect, ['LYON' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001')]);
        app(TenantContext::class)->set($magasin->fresh());

        return $sirenCorrect;
    }

    /**
     * Une facture émise par le responsable du magasin, d'un article à
     * 120,00 € TTC sans suivi du stock, sauf autres lignes, avec ces
     * attributs (sa nature d'opération et sa livraison...).
     *
     * @param  list<array<string, mixed>>|null  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function facturer(Magasin $magasin, Client $client, ?array $lignes = null, array $attributs = []): Facture
    {
        app(TenantContext::class)->set($magasin);
        $responsable = self::responsableDeTest($magasin);
        $lignes ??= [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];

        return app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, $lignes, $attributs));
    }

    private function payer(Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::responsableDeTest($facture->magasin), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'recu_le' => $facture->magasin->calendrier()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    /**
     * L'éditeur envoie le courrier d'examen au représentant légal de
     * l'entité du SIREN correct, son code est saisi, puis l'examen conclut
     * à cette décision.
     */
    private function examiner(Societe $societe, string $sirenCorrect, DecisionExamenSiren $decision): void
    {
        $editeur = new InterventionDeLEditeur(self::JUSTIFICATION, 'DOSSIER-2026-0095', ['alice']);
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::ExamenSiren, $editeur, $sirenCorrect);
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure($sirenCorrect, MotifCode::ExamenSiren, $this->codeDuCourrier($societe, MotifCode::ExamenSiren), '127.0.0.1');
        app(TenantContext::class)->clear();
        app(ExaminerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $decision, $editeur);
        app(TenantContext::class)->clear();
    }

    /**
     * @param  array<string, string>  $sirets
     */
    private function corriger(Societe $societe, string $sirenCorrect, array $sirets): void
    {
        app(TenantContext::class)->clear();
        app(CorrigerSirenAction::class)->handle($societe->fresh(), $sirenCorrect, $sirets, null, new InterventionDeLEditeur(self::JUSTIFICATION, 'DOSSIER-2026-0095', ['alice', 'bruno']));
        app(TenantContext::class)->clear();
    }

    /**
     * Les imputations de l'avoir, dans leur ordre : la facture et le
     * montant de chacune.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function imputationsDe(Avoir $avoir): array
    {
        return AvoirAllocation::query()->where('avoir_id', $avoir->id)->orderBy('id')->get()
            ->map(fn (AvoirAllocation $allocation): array => [(int) $allocation->facture_id, $allocation->montant_alloue])
            ->all();
    }

    /**
     * Le contenu que le document a signé dans la chaîne des ventes.
     *
     * @return array<string, mixed>
     */
    private function contenuSigne(Facture|Avoir $document): array
    {
        return $document->enregistrementDeVente()->firstOrFail()->contenuSigne();
    }
}
