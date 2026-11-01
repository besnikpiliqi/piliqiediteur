<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\Cabinet\Pages\IdentiteDuCabinet;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\Pages\VerificationDuCabinet;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'inscription d'un cabinet d'expertise comptable (voir CreerSocieteAction
 * et Cabinet) : sa ligne de vérification naît avec la société, avec le
 * code d'activité lu au répertoire Sirene à la confirmation, contrôlé quand
 * il est 69.20Z en NAF rév. 2, sinon à contrôler par l'éditeur ; aucune
 * autre société n'en a ; le lien qu'une société envoie à son
 * expert-comptable présélectionne le cabinet ; son propriétaire arrive
 * dans l'espace du cabinet, jamais dans l'espace d'administration d'une
 * société, sur la liste de ses étapes ; la revérification mensuelle relit
 * son code d'activité ; ses pages de la vérification et de l'identité ne
 * parlent ni de magasins ni de démonstration. Les refus hors 69.20Z,
 * l'absence de magasin et l'immuabilité du type sont dans
 * TypeDActiviteTest.
 */
class CabinetInscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ADRESSE = 'cabinet@example.test';

    public function test_un_cabinet_inscrit_a_sa_ligne_cabinets_avec_le_code_lu_et_son_activite_controlee(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);

        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame(['69.20Z', 'NAFRev2'], [$verification->code_activite, $verification->nomenclature_activite]);
        $this->assertMemeInstant(self::aParis('2026-10-05 10:00:00'), $verification->activite_controlee_le);
        $this->assertSame([null, null, null], [$verification->ordre_verifie_le, $verification->ordre_demande_le, $verification->numero_ordre]);
        [$controle] = $this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee);
        $this->assertSame(['code_activite' => '69.20Z', 'nomenclature' => 'NAFRev2', 'resultat' => 'concluant'], $controle->nouvelles_valeurs);
        [$inscrite] = $this->entreesDAudit($cabinet, ActionAuditee::SocieteInscrite);
        $this->assertSame('inscription', $inscrite->nouvelles_valeurs['origine']);
    }

    /**
     * Un code absent, ou d'une autre nomenclature, ne refuse pas le cabinet
     * : son activité reste à contrôler, et l'éditeur la tient pour
     * contrôlée dans la procédure de l'Ordre, avec --activite-controlee.
     */
    public function test_une_nomenclature_inconnue_laisse_l_activite_a_controler_par_l_editeur(): void
    {
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($siren, '69.20', 'NAF2025');

        $cabinet = $this->inscrireUneSociete($siren, type: TypeDActivite::Cabinet);

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame(['69.20', 'NAF2025', null], [$verification->code_activite, $verification->nomenclature_activite, $verification->activite_controlee_le]);
        $this->assertSame('a_controler', $this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee)[0]->nouvelles_valeurs['resultat']);
        $this->saisirLeCodeDuCourrier($cabinet);
        $this->assertFalse($cabinet->fresh()->estUnCabinetVerifie());

        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $this->artisan('editeur:cabinet:verifier-ordre', [
            'societe' => (string) $cabinet->id,
            '--numero' => '75-01234',
            '--conseil' => 'Île-de-France',
            '--activite-controlee' => true,
            '--justification' => 'Code NAF 2025 contrôlé et inscription lue à l\'annuaire de l\'Ordre',
            '--piece' => 'ORDRE-2026-0001',
            '--confirmer' => $siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice')
            ->assertSuccessful();

        $this->assertNotNull($cabinet->cabinet()->sole()->activite_controlee_le);
        $this->assertSame(['a_controler', 'controle_par_l_editeur'], array_map(fn ($entree): string => $entree->nouvelles_valeurs['resultat'], $this->entreesDAudit($cabinet, ActionAuditee::ActiviteDuCabinetControlee)));
        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesQuiOntDesMagasins(): array
    {
        return [
            'commerce ou atelier' => [TypeDActivite::Commerce],
            'services sans vente d\'articles' => [TypeDActivite::Services],
        ];
    }

    #[DataProvider('typesQuiOntDesMagasins')]
    public function test_une_societe_qui_n_est_pas_un_cabinet_n_a_jamais_de_ligne_cabinets(TypeDActivite $type): void
    {
        $societe = $this->inscrireUneSociete(type: $type);

        $this->assertSame(0, DB::table('cabinets')->count());
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::ActiviteDuCabinetControlee));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('cabinets')->insert(['societe_id' => $societe->id, 'created_at' => now(), 'updated_at' => now()])), QueryException::class, 'seul un cabinet d\'expertise comptable a une vérification de cabinet');
    }

    /**
     * Le lien qu'une société envoie à son expert-comptable (?type=cabinet)
     * présélectionne « Cabinet d'expertise comptable » ; la page du lien
     * d'inscription montre encore le menu, modifiable, et la case du choix
     * définitif. Sans ce paramètre, rien n'est présélectionné.
     */
    public function test_le_lien_d_une_societe_vers_l_inscription_preselectionne_le_cabinet(): void
    {
        Mail::fake();
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);

        Livewire::test(InscriptionSociete::class)->assertSet('data.type_activite', null);
        Livewire::withQueryParams(['type' => 'cabinet'])
            ->test(InscriptionSociete::class)
            ->assertSet('data.type_activite', TypeDActivite::Cabinet->value)
            ->fillForm([
                'siren' => FauxRepertoireSirene::SIREN_CABINET,
                'email' => self::ADRESSE,
                'password' => self::MOT_DE_PASSE_CHOISI,
                'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('register');

        $mail = Mail::sent(DemandeInscriptionMail::class)->sole();
        $this->get($mail->lien)
            ->assertOk()
            ->assertSee('name="type_activite"', false)
            ->assertSee('name="choix_definitif"', false)
            ->assertSeeText([TypeDActivite::Commerce->getLabel(), TypeDActivite::Services->getLabel(), TypeDActivite::Cabinet->getLabel()]);
    }

    /**
     * Après la confirmation de son inscription, le propriétaire d'un
     * cabinet arrive dans l'espace du cabinet, sur sa liste d'étapes ; à
     * chaque connexion, le panneau des magasins l'y mène ; l'espace
     * d'administration d'une société lui reste fermé.
     */
    public function test_le_proprietaire_d_un_cabinet_arrive_dans_l_espace_du_cabinet_et_jamais_dans_l_espace_societe(): void
    {
        Mail::fake();
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        Livewire::test(InscriptionSociete::class)
            ->fillForm([
                'siren' => FauxRepertoireSirene::SIREN_CABINET,
                'type_activite' => TypeDActivite::Cabinet->value,
                'email' => self::ADRESSE,
                'password' => self::MOT_DE_PASSE_CHOISI,
                'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI,
            ])
            ->call('register');

        $reponse = $this->post(Mail::sent(DemandeInscriptionMail::class)->sole()->lien, ['type_activite' => TypeDActivite::Cabinet->value, 'choix_definitif' => '1']);

        $cabinet = Societe::query()->sole();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $reponse->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet));
        $this->assertAuthenticatedAs($proprietaire);
        $this->assertSame([true, false, true], [
            $proprietaire->canAccessPanel(Filament::getPanel('cabinet')),
            $proprietaire->canAccessPanel(Filament::getPanel('societe')),
            $proprietaire->canAccessPanel(Filament::getPanel('app')),
        ]);
        $this->assertSame([$cabinet->id], $proprietaire->getTenants(Filament::getPanel('cabinet'))->modelKeys());
        $this->assertSame([], $proprietaire->getTenants(Filament::getPanel('societe'))->modelKeys());

        $this->get(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSeeText([TableauDeBordDuCabinet::ETAPE_CODE_DU_COURRIER, TableauDeBordDuCabinet::ETAPE_ORDRE, 'Propriétaire du cabinet']);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $cabinet))->assertForbidden();
        $this->get(route('filament.app.tenant'))->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet));
    }

    /**
     * La revérification mensuelle relit le code d'activité d'un cabinet
     * validé : un code devenu autre que 69.20Z alerte son propriétaire et
     * l'éditeur, sans rien bloquer : le cabinet reste vérifié.
     */
    public function test_la_reverification_mensuelle_relit_l_activite_d_un_cabinet_et_alerte_sans_rien_bloquer(): void
    {
        Mail::fake();
        config(['editeur.email' => 'alertes@editeur.test']);
        $cabinet = $this->ouvrirUnCabinet();
        app(RepertoireSirene::class)->exercer($cabinet->siren, '70.22Z');

        app(ReverificationSirene::class)->reprendre($cabinet, true);

        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => str_contains(implode(' ', $mail->lignes), '70.22Z'));
        $this->assertContains(
            'Le code d\'activité de votre cabinet au répertoire Sirene est désormais 70.22Z, et non plus 69.20Z (activités comptables). Vos ventes continuent ; l\'éditeur, prévenu, pourra vous contacter.',
            $this->proprietaireDuCabinet($cabinet)->notifications()->get()->pluck('data.body')->all(),
        );
    }

    /**
     * La liste des étapes du propriétaire d'un cabinet : le code du
     * courrier, le code d'activité, l'inscription à l'Ordre et ses
     * collaborateurs, chacune faite à son tour, sans magasin ni
     * démonstration ; toutes faites, elle disparaît ; un collaborateur n'en
     * a aucune.
     */
    public function test_la_liste_d_etapes_du_cabinet_ne_parle_ni_de_magasins_ni_de_demonstration(): void
    {
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        $tableau = Livewire::test(TableauDeBordDuCabinet::class);
        $etapes = $tableau->instance()->etapes();

        $this->assertSame([TableauDeBordDuCabinet::ETAPE_CODE_DU_COURRIER, TableauDeBordDuCabinet::ETAPE_ACTIVITE, TableauDeBordDuCabinet::ETAPE_ORDRE, TableauDeBordDuCabinet::ETAPE_COLLABORATEURS], array_column($etapes, 'titre'));
        $this->assertSame([false, true, false, false], array_column($etapes, 'faite'));
        $this->assertSame('Votre cabinet attend le code de son courrier recommandé.', $tableau->instance()->etatDeLaVerification());
        foreach ([...array_column($etapes, 'etat'), $tableau->instance()->etatDeLaVerification()] as $texte) {
            $this->assertStringNotContainsStringIgnoringCase('magasin', $texte);
            $this->assertStringNotContainsStringIgnoringCase('démonstration', $texte);
        }
        $tableau->assertSeeText(TableauDeBordDuCabinet::ETAPE_ORDRE)->assertDontSeeText(['magasin', 'démonstration']);

        $verifie = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif());
        $collaborateur = $this->collaborateur($verifie);
        $this->dansLEspaceDuCabinet($verifie, $this->proprietaireDuCabinet($verifie));

        $this->assertFalse(Livewire::test(TableauDeBordDuCabinet::class)->instance()->resteDesEtapes());

        $this->dansLEspaceDuCabinet($verifie, $collaborateur);
        $this->assertSame([], Livewire::test(TableauDeBordDuCabinet::class)->instance()->etapes());
    }

    /**
     * La page de la vérification du cabinet rappelle son type tant que le
     * code n'est pas saisi, par la vue partielle du rappel, et ne parle ni
     * de magasins ni de démonstration, en attente du code comme après la
     * clôture de l'inscription ; le bandeau de l'espace du cabinet dit
     * seulement qu'il attend son code.
     */
    public function test_la_verification_du_cabinet_rappelle_son_type_et_ne_parle_ni_de_magasins_ni_de_demonstration(): void
    {
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $rappel = trim(html_entity_decode(strip_tags(view('filament.rappel-du-type', ['societe' => $cabinet])->render()), ENT_QUOTES));
        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);

        $page = $this->get(VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSeeText([$rappel, 'Votre cabinet attend le code de son courrier recommandé.', 'Cabinet en attente du code de son courrier recommandé.', 'Le courrier recommandé', 'Code d\'activité', 'Inscription au tableau de l\'Ordre des experts-comptables']);
        $this->assertSansMagasinNiDemonstration($page->getContent());

        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->parLEditeur($cabinet, new InterventionDeLEditeur('Refus après examen des pièces du courrier non reçu', 'DOSSIER-2026-0091', ['alice']), MotifCloture::Refus);
        $close = $cabinet->fresh();
        $this->dansLEspaceDuCabinet($close, $proprietaire->fresh());

        $page = $this->get(VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $close))
            ->assertOk()
            ->assertSeeText('L\'inscription de votre cabinet est close (Refus de l\'éditeur) : son espace reste consultable, en lecture seule.')
            ->assertDontSeeText($rappel);
        $this->assertSansMagasinNiDemonstration($page->getContent());
    }

    /**
     * La page de l'identité du cabinet montre son SIREN et son type en
     * lecture seule, sans bloc d'assurance ni encadré de ce qui manque pour
     * facturer : un champ d'assurance forgé n'est pas enregistré.
     */
    public function test_l_identite_du_cabinet_montre_son_type_sans_bloc_d_assurance(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);

        $this->get(IdentiteDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSeeText(['Identité du cabinet', 'Identité légale', 'Type d\'activité', TypeDActivite::Cabinet->getLabel(), (string) $cabinet->siren])
            ->assertDontSeeText(['Assurance professionnelle', 'Assureur ou garant', 'pour facturer un professionnel']);
        Livewire::test(IdentiteDuCabinet::class)
            ->assertFormFieldIsHidden('assurance_assureur')
            ->assertFormFieldDoesNotExist('type_activite')
            ->fillForm(['assurance_assureur' => 'Mutuelle du Bâtiment', 'capital_social' => '5000', 'immatriculation' => 'RCS Paris'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['immatriculation' => 'RCS Paris', 'assurance_assureur' => null, 'assurance_adresse' => null, 'assurance_contrat' => null, 'assurance_couverture' => null], $cabinet->fresh()->only(['immatriculation', ...ModifierIdentiteSocieteAction::CHAMPS_DE_L_ASSURANCE]));
    }

    private function assertSansMagasinNiDemonstration(string $html): void
    {
        $texte = html_entity_decode(strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $html)), ENT_QUOTES);

        $this->assertStringNotContainsStringIgnoringCase('magasin', $texte);
        $this->assertStringNotContainsStringIgnoringCase('démonstration', $texte);
    }
}
