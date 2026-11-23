<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les mentions d'identité de la société (décisions P14.1, P14.3, P14.4 et
 * P14.7, art. R123-237, R123-238 et R526-27 du Code de commerce) : le
 * capital que chaque forme exige, la dénomination d'un entrepreneur
 * individuel, qui contient son nom, l'EIRL et son objet, et les mentions
 * facultatives de l'identité (liquidation, location-gérance). Ce qu'elles
 * impriment dans le pied des documents et ce que les documents émis en
 * gardent se lit dans EmetteurFigeTest ; le capital que chaque forme exige
 * à l'écran, dans IdentiteSocieteTest.
 */
class MentionsDIdentiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le capital exigé selon la forme, le capital variable et la réponse
     * d'une autre forme à « société commerciale ? » (décisions P14.1 et
     * P14.3) : la SARL, l'EURL, la SA, la SAS, la SASU, la SCA et la SE à
     * capital fixe ; ni la SNC, ni la SCS, ni l'entrepreneur individuel ;
     * une autre forme que la société dit commerciale (suit la famille la
     * plus large) ; null tant qu'elle n'a pas répondu.
     *
     * @return array<string, array{0: FormeJuridique, 1: bool|null, 2: bool, 3: bool|null}>
     */
    public static function capitauxExiges(): array
    {
        return [
            'SARL à capital fixe' => [FormeJuridique::Sarl, null, false, true],
            'SARL à capital variable' => [FormeJuridique::Sarl, null, true, false],
            'EURL à capital fixe' => [FormeJuridique::Eurl, null, false, true],
            'SAS à capital fixe' => [FormeJuridique::Sas, null, false, true],
            'SAS à capital variable' => [FormeJuridique::Sas, null, true, false],
            'SASU à capital fixe' => [FormeJuridique::Sasu, null, false, true],
            'SA à capital fixe' => [FormeJuridique::Sa, null, false, true],
            'SCA à capital fixe' => [FormeJuridique::Sca, null, false, true],
            'SE à capital fixe' => [FormeJuridique::Se, null, false, true],
            'SNC à capital fixe' => [FormeJuridique::Snc, null, false, false],
            'SNC à capital variable' => [FormeJuridique::Snc, null, true, false],
            'SCS à capital fixe' => [FormeJuridique::Scs, null, false, false],
            'entrepreneur individuel' => [FormeJuridique::EntrepreneurIndividuel, null, false, false],
            'autre forme commerciale à capital fixe' => [FormeJuridique::Autre, true, false, true],
            'autre forme commerciale à capital variable' => [FormeJuridique::Autre, true, true, false],
            'autre forme non commerciale' => [FormeJuridique::Autre, false, false, false],
            'autre forme sans réponse' => [FormeJuridique::Autre, null, false, null],
            'autre forme sans réponse à capital variable' => [FormeJuridique::Autre, null, true, null],
        ];
    }

    #[DataProvider('capitauxExiges')]
    public function test_le_capital_exige_suit_la_famille_de_la_forme_et_le_capital_variable(FormeJuridique $forme, ?bool $commerciale, bool $capitalVariable, ?bool $exige): void
    {
        $this->assertSame($exige, $forme->exigeUnCapital($commerciale, $capitalVariable));
    }

    /**
     * Une SNC sans capital n'a aucune mention à compléter pour facturer un
     * professionnel, une SARL sans capital l'attend, et une SARL à capital
     * variable ne l'attend plus.
     */
    public function test_les_mentions_qui_manquent_ne_comptent_le_capital_que_pour_la_famille_qui_l_exige(): void
    {
        $societe = $this->ouvrirLAdministration();
        $societe->forceFill(['immatriculation' => 'RCS Lyon', 'capital_social' => null]);

        $this->assertContains('capital social', $societe->forceFill(['forme_juridique' => FormeJuridique::Sarl])->mentionsLegalesManquantes());
        $this->assertNotContains('capital social', $societe->forceFill(['forme_juridique' => FormeJuridique::Sarl, 'capital_variable' => true])->mentionsLegalesManquantes());
        $this->assertNotContains('capital social', $societe->forceFill(['forme_juridique' => FormeJuridique::Snc, 'capital_variable' => false])->mentionsLegalesManquantes());
    }

    /**
     * La raison sociale d'un entrepreneur individuel contient son nom
     * (décision P14.4, art. R526-27) : celui que lui donne le répertoire
     * Sirene, ou son nom d'usage, en mots entiers, sans tenir compte de la
     * casse, des accents ni de la ponctuation ; le prénom n'est pas exigé.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function raisonsSocialesDEntrepreneurs(): array
    {
        return [
            'le prénom et le nom' => ['Jeanne Dupont', true],
            'le nom seul' => ['Dupont', true],
            'le nom en majuscules' => ['JEANNE DUPONT', true],
            'le nom sans accent' => ['Boulangerie de Dupónt', true],
            'le nom dans un nom commercial' => ['Boulangerie Dupont, Jeanne', true],
            'le nom d\'usage' => ['Jeanne Durand', true],
            'le nom composé' => ['Jeanne Dupont-Martin', true],
            'un nom commercial sans le nom' => ['Boulangerie du Port', false],
            'le prénom seul' => ['Jeanne', false],
            'un autre nom' => ['Jeanne Dupuis', false],
            'le nom au milieu d\'un mot' => ['Dupontel Traiteur', false],
        ];
    }

    #[DataProvider('raisonsSocialesDEntrepreneurs')]
    public function test_la_raison_sociale_d_un_entrepreneur_individuel_contient_son_nom_ou_son_nom_d_usage(string $raisonSociale, bool $accepte): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', 'DURAND');
        $this->assertSame(['DUPONT', 'DURAND'], $societe->nomsDeLEntrepreneur());

        $enregistrer = fn () => app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe, ['raison_sociale' => $raisonSociale]);

        if ($accepte) {
            $this->assertSame($raisonSociale, $enregistrer()->raison_sociale);

            return;
        }

        $this->assertThrows(
            $enregistrer,
            fn (ValidationException $refus): bool => $refus->errors() === ['raison_sociale' => [ModifierIdentiteSocieteAction::messageDuNomDeLEntrepreneur(['DUPONT', 'DURAND'])]],
        );
        $this->assertSame('JEANNE DUPONT', $societe->fresh()->raison_sociale);
    }

    /**
     * Le refus se lit à l'écran, avec les noms que le répertoire donne, et
     * l'aide de la raison sociale les rappelle (décision P14.4 : « prérempli
     * depuis Sirene ») ; l'avis de l'identité incomplète le dit aussi d'une
     * raison sociale qui ne les contient plus.
     */
    public function test_l_ecran_refuse_la_raison_sociale_sans_le_nom_et_l_aide_le_rappelle(): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', null);

        Livewire::test(IdentiteSociete::class)
            ->assertSee('Celle d\'un entrepreneur individuel contient son nom ou son nom d\'usage : « DUPONT ».')
            ->fillForm(['raison_sociale' => 'Boulangerie du Port'])
            ->call('save')
            ->assertHasFormErrors(['raison_sociale'])
            ->assertSee(ModifierIdentiteSocieteAction::messageDuNomDeLEntrepreneur(['DUPONT']))
            ->fillForm(['raison_sociale' => 'Boulangerie Dupont'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Boulangerie Dupont', $societe->fresh()->raison_sociale);
        $this->assertNotContains('nom de l\'entrepreneur dans la raison sociale', $societe->fresh()->mentionsLegalesManquantes());
        $this->assertContains('nom de l\'entrepreneur dans la raison sociale', $societe->fresh()->forceFill(['raison_sociale' => 'Boulangerie du Port'])->mentionsLegalesManquantes());
    }

    /**
     * Sans nom que le répertoire donne (aucun contrôle concluant de
     * l'entreprise, ou un contrôle qui n'en donne pas, celui d'une société),
     * la raison sociale d'un entrepreneur individuel ne se contrôle pas ; le
     * nom d'une autre société ne sert jamais, et une société qui n'est pas
     * un entrepreneur individuel n'est jamais contrôlée.
     */
    public function test_sans_nom_connu_aucun_controle_et_le_nom_d_une_autre_societe_ne_sert_jamais(): void
    {
        $entrepreneur = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', null);
        $sansControle = $this->ouvrirUneSociete(['nom' => 'Boutique des Quais'])->societe;
        $sansNom = $this->ouvrirUneSociete(['nom' => 'Boutique du Port'])->societe;
        $controle = app(ControleSirene::class);
        app(TenantContext::class)->runAsSociete($sansNom, fn () => $controle->noterUnite($sansNom, $controle->unite($sansNom->siren)));

        $contexte = app(TenantContext::class);
        $this->assertSame(['DUPONT'], $contexte->runAsSociete($entrepreneur, fn (): array => $entrepreneur->nomsDeLEntrepreneur()));
        $this->assertSame([[], []], [$contexte->runAsSociete($sansControle, fn (): array => $sansControle->nomsDeLEntrepreneur()), $contexte->runAsSociete($sansNom, fn (): array => $sansNom->nomsDeLEntrepreneur())]);
        $this->assertSame([], $contexte->runAsSociete($sansNom, fn (): array => $entrepreneur->nomsDeLEntrepreneur()));

        foreach ([$sansControle, $sansNom] as $societe) {
            $contexte->clear();
            $enregistree = app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe, ['forme_juridique' => FormeJuridique::EntrepreneurIndividuel->value, 'raison_sociale' => 'Boulangerie du Port']);

            $this->assertSame([FormeJuridique::EntrepreneurIndividuel, 'Boulangerie du Port'], [$enregistree->forme_juridique, $enregistree->raison_sociale]);
            $this->assertNotContains('nom de l\'entrepreneur dans la raison sociale', $contexte->runAsSociete($societe, fn (): array => $societe->fresh()->mentionsLegalesManquantes()));
        }

        $contexte->clear();
        $sarl = app(ModifierIdentiteSocieteAction::class)->handle($entrepreneur->proprietaire, $entrepreneur, ['forme_juridique' => FormeJuridique::Sarl->value, 'raison_sociale' => 'Boulangerie du Port', 'capital_social' => '5000']);
        $this->assertSame('Boulangerie du Port', $sarl->raison_sociale);
    }

    /**
     * Le répertoire Sirene ne donne un nom qu'à un entrepreneur
     * individuel : le contrôle de l'entreprise le retient (nom et nom
     * d'usage) dans le registre des vérifications de la société, qui ne le
     * donne qu'à elle.
     */
    public function test_le_controle_de_l_entreprise_retient_le_nom_et_le_nom_d_usage_de_l_entrepreneur(): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', 'DURAND');

        $details = VerificationSociete::query()->where('societe_id', $societe->getKey())->where('type', TypeVerification::SireneUnite->value)->latest('id')->firstOrFail()->details;

        $this->assertSame(['JEANNE DUPONT', 'DUPONT', 'DURAND'], [$details['denomination'], $details['nom'], $details['nom_d_usage']]);
    }

    /**
     * À l'inscription, la société reçoit du répertoire Sirene sa forme (par
     * la catégorie juridique qu'il lui donne) et, pour un entrepreneur
     * individuel, son nom, que le registre des vérifications retient : le
     * répertoire factice les donne à l'entreprise qu'un test (ou le semis)
     * range dans une catégorie ou dit entrepreneur.
     */
    public function test_l_inscription_preremplit_la_forme_et_le_nom_que_le_repertoire_donne(): void
    {
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);
        $nom = SocieteFactory::sirenFictif();
        $repertoire->nommer($nom, 'LIBRAIRIE DES QUAIS');
        $repertoire->categoriser($nom, '5202');
        $entrepreneur = SocieteFactory::sirenFictif();
        $repertoire->nommer($entrepreneur, 'BOULANGERIE DUVAL');
        $repertoire->entrepreneur($entrepreneur, 'DUVAL', 'MARTIN');

        $snc = $this->inscrireUneSociete($nom);
        $boulangerie = $this->inscrireUneSociete($entrepreneur);

        $this->assertSame([FormeJuridique::Snc, 'LIBRAIRIE DES QUAIS', '5202'], [$snc->forme_juridique, $snc->raison_sociale, $snc->categorie_juridique_insee]);
        $this->assertSame([FormeJuridique::EntrepreneurIndividuel, 'BOULANGERIE DUVAL', '1000'], [$boulangerie->forme_juridique, $boulangerie->raison_sociale, $boulangerie->categorie_juridique_insee]);
        $contexte = app(TenantContext::class);
        $this->assertSame([], $contexte->runAsSociete($snc, fn (): array => $snc->nomsDeLEntrepreneur()));
        $this->assertSame(['DUVAL', 'MARTIN'], $contexte->runAsSociete($boulangerie, fn (): array => $boulangerie->nomsDeLEntrepreneur()));
    }

    /**
     * Un EIRL dit l'objet de l'activité à laquelle son patrimoine est
     * affecté (art. R123-237, 8°) : la case sans objet est refusée, et
     * l'objet sans la case n'est jamais gardé.
     */
    public function test_un_eirl_dit_l_objet_de_son_activite(): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', null);
        $action = app(ModifierIdentiteSocieteAction::class);

        $this->assertThrows(
            fn () => $action->handle($societe->proprietaire, $societe, ['eirl' => true, 'eirl_objet' => '  ']),
            fn (ValidationException $refus): bool => $refus->errors() === ['eirl_objet' => [ModifierIdentiteSocieteAction::MESSAGE_OBJET_DE_L_EIRL]],
        );
        $this->assertFalse($societe->fresh()->eirl);

        $enregistree = $action->handle($societe->proprietaire, $societe, ['eirl' => true, 'eirl_objet' => 'Vente de livres et de papeterie']);
        $this->assertSame([true, 'Vente de livres et de papeterie'], [$enregistree->eirl, $enregistree->eirl_objet]);

        $sansCase = $action->handle($societe->proprietaire, $societe->fresh(), ['eirl' => false, 'eirl_objet' => 'Vente de livres et de papeterie']);
        $this->assertSame([false, null], [$sansCase->eirl, $sansCase->eirl_objet]);
    }

    /**
     * Seul un entrepreneur individuel est un EIRL : la case est refusée pour
     * une société, et changer de forme efface la case et l'objet d'avant,
     * comme le capital d'un entrepreneur ; la mention « EI » n'accompagne
     * jamais un EIRL, et « EIRL » n'est jamais celle d'une société.
     */
    public function test_seul_un_entrepreneur_individuel_est_un_eirl_et_sa_raison_sociale_porte_eirl_et_non_ei(): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', null);
        $action = app(ModifierIdentiteSocieteAction::class);
        $action->handle($societe->proprietaire, $societe, ['eirl' => true, 'eirl_objet' => 'Vente de livres']);

        $this->assertThrows(
            fn () => $action->handle($societe->proprietaire, $societe->fresh(), ['raison_sociale' => 'Jeanne Dupont EI']),
            fn (ValidationException $refus): bool => $refus->errors() === ['raison_sociale' => [ModifierIdentiteSocieteAction::MESSAGE_EI_D_UN_EIRL]],
        );
        $this->assertSame('JEANNE DUPONT', $societe->fresh()->raison_sociale);
        $this->assertSame('Jeanne Dupont EIRL', $action->handle($societe->proprietaire, $societe->fresh(), ['raison_sociale' => 'Jeanne Dupont EIRL'])->raison_sociale);
        $this->assertThrows(
            fn () => $action->handle($societe->proprietaire, $societe->fresh(), ['forme_juridique' => FormeJuridique::Sarl->value, 'eirl' => true, 'capital_social' => '5000']),
            fn (ValidationException $refus): bool => array_key_exists('eirl', $refus->errors()) && $refus->errors()['eirl'] === [ModifierIdentiteSocieteAction::MESSAGE_EIRL_D_UNE_SOCIETE],
        );
        $this->assertThrows(
            fn () => $action->handle($societe->proprietaire, $societe->fresh(), ['forme_juridique' => FormeJuridique::Sarl->value, 'capital_social' => '5000']),
            fn (ValidationException $refus): bool => $refus->errors() === ['raison_sociale' => [ModifierIdentiteSocieteAction::MESSAGE_MENTION_EI]],
        );

        $sarl = $action->handle($societe->proprietaire, $societe->fresh(), ['forme_juridique' => FormeJuridique::Sarl->value, 'raison_sociale' => 'Dupont et Fils', 'capital_social' => '5000']);

        $this->assertSame([FormeJuridique::Sarl, false, null], [$sarl->forme_juridique, $sarl->eirl, $sarl->eirl_objet]);
        $this->assertContains('objet de l\'activité de l\'EIRL', $societe->fresh()->forceFill(['forme_juridique' => FormeJuridique::EntrepreneurIndividuel])->forceFill(['eirl' => true, 'eirl_objet' => null])->mentionsLegalesManquantes());
    }

    /**
     * Une raison sociale qui porte « EIRL » sans la case de l'EIRL ni l'objet
     * de son activité est refusée (décision P14.4 : « permettre alors de
     * saisir l'objet de l'activité ») : l'entrepreneur coche la case et dit
     * l'objet, ou retire la mention.
     */
    public function test_une_raison_sociale_qui_porte_eirl_demande_la_case_et_l_objet(): void
    {
        $societe = $this->ouvrirUnEntrepreneurIndividuel('Jeanne', 'DUPONT', null);
        $action = app(ModifierIdentiteSocieteAction::class);

        $this->assertThrows(
            fn () => $action->handle($societe->proprietaire, $societe, ['raison_sociale' => 'Garage Dupont EIRL']),
            fn (ValidationException $refus): bool => $refus->errors() === ['eirl' => [ModifierIdentiteSocieteAction::MESSAGE_EIRL_A_COCHER]],
        );

        $enregistree = $action->handle($societe->proprietaire, $societe, ['raison_sociale' => 'Garage Dupont EIRL', 'eirl' => true, 'eirl_objet' => 'Réparation automobile']);

        $this->assertSame(['Garage Dupont EIRL', true, 'Réparation automobile'], [$enregistree->raison_sociale, $enregistree->eirl, $enregistree->eirl_objet]);
    }

    /**
     * À l'écran, la case EIRL et l'objet n'existent que pour un
     * entrepreneur individuel, l'objet que pour un EIRL ; le formulaire les
     * enregistre.
     */
    public function test_a_l_ecran_la_case_eirl_et_son_objet_n_existent_que_pour_un_entrepreneur_individuel(): void
    {
        $societe = $this->ouvrirLAdministration();

        Livewire::test(IdentiteSociete::class)
            ->assertFormFieldIsHidden('eirl')
            ->assertFormFieldIsHidden('eirl_objet')
            ->fillForm(['forme_juridique' => FormeJuridique::EntrepreneurIndividuel->value])
            ->assertFormFieldIsVisible('eirl')
            ->assertFormFieldIsHidden('eirl_objet')
            ->fillForm(['eirl' => true])
            ->assertFormFieldIsVisible('eirl_objet')
            ->fillForm(['raison_sociale' => 'Jeanne Dupont', 'eirl_objet' => 'Vente de livres'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->fillForm(['forme_juridique' => FormeJuridique::Sas->value])
            ->assertFormFieldIsHidden('eirl');

        $this->assertSame([FormeJuridique::EntrepreneurIndividuel, true, 'Vente de livres'], [$societe->fresh()->forme_juridique, $societe->fresh()->eirl, $societe->fresh()->eirl_objet]);
    }

    /**
     * La liquidation et la qualité de locataire-gérant ou de
     * gérant-mandataire s'enregistrent à l'écran, par le propriétaire, et
     * s'inscrivent au journal d'audit avec leurs anciennes et nouvelles
     * valeurs ; la qualité se retire en choisissant « Aucune ».
     */
    public function test_le_proprietaire_declare_la_liquidation_et_la_location_gerance_et_le_journal_d_audit_les_garde(): void
    {
        $societe = $this->ouvrirLAdministration();
        $proprietaire = $societe->proprietaire()->firstOrFail();

        Livewire::test(IdentiteSociete::class)
            ->assertFormFieldExists('en_liquidation')
            ->assertFormFieldExists('exploitation_en_gerance')
            ->fillForm([...self::identiteComplete(), 'en_liquidation' => true, 'exploitation_en_gerance' => ExploitationEnGerance::GerantMandataire->value])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['en_liquidation' => true, 'exploitation_en_gerance' => ExploitationEnGerance::GerantMandataire->value])
            ->fillForm(['exploitation_en_gerance' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([true, null], [$societe->fresh()->en_liquidation, $societe->fresh()->exploitation_en_gerance]);

        $changements = app(TenantContext::class)->runAsSociete($societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::IdentiteModifiee->value)->orderBy('sequence')->get());
        $retenus = $changements->map(fn (EntreeAudit $entree): array => [
            array_intersect_key($entree->anciennes_valeurs, array_flip(['en_liquidation', 'exploitation_en_gerance'])),
            array_intersect_key($entree->nouvelles_valeurs, array_flip(['en_liquidation', 'exploitation_en_gerance'])),
        ])->filter(fn (array $paire): bool => $paire[0] !== [] || $paire[1] !== [])->values()->all();

        $this->assertSame([
            [['en_liquidation' => false, 'exploitation_en_gerance' => null], ['en_liquidation' => true, 'exploitation_en_gerance' => 'gerant_mandataire']],
            [['exploitation_en_gerance' => 'gerant_mandataire'], ['exploitation_en_gerance' => null]],
        ], $retenus);
        $this->assertSame($proprietaire->id, (int) $changements->last()->auteur_id);
    }

    /**
     * Une qualité d'exploitant inconnue est refusée.
     */
    public function test_une_qualite_d_exploitant_inconnue_est_refusee(): void
    {
        $societe = $this->ouvrirLAdministration();

        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe, ['exploitation_en_gerance' => 'bailleur', 'capital_social' => '5000']),
            fn (ValidationException $refus): bool => array_keys($refus->errors()) === ['exploitation_en_gerance'],
        );
    }

    /**
     * Un cabinet d'expertise comptable n'a ni magasin ni document de vente :
     * l'action n'écrit aucune mention facultative, même reçue, et la page de
     * son identité ne les propose pas.
     */
    public function test_un_cabinet_n_a_aucune_mention_facultative(): void
    {
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet)->fresh();

        app(ModifierIdentiteSocieteAction::class)->handle($cabinet->proprietaire()->firstOrFail(), $cabinet, [
            'en_liquidation' => true,
            'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant->value,
            'eirl' => true,
            'eirl_objet' => 'Conseil',
            'capital_social' => '5000',
            'immatriculation' => 'RCS Paris',
        ]);

        $this->assertSame(
            ['immatriculation' => 'RCS Paris', 'en_liquidation' => false, 'exploitation_en_gerance' => null, 'eirl' => false, 'eirl_objet' => null],
            $cabinet->fresh()->only(['immatriculation', ...ModifierIdentiteSocieteAction::CHAMPS_DES_MENTIONS_FACULTATIVES]),
        );
        $this->assertSame('Aucune', $cabinet->fresh()->mentionsFacultativesEnTexte());
    }

    /**
     * Les mentions facultatives, telles que les écrans en lecture seule de
     * l'expert-comptable et du cabinet les disent.
     */
    public function test_les_mentions_facultatives_se_disent_en_une_ligne_pour_les_ecrans_en_lecture_seule(): void
    {
        $societe = $this->ouvrirLAdministration();

        $this->assertSame('Aucune', $societe->mentionsFacultativesEnTexte());
        $this->assertSame(
            'Société en liquidation · Locataire-gérant · EIRL (objet : Vente de livres) · Membre d\'un assujetti unique',
            $societe->forceFill(['forme_juridique' => FormeJuridique::EntrepreneurIndividuel, 'en_liquidation' => true, 'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant, 'eirl' => true, 'eirl_objet' => 'Vente de livres', 'membre_assujetti_unique' => true])->mentionsFacultativesEnTexte(),
        );
    }

    /**
     * La base garde ce que le code garde : une qualité d'exploitant connue,
     * un EIRL qui est un entrepreneur individuel et qui dit son objet, un
     * objet que seul un EIRL porte, même par une requête qui contourne le
     * modèle.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function misesAJourRefuseesParLaBase(): array
    {
        return [
            'une qualité d\'exploitant inconnue' => [['exploitation_en_gerance' => 'bailleur']],
            'un EIRL sans objet' => [['forme_juridique' => 'entrepreneur_individuel', 'eirl' => true, 'eirl_objet' => null]],
            'un EIRL à l\'objet vide' => [['forme_juridique' => 'entrepreneur_individuel', 'eirl' => true, 'eirl_objet' => '  ']],
            'un EIRL sans forme juridique' => [['forme_juridique' => null, 'eirl' => true, 'eirl_objet' => 'Vente de livres']],
            'un EIRL qui est une SARL' => [['forme_juridique' => 'sarl', 'eirl' => true, 'eirl_objet' => 'Vente de livres']],
            'un objet sans EIRL' => [['eirl' => false, 'eirl_objet' => 'Vente de livres']],
        ];
    }

    /**
     * @param  array<string, mixed>  $miseAJour
     */
    #[DataProvider('misesAJourRefuseesParLaBase')]
    public function test_la_base_refuse_une_mention_facultative_incoherente(array $miseAJour): void
    {
        $societe = $this->ouvrirLAdministration();

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $societe->getKey())->update($miseAJour)),
            QueryException::class,
            'societes_',
        );
        $this->assertFalse((bool) Societe::query()->whereKey($societe->getKey())->value('eirl'));
    }

    /**
     * La base accepte, elle, un EIRL cohérent et chacune des deux qualités.
     */
    public function test_la_base_accepte_un_eirl_coherent_et_les_deux_qualites_d_exploitant(): void
    {
        $societe = $this->ouvrirLAdministration();

        DB::table('societes')->where('id', $societe->getKey())->update(['forme_juridique' => 'entrepreneur_individuel', 'eirl' => true, 'eirl_objet' => 'Vente de livres', 'exploitation_en_gerance' => 'locataire_gerant']);
        DB::table('societes')->where('id', $societe->getKey())->update(['exploitation_en_gerance' => 'gerant_mandataire']);

        $this->assertSame([true, 'gerant_mandataire'], [$societe->fresh()->eirl, $societe->fresh()->exploitation_en_gerance->value]);
    }

    /**
     * Une société à l'identité légale complète, ouverte par son
     * propriétaire dans l'administration de sa société (voir
     * IdentiteSocieteTest).
     */
    private function ouvrirLAdministration(): Societe
    {
        $societe = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'])->societe;
        app(TenantContext::class)->clear();
        $this->actingAs($societe->proprietaire()->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);

        return $societe;
    }

    /**
     * Une société que le répertoire Sirene factice connaît comme
     * l'entrepreneur individuel de ce prénom, de ce nom et de ce nom
     * d'usage, enregistrée comme telle (sa dénomination est son prénom et
     * son nom), son administration ouverte par son propriétaire.
     */
    private function ouvrirUnEntrepreneurIndividuel(string $prenom, string $nom, ?string $nomDUsage): Societe
    {
        $societe = $this->ouvrirLAdministration();
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);
        $repertoire->nommerUnEntrepreneur($societe->siren, $prenom, $nom, $nomDUsage);
        $controle = app(ControleSirene::class);
        $controle->noterUnite($societe, $controle->unite($societe->siren));
        $societe->forceFill(['raison_sociale' => mb_strtoupper("{$prenom} {$nom}"), 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel])->save();

        return $societe->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private static function identiteComplete(): array
    {
        return [
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl->value,
            'forme_juridique_autre' => '',
            'capital_social' => '10000',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République',
            'siege_code_postal' => '13001',
            'siege_ville' => 'Marseille',
        ];
    }
}
