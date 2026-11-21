<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Actions\GererLesTypesDeChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Models\TypeDeChargeDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\ChargesDeLaSociete;
use App\Filament\App\Pages\ChargesDuMagasin;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\PageDesCharges;
use App\Filament\Societe\Pages\Charges;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les quatre écrans de la page « Charges » (modèle C1, décisions de
 * l'éditeur du 14/11/2026, lot CHARGES) et leurs gestes, par le composant
 * qu'ils partagent (voir PageDesCharges) : l'espace de la société pour le
 * propriétaire et l'administrateur coché, la page d'un client dans l'espace
 * du cabinet, la page de l'expert-comptable déclaré et « Charges du
 * magasin » du responsable coché ; un vendeur, un responsable non coché,
 * un collaborateur à qui le client n'est pas confié, un cabinet à qui le
 * client retire les charges et une autre société n'y entrent pas.
 */
class PagesDesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $paris;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->paris = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Paris']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        app(TenantContext::class)->clear();
    }

    public function test_le_proprietaire_ouvre_la_page_et_ajoute_une_charge_avec_sa_piece_a_valider(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $this->get(Charges::getUrl(panel: 'societe', tenant: $this->societe))->assertOk()->assertSee('Ce qui est déjà saisi');

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('ajouterUneCharge', data: [
                'piece' => [UploadedFile::fake()->createWithContent('quittance-mai.pdf', FichiersDeTest::pdf('Quittance de loyer'))],
                'type' => 'piliqi:loyer',
                'fournisseur' => 'SCI des Halles',
                'numero_piece' => 'Q-2026-05',
                'date_piece' => '2026-05-02',
                'magasin' => '',
                'lignes' => [['taux' => '2000', 'ht' => '1200,00', 'tva' => '240,00', 'ttc' => '1440,00']],
            ])
            ->assertHasNoTableActionErrors()
            ->assertSee('Loyer')
            ->assertSeeHtml('data-case="a_valider"');

        $charge = Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $this->assertSame([EtatDeLaCharge::AValider, OrigineDeLaCharge::Client, '2026-05', 120000, 24000, 144000, null], [$charge->etat, $charge->origine, $charge->mois_tva, $charge->total_ht, $charge->total_tva, $charge->total_ttc, $charge->magasin_id]);
    }

    public function test_sans_comptable_le_proprietaire_valide_lui_meme_et_la_case_devient_verte(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertTableActionVisible('valider', $charge)
            ->assertTableActionHidden('renvoyer', $charge)
            ->callTableAction('valider', $charge)
            ->assertSeeHtml('data-case="validee"')
            ->assertSee('Validée par le propriétaire')
            ->assertTableActionHidden('supprimer', $charge->fresh());

        $this->assertSame(ValideePar::Proprietaire, $charge->fresh()?->validee_par);
    }

    public function test_le_comptable_du_cabinet_voit_la_page_du_client_valide_renvoie_et_ajoute_une_charge_validee(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $aValider = $this->deposer($this->proprietaire, $this->societe);
        $aRenvoyer = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2']));
        $cabinet = $collaborateur->societe()->firstOrFail();
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);

        $this->get(ClientDuCabinetResource::getUrl('charges', ['record' => $lien], panel: 'cabinet', tenant: $cabinet))->assertOk()->assertSee('Ce qui est déjà saisi');

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->callTableAction('valider', $aValider)
            ->callTableAction('renvoyer', $aRenvoyer, data: ['mot' => 'Pièce illisible'])
            ->callTableAction('ajouterUneCharge', data: [
                'piece' => [UploadedFile::fake()->createWithContent('edf.pdf', FichiersDeTest::pdf('Facture EDF'))],
                'type' => 'piliqi:electricite',
                'fournisseur' => 'EDF',
                'numero_piece' => 'EDF-1',
                'date_piece' => '2026-05-10',
                'mois_tva' => '2026-05',
                'magasin' => (string) $this->lyon->uuid,
                'lignes' => [['taux' => '2000', 'ht' => '100,00', 'tva' => '20,00', 'ttc' => '120,00', 'tva_deductible' => '20,00']],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(EtatDeLaCharge::Validee, $aValider->fresh()?->etat);
        $this->assertSame(ValideePar::Cabinet, $aValider->fresh()?->validee_par);
        $this->assertSame([EtatDeLaCharge::Renvoyee, 'Pièce illisible'], [$aRenvoyer->fresh()?->etat, $aRenvoyer->fresh()?->motif_renvoi]);
        $ajoutee = Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->where('fournisseur', 'EDF')->sole();
        $this->assertSame([EtatDeLaCharge::Validee, OrigineDeLaCharge::Comptable, $this->lyon->getKey()], [$ajoutee->etat, $ajoutee->origine, $ajoutee->magasin_id]);
    }

    /**
     * « Écrire les chiffres » et « Corriger » ouvrent leur fenêtre remplie
     * des chiffres de la charge, chaque ligne avec son taux : le client
     * réécrit une charge que le comptable lui a renvoyée, et le comptable
     * corrige une charge validée, avec un motif.
     */
    public function test_ecrire_les_chiffres_et_corriger_ouvrent_leur_fenetre_remplie_des_lignes_de_la_charge(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();
        $aRenvoyer = $this->deposer($this->proprietaire, $this->societe, $this->saisie(taux: 550, champs: ['numero_piece' => 'Q-1']));
        $aCorriger = $this->deposer($collaborateur, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2']));

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->callTableAction('renvoyer', $aRenvoyer, data: ['mot' => 'Mauvais taux'])
            ->mountTableAction('corriger', $aCorriger)
            ->assertHasNoTableActionErrors()
            ->assertTableActionDataSet(fn (array $donnees): bool => array_column(array_values($donnees['lignes']), 'taux') === ['2000'] && array_column(array_values($donnees['lignes']), 'ht') === ['1200,00'])
            ->set('mountedTableActionsData.0.lignes', [['taux' => '2000', 'ht' => '1100,00', 'tva' => '220,00', 'ttc' => '1320,00', 'tva_deductible' => '220,00']])
            ->setTableActionData(['motif' => 'HT mal recopié'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->mountTableAction('ecrireLesChiffres', $aRenvoyer)
            ->assertHasNoTableActionErrors()
            ->assertTableActionDataSet(fn (array $donnees): bool => array_column(array_values($donnees['lignes']), 'taux') === ['550'] && $donnees['fournisseur'] === 'SCI des Halles')
            ->set('mountedTableActionsData.0.lignes', [['taux' => '2000', 'ht' => '1200,00', 'tva' => '240,00', 'ttc' => '1440,00']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame([110000, 1], [$aCorriger->fresh()?->total_ht, $aCorriger->fresh()?->corrections]);
        $this->assertSame([EtatDeLaCharge::AValider, 24000], [$aRenvoyer->fresh()?->etat, $aRenvoyer->fresh()?->total_tva]);
    }

    /**
     * Dans la fenêtre, la case « Fournisseur établi hors de France »
     * recalcule chaque ligne qui a un HT : autoliquidée, sa TVA reste
     * calculée et son TTC est son HT ; décochée, son TTC redevient le HT
     * et la TVA.
     */
    public function test_la_case_du_fournisseur_hors_de_france_recalcule_le_ttc_des_lignes_sur_leur_ht(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->mountTableAction('ajouterUneCharge')
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '100,00', 'tva' => '20,00', 'ttc' => '120,00']])
            ->set('mountedTableActionsData.0.fournisseur_hors_france', true)
            ->assertSet('mountedTableActionsData.0.lignes.ligne.tva', '20,00')
            ->assertSet('mountedTableActionsData.0.lignes.ligne.ttc', '100,00')
            ->set('mountedTableActionsData.0.fournisseur_hors_france', false)
            ->assertSet('mountedTableActionsData.0.lignes.ligne.ttc', '120,00');
    }

    public function test_un_cabinet_a_qui_le_client_retire_les_charges_ni_un_collaborateur_non_confie_n_ouvrent_la_page(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon, autorisations: array_values(array_diff(PermissionRegistry::parDefautDuCabinet($this->societe->type_activite), [PermissionRegistry::CHARGES_VALIDER])));
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);

        $this->get(ClientDuCabinetResource::getUrl('charges', ['record' => $lien], panel: 'cabinet', tenant: $cabinet))->assertForbidden();
        $this->get(ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet))->assertOk()->assertDontSee(ClientDuCabinetResource::getUrl('charges', ['record' => $lien], panel: 'cabinet', tenant: $cabinet));
    }

    public function test_l_expert_comptable_declare_valide_sur_sa_page(): void
    {
        $expert = $this->expertComptable($this->lyon);
        $charge = $this->deposer($this->proprietaire, $this->societe);
        $this->dansLeMagasin($this->lyon, $expert);

        $this->get(ChargesDeLaSociete::getUrl(panel: 'app', tenant: $this->lyon))->assertOk()->assertSee('Ce qui est déjà saisi');

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::EXPERT_COMPTABLE])->callTableAction('valider', $charge);

        $this->assertSame(ValideePar::ExpertComptable, $charge->fresh()?->validee_par);
    }

    public function test_le_responsable_coche_ajoute_une_charge_de_son_magasin_sans_voir_celles_de_la_societe(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]);
        $deLaSociete = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['fournisseur' => 'Assureur', 'numero_piece' => 'A-1', 'type' => 'piliqi:assurances']));
        $deParis = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['fournisseur' => 'Bailleur de Paris', 'numero_piece' => 'P-1', 'magasin' => (string) $this->paris->uuid]));
        $this->dansLeMagasin($this->lyon, $responsable);

        $this->get(ChargesDuMagasin::getUrl(panel: 'app', tenant: $this->lyon))->assertOk();

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::MAGASIN])
            ->assertCanNotSeeTableRecords([$deLaSociete, $deParis])
            ->assertDontSee('Assureur')
            ->assertDontSee('Bailleur de Paris')
            ->callTableAction('ajouterUneCharge', data: [
                'type' => 'piliqi:fournitures',
                'fournisseur' => 'Papeterie du centre',
                'date_piece' => '2026-05-12',
                'magasin' => (string) $this->lyon->uuid,
                'lignes' => [['taux' => '2000', 'ht' => '50,00']],
            ])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('valider', Charge::query()->withoutGlobalScopes()->where('fournisseur', 'Papeterie du centre')->sole());

        $this->assertSame($this->lyon->getKey(), Charge::query()->withoutGlobalScopes()->where('fournisseur', 'Papeterie du centre')->sole()->magasin_id);
    }

    public function test_un_vendeur_et_un_responsable_non_coche_n_ont_pas_la_page(): void
    {
        $vendeur = $this->membre($this->lyon, RoleMagasin::Vendeur);
        $responsable = $this->responsable($this->lyon);

        foreach ([$vendeur, $responsable] as $compte) {
            $this->dansLeMagasin($this->lyon, $compte);
            $this->get(ChargesDuMagasin::getUrl(panel: 'app', tenant: $this->lyon))->assertForbidden();
            $this->get(ChargesDeLaSociete::getUrl(panel: 'app', tenant: $this->lyon))->assertForbidden();
        }
    }

    public function test_l_administrateur_coche_ajoute_sans_valider_et_celui_sans_la_case_n_a_pas_la_page(): void
    {
        $coche = $this->administrateur($this->lyon, [$this->lyon->id], [], [PermissionRegistry::CHARGES_AJOUTER]);
        $sansLaCase = $this->administrateur($this->lyon, [$this->lyon->id]);
        $charge = $this->deposer($this->proprietaire, $this->societe);

        $this->dansLEspaceDeLaSociete($this->societe, $this->choisirSonMotDePasse($sansLaCase));
        $this->get(Charges::getUrl(panel: 'societe', tenant: $this->societe))->assertForbidden();

        $this->dansLEspaceDeLaSociete($this->societe, $this->choisirSonMotDePasse($coche));
        $this->get(Charges::getUrl(panel: 'societe', tenant: $this->societe))->assertOk();
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertCanSeeTableRecords([$charge])
            ->assertTableActionHidden('valider', $charge)
            ->assertTableActionHidden('corriger', $charge);
    }

    public function test_l_avertissement_dit_qu_une_charge_de_ce_type_est_deja_saisie_pour_ce_mois(): void
    {
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-AVRIL']));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->mountTableAction('ajouterUneCharge')
            ->setTableActionData(['type' => 'piliqi:loyer', 'date_piece' => '2026-05-15'])
            ->assertSee('Une charge de ce type est déjà saisie pour mai 2026')
            ->assertSee('Q-AVRIL');
    }

    public function test_le_doublon_probable_demande_une_confirmation_puis_s_enregistre(): void
    {
        $this->deposer($this->proprietaire, $this->societe);
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $donnees = ['type' => 'piliqi:loyer', 'fournisseur' => 'SCI des Halles', 'date_piece' => '2026-05-02', 'magasin' => '', 'lignes' => [['taux' => '2000', 'ht' => '1200,00']]];

        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('ajouterUneCharge', data: $donnees)
            ->assertSet('confirmationDemandee', 'doublon');
        $this->assertSame(1, Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->count());

        $page->setTableActionData(['confirmer' => true])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame(2, Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->count());
    }

    public function test_la_piece_se_telecharge_en_piece_jointe(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('voirLaPiece', $charge)
            ->assertFileDownloaded('quittance.pdf');
    }

    public function test_une_autre_societe_ne_voit_jamais_ces_charges(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $this->dansLEspaceDeLaSociete($autre->societe, $this->proprietaireDe($autre));

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertCanNotSeeTableRecords([$charge])
            ->assertDontSee('SCI des Halles');
        $this->assertNull(app(\App\Domain\Charges\Services\ChargesDeLaSociete::class)->trouver(app(DroitsSurLesCharges::class)->pour($this->proprietaireDe($autre), $autre->societe) ?? throw new \LogicException, (string) $charge->uuid));
    }

    /**
     * Le collaborateur du cabinet lié écrit les chiffres d'une charge à
     * valider qui a déjà les siens, en corrigeant la TVA déductible d'un
     * cadeau de moins de 73 €, et la valide d'un même geste.
     */
    public function test_le_comptable_ecrit_les_chiffres_d_une_charge_qui_a_les_siens_et_la_valide(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cadeau = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:cadeaux', '50.00', champs: ['fournisseur' => 'Chocolatier', 'numero_piece' => 'C-1']));
        $this->dansLEspaceDuCabinet($collaborateur->societe()->firstOrFail(), $collaborateur);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->mountTableAction('ecrireLesChiffres', $cadeau)
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '50,00', 'tva' => '10,00', 'ttc' => '60,00', 'tva_deductible' => '10,00']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame([EtatDeLaCharge::Validee, ValideePar::Cabinet, 1000], [$cadeau->fresh()?->etat, $cadeau->fresh()?->validee_par, $cadeau->fresh()?->total_tva_deductible]);
    }

    /**
     * La TVA déductible préremplie suit la TVA quand le comptable change le
     * HT (elle valait la proposition) ; celle qu'il a écrite lui-même ne se
     * réécrit pas ; le mois de TVA suit la date de paiement ajoutée.
     */
    public function test_la_tva_deductible_et_le_mois_de_tva_se_recalculent_en_direct(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $loyer = $this->deposer($collaborateur, $this->societe, $this->saisie(ht: '1000.00', champs: ['numero_piece' => 'Q-1']));
        $electricite = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:electricite', null));
        $this->dansLEspaceDuCabinet($collaborateur->societe()->firstOrFail(), $collaborateur);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->mountTableAction('corriger', $loyer)
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '1000,00', 'tva' => '200,00', 'ttc' => '1200,00', 'tva_deductible' => '200,00']])
            ->set('mountedTableActionsData.0.lignes.ligne.ht', '1200,00')
            ->assertSet('mountedTableActionsData.0.lignes.ligne.tva', '240,00')
            ->assertSet('mountedTableActionsData.0.lignes.ligne.tva_deductible', '240,00')
            ->setTableActionData(['motif' => 'HT mal recopié'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->mountTableAction('ecrireLesChiffres', $electricite)
            ->setTableActionData(['fournisseur' => 'EDF', 'date_piece' => '2026-05-28'])
            ->assertSet('mountedTableActionsData.0.mois_tva', '2026-05')
            ->setTableActionData(['date_paiement' => '2026-06-04'])
            ->assertSet('mountedTableActionsData.0.mois_tva', '2026-06')
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '100,00', 'tva' => '20,00', 'ttc' => '120,00', 'tva_deductible' => '15,00']])
            ->set('mountedTableActionsData.0.lignes.ligne.ht', '200,00')
            ->assertSet('mountedTableActionsData.0.lignes.ligne.tva_deductible', '15,00');

        $this->assertSame([144000, 24000, 24000], [$loyer->fresh()?->total_ttc, $loyer->fresh()?->total_tva, $loyer->fresh()?->total_tva_deductible]);
    }

    /**
     * Un écart de TVA et un doublon probable sur la même saisie : chaque
     * confirmation se donne une fois, sa case décochée quand elle est
     * demandée, et la charge s'enregistre après les deux, avec la pièce
     * envoyée au premier envoi.
     */
    public function test_un_ecart_de_tva_et_un_doublon_probable_se_confirment_l_un_apres_l_autre(): void
    {
        $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:electricite', '100.00', champs: ['fournisseur' => 'EDF']));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $donnees = ['piece' => [UploadedFile::fake()->createWithContent('edf.pdf', FichiersDeTest::pdf('Facture EDF'))], 'type' => 'piliqi:electricite', 'fournisseur' => 'EDF', 'date_piece' => '2026-05-02', 'magasin' => '', 'lignes' => [['taux' => '2000', 'ht' => '100,00', 'tva' => '19,95', 'ttc' => '120,00']]];

        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('ajouterUneCharge', data: $donnees)
            ->assertSet('confirmationDemandee', 'ecarts');
        $page->setTableActionData(['confirmer' => true])->callMountedTableAction()
            ->assertSet('confirmationDemandee', 'doublon')
            ->assertSet('mountedTableActionsData.0.confirmer', false);
        $this->assertSame(1, Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->count());

        $page->setTableActionData(['confirmer' => true])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame(2, Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->count());
        $ajoutee = Charge::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->latest('id')->firstOrFail();
        $this->assertSame([true, 'edf.pdf'], [$ajoutee->tva_a_verifier, PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $ajoutee->getKey())->value('nom')]);
    }

    /**
     * « Toute la société seulement » filtre le tableau des mois comme la
     * liste ; la vue « À valider » montre aussi les pièces sans chiffres.
     */
    public function test_le_tableau_suit_le_filtre_et_la_vue_a_valider_montre_les_pieces_sans_chiffres(): void
    {
        $deLyon = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'L-1', 'magasin' => (string) $this->lyon->uuid]));
        $piece = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:eau', null));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->set('magasinFiltre', 'societe')
            ->assertCanNotSeeTableRecords([$deLyon])
            ->assertDontSeeHtml('data-case="a_valider"')
            ->assertSeeHtml('data-case="piece_sans_chiffres"')
            ->set('magasinFiltre', '')
            ->set('vue', 'a_valider')
            ->assertCanSeeTableRecords([$deLyon, $piece]);
    }

    /**
     * Le client voit, sans les écrire, le mois de TVA et la TVA déductible
     * que PILIQI propose ; le fournisseur se choisit aussi parmi ceux des
     * magasins, sans en créer de fiche.
     */
    public function test_le_client_voit_le_mois_et_la_deductible_proposes_et_les_fournisseurs_des_magasins(): void
    {
        $this->collaborateurDuCabinetLie($this->lyon);
        app(TenantContext::class)->runAs($this->paris, fn () => Fournisseur::factory()->create(['nom' => 'Grossiste Est']));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->mountTableAction('ajouterUneCharge')
            ->setTableActionData(['type' => 'piliqi:carburant', 'date_piece' => '2026-05-12'])
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '50,00']])
            ->set('mountedTableActionsData.0.lignes.ligne.ht', '72,50')
            ->assertSet('mountedTableActionsData.0.mois_tva', '2026-05')
            ->assertSet('mountedTableActionsData.0.lignes.ligne.tva_deductible', '11,60')
            ->assertSee('Mois de TVA proposé')
            ->assertSee('TVA déductible proposée')
            ->assertSee('Grossiste Est');

        $this->assertSame(1, Fournisseur::query()->withoutGlobalScopes()->count());
    }

    /**
     * La TVA du mois, sous le tableau : complète pour le propriétaire, qui
     * voit les graphiques ; les seules charges pour l'administrateur coché
     * qui ne les voit pas.
     */
    public function test_la_page_montre_la_tva_du_mois(): void
    {
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']));
        $administrateur = $this->administrateur($this->lyon, [$this->lyon->id], [], [PermissionRegistry::CHARGES_AJOUTER]);
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSeeHtml('data-tva-du-mois="complete"')
            ->assertSet('moisDeLaTva', '2026-05')
            ->assertSee('-240,00')
            ->set('moisDeLaTva', '2026-04')
            ->assertSee('avec 0,00');

        $this->dansLEspaceDeLaSociete($this->societe, $this->choisirSonMotDePasse($administrateur));
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSeeHtml('data-tva-du-mois="charges"')
            ->assertSee('TVA déductible des charges à valider')
            ->assertSee('240,00');
    }

    /**
     * Le propriétaire supprime un type de la société qu'aucune charge ne
     * porte ; le comptable n'a ni « Types de charge », ni « Ajouter un
     * type », ni « Supprimer un type ».
     */
    public function test_le_proprietaire_supprime_un_type_inutilise_et_le_comptable_n_a_pas_les_types(): void
    {
        $type = app(GererLesTypesDeChargeAction::class)->ajouter($this->proprietaire, $this->societe, 'Ménnage', NatureDeCharge::Services);
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $this->dansLEspaceDuCabinet($collaborateur->societe()->firstOrFail(), $collaborateur);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->assertTableActionHidden('typesDeCharge')
            ->assertTableActionHidden('ajouterUnType')
            ->assertTableActionHidden('supprimerUnType');

        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('supprimerUnType', data: ['type' => (string) $type->uuid])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('supprimerUnType');

        $this->assertNull(TypeDeChargeDeLaSociete::query()->withoutGlobalScopes()->find($type->getKey()));
    }
}
