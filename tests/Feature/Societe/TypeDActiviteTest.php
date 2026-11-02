<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Mail\InscriptionCloseMail;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\AvertissementsDuCodeDActivite;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\Cabinet\Pages\IdentiteDuCabinet;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Filament\Societe\Resources\MembreResource;
use App\Http\Controllers\ConfirmerInscriptionController;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Forms\Components\Select;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le type d'activité d'une société (voir TypeDActivite) : choisi à
 * l'inscription dans un menu sans valeur par défaut, gardé dans la demande
 * avec le code d'activité lu au répertoire Sirene, encore modifiable sur la
 * page du lien avec une case qui dit qu'il sera définitif, puis définitif
 * dès la création de la société, la base refusant toute modification. Le
 * code d'activité ne bloque que le cabinet d'expertise comptable, hors du
 * code 69.20Z ; pour les autres types, il n'affiche que des avertissements.
 * Un cabinet n'a ni magasin ni démonstration, et, jusqu'à l'espace des
 * cabinets, ses textes de l'espace de la société ne lui parlent ni de
 * l'un ni de l'autre. Une inscription faite avec un mauvais type se clôt
 * par la procédure de l'éditeur, et la même personne se réinscrit avec le
 * bon type. Aucune requête ne sort des tests (répertoire, registre et
 * prestataire d'envoi factices).
 */
class TypeDActiviteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un SIREN fictif valide, d'une entreprise active dans le répertoire
     * factice, d'activité 47.11B en NAF rév. 2.
     */
    private const SIREN = '100000009';

    private const ADRESSE = 'jeanne@maboutique.fr';

    public function test_le_menu_propose_les_trois_types_dans_l_ordre_sans_valeur_par_defaut(): void
    {
        $page = Livewire::test(InscriptionSociete::class);

        $page->assertFormFieldExists('type_activite', fn (Select $champ): bool => $champ->getLabel() === 'Votre activité'
            && $champ->getPlaceholder() === 'Choisissez votre activité'
            && array_keys($champ->getOptions()) === [TypeDActivite::Commerce->value, TypeDActivite::Services->value, TypeDActivite::Cabinet->value]
            && $champ->getState() === null
            && $champ->isRequired())
            ->assertSeeText(TypeDActivite::rappelDuChoixDefinitif());

        $options = array_values($page->instance()->form->getComponent('data.type_activite')->getOptions());
        foreach ([TypeDActivite::Commerce, TypeDActivite::Services, TypeDActivite::Cabinet] as $rang => $type) {
            $this->assertStringContainsString(e($type->getLabel()), $options[$rang]);
            $this->assertStringContainsString(e($type->exemples()), $options[$rang]);
        }

        $page->fillForm(['type_activite' => TypeDActivite::Services->value])
            ->assertSeeText(TypeDActivite::Services->aide())
            ->assertSeeText('Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale des travaux du bâtiment, par exemple), renseignez-la dans l\'identité de votre société : '.config('app.name').' l\'imprime sur vos devis et vos factures. Ce choix ne permet pas de vendre des articles.')
            ->assertDontSeeText(TypeDActivite::Commerce->aide());
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function typesRefuses(): array
    {
        return [
            'sans type' => [null, 'required'],
            'type inconnu' => ['boulangerie', 'in'],
        ];
    }

    #[DataProvider('typesRefuses')]
    public function test_la_demande_sans_type_ou_avec_un_type_inconnu_est_refusee(?string $type, string $regle): void
    {
        Mail::fake();

        $this->demander(['type_activite' => $type])->assertHasFormErrors(['type_activite' => $regle]);

        $this->assertSame(0, DemandeInscription::query()->count());
        Mail::assertNothingSent();
    }

    /**
     * Le type choisi entre dans la demande avec le code d'activité et sa
     * nomenclature lus au répertoire Sirene, nuls quand il n'a pas
     * répondu ; l'e-mail du lien rappelle le type, qui peut encore changer
     * en confirmant.
     */
    public function test_le_type_choisi_entre_dans_la_demande_avec_le_code_d_activite_et_se_rappelle_dans_l_e_mail(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        Mail::fake();

        $this->demander(['type_activite' => TypeDActivite::Services->value])->assertHasNoFormErrors();
        $this->travel(61)->seconds();
        app(RepertoireSirene::class)->rendreInjoignable();
        $this->demander(['siren' => SocieteFactory::sirenFictif(), 'type_activite' => TypeDActivite::Commerce->value, 'email' => 'paul@autre.fr'])->assertHasNoFormErrors();

        $donnees = DemandeInscription::query()->orderBy('id')->get()->map(fn (DemandeInscription $demande): array => array_intersect_key($demande->donnees, array_flip(['type_activite', 'code_activite', 'nomenclature_activite'])))->all();
        $this->assertSame([
            ['type_activite' => TypeDActivite::Services->value, 'code_activite' => '47.11B', 'nomenclature_activite' => 'NAFRev2'],
            ['type_activite' => TypeDActivite::Commerce->value, 'code_activite' => null, 'nomenclature_activite' => null],
        ], $donnees);
        $mail = Mail::sent(DemandeInscriptionMail::class)->first();
        $mail->assertSeeInText('Type d\'activité choisi : Services, sans vente d\'articles. Vous pourrez encore le changer en confirmant votre inscription ; il sera ensuite définitif.');
    }

    /**
     * La page du lien rappelle le SIREN et le type choisi, montre le menu
     * avec ce type coché, et le laisse changer : la société naît avec le
     * type envoyé par le formulaire, sans nouvel e-mail.
     */
    public function test_la_page_du_lien_rappelle_le_type_et_permet_de_le_changer_sans_nouvel_e_mail(): void
    {
        Mail::fake();
        $this->demander();
        $lien = $this->lienEnvoye();

        $this->get($lien)
            ->assertOk()
            ->assertSeeText('SIREN : '.self::SIREN.' · type d\'activité choisi : Commerce ou atelier')
            ->assertSeeInOrder([TypeDActivite::Commerce->getLabel(), TypeDActivite::Services->getLabel(), TypeDActivite::Cabinet->getLabel()])
            ->assertSee('value="'.TypeDActivite::Commerce->value.'" checked', false)
            ->assertSeeText(ConfirmerInscriptionController::CASE_DU_CHOIX_DEFINITIF)
            ->assertSeeText(TypeDActivite::rappelDuChoixDefinitif());

        $this->confirmer($lien, TypeDActivite::Services)->assertRedirect();

        $this->assertSame(TypeDActivite::Services, Societe::query()->sole()->type_activite);
        Mail::assertSentCount(1);
    }

    /**
     * Ouvrir le lien ne consulte pas le répertoire Sirene : les
     * avertissements se lisent dans la demande, même quand le répertoire
     * ne répond plus ou dit autre chose ; rien n'est consommé ni créé.
     */
    public function test_l_ouverture_du_lien_n_interroge_pas_le_repertoire_et_ne_consomme_rien(): void
    {
        Mail::fake();
        $this->demander();
        $repertoire = app(RepertoireSirene::class);
        $repertoire->exercer(self::SIREN, '56.10A');
        $repertoire->rendreInjoignable();

        $this->get($this->lienEnvoye())
            ->assertOk()
            ->assertSeeText('Votre code d\'activité au répertoire Sirene est 47.11B')
            ->assertDontSeeText('56.10A');

        $this->assertNull(DemandeInscription::query()->sole()->consommee_le);
        $this->assertSame([0, []], [Societe::query()->count(), app(ServiceEnvoiRecommande::class)->envois()]);
    }

    /**
     * Sans la case du choix définitif, ou sans un type du menu, la
     * confirmation ne crée rien, ne consomme pas le lien et ne fait partir
     * aucun courrier : la page dit pourquoi et garde le type envoyé.
     */
    public function test_la_confirmation_sans_la_case_du_choix_definitif_ne_cree_rien(): void
    {
        Mail::fake();
        $this->demander();
        $lien = $this->lienEnvoye();

        $this->post($lien, ['type_activite' => TypeDActivite::Services->value])
            ->assertOk()
            ->assertSeeText(ConfirmerInscriptionController::MESSAGE_CHOIX_DEFINITIF_A_CONFIRMER)
            ->assertSee('value="'.TypeDActivite::Services->value.'" checked', false);
        $this->post($lien, ['choix_definitif' => '1'])->assertOk()->assertSeeText(ConfirmerInscriptionController::MESSAGE_TYPE_A_CHOISIR);
        $this->post($lien, ['type_activite' => 'boulangerie', 'choix_definitif' => '1'])->assertOk()->assertSeeText(ConfirmerInscriptionController::MESSAGE_TYPE_A_CHOISIR);

        $this->assertSame([0, 0, []], [Societe::query()->count(), User::query()->count(), app(ServiceEnvoiRecommande::class)->envois()]);
        $this->assertNull(DemandeInscription::query()->sole()->consommee_le);
        $this->assertGuest();
    }

    /**
     * La société naît avec le type confirmé ; l'entrée d'audit « Société
     * inscrite » retient ce type, le code d'activité lu au répertoire à la
     * confirmation et sa nomenclature, qui ne s'enregistrent nulle part
     * ailleurs sur la société.
     */
    public function test_la_societe_nait_avec_le_type_confirme_et_societe_inscrite_retient_le_type_et_le_code(): void
    {
        Mail::fake();
        $this->demander();
        app(RepertoireSirene::class)->exercer(self::SIREN, '62.01Z');

        $this->confirmer($this->lienEnvoye(), TypeDActivite::Services)->assertRedirect();

        $societe = Societe::query()->sole();
        $this->assertSame(TypeDActivite::Services, $societe->type_activite);
        $this->assertArrayNotHasKey('code_activite', $societe->getAttributes());
        [$inscrite] = $this->entreesDAudit($societe, ActionAuditee::SocieteInscrite);
        $this->assertSame(
            ['code_activite' => '62.01Z', 'nomenclature_activite' => 'NAFRev2', 'type_activite' => TypeDActivite::Services->value],
            array_intersect_key($inscrite->nouvelles_valeurs, array_flip(['type_activite', 'code_activite', 'nomenclature_activite'])),
        );
    }

    /**
     * @return array<string, array{0: TypeDActivite, 1: TypeDActivite}>
     */
    public static function passagesDUnTypeAUnAutre(): array
    {
        $passages = [];

        foreach (TypeDActivite::cases() as $de) {
            foreach (TypeDActivite::cases() as $vers) {
                if ($de !== $vers) {
                    $passages["{$de->value} vers {$vers->value}"] = [$de, $vers];
                }
            }
        }

        return $passages;
    }

    /**
     * La base elle-même refuse un type inconnu et tout passage d'un type à
     * un autre, même par une requête qui ne passe pas par le modèle.
     */
    #[DataProvider('passagesDUnTypeAUnAutre')]
    public function test_la_base_refuse_un_type_inconnu_et_toute_modification_du_type(TypeDActivite $de, TypeDActivite $vers): void
    {
        $societe = Societe::factory()->create(['type_activite' => $de]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $societe->id)->update(['type_activite' => $vers->value])), QueryException::class, 'le type d\'activité d\'une société ne change jamais');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $societe->id)->update(['type_activite' => 'boulangerie'])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->insert(['uuid' => (string) Str::uuid(), 'siren' => SocieteFactory::sirenFictif(), 'type_activite' => 'boulangerie'])), QueryException::class, 'type d\'activité inconnu');

        $this->assertSame($de->value, DB::table('societes')->where('id', $societe->id)->value('type_activite'));
        $this->assertSame(1, DB::table('societes')->count());
    }

    /**
     * Un cabinet d'expertise comptable que le répertoire dit d'une autre
     * activité que 69.20Z est refusé au formulaire quand le répertoire
     * répond, et toujours à la confirmation : aucun courrier ne part, le
     * lien reste valable et le SIREN reste libre.
     */
    public function test_un_cabinet_hors_69_20z_est_refuse_au_formulaire_et_a_la_confirmation_sans_courrier_et_le_siren_reste_libre(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        Mail::fake();

        $this->demander(['type_activite' => TypeDActivite::Cabinet->value])
            ->assertHasFormErrors(['type_activite' => fn (array $reglesEnEchec, array $messages): bool => $messages === [ControleSirene::MOTIF_ACTIVITE_NON_COMPTABLE]]);
        $this->assertSame(0, DemandeInscription::query()->count());

        $this->travel(61)->seconds();
        $repertoire = app(RepertoireSirene::class);
        $repertoire->rendreInjoignable();
        $this->demander(['type_activite' => TypeDActivite::Cabinet->value])->assertHasNoFormErrors();
        $repertoire->rendreInjoignable(false);

        $this->confirmer($this->lienEnvoye(), TypeDActivite::Cabinet)->assertOk()->assertSeeText(ControleSirene::MOTIF_ACTIVITE_NON_COMPTABLE);

        $this->assertSame([0, []], [Societe::query()->count(), app(ServiceEnvoiRecommande::class)->envois()]);
        $this->assertNull(DemandeInscription::query()->sole()->consommee_le);
        $this->assertFalse(Societe::sirenPris(self::SIREN));
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function activitesQuiNeRefusentPasLeCabinet(): array
    {
        return [
            'code absent' => [self::SIREN, null, 'NAFRev2'],
            'autre nomenclature' => [self::SIREN, '74.1A', 'NAFRev1'],
            'activités comptables' => [FauxRepertoireSirene::SIREN_CABINET, '69.20Z', 'NAFRev2'],
        ];
    }

    /**
     * Un code absent ou d'une autre nomenclature ne refuse pas un cabinet,
     * dont l'éditeur contrôlera alors l'activité avec l'Ordre, pas plus que
     * le code 69.20Z.
     */
    #[DataProvider('activitesQuiNeRefusentPasLeCabinet')]
    public function test_un_cabinet_sans_code_ou_d_une_autre_nomenclature_n_est_pas_refuse(string $siren, ?string $code, ?string $nomenclature): void
    {
        Mail::fake();
        app(RepertoireSirene::class)->exercer($siren, $code, $nomenclature);

        $this->demander(['siren' => $siren, 'type_activite' => TypeDActivite::Cabinet->value])->assertHasNoFormErrors();
        $this->confirmer($this->lienEnvoye(), TypeDActivite::Cabinet)->assertRedirect();

        $this->assertSame([$siren, TypeDActivite::Cabinet, true], [Societe::query()->sole()->siren, Societe::query()->sole()->type_activite, Societe::query()->sole()->attendSonCode()]);
        $this->assertCount(1, app(ServiceEnvoiRecommande::class)->envois());
    }

    /**
     * Un cabinet n'a jamais de magasin ni de démonstration : la politique
     * le refuse à son propriétaire, les actions le refusent avec leur
     * motif, et la base elle-même refuse un magasin dans un cabinet, à la
     * création comme par le déplacement d'un magasin existant.
     */
    public function test_un_cabinet_n_a_jamais_de_magasin_ni_de_demonstration(): void
    {
        $cabinet = $this->inscrireUnCabinet();
        $proprietaire = $cabinet->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();

        $this->assertSame([false, false, false], [
            $proprietaire->can('creerUnMagasin', $cabinet),
            $proprietaire->can('gererLesMagasins', $cabinet),
            $proprietaire->can('ouvrirLaDemonstration', $cabinet),
        ]);
        $this->assertThrows(
            fn () => app(CreerEtablissementAction::class)->handle($proprietaire, $cabinet, ['nom' => 'Cabinet', 'nic' => '00010']),
            LogicException::class,
            CreerEtablissementAction::MESSAGE_CABINET_SANS_MAGASIN,
        );
        $this->assertThrows(
            fn () => app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $cabinet),
            VerificationRefuseeException::class,
            CreerEtablissementAction::MESSAGE_CABINET_SANS_MAGASIN,
        );

        $ailleurs = Magasin::factory()->create();
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => Magasin::forceCreate([
            'societe_id' => $cabinet->id,
            'nom' => OuvrirLaDemonstrationAction::NOM,
            'code' => 'DEMO',
            'est_demonstration' => true,
            'pays' => 'FR',
            'territoire' => Territoire::Metropole,
        ])), QueryException::class, 'un cabinet d\'expertise comptable n\'a aucun magasin');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins')->where('id', $ailleurs->id)->update(['societe_id' => $cabinet->id])), QueryException::class, 'un cabinet d\'expertise comptable n\'a aucun magasin');

        $this->assertSame(0, Magasin::query()->where('societe_id', $cabinet->id)->count());
    }

    /**
     * Un cabinet ne configure que son identité et ses collaborateurs
     * (CLAUDE.md, « Vérification de la société »), dans l'espace du cabinet
     * : son propriétaire ne crée ni membre ni administrateur, et n'ouvre ni
     * ne modifie de réglages fiscaux ; les
     * actions le refusent avec leur motif, sans rien écrire, et l'espace
     * d'administration d'une société, où ces écrans se trouvent, lui reste
     * fermé : leurs adresses sous le uuid du cabinet le mènent à l'espace
     * du cabinet ; il crée ses collaborateurs. Le propriétaire d'une
     * société qui a des magasins, lui, crée son équipe et règle sa
     * fiscalité, jamais un collaborateur.
     */
    public function test_un_cabinet_ne_configure_que_son_identite_et_ses_collaborateurs_ni_equipe_de_magasin_ni_reglages_fiscaux(): void
    {
        $commerce = $this->inscrireUneSociete(self::SIREN);
        $proprietaireDuCommerce = $commerce->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();
        $this->assertSame([true, true, true, true, false], [
            $proprietaireDuCommerce->can('create', User::class),
            $proprietaireDuCommerce->can('creerUnAdministrateur', User::class),
            $proprietaireDuCommerce->can('voirLesParametresFiscaux', $commerce),
            $proprietaireDuCommerce->can('modifierLesParametresFiscaux', $commerce),
            $proprietaireDuCommerce->can('creerUnCollaborateur', User::class),
        ]);

        $cabinet = $this->inscrireUnCabinet();
        $proprietaire = $cabinet->proprietaire()->firstOrFail();
        $avant = $cabinet->fresh()->only(ModifierParametresFiscauxSocieteAction::CHAMPS);
        app(TenantContext::class)->clear();

        $this->assertSame([false, false, false, false, true, true, true], [
            $proprietaire->can('create', User::class),
            $proprietaire->can('creerUnAdministrateur', User::class),
            $proprietaire->can('voirLesParametresFiscaux', $cabinet),
            $proprietaire->can('modifierLesParametresFiscaux', $cabinet),
            $proprietaire->can('voirLesParametres', $cabinet),
            $proprietaire->can('modifierLesParametres', $cabinet),
            $proprietaire->can('creerUnCollaborateur', User::class),
        ]);
        $this->assertThrows(
            fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $cabinet, ['name' => 'Camille Durand'], null, [], AutorisationsSociete::toutes()),
            LogicException::class,
            CreerAdministrateurAction::MESSAGE_CABINET,
        );
        $this->assertThrows(
            fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $cabinet, ['regime_tva' => RegimeTva::FranchiseEnBase->value, 'mois_fin_exercice' => 6]),
            LogicException::class,
            ModifierParametresFiscauxSocieteAction::MESSAGE_CABINET,
        );
        $this->assertSame([$proprietaire->id], User::query()->where('societe_id', $cabinet->id)->pluck('id')->all());
        $this->assertSame($avant, $cabinet->fresh()->only(ModifierParametresFiscauxSocieteAction::CHAMPS));

        $this->dansLEspaceDuCabinet($cabinet->fresh(), $proprietaire);
        $this->get(IdentiteDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))->assertOk();
        $this->get(ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $cabinet))->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet));
        $this->get(MembreResource::getUrl('index', panel: 'societe', tenant: $cabinet))->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet));
    }

    /**
     * Aucun texte de la vérification ne parle à un cabinet de magasins ni
     * de démonstration : le bandeau, l'avis de validation, l'avis et
     * l'e-mail de clôture (sa page de la vérification, dans l'espace du
     * cabinet, voir CabinetInscriptionTest).
     */
    public function test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration(): void
    {
        Mail::fake();
        $cabinet = $this->inscrireUnCabinet();
        $proprietaire = $cabinet->proprietaire()->firstOrFail();

        $bandeau = strip_tags(view('filament.verification-en-cours', ['magasin' => null, 'societe' => $cabinet])->render());
        $this->assertStringContainsString('Cabinet en attente du code de son courrier recommandé.', $bandeau);
        $this->assertTextesSansMagasinNiDemonstration([$bandeau]);

        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->parLEditeur($cabinet, self::editeur(), MotifCloture::Refus);

        $valide = $this->inscrireUnCabinet(SocieteFactory::sirenFictif());
        $this->saisirLeCodeDuCourrier($valide);

        $avis = [
            ...$proprietaire->fresh()->notifications()->get()->pluck('data.body')->all(),
            ...$valide->proprietaire()->firstOrFail()->notifications()->get()->pluck('data.body')->all(),
        ];
        $this->assertContains('Votre cabinet est validé.', $avis);
        $mail = Mail::sent(InscriptionCloseMail::class)->sole();
        $this->assertTextesSansMagasinNiDemonstration([...$avis, $mail->render()]);
        $mail->assertSeeInText('Son espace reste consultable, en lecture seule.');
    }

    /**
     * @return array<string, array{0: TypeDActivite, 1: string, 2: string}>
     */
    public static function avertissements(): array
    {
        return [
            'services et 47.42Z' => [TypeDActivite::Services, '47.42Z', 'Votre code d\'activité au répertoire Sirene est 47.42Z : ces entreprises vendent en général des articles. Avec « Services, sans vente d\'articles », vous ne pourrez pas en vendre.'],
            'services et 95.12Z' => [TypeDActivite::Services, '95.12Z', 'Votre code d\'activité au répertoire Sirene est 95.12Z : ces entreprises vendent en général des articles.'],
            'commerce et 10.71C' => [TypeDActivite::Commerce, '10.71C', 'Votre code d\'activité est 10.71C, une activité de fabrication'],
            'commerce et 43.22A' => [TypeDActivite::Commerce, '43.22A', 'Votre code d\'activité est 43.22A, une activité du bâtiment. Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale, par exemple), renseignez-la dans l\'identité de votre société :'],
            'services et 43.22A' => [TypeDActivite::Services, '43.22A', 'ne prend pas encore en charge la sous-traitance où la TVA est due par le donneur d\'ordre (autoliquidation), les factures d\'acompte et de situation, ni la retenue de garantie.'],
            'services et 69.20Z' => [TypeDActivite::Services, '69.20Z', 'Votre code d\'activité est 69.20Z (activités comptables) : si vous êtes inscrit à l\'Ordre des experts-comptables, choisissez « Cabinet d\'expertise comptable ».'],
            'commerce et 86.90D' => [TypeDActivite::Commerce, '86.90D', 'Si votre activité est exonérée de TVA (soins, enseignement, assurance, services financiers…)'],
            'services et 68.20A' => [TypeDActivite::Services, '68.20A', 'La location de logements est en général exonérée de TVA (art. 261 D du CGI)'],
            'commerce et 47.91B' => [TypeDActivite::Commerce, '47.91B', 'Au-delà de 10 000 € par an de ventes à distance à des particuliers d\'autres pays de l\'Union européenne, la TVA due est celle du pays de l\'acheteur'],
            'commerce et 56.10A' => [TypeDActivite::Commerce, '56.10A', 'ne prend pas en charge la vente de ce que vous préparez vous-même, ni celle d\'un même article acheté à deux taux (sur place et à emporter), ni le titre-restaurant.'],
            'commerce et 47.22Z' => [TypeDActivite::Commerce, '47.22Z', 'ne vend pas encore au poids : les quantités sont des nombres entiers.'],
        ];
    }

    /**
     * Chaque avertissement du code d'activité s'affiche sur la page du
     * lien, et la société s'inscrit quand même avec le type choisi : aucun
     * ne bloque.
     */
    #[DataProvider('avertissements')]
    public function test_les_avertissements_du_code_d_activite_ne_bloquent_jamais(TypeDActivite $type, string $code, string $avertissement): void
    {
        Mail::fake();
        app(RepertoireSirene::class)->exercer(self::SIREN, $code);
        $this->demander(['type_activite' => $type->value])->assertHasNoFormErrors();
        $lien = $this->lienEnvoye();

        $this->get($lien)->assertOk()->assertSeeText($avertissement);
        $this->confirmer($lien, $type)->assertRedirect();

        $this->assertSame([$type, true], [Societe::query()->sole()->type_activite, Societe::query()->sole()->attendSonCode()]);
    }

    /**
     * Un code absent, inconnu ou d'une autre nomenclature que la NAF rév. 2
     * n'avertit de rien.
     */
    public function test_aucun_avertissement_pour_un_code_absent_ou_d_une_autre_nomenclature(): void
    {
        $this->assertSame([[], [], [], []], [
            AvertissementsDuCodeDActivite::pour(null, 'NAFRev2'),
            AvertissementsDuCodeDActivite::pour('47.91B', null),
            AvertissementsDuCodeDActivite::pour('47.91B', 'NAFRev1'),
            AvertissementsDuCodeDActivite::pour('4791B', 'NAFRev2'),
        ]);

        Mail::fake();
        app(RepertoireSirene::class)->exercer(self::SIREN, '56.10A', 'NAF2025');
        $this->demander()->assertHasNoFormErrors();

        $this->get($this->lienEnvoye())->assertOk()->assertDontSeeText('D\'après le code d\'activité de votre entreprise');
    }

    /**
     * Le courrier recommandé de l'inscription rappelle le type choisi, qui
     * ne pourra plus changer, et ce qu'il faut faire s'il ne convient pas ;
     * le courrier d'une procédure de l'éditeur n'en dit rien.
     */
    public function test_le_courrier_recommande_rappelle_le_type_choisi(): void
    {
        $societe = $this->inscrireUneSociete(type: TypeDActivite::Services);
        $envois = app(ServiceEnvoiRecommande::class);
        $verification = $this->texteDuPdf(collect($envois->envois())->sole()['pdf']);

        $this->assertStringContainsString('Type d\'activité choisi à l\'inscription : Services, sans vente d\'articles. Il ne pourra plus changer. S\'il ne convient pas, ne saisissez pas ce code et contactez l\'éditeur : l\'inscription sera close et votre entreprise pourra s\'inscrire de nouveau avec le bon type.', $verification);

        $this->saisirLeCodeDuCourrier($societe);
        app(TenantContext::class)->clear();
        $courrier = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Recuperation, self::editeur());

        $this->assertStringNotContainsString('Type d\'activité', $this->texteDuPdf($envois->envois()[$courrier->reference_envoi]['pdf']));
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function types(): array
    {
        return [
            'commerce ou atelier' => [TypeDActivite::Commerce],
            'services sans vente d\'articles' => [TypeDActivite::Services],
        ];
    }

    /**
     * La page « Vérification » rappelle le type tant que le code n'est pas
     * saisi, par la vue partielle du rappel ; la page « Identité » le
     * montre en lecture seule, sans champ qui le change : un type envoyé
     * avec l'identité est ignoré. Les pages d'un cabinet, dans l'espace du
     * cabinet, voir CabinetInscriptionTest.
     */
    #[DataProvider('types')]
    public function test_la_verification_et_l_identite_rappellent_le_type_sans_permettre_de_le_changer(TypeDActivite $type): void
    {
        $societe = $this->inscrireUneSociete(self::SIREN, type: $type);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $rappel = trim(html_entity_decode(strip_tags(view('filament.rappel-du-type', ['societe' => $societe])->render()), ENT_QUOTES));
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);

        $this->assertSame("Votre société est inscrite comme « {$type->getLabel()} ». Si ce type est faux, ne saisissez pas le code : contactez l'éditeur, qui clôt l'inscription et libère votre SIREN.", $rappel);
        $this->get(VerificationSociete::getUrl(panel: 'societe', tenant: $societe))->assertOk()->assertSeeText($rappel);
        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $societe))->assertOk()->assertSeeText(['Type d\'activité', $type->getLabel()]);
        $autre = $type === TypeDActivite::Commerce ? TypeDActivite::Services : TypeDActivite::Commerce;
        Livewire::test(IdentiteSociete::class)
            ->assertFormFieldDoesNotExist('type_activite')
            ->fillForm(['capital_social' => '1000', 'immatriculation' => 'RCS Paris'])
            ->set('data.type_activite', $autre->value)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($type, $societe->fresh()->type_activite);

        $proprietaire = $this->saisirLeCodeDuCourrier($societe);
        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);
        $this->get(VerificationSociete::getUrl(panel: 'societe', tenant: $societe))->assertOk()->assertDontSeeText('Votre société est inscrite comme');
    }

    /**
     * Après la clôture d'une inscription pour un type d'activité choisi par
     * erreur, la même personne inscrit de nouveau son entreprise, avec le
     * même SIREN et la même adresse, tous deux libérés, et un autre type :
     * une autre société, sous un autre compte, avec un autre identifiant.
     * Le compte de l'inscription close reste, sans adresse de connexion :
     * il ne s'ouvre plus qu'avec son identifiant.
     */
    public function test_apres_une_cloture_pour_type_errone_la_meme_personne_se_reinscrit_avec_le_meme_siren_la_meme_adresse_et_un_autre_type(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        Mail::fake();
        $close = $this->inscrireUneSociete(self::SIREN, self::ADRESSE);
        $ancienCompte = $close->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->parLEditeur($close, self::editeur(), MotifCloture::TypeErrone);
        app(TenantContext::class)->clear();

        $this->demander(['type_activite' => TypeDActivite::Services->value])->assertHasNoFormErrors();
        $this->confirmer($this->lienEnvoye(), TypeDActivite::Services)->assertRedirect();

        $nouvelle = Societe::query()->whereKeyNot($close->id)->sole();
        $nouveauCompte = $nouvelle->proprietaire()->firstOrFail();
        $ancienCompte = $ancienCompte->fresh();
        $this->assertSame(
            [self::SIREN, TypeDActivite::Services, true, self::ADRESSE],
            [$nouvelle->siren, $nouvelle->type_activite, $nouvelle->attendSonCode(), $nouveauCompte->email],
        );
        $this->assertSame([true, MotifCloture::TypeErrone, TypeDActivite::Commerce], [$close->fresh()->estClose(), $close->fresh()->motif_cloture, $close->fresh()->type_activite]);
        $this->assertSame([null, (int) $close->id], [$ancienCompte->email, (int) $ancienCompte->societe_id]);
        $this->assertNotSame($ancienCompte->id, $nouveauCompte->id);
        $this->assertNotSame($ancienCompte->identifiantDeConnexion(), $nouveauCompte->identifiantDeConnexion());
        $this->assertStringContainsString('Votre entreprise peut s\'inscrire de nouveau avec le bon type d\'activité, avec la même adresse e-mail et le même SIREN', $ancienCompte->notifications()->sole()->data['body']);
    }

    /**
     * @param  list<string>  $textes
     */
    private function assertTextesSansMagasinNiDemonstration(array $textes): void
    {
        foreach ($textes as $texte) {
            $this->assertStringNotContainsStringIgnoringCase('magasin', $texte);
            $this->assertStringNotContainsStringIgnoringCase('démonstration', $texte);
        }
    }

    /**
     * Un cabinet d'expertise comptable inscrit comme par la confirmation de
     * son lien, qui attend le code de son courrier ; d'activité 69.20Z.
     */
    private function inscrireUnCabinet(string $siren = FauxRepertoireSirene::SIREN_CABINET): Societe
    {
        app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);

        return $this->inscrireUneSociete($siren, type: TypeDActivite::Cabinet);
    }

    private static function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur('Type d\'activité choisi par erreur, demande écrite du propriétaire.', 'DOSSIER-2026-0090', ['alice']);
    }

    /**
     * Le texte d'un PDF, ses lignes mises bout à bout.
     */
    private function texteDuPdf(string $pdf): string
    {
        return implode(' ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));
    }

    /**
     * La demande d'inscription remplie et envoyée : « Commerce ou atelier »,
     * sauf autre type.
     *
     * @param  array<string, string|null>  $remplacements
     */
    private function demander(array $remplacements = []): Testable
    {
        return Livewire::test(InscriptionSociete::class)
            ->fillForm([
                'siren' => self::SIREN,
                'type_activite' => TypeDActivite::Commerce->value,
                'email' => self::ADRESSE,
                'password' => self::MOT_DE_PASSE_CHOISI,
                'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI,
                ...$remplacements,
            ])
            ->call('register');
    }

    /**
     * Le formulaire de la page du lien envoyé avec ce type et la case du
     * choix définitif.
     */
    private function confirmer(string $lien, TypeDActivite $type): TestResponse
    {
        return $this->post($lien, ['type_activite' => $type->value, 'choix_definitif' => '1']);
    }

    /**
     * Le lien du dernier e-mail de demande envoyé.
     */
    private function lienEnvoye(): string
    {
        return Mail::sent(DemandeInscriptionMail::class)->last()->lien;
    }
}
