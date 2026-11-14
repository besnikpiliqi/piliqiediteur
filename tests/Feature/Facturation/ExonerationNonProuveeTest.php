<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\RegulariserUneExonerationNonProuveeAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\MotifDeRegularisation;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Facturation\Services\RegularisationDUneExoneration;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un export hors de l'Union ou une livraison vers un DOM sans preuve de
 * sortie 150 jours après sa date se régularise d'un seul geste (T29.3,
 * voir RegularisationDUneExoneration) : un avoir de régularisation sans
 * retour en stock, puis une facture rectificative à la TVA française au
 * même prix TTC, que le crédit de l'avoir règle de ce que la vente avait
 * reçu.
 */
class ExonerationNonProuveeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-02 10:00:00'));
    }

    /**
     * L'exemple du cahier : un export de 500,00 € (un article à 600,00 €
     * TTC à 20 %, vendu sans la TVA française), payé, sans preuve de sortie
     * le 1er mars 2027, 150e jour après sa date, devient un avoir de
     * 500,00 € sans retour en stock et une facture de 500,00 € TTC à 20 %,
     * soit 416,67 € HT et 83,33 € de TVA, réglée par le crédit de l'avoir ;
     * le stock ne bouge pas, l'une et l'autre se signent, le geste
     * s'inscrit au journal d'audit, et la vente quitte les preuves à
     * fournir.
     */
    public function test_un_export_sans_preuve_au_150e_jour_se_regularise_a_la_tva_francaise_au_meme_prix_ttc(): void
    {
        [$magasin, $export, $produit] = $this->exportDe500Euros(stock: 5);
        $this->payer($export, '500.00');
        $mouvements = MouvementStock::query()->count();

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $rectificative = $this->regulariser($magasin, $export);

        $avoir = Avoir::query()->sole();
        $mention = 'Avoir de régularisation : preuve de sortie non reçue pour la facture n° '.$export->numero;
        $this->assertSame([MotifDeRegularisation::ExonerationNonProuvee, '500.00', '0.00', $mention], [$avoir->motif_regularisation, (string) $avoir->total_ttc, (string) $avoir->total_tva, $avoir->mentionDeRegularisation()]);
        $this->assertSame('Régularisation de la facture '.$export->numero.' : preuve de sortie non reçue', $avoir->motif);
        $this->assertSame([(string) $magasin->societe->siren, (string) $magasin->siret], [$avoir->emetteur()->siren, $avoir->emetteur()->siret]);
        $this->assertContains($mention, PiedDePage::pour($avoir, $avoir->emetteur())->lignes);

        $ligne = $rectificative->lignes()->sole();
        $this->assertSame([NatureOperation::VenteEnFrance, $export->id, StatutFacture::Payee], [$rectificative->nature_operation, $rectificative->facture_rectifiee_id, $rectificative->statut]);
        $this->assertSame(['500.00', '416.67', '83.33'], [(string) $rectificative->total_ttc, (string) $rectificative->total_ht, (string) $rectificative->total_tva]);
        $this->assertSame([$produit->id, 1, '500.00', '20.00', '416.67', '83.33', '500.00'], [$ligne->produit_id, $ligne->quantite, (string) $ligne->prix_unitaire_ttc, (string) $ligne->taux_tva, (string) $ligne->montant_ht, (string) $ligne->montant_tva, (string) $ligne->montant_ttc]);
        $this->assertSame([0, 0], [$export->fresh()->resteDuEnCentimes(), $rectificative->resteDuEnCentimes()]);
        $this->assertSame([4, $mouvements], [$produit->fresh()->stock_quantite, MouvementStock::query()->count()]);

        $this->assertContains(sprintf(MentionsFiscales::RECTIFICATIVE, $export->numero, '02/10/2026'), $rectificative->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['mentions']);
        $this->assertSame($mention, $avoir->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['regularisation']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin->fresh())->anomalies());

        [$audit] = $this->entreesDAudit($magasin->societe, ActionAuditee::ExonerationNonProuveeRegularisee);
        $this->assertSame(['avoir' => $avoir->numero, 'facture' => $export->numero, 'facture_rectificative' => $rectificative->numero, 'total_ttc' => 50000, 'total_tva' => 8333], $audit->nouvelles_valeurs);
        $this->assertFalse($export->fresh()->attendUnePreuve());
        $this->assertSame(RegularisationDUneExoneration::REFUS_DEJA_REGULARISEE, app(RegularisationDUneExoneration::class)->refus($export->fresh(), '2027-03-01'));
    }

    /**
     * Le 149e jour, la vente attend encore sa preuve : la régularisation
     * est refusée, en nommant le premier jour où elle se fera, en jours du
     * calendrier du magasin (le 28 février à 23 h 30 à Paris), et rien
     * n'est écrit.
     */
    public function test_avant_le_150e_jour_la_regularisation_est_refusee(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();

        $this->travelTo(self::aParis('2027-02-28 23:30:00'));

        $this->assertThrows(fn () => $this->regulariser($magasin, $export), LogicException::class, sprintf(RegularisationDUneExoneration::REFUS_TROP_TOT, '01/03/2027'));
        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Une vente dont la preuve de sortie (MRN) est enregistrée garde son
     * exonération : la régularisation est refusée, rien n'est écrit.
     */
    public function test_une_vente_dont_la_preuve_de_sortie_est_enregistree_ne_se_regularise_pas(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        app(AjouterPreuveOperationAction::class)->handle(self::responsableDeTest($magasin), $export, TypePreuve::Mrn, '26FR000000123456E1', '2026-10-02', 'Douane');

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));

        $this->assertThrows(fn () => $this->regulariser($magasin, $export), LogicException::class, RegularisationDUneExoneration::REFUS_PREUVE_RECUE);
        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Une vente en France n'a aucune exonération à régulariser.
     */
    public function test_une_vente_en_france_ne_se_regularise_pas(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $vente = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->professionnel()->create(), [['produit_id' => $this->produit(5)->id, 'quantite' => 1]]));

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));

        $this->assertThrows(fn () => $this->regulariser($magasin, $vente), LogicException::class, RegularisationDUneExoneration::REFUS_NATURE);
    }

    /**
     * Le propriétaire, qui ne vend dans aucun magasin réel, ne régularise
     * pas : le geste émet une facture. Rien n'est écrit.
     */
    public function test_le_proprietaire_ne_regularise_pas_hors_du_magasin_de_demonstration(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));

        $this->assertThrows(fn () => $this->regulariser($magasin, $export, $this->proprietaireDe($magasin)), AuthorizationException::class);
        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Un observateur, à qui la vente n'est pas cochée, ne régularise pas
     * non plus : le geste émet une facture et fait un avoir.
     */
    public function test_un_compte_qui_ne_vend_pas_ne_regularise_pas(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));

        $this->assertThrows(fn () => $this->regulariser($magasin, $export, $observateur), AuthorizationException::class);
        $this->assertSame([0, 1], [Avoir::query()->count(), Facture::query()->count()]);
    }

    /**
     * Une demande répétée après une réponse perdue rend la facture déjà
     * émise, sans second avoir ni seconde facture ; le même jeton pour une
     * autre vente est refusé.
     */
    public function test_une_demande_repetee_rend_la_meme_facture_sans_doublon(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        $autre = $this->exporter(self::responsableDeTest($magasin), $this->produit(5));
        $jeton = (string) Str::uuid();

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $premiere = $this->regulariser($magasin, $export, jeton: $jeton);
        $repetee = $this->regulariser($magasin, $export, jeton: $jeton);

        $this->assertSame([$premiere->id, 1, 3], [$repetee->id, Avoir::query()->count(), Facture::query()->count()]);
        $this->assertThrows(fn () => $this->regulariser($magasin, $autre, jeton: $jeton), LogicException::class, ActionsUniques::REFUS);
    }

    /**
     * Une vente impayée : le crédit de l'avoir solde la vente d'origine, et
     * la facture rectificative reste due de ce que le client y devait,
     * 500,00 €.
     */
    public function test_une_vente_impayee_reste_due_sur_sa_facture_rectificative(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $rectificative = $this->regulariser($magasin, $export);

        $this->assertSame([0, 50000], [$export->fresh()->resteDuEnCentimes(), $rectificative->resteDuEnCentimes()]);
        $this->assertSame(StatutFacture::Emise, $rectificative->statut);
    }

    /**
     * Un ticket d'export à un particulier, 4 articles à 65,00 € TTC à 20 %
     * vendus 216,67 € sans la TVA française : son ticket rectificatif garde
     * ce TTC, 180,56 € HT et 36,11 € de TVA, au PU TTC payé au centime
     * (54,17 €), et dit le ticket qu'il rectifie ; l'avoir nomme le ticket.
     */
    public function test_le_ticket_d_un_particulier_se_regularise_par_un_ticket_rectificatif(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $client = Client::factory()->create(['nom' => 'John Smith', 'type_client' => TypeClient::Particulier, 'pays' => 'US', 'etabli_en_france' => false]);
        $ticket = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 65, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 4]], self::livraisonAuxEtatsUnis()));
        $this->assertSame('216.67', (string) $ticket->total_ttc);

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $rectificatif = $this->regulariser($magasin, $ticket);

        $ligne = $rectificatif->lignes()->sole();
        $this->assertSame(['216.67', '180.56', '36.11', 4, '54.17'], [(string) $rectificatif->total_ttc, (string) $rectificatif->total_ht, (string) $rectificatif->total_tva, $ligne->quantite, (string) $ligne->prix_unitaire_ttc]);
        $this->assertContains(sprintf(MentionsFiscales::TICKET_RECTIFICATIF, $ticket->numero, '02/10/2026'), $rectificatif->enregistrementDeVente()->firstOrFail()->contenuSigne()['donnees']['contexte']['mentions']);
        $this->assertSame('Avoir de régularisation : preuve de sortie non reçue pour le ticket n° '.$ticket->numero, Avoir::query()->sole()->mentionDeRegularisation());
    }

    /**
     * Un export de 100, puis de 10 000 articles à 10,00 € TTC à 20 %,
     * vendus 833,33 € et 83 333,33 € sans la TVA française (PU HT de
     * 8,333333 €) : la ligne de la facture rectificative garde la quantité
     * et le TTC payé, 694,44 € HT et 138,89 € de TVA, puis 69 444,44 € HT et
     * 13 888,89 € de TVA ; son PU HT est ce HT ÷ la quantité, au millionième
     * (6,9444 € puis 6,944444 €), si bien que la quantité x ce PU HT, celui
     * de la facture électronique, retombe sur le HT de la ligne à moins de
     * deux centimes près (constat de la relecture du lot ER-PRO : 10 000 x
     * 6,941667 € tombaient 27,77 € sous le HT) ; son PU TTC est le prix
     * payé au centime, 8,33 €, que la facture n'imprime pas, et la quantité
     * x ce PU TTC s'écarte du TTC de la ligne d'au plus un demi-centime par
     * article (décision de Claude, D76 : la ligne garde le TTC payé). Le
     * PU HT affiché est, comme partout, celui d'un article à ce PU TTC :
     * 8,33 € moins sa TVA de 1,39 €, soit 6,94 € (décision de l'éditeur du
     * 14/11/2026), ce que dit la note de calcul.
     */
    public function test_la_ligne_rectificative_d_un_export_de_nombreux_articles_retombe_sur_son_ht(): void
    {
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $centArticles = $this->exporter($responsable, $produit, 100);
        $dixMilleArticles = $this->exporter($responsable, $produit, 10_000);
        $this->assertSame(['833.33', '83333.33'], [(string) $centArticles->total_ttc, (string) $dixMilleArticles->total_ttc]);
        $calculateur = app(CalculateurLigne::class);

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));

        foreach ([[$centArticles, 100, '694.44', '138.89', '833.33', '6.944400'], [$dixMilleArticles, 10_000, '69444.44', '13888.89', '83333.33', '6.944444']] as [$export, $quantite, $ht, $tva, $ttc, $prixUnitaireHt]) {
            $rectificative = $this->regulariser($magasin, $export);
            $ligne = $rectificative->lignes()->sole();

            $this->assertSame([$quantite, $ht, $tva, $ttc, $prixUnitaireHt, '8.33'], [$ligne->quantite, (string) $ligne->montant_ht, (string) $ligne->montant_tva, (string) $ligne->montant_ttc, (string) $ligne->prix_unitaire_ht, (string) $ligne->prix_unitaire_ttc]);
            $this->assertSame($calculateur->enCentiemes($ht) * 10_000, $quantite * $calculateur->enMillioniemes($ligne->prix_unitaire_ht));
            $this->assertLessThanOrEqual($quantite / 2, abs($calculateur->enCentiemes($ttc) - $quantite * $calculateur->enCentiemes($ligne->prix_unitaire_ttc)));
            $this->assertSame(694, $calculateur->prixUnitaireHtAffiche($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix)));
            $this->assertStringEndsWith('Le PU HT affiché est celui d\'un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité.', (string) app(MentionsFiscales::class)->calcul($rectificative));
        }
    }

    /**
     * Depuis la fiche de la vente, le responsable régularise d'un clic au
     * 150e jour, et la facture rectificative s'ouvre ; le bouton n'existe
     * ni le 149e jour, ni pour le propriétaire, à qui une demande forgée
     * n'émet rien.
     */
    public function test_le_responsable_regularise_depuis_la_fiche_de_la_vente(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        $responsable = self::responsableDeTest($magasin);

        $this->travelTo(self::aParis('2027-02-28 23:30:00'));
        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('regulariserLExoneration');

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);
        $fiche = Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('regulariserLExoneration');
        $this->forgerLAction($fiche, 'regulariserLExoneration', ['jeton' => (string) Str::uuid()]);
        $this->assertSame(0, Avoir::query()->count());

        $this->dansLeMagasin($magasin, $responsable);
        $page = Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])
            ->assertActionVisible('regulariserLExoneration')
            ->callAction('regulariserLExoneration');

        $rectificative = Facture::query()->where('facture_rectifiee_id', $export->id)->sole();
        $page->assertRedirect(FactureResource::getUrl('view', ['record' => $rectificative]));
        $this->assertSame(['416.67', '83.33'], [(string) $rectificative->total_ht, (string) $rectificative->total_tva]);
        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('regulariserLExoneration');
    }

    /**
     * L'« À faire » du magasin : au 150e jour, la vente entre, en rouge, à
     * faire pour le responsable avec « Régulariser » et sa fiche, à
     * surveiller pour le propriétaire avec « Voir » ; le rappel de sa
     * preuve reste. Le 149e jour, elle n'y est pas encore ; régularisée,
     * elle en sort. Deux ventes mènent à la liste filtrée, qui montre
     * exactement ces deux ventes, chacune avec sa raison.
     */
    public function test_l_a_faire_et_la_liste_filtree_montrent_l_exoneration_non_prouvee(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        $responsable = self::responsableDeTest($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->travelTo(self::aParis('2027-02-28 23:30:00'));
        $this->assertNull($this->chose($responsable, $magasin, 'exonerations_non_prouvees'));

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $pourLeResponsable = $this->chose($responsable, $magasin, 'exonerations_non_prouvees');
        $this->assertSame([UrgenceAFaire::Rouge, 1, false, 'Régulariser', FactureResource::getUrl('view', ['record' => $export]), '2026-10-02'], [$pourLeResponsable?->urgence, $pourLeResponsable?->nombre, $pourLeResponsable?->aSurveiller, $pourLeResponsable?->bouton, $pourLeResponsable?->url, $pourLeResponsable?->date]);
        $this->assertSame('1 vente hors de France sans preuve de sortie 150 jours après sa date : '.$export->numero.', Acme Inc..', $pourLeResponsable?->phrase);
        $this->assertNotNull($this->chose($responsable, $magasin, 'preuves'));
        $pourLeProprietaire = $this->chose($proprietaire, $magasin, 'exonerations_non_prouvees');
        $this->assertSame([true, 'Voir'], [$pourLeProprietaire?->aSurveiller, $pourLeProprietaire?->bouton]);

        $this->travelTo(self::aParis('2026-10-03 10:00:00'));
        $seconde = $this->exporter($responsable, $this->produit(5));
        $this->travelTo(self::aParis('2027-03-02 09:00:00'));
        $this->dansLeMagasin($magasin, $responsable);
        $this->assertSame([2, FactureResource::urlDesExonerationsNonProuvees()], [$this->chose($responsable, $magasin, 'exonerations_non_prouvees')?->nombre, $this->chose($responsable, $magasin, 'exonerations_non_prouvees')?->url]);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_EXONERATIONS_NON_PROUVEES)
            ->assertCanSeeTableRecords([$export, $seconde])
            ->assertTableColumnStateSet('a_faire', ['À encaisser', 'En retard', 'Preuve à fournir', 'Exonération non prouvée'], $export);

        $this->regulariser($magasin, $export);
        $this->regulariser($magasin, $seconde);
        $this->assertNull($this->chose($responsable, $magasin, 'exonerations_non_prouvees'));
    }

    /**
     * Dans un magasin qui ne peut pas vendre, la fiche n'offre pas « Régulariser
     * l'exonération » : l'« À faire » ne propose donc que « Voir », et sa
     * précision dit pourquoi (constat de la relecture du lot ER-PRO). Un
     * export de 500,00 € du 2 octobre 2026, puis la société se déclare
     * membre d'un assujetti unique : le 1er mars 2027, la vente reste à
     * faire pour le responsable, en rouge, avec « Voir » et le refus de
     * vente ; la case décochée, « Régulariser » revient.
     */
    public function test_l_a_faire_ne_propose_pas_de_regulariser_dans_un_magasin_qui_ne_peut_pas_vendre(): void
    {
        [$magasin, $export] = $this->exportDe500Euros();
        $responsable = self::responsableDeTest($magasin);
        $this->cocherLAssujettiUnique($magasin, true);

        $this->travelTo(self::aParis('2027-03-01 09:00:00'));
        $chose = $this->chose($responsable, $magasin, 'exonerations_non_prouvees');
        $this->assertSame([UrgenceAFaire::Rouge, false, 'Voir', FactureResource::getUrl('view', ['record' => $export])], [$chose?->urgence, $chose?->aSurveiller, $chose?->bouton, $chose?->url]);
        $this->assertSame('L\'exonération se régularise : un avoir sans retour en stock, puis une facture à la TVA française au même prix TTC. '.Magasin::REFUS_ASSUJETTI_UNIQUE, $chose?->precision);
        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionHidden('regulariserLExoneration');

        $this->cocherLAssujettiUnique($magasin, false);
        $chose = $this->chose($responsable, $magasin, 'exonerations_non_prouvees');
        $this->assertSame(['Régulariser', 'L\'exonération se régularise : un avoir sans retour en stock, puis une facture à la TVA française au même prix TTC.'], [$chose?->bouton, $chose?->precision]);
        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionVisible('regulariserLExoneration');
    }

    private function cocherLAssujettiUnique(Magasin $magasin, bool $membre): void
    {
        app(TenantContext::class)->runAsSociete($magasin->societe, fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe->fresh(), ['membre_assujetti_unique' => $membre]));
        $magasin->unsetRelation('societe');
        app(TenantContext::class)->set($magasin->fresh());
    }

    private function chose(User $compte, Magasin $magasin, string $cle): ?ChoseAFaire
    {
        $this->dansLeMagasin($magasin, $compte->fresh() ?? $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte->fresh() ?? $compte, $magasin->fresh() ?? $magasin);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', $cle);
    }

    /**
     * Le magasin de Lyon, au catalogue, et l'export de 500,00 € du
     * 2 octobre 2026 qu'y émet son responsable : rend le magasin, la vente
     * et son produit, qui suit son stock.
     *
     * @return array{0: Magasin, 1: Facture, 2: Produit}
     */
    private function exportDe500Euros(int $stock = 5): array
    {
        $magasin = $this->magasin();
        $produit = $this->produit($stock);
        $export = $this->exporter(self::responsableDeTest($magasin), $produit);
        $this->assertSame(['500.00', '0.00'], [(string) $export->total_ttc, (string) $export->total_tva]);

        return [$magasin, $export, $produit];
    }

    private function exporter(User $auteur, Produit $produit, int $quantite = 1): Facture
    {
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => $quantite]], self::livraisonAuxEtatsUnis()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function livraisonAuxEtatsUnis(): array
    {
        return ['nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US'];
    }

    private function regulariser(Magasin $magasin, Facture $facture, ?User $auteur = null, ?string $jeton = null): Facture
    {
        return app(RegulariserUneExonerationNonProuveeAction::class)->handle($auteur ?? self::responsableDeTest($magasin), $facture->fresh() ?? $facture, $jeton);
    }

    private function payer(Facture $facture, string $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle(self::responsableDeTest($facture->magasin), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'recu_le' => $facture->magasin->calendrier()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    private function magasin(): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1']));
    }

    private function produit(int $stock): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 600, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => $stock]);
    }
}
