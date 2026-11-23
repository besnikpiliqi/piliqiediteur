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
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\GrilleDesGraphiques;
use App\Filament\PageDesCharges;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee(TextesDuCreditDeTva::conseilDuBloc(false)['titre'])
            ->assertSee(TextesDuCreditDeTva::conseilDuBloc(false)['texte'])
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
     * Sous le graphique de la TVA reportée, le comptable a lui aussi le
     * lien « Indiquer ou voir le crédit de TVA » (voir
     * CreditsDeTva::peutDeclarer()) : le collaborateur confié sur la page
     * « Graphiques » du client, vers la page des charges du client, et
     * l'expert-comptable déclaré sur « Tableau de bord de la société », vers
     * « Charges de la société ».
     */
    public function test_le_comptable_a_le_lien_du_credit_sous_le_graphique_de_la_tva(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'cabinet', 'lien' => (string) $lien->uuid])
            ->assertSee('Indiquer ou voir le crédit de TVA')
            ->assertSeeHtml(e(ClientDuCabinetResource::getUrl('charges', ['record' => (string) $lien->uuid], panel: 'cabinet', tenant: $cabinet).'#credit-de-tva'));

        $autreClient = $this->ouvrirUneSociete(['nom' => 'Annecy']);
        $expert = $this->expertComptable($autreClient);
        app(TenantContext::class)->clear();
        $this->dansLeMagasin($autreClient->fresh(), $expert);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'expert_comptable'])
            ->assertSee('Indiquer ou voir le crédit de TVA')
            ->assertSeeHtml('#credit-de-tva');
    }

    /**
     * Un administrateur qui voit la carte de la TVA sans voir les charges
     * lit, au tableau de bord de la société, où en est le crédit de TVA,
     * sans son montant ni bouton ; un administrateur qui ne voit pas les
     * graphiques ne lit rien du crédit.
     */
    public function test_l_administrateur_qui_voit_la_carte_de_la_tva_lit_le_message_sans_montant(): void
    {
        app(TenantContext::class)->clear();
        app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, '2026-11', -125000);
        app(TenantContext::class)->clear();
        $avecLesGraphiques = $this->administrateur($this->lyon, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur($this->societe->type_activite), PermissionRegistry::GRAPHIQUES_VOIR], nom: 'Admin des graphiques');
        $sansLesGraphiques = $this->administrateur($this->lyon, nom: 'Admin sans graphiques');

        $this->dansLEspaceDeLaSociete($this->societe, $avecLesGraphiques);
        Livewire::test(TableauDeBord::class)
            ->assertSeeHtml('data-message-de-la-tva="non_verifie"')
            ->assertSee('Crédit de TVA saisi, non vérifié par un comptable')
            ->assertSee('Indiqué avec la déclaration de novembre 2026, il compte déjà dans votre TVA : le propriétaire le fait vérifier par votre expert-comptable.')
            ->assertDontSee('1 250,00 €')
            ->assertDontSeeHtml('data-geste-du-message');

        $this->dansLEspaceDeLaSociete($this->societe, $sansLesGraphiques);
        Livewire::test(TableauDeBord::class)->assertDontSeeHtml('data-message-de-la-tva');
    }

    /**
     * Avant toute déclaration, le comptable lit des phrases qui lui sont
     * adressées (« la société », « son arrivée ») et le bouton « Indiquer
     * le crédit de TVA », jamais celles de la société : en attente le 20
     * novembre, à indiquer le 15 décembre, sur la page « Graphiques » du
     * client dans l'espace du cabinet.
     */
    public function test_le_comptable_lit_les_phrases_qui_lui_sont_adressees_avant_toute_declaration(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();
        $page = ClientDuCabinetResource::getUrl('graphiques', ['record' => $lien], panel: 'cabinet', tenant: $cabinet);
        app(TenantContext::class)->clear();

        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        $this->actingAs($collaborateur)->get($page)->assertOk()
            ->assertSee('data-message-de-la-tva="attente"', false)
            ->assertSee('En attente de la déclaration du crédit de TVA de la société')
            ->assertSee('PILIQI calcule déjà sa TVA depuis son arrivée, le 12/11/2026.')
            ->assertSee('Indiquer le crédit de TVA')
            ->assertDontSee('Indiquer mon crédit de TVA')
            ->assertDontSee('de vos déclarations');

        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        $this->flushSession();
        $this->actingAs($collaborateur)->get($page)->assertOk()
            ->assertSee('data-message-de-la-tva="a_saisir"', false)
            ->assertSee('Crédit de TVA de la déclaration de novembre 2026, ou d\'une plus récente, à indiquer')
            ->assertSee('Il se déclare pour n\'importe quel mois depuis son arrivée (novembre 2026).')
            ->assertSee('Indiquer le crédit de TVA')
            ->assertDontSee('Indiquer mon crédit de TVA')
            ->assertDontSee('votre déclaration');
    }

    /**
     * Dans l'espace du cabinet, une lecture hors de sa société : la page
     * des charges du client, sa page « Graphiques » (le message et la
     * grille) et le tableau de bord du cabinet ne lisent les déclarations
     * du crédit de TVA du client que par leurs colonnes nommées, jamais par
     * « select * », ni l'identifiant des comptes qui les ont saisies ou
     * vérifiées.
     */
    public function test_les_pages_d_un_client_du_cabinet_ne_lisent_le_credit_que_par_ses_colonnes_nommees(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $cabinet = $collaborateur->societe()->firstOrFail();
        app(TenantContext::class)->clear();
        app(DeclarerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, '2026-11', -125000);
        app(TenantContext::class)->clear();
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])->assertSeeHtml('data-credit-de-tva');
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => 'cabinet', 'lien' => (string) $lien->uuid])->assertSee('Indiquer ou voir le crédit de TVA');
        Livewire::test(TableauDeBordDuCabinet::class)->assertSee('Crédit de TVA de départ à vérifier');
        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->actingAs($collaborateur)->get(ClientDuCabinetResource::getUrl('graphiques', ['record' => $lien], panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSee('data-message-de-la-tva="a_verifier"', false);

        $lectures = array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, 'from "credits_de_tva"')));
        $permises = [
            'select "id", "uuid", "societe_id", "cadence", "periode", "solde", "saisi_par_nom", "saisi_comme", "saisi_le", "verifie_par_nom", "verifie_comme", "verifie_le", "motif" from "credits_de_tva"',
            'select "id", "societe_id", "cadence", "periode", "verifie_le" from "credits_de_tva"',
        ];

        $this->assertNotEmpty($lectures);
        foreach ($lectures as $sql) {
            $this->assertTrue(str_starts_with($sql, $permises[0]) || str_starts_with($sql, $permises[1]), "Une lecture du crédit de TVA nomme ses colonnes : {$sql}");
        }
    }

    /**
     * Le bloc du crédit de la page des charges dit son conseil une seule
     * fois, en un titre et sa phrase : à la société, de faire vérifier le
     * montant par son expert-comptable ; au comptable, de le vérifier sur
     * la déclaration de la société.
     */
    public function test_le_conseil_du_bloc_ne_se_repete_pas_et_s_adresse_a_qui_le_lit(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $societe = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])->html();

        $this->assertSame(1, substr_count($societe, 'Faites vérifier ce montant par votre expert-comptable'));
        $this->assertStringContainsString('Il figure à la ligne « Crédit de TVA à reporter » de votre déclaration. Une erreur fausse toute la TVA à payer qui suit.', html_entity_decode($societe, ENT_QUOTES));

        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        $this->dansLEspaceDuCabinet($collaborateur->societe()->firstOrFail(), $collaborateur);
        $duComptable = html_entity_decode(Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])->html(), ENT_QUOTES);

        $this->assertStringContainsString('Vérifiez ce montant sur la déclaration de la société.', $duComptable);
        $this->assertStringContainsString('Il figure à la ligne « Crédit de TVA à reporter » de sa déclaration.', $duComptable);
        $this->assertStringNotContainsString('votre expert-comptable', $duComptable);
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
