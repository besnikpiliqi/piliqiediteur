<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Charges\Actions\ConfirmerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\DeclarerUnCreditDeTvaAction;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\CreditsDeTva;
use App\Domain\Charges\Support\TextesDuCreditDeTva;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\TableauDeBordDeLaSociete;
use App\Filament\GrilleDesGraphiques;
use App\Filament\PageDesCharges;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les écrans du crédit de TVA et de son report (lot TVA27, maquette
 * « Message TVA avant PILIQI » approuvée par l'éditeur le 18/11/2026) : le
 * bloc du crédit de la page des charges et ses gestes, chez la société et
 * chez son comptable ; le message de la TVA du tableau de bord ; la carte
 * de la TVA reportée ; ce que voit le tableau de bord du cabinet et la
 * page de l'expert-comptable déclaré ; rien en franchise en base, ni dans
 * le panneau d'un magasin. La société, de Lyon, arrive le 12 novembre 2026
 * au réel normal mensuel ; le temps est figé au 15 décembre 2026 à Paris,
 * novembre se déclarant depuis le 1er décembre.
 */
class EcransDuCreditDeTvaTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->societe = $this->lyon->societe()->firstOrFail();
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        app(TenantContext::class)->clear();
    }

    /**
     * Le propriétaire lit le bloc et son conseil, indique −1 250,00 € pour
     * novembre par « Indiquer mon crédit de TVA » ; la ligne montre le
     * solde, l'état « non vérifié par un comptable », qui l'a saisi et
     * quand, et le point de départ du report ; puis il le modifie.
     */
    public function test_le_proprietaire_indique_puis_modifie_son_credit_depuis_la_page_des_charges(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSee(TextesDuCreditDeTva::TITRE_DE_LA_SAISIE)
            ->assertSee(CreditsDeTva::CONSEIL)
            ->assertSee('Aucun crédit de TVA indiqué pour le moment')
            ->assertActionVisible('declarerLeCredit')
            ->assertActionEnabled('declarerLeCredit')
            ->callAction('declarerLeCredit', ['periode' => '2026-11', 'solde' => '−1 250,00 €'])
            ->assertNotified('Crédit de TVA enregistré.');

        $credit = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->sole();
        $this->assertSame([-125000, '2026-11', ValideePar::Proprietaire], [$credit->solde, $credit->periode, $credit->saisi_comme]);
        $page->assertSee('-1 250,00 €')
            ->assertSee('Non vérifié par un comptable')
            ->assertSee('point de départ du report')
            ->assertSee('Saisi par '.$this->proprietaire->name.' (propriétaire) le 15/12/2026.')
            ->assertSeeHtml('data-message-de-la-tva="non_verifie"')
            ->callAction('modifierLeCredit', ['solde' => '-1 200,00'], ['credit' => (string) $credit->uuid])
            ->assertNotified('Crédit de TVA modifié.');

        $this->assertSame(-120000, $credit->fresh()->solde);
    }

    /**
     * Un solde positif est refusé à l'écran avec le message de la
     * maquette ; une écriture illisible, avec un message qui dit comment
     * l'écrire ; rien ne s'enregistre.
     */
    public function test_un_solde_positif_ou_illisible_est_refuse_a_l_ecran(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callAction('declarerLeCredit', ['periode' => '2026-11', 'solde' => '125,00'])
            ->assertNotified(DeclarerUnCreditDeTvaAction::REFUS_POSITIF)
            ->callAction('declarerLeCredit', ['periode' => '2026-11', 'solde' => 'mille'])
            ->assertNotified('Écrivez le solde de la déclaration en euros, au centime : -1 250,00 pour un crédit, 0 si vous n\'en avez pas.');

        $this->assertSame(0, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Avant la fin de novembre, rien ne se déclare encore : le bouton est
     * grisé, avec la date à partir de laquelle il le sera, et le message
     * du tableau de bord attend la déclaration.
     */
    public function test_avant_la_fin_de_la_premiere_periode_le_bouton_est_grise_avec_sa_date(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertActionDisabled('declarerLeCredit')
            ->assertSee('Disponible le 01/12/2026.');

        Livewire::test(TableauDeBord::class)
            ->assertSee('En attente de la déclaration de votre crédit de TVA')
            ->assertSee('PILIQI calcule déjà votre TVA depuis le 12/11/2026.')
            ->assertSeeHtml('data-message-de-la-tva="attente"');
    }

    /**
     * Le message du tableau de bord de la société, à indiquer, mène au bloc
     * du crédit de la page des charges ; la carte de la TVA dit la période,
     * la pastille « estimation : crédit de TVA non déclaré » et son calcul.
     */
    public function test_le_tableau_de_bord_de_la_societe_montre_le_message_et_la_carte_de_la_tva_reportee(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(TableauDeBord::class)
            ->assertSee('Indiquez le crédit de TVA de votre déclaration de novembre 2026, ou d\'une plus récente')
            ->assertSeeHtml('data-message-de-la-tva="a_saisir"')
            ->assertSeeHtml('#credit-de-tva');

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'societe'])
            ->assertSee('TVA à payer (estimation) · Décembre 2026')
            ->assertSee('estimation : crédit de TVA non déclaré')
            ->assertSee('TVA du mois 0,00 € − crédit reporté 0,00 € (non déclaré).')
            ->assertSee('Indiquer ou voir le crédit de TVA');
    }

    /**
     * Le collaborateur à qui le client est confié lit le crédit « à
     * vérifier » au tableau de bord du cabinet, le confirme depuis la page
     * des charges du client, puis le corrige avec un motif ; la ligne du
     * tableau de bord disparaît une fois le crédit vérifié.
     */
    public function test_le_cabinet_lit_le_credit_a_verifier_le_confirme_puis_le_corrige_depuis_la_page_du_client(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();
        app(TenantContext::class)->clear();
        $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, '2026-11', -125000);
        app(TenantContext::class)->clear();

        $this->assertSame([true], array_column(app(TableauDeBordDesClients::class)->pour($collaborateur), 'credit_a_verifier'));

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])
            ->assertSee('Le crédit de TVA de la société')
            ->assertSee('À vérifier')
            ->assertActionHidden('modifierLeCredit', ['credit' => (string) $credit->uuid])
            ->callAction('confirmerLeCredit', [], ['credit' => (string) $credit->uuid])
            ->assertNotified('Crédit de TVA vérifié.')
            ->assertSee('Vérifié par le comptable')
            ->callAction('corrigerLeCredit', ['solde' => '-1 200,00', 'motif' => 'Ligne 27 de la CA3 de novembre'], ['credit' => (string) $credit->uuid])
            ->assertNotified('Crédit de TVA corrigé.')
            ->assertSee('motif : Ligne 27 de la CA3 de novembre');

        $this->assertSame([-120000, ValideePar::Cabinet], [$credit->fresh()->solde, $credit->fresh()->verifie_comme]);
        app(TenantContext::class)->clear();
        $this->assertSame([false], array_column(app(TableauDeBordDesClients::class)->pour($collaborateur), 'credit_a_verifier'));
    }

    /**
     * L'expert-comptable déclaré lit « Crédit de TVA de départ à vérifier »
     * sur « Tableau de bord de la société », avec le message du côté du
     * comptable ; une fois confirmé, la ligne disparaît.
     */
    public function test_l_expert_comptable_declare_lit_le_credit_de_depart_a_verifier_sur_sa_page(): void
    {
        $expert = $this->expertComptable($this->lyon);
        app(TenantContext::class)->clear();
        $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, '2026-11', -125000);
        app(TenantContext::class)->clear();

        $this->dansLeMagasin($this->lyon->fresh(), $expert);
        Livewire::test(TableauDeBordDeLaSociete::class)
            ->assertSeeHtml('data-credit-de-tva-a-verifier')
            ->assertSee('Crédit de TVA de départ à vérifier : -1 250,00 € (déclaration de novembre 2026)');

        app(TenantContext::class)->clear();
        app(ConfirmerUnCreditDeTvaAction::class)->handle($expert, $this->societe, (string) $credit->uuid);
        app(TenantContext::class)->clear();
        $this->dansLeMagasin($this->lyon->fresh(), $expert);

        Livewire::test(TableauDeBordDeLaSociete::class)
            ->assertDontSeeHtml('data-credit-de-tva-a-verifier')
            ->assertSee('Crédit de TVA vérifié le 15/12/2026');
    }

    /**
     * L'administrateur qui voit les charges et les graphiques de toute la
     * société lit le crédit, sans aucun geste ; un administrateur qui ne
     * voit pas la TVA de toute la société ne voit pas le bloc ; dans le
     * panneau de son magasin, le responsable coché n'a ni bloc ni report.
     */
    public function test_seuls_le_proprietaire_et_le_comptable_changent_le_credit_et_le_magasin_n_a_ni_bloc_ni_report(): void
    {
        app(TenantContext::class)->clear();
        $credit = app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, '2026-11', -125000);
        app(TenantContext::class)->clear();
        $toutVoir = [...PermissionRegistry::modeleDeLAdministrateur($this->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR, PermissionRegistry::CHARGES_AJOUTER];
        $administrateur = $this->administrateur($this->lyon, autorisationsMagasin: $toutVoir, nom: 'Admin des graphiques');
        $sansGraphiques = $this->administrateur($this->lyon, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur($this->societe->type_activite), PermissionRegistry::CHARGES_AJOUTER], nom: 'Admin des charges');
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]);

        $this->dansLEspaceDeLaSociete($this->societe, $administrateur);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSeeHtml('data-credit-de-tva')
            ->assertSee('-1 250,00 €')
            ->assertActionHidden('declarerLeCredit')
            ->assertActionHidden('modifierLeCredit', ['credit' => (string) $credit->uuid])
            ->assertActionHidden('confirmerLeCredit', ['credit' => (string) $credit->uuid]);

        $this->dansLEspaceDeLaSociete($this->societe, $sansGraphiques);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertDontSeeHtml('data-credit-de-tva')
            ->assertDontSee('-1 250,00 €');

        $this->dansLeMagasin($this->lyon->fresh(), $responsable);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::MAGASIN])
            ->assertDontSeeHtml('data-credit-de-tva')
            ->assertDontSeeHtml('data-message-de-la-tva')
            ->assertDontSeeHtml('data-report-du-credit="avec_credit_declare"');
    }

    /**
     * En franchise en base, la page des charges ne montre aucune TVA, ni
     * bloc ni message ; avant le choix du régime, elle demande de le
     * choisir.
     */
    public function test_en_franchise_ou_sans_regime_la_page_ne_montre_ni_tva_ni_bloc(): void
    {
        $this->travelTo(self::aParis('2026-11-12 11:00:00'));
        $franchise = $this->ouvrirUneSociete(['nom' => 'Bastia', 'regime_tva' => RegimeTva::FranchiseEnBase]);
        $sansRegime = $this->ouvrirUneSociete(['nom' => 'Nancy', 'regime_tva' => null]);
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));

        $this->dansLEspaceDeLaSociete($franchise->societe()->firstOrFail(), $this->proprietaireDe($franchise));
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSee('Aucune TVA à afficher : votre société est en franchise en base.')
            ->assertDontSeeHtml('data-credit-de-tva')
            ->assertDontSeeHtml('data-message-de-la-tva');
        Livewire::test(TableauDeBord::class)->assertDontSeeHtml('data-message-de-la-tva');

        $this->dansLEspaceDeLaSociete($sansRegime->societe()->firstOrFail(), $this->proprietaireDe($sansRegime));
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertSee('Choisissez le régime de TVA pour voir la TVA du mois.')
            ->assertDontSeeHtml('data-credit-de-tva');
        Livewire::test(TableauDeBord::class)
            ->assertSeeHtml('data-message-de-la-tva="regime_a_choisir"')
            ->assertSee('Choisissez d\'abord le régime de TVA de la société');
    }
}
