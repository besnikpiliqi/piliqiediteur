<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Mail\CompteExistantMail;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Pages\VerificationSociete as PageDeVerification;
use App\Filament\Societe\Resources\MagasinResource;
use App\Http\Controllers\ConfirmerInscriptionController;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'inscription d'une société en deux temps (voir InscriptionSociete et
 * ConfirmerInscriptionController) : la demande, contrôlée au répertoire
 * Sirene factice, n'envoie qu'un lien ; sa confirmation crée la société à
 * vérifier et son propriétaire, sans aucun magasin, et mène le propriétaire
 * à la liste de ses premières étapes. Aucune requête ne sort des tests
 * (répertoire factice, voir FauxRepertoireSirene).
 */
class InscriptionSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un SIREN fictif valide, d'une entreprise active dans le répertoire
     * factice.
     */
    private const SIREN = '100000009';

    public function test_la_demande_envoie_un_lien_et_ne_cree_rien_avant_sa_confirmation(): void
    {
        Mail::fake();

        $this->demander()
            ->assertHasNoFormErrors()
            ->assertSet('demandeEnvoyee', true)
            ->assertSee(InscriptionSociete::MESSAGE_NEUTRE);

        Mail::assertSent(DemandeInscriptionMail::class, fn (DemandeInscriptionMail $mail): bool => $mail->hasTo('jeanne@maboutique.fr')
            && str_starts_with($mail->lien, rtrim((string) config('app.url'), '/').'/inscription/'));
        $this->assertSame(1, DemandeInscription::query()->count());
        $this->assertSame([0, 0, 0], [Societe::query()->count(), User::query()->count(), Magasin::query()->count()]);
        $this->assertGuest();
    }

    /**
     * La demande ne porte que le SIREN et le compte du propriétaire : aucun
     * champ de magasin (nom, code, NIC), et rien d'un magasin dans la
     * demande enregistrée.
     */
    public function test_la_demande_ne_demande_ni_nom_ni_code_ni_nic_de_magasin(): void
    {
        Mail::fake();

        $this->demander()
            ->assertFormFieldExists('siren')
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('email')
            ->assertFormFieldDoesNotExist('nom_magasin')
            ->assertFormFieldDoesNotExist('code')
            ->assertFormFieldDoesNotExist('nic');

        $this->assertSame(['mot_de_passe', 'nom', 'siren'], collect(DemandeInscription::query()->sole()->donnees)->keys()->sort()->values()->all());
    }

    /**
     * La confirmation crée la société à vérifier et son propriétaire, dont
     * le lien suivi confirme l'adresse, sans aucun magasin : ni SIRET
     * réservé, ni registre, ni série de numérotation.
     */
    public function test_la_confirmation_cree_la_societe_a_verifier_et_son_proprietaire_sans_aucun_magasin(): void
    {
        Mail::fake();
        $this->demander();
        $lien = $this->lienEnvoye();

        $reponse = $this->post($lien);

        $societe = Societe::query()->sole();
        $proprietaire = User::query()->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame([self::SIREN, false, false], [$societe->siren, $societe->estValidee(), $societe->estRefusee()]);
        $this->assertSame(
            ['Jeanne Dupont', 'jeanne@maboutique.fr', RoleSociete::Proprietaire, $societe->id],
            [$proprietaire->name, $proprietaire->email, $proprietaire->role_societe, (int) $proprietaire->societe_id],
        );
        $this->assertNotNull($proprietaire->email_verified_at);
        $this->assertSame(
            [0, 0, 0, 0, 0],
            [Magasin::query()->count(), SiretMagasin::query()->count(), EnregistrementFiscal::withoutGlobalScopes()->count(), SequenceDocument::withoutGlobalScopes()->count(), PeriodeActivite::query()->count()],
        );
        $this->assertAuthenticatedAs($proprietaire);
        $this->assertNotNull(DemandeInscription::query()->sole()->consommee_le);
    }

    /**
     * Après son lien, le propriétaire arrive sur le tableau de bord de
     * l'espace de sa société, avec la liste de ses premières étapes, chacune
     * menant à son écran, aucune encore cochée : vérifier la société, créer
     * le premier magasin, ajouter l'équipe, essayer la démonstration.
     */
    public function test_apres_le_lien_le_proprietaire_arrive_sur_la_liste_de_ses_premieres_etapes(): void
    {
        Mail::fake();
        $this->demander();

        $this->followingRedirects()->post($this->lienEnvoye())
            ->assertOk()
            ->assertSeeTextInOrder(['Vos premières étapes', TableauDeBord::ETAPE_VERIFIER, TableauDeBord::ETAPE_PREMIER_MAGASIN, TableauDeBord::ETAPE_EQUIPE, TableauDeBord::ETAPE_DEMONSTRATION])
            ->assertSee([PageDeVerification::getUrl(panel: 'societe', tenant: Societe::query()->sole()), MagasinResource::getUrl('create', panel: 'societe', tenant: Societe::query()->sole())], false)
            ->assertSeeText('Prochaine étape : prouver votre qualité de représentant de l\'entreprise.')
            ->assertSeeText('Ouvrir le magasin de démonstration');
    }

    public function test_l_identite_et_l_adresse_du_siege_sont_preremplies_depuis_sirene(): void
    {
        Mail::fake();
        $this->demander();

        $this->post($this->lienEnvoye());

        $societe = Societe::query()->sole();
        $this->assertSame([
            'raison_sociale' => 'SOCIETE FICTIVE 100000009',
            'categorie_juridique_insee' => '5710',
            'forme_juridique' => FormeJuridique::Sas,
            'numero_tva' => 'FR88100000009',
            'siege_adresse' => '1 RUE DE LA REPUBLIQUE',
            'siege_code_postal' => '75001',
            'siege_ville' => 'PARIS',
            'capital_social' => null,
            'immatriculation' => null,
        ], $societe->only(['raison_sociale', 'categorie_juridique_insee', 'forme_juridique', 'numero_tva', 'siege_adresse', 'siege_code_postal', 'siege_ville', 'capital_social', 'immatriculation']));
        $this->assertSame(
            [[TypeVerification::SireneUnite, ResultatVerification::Concluante]],
            app(TenantContext::class)->runAsSociete($societe, fn (): array => VerificationSociete::query()->orderBy('id')->get()->map(fn (VerificationSociete $verification): array => [$verification->type, $verification->resultat])->all()),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusDuRepertoire(): array
    {
        return [
            'entreprise cessée' => [FauxRepertoireSirene::SIREN_CESSEE, ControleSirene::MOTIF_ENTREPRISE_CESSEE],
            'SIREN inconnu' => [FauxRepertoireSirene::SIREN_INCONNU, ControleSirene::MOTIF_ENTREPRISE_INCONNUE],
        ];
    }

    /**
     * Le répertoire public est consulté tout de suite : son refus se dit
     * avec son motif, et rien n'est demandé ni envoyé.
     */
    #[DataProvider('refusDuRepertoire')]
    public function test_une_entreprise_cessee_ou_un_numero_inconnu_est_refuse(string $siren, string $motif): void
    {
        Mail::fake();

        $this->demander(['siren' => $siren])
            ->assertHasFormErrors(['siren' => fn (array $reglesEnEchec, array $messages): bool => $messages === [$motif]]);

        $this->assertSame(0, DemandeInscription::query()->count());
        Mail::assertNothingSent();
    }

    /**
     * L'INSEE qui ne répond pas n'empêche ni la demande ni sa
     * confirmation : la société attend, sans identité préremplie ; le
     * contrôle repris complète son identité.
     */
    public function test_l_insee_injoignable_n_empeche_pas_l_inscription_et_le_controle_reprend(): void
    {
        Mail::fake();
        $repertoire = app(RepertoireSirene::class);
        $repertoire->rendreInjoignable();
        $this->demander()->assertHasNoFormErrors();
        $this->post($this->lienEnvoye());
        $societe = Societe::query()->sole();
        $this->assertNull($societe->raison_sociale);
        $repertoire->rendreInjoignable(false);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertSame(['SOCIETE FICTIVE 100000009', FormeJuridique::Sas, '1 RUE DE LA REPUBLIQUE'], [$societe->fresh()->raison_sociale, $societe->fresh()->forme_juridique, $societe->fresh()->siege_adresse]);
        $this->assertSame(
            [ResultatVerification::Indisponible, ResultatVerification::Concluante],
            app(TenantContext::class)->runAsSociete($societe, fn (): array => VerificationSociete::query()->orderBy('id')->pluck('resultat')->all()),
        );
    }

    /**
     * @return array<string, array{0: Closure(): mixed}>
     */
    public static function situationsDejaConnues(): array
    {
        return [
            'tout est libre' => [static fn (): mixed => null],
            'SIREN d\'une société validée' => [static fn (): mixed => Magasin::factory()->avecLaSociete(['siren' => self::SIREN])->create(['siret' => '10000000900025'])],
            'adresse d\'un compte' => [static fn (): mixed => User::factory()->create(['email' => 'jeanne@maboutique.fr'])],
        ];
    }

    /**
     * L'écran de la demande ne dit jamais si le SIREN ou l'adresse sont
     * déjà connus du logiciel : la même réponse, sans erreur, dans tous les
     * cas.
     *
     * @param  Closure(): mixed  $situation
     */
    #[DataProvider('situationsDejaConnues')]
    public function test_l_ecran_repond_pareil_que_le_siren_ou_l_adresse_soient_libres_ou_non(Closure $situation): void
    {
        Mail::fake();
        $situation();
        app(TenantContext::class)->clear();

        $this->demander()
            ->assertHasNoFormErrors()
            ->assertSet('demandeEnvoyee', true)
            ->assertSee(InscriptionSociete::MESSAGE_NEUTRE)
            ->assertDontSee(['déjà', 'ne peut pas être utilisé']);
    }

    /**
     * Le vrai titulaire d'un SIREN déjà validé par une autre société
     * s'inscrit comme tout le monde : sa société est créée à vérifier, sans
     * magasin, sans rien apprendre de l'autre, qui ne change pas.
     */
    public function test_la_confirmation_d_un_siren_deja_valide_ne_revele_rien_et_cree_une_societe_a_verifier(): void
    {
        Mail::fake();
        $dejaValidee = Magasin::factory()->avecLaSociete(['siren' => self::SIREN])->create(['siret' => FauxRepertoireSirene::siretDuSiege(self::SIREN)])->societe;
        app(TenantContext::class)->clear();
        $this->demander();

        $reponse = $this->post($this->lienEnvoye());

        $nouvelle = Societe::query()->whereKeyNot($dejaValidee->id)->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $nouvelle));
        $this->assertSame([self::SIREN, false], [$nouvelle->siren, $nouvelle->estValidee()]);
        $this->assertSame(0, Magasin::query()->where('societe_id', $nouvelle->id)->count());
        $this->assertTrue($dejaValidee->fresh()->estValidee());
    }

    /**
     * Une adresse déjà l'adresse de connexion d'un propriétaire, sous une
     * autre casse : aucune demande, et la personne qui la détient apprend
     * par e-mail qu'elle a déjà un compte ; l'écran répond comme pour une
     * adresse libre. L'adresse de contact d'un autre compte n'est pas une
     * adresse de connexion : elle s'inscrit comme une adresse libre.
     */
    public function test_une_adresse_deja_utilisee_recoit_un_e_mail_et_aucune_demande(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jeanne@maboutique.fr']);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@maboutique.fr']);
        app(TenantContext::class)->clear();

        $this->demander(['email' => 'Jeanne@MaBoutique.FR'])
            ->assertHasNoFormErrors()
            ->assertSee(InscriptionSociete::MESSAGE_NEUTRE);

        Mail::assertSent(CompteExistantMail::class, fn (CompteExistantMail $mail): bool => $mail->hasTo('jeanne@maboutique.fr')
            && $mail->urlDeConnexion === rtrim((string) config('app.url'), '/').'/app/login');
        Mail::assertNotSent(DemandeInscriptionMail::class);
        $this->assertSame(0, DemandeInscription::query()->count());

        $this->demander(['email' => 'paul@maboutique.fr'])->assertHasNoFormErrors()->assertSee(InscriptionSociete::MESSAGE_NEUTRE);

        Mail::assertSent(DemandeInscriptionMail::class, fn (DemandeInscriptionMail $mail): bool => $mail->hasTo('paul@maboutique.fr'));
        Mail::assertNotSent(CompteExistantMail::class, fn (CompteExistantMail $mail): bool => $mail->hasTo('paul@maboutique.fr'));
        $this->assertSame(1, DemandeInscription::query()->count());
    }

    /**
     * Le propriétaire que crée la confirmation de son lien, et non la
     * fabrique, agit dès son inscription : son adresse est confirmée, son
     * mot de passe est le sien, il a son identifiant de connexion, il ouvre
     * l'espace de sa société et peut y créer son premier magasin.
     */
    public function test_le_proprietaire_inscrit_par_son_lien_peut_agir_des_son_inscription(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->sole();

        $this->assertTrue($proprietaire->peutAgir());
        $this->assertNotNull($proprietaire->email_verified_at);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/', (string) $proprietaire->identifiantDeConnexion());
        $this->assertTrue($proprietaire->canAccessPanel(Filament::getPanel('societe')));
        $this->assertTrue($proprietaire->can('creerUnMagasin', $societe));
    }

    /**
     * La page est ouverte à tous : ses e-mails ne prennent pas leurs liens
     * dans l'adresse de la requête, dont l'en-tête Host vient de qui la
     * remplit. Un hôte forgé (simulé ici par la racine que suivent les
     * adresses absolues) n'envoie pas le titulaire d'un compte vers un
     * autre site que celui d'APP_URL.
     */
    public function test_les_liens_de_l_e_mail_d_un_compte_existant_viennent_d_app_url_jamais_de_la_requete(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jeanne@maboutique.fr']);
        URL::forceRootUrl('http://pirate.example');
        $racine = rtrim((string) config('app.url'), '/');

        $this->demander()->assertHasNoFormErrors();

        Mail::assertSent(CompteExistantMail::class, fn (CompteExistantMail $mail): bool => $mail->urlDeConnexion === "{$racine}/app/login"
            && $mail->urlMotDePasseOublie === "{$racine}/app/password-reset/request");
        $this->assertStringNotContainsString('pirate.example', Mail::sent(CompteExistantMail::class)->sole()->render());
    }

    /**
     * Hors du développement et des tests, seul l'hôte d'APP_URL (et ses
     * sous-domaines) est servi : aucune adresse absolue construite depuis
     * une requête, comme celle du « Mot de passe oublié », ne mène
     * ailleurs.
     */
    public function test_seul_l_hote_d_app_url_est_un_hote_de_confiance(): void
    {
        $motifs = app(TrustHosts::class)->hosts();
        $estDeConfiance = fn (string $hote): bool => collect($motifs)->contains(fn (string $motif): bool => preg_match('{'.$motif.'}i', $hote) === 1);

        $this->assertTrue(app(Kernel::class)->hasMiddleware(TrustHosts::class));
        $this->assertSame([true, false], [$estDeConfiance((string) parse_url((string) config('app.url'), PHP_URL_HOST)), $estDeConfiance('pirate.example')]);
    }

    /**
     * Les entrées du journal d'audit de l'inscription (contrôle Sirene de
     * l'entreprise, société) ont pour auteur la personne qui l'a confirmée,
     * bien qu'elle ne soit connectée qu'ensuite ; aucune ne parle d'un
     * magasin.
     */
    public function test_les_entrees_d_audit_de_l_inscription_ont_le_proprietaire_pour_auteur(): void
    {
        Mail::fake();
        $this->demander();

        $this->post($this->lienEnvoye());

        $societe = Societe::query()->sole();
        $proprietaire = User::query()->sole();
        $this->assertSame(
            [
                ['verification_enregistree', 'membre', $proprietaire->id, 'Jeanne Dupont'],
                ['societe_inscrite', 'membre', $proprietaire->id, 'Jeanne Dupont'],
            ],
            app(TenantContext::class)->runAsSociete($societe, fn (): array => EntreeAudit::query()->orderBy('id')->get()
                ->map(fn (EntreeAudit $entree): array => [$entree->action->value, $entree->origine, (int) $entree->auteur_id, $entree->auteur_libelle])->all()),
        );
    }

    /**
     * Une société dont la dénomination au répertoire porte le mot « EI »
     * sans être un entrepreneur individuel s'inscrit sous ce nom, préremplie
     * comme toute autre, puis complète son identité ; seule une raison
     * sociale saisie avec la mention, pour une autre forme, est refusée.
     */
    public function test_une_sas_que_le_repertoire_nomme_avec_le_mot_ei_s_inscrit_et_complete_son_identite(): void
    {
        Mail::fake();
        app(RepertoireSirene::class)->nommer(self::SIREN, 'EI SOLUTIONS');
        $this->demander();

        $this->post($this->lienEnvoye())->assertRedirect();

        $societe = Societe::query()->sole();
        $this->assertSame(['EI SOLUTIONS', FormeJuridique::Sas], [$societe->raison_sociale, $societe->forme_juridique]);
        app(TenantContext::class)->clear();
        app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe, ['capital_social' => '1000', 'immatriculation' => 'RCS Paris']);
        $this->assertSame(['EI SOLUTIONS', '1000.00', []], [$societe->fresh()->raison_sociale, $societe->fresh()->capital_social, $societe->fresh()->mentionsLegalesManquantes()]);
        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe->fresh(), ['raison_sociale' => 'EI CONSEIL']),
            fn (ValidationException $refus): bool => $refus->errors() === ['raison_sociale' => [ModifierIdentiteSocieteAction::MESSAGE_MENTION_EI]],
        );
    }

    public function test_deux_inscriptions_en_attente_du_meme_siren_coexistent(): void
    {
        Mail::fake();
        $this->demander();
        $premierLien = $this->lienEnvoye();
        $this->travel(61)->seconds();
        $this->demander(['email' => 'paul@autre.fr', 'name' => 'Paul Martin']);
        $secondLien = $this->lienEnvoye();

        $this->post($premierLien);
        auth()->logout();
        $this->post($secondLien);

        $societes = Societe::query()->orderBy('id')->get();
        $this->assertSame([self::SIREN, self::SIREN], $societes->pluck('siren')->all());
        $this->assertSame([false, false], $societes->map(fn (Societe $societe): bool => $societe->estValidee())->all());
        $this->assertSame(0, Magasin::query()->count());
    }

    public function test_le_lien_expire_apres_24_heures(): void
    {
        Mail::fake();
        $this->demander();
        $lien = $this->lienEnvoye();
        $this->travel(24)->hours();
        $this->travel(1)->seconds();

        $this->get($lien)->assertStatus(410)->assertSee(ConfirmerInscriptionController::MESSAGE_LIEN_INVALIDE);
        $this->post($lien)->assertStatus(410)->assertSee(ConfirmerInscriptionController::MESSAGE_LIEN_INVALIDE);

        $this->assertSame(0, Societe::query()->count());
        $this->assertGuest();
    }

    /**
     * Ouvrir le lien (un outil qui suit les liens d'un e-mail) n'affiche
     * que le bouton et ne consomme rien ; la confirmation le consomme, et
     * le lien ne sert plus.
     */
    public function test_le_lien_ne_sert_qu_une_fois_et_l_ouverture_seule_ne_le_consomme_pas(): void
    {
        Mail::fake();
        $this->demander();
        $lien = $this->lienEnvoye();

        $this->get($lien)->assertOk()->assertSeeText('Confirmer l\'inscription')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($lien)->assertOk();
        $this->assertSame([0, null], [Societe::query()->count(), DemandeInscription::query()->sole()->consommee_le]);
        $this->post($lien)->assertRedirect();
        auth()->logout();

        $this->post($lien)->assertStatus(410)->assertSee(ConfirmerInscriptionController::MESSAGE_LIEN_INVALIDE);

        $this->assertSame(1, Societe::query()->count());
    }

    /**
     * Seule l'empreinte du jeton se garde ; les données saisies, mot de
     * passe déjà haché compris, sont chiffrées en base.
     */
    public function test_le_jeton_n_est_jamais_stocke_en_clair(): void
    {
        Mail::fake();
        $this->demander();
        $jeton = basename(parse_url($this->lienEnvoye(), PHP_URL_PATH));

        $ligne = (array) DB::table('demandes_inscription')->sole();

        $this->assertSame(hash('sha256', $jeton), $ligne['jeton_hache']);
        $this->assertStringNotContainsString($jeton, implode('|', array_map(strval(...), $ligne)));
        $this->assertStringNotContainsString(self::SIREN, $ligne['donnees']);
        $this->assertStringNotContainsString('mot-de-passe-sur', $ligne['donnees']);
        $this->assertNotSame('mot-de-passe-sur', DemandeInscription::query()->sole()->donnees['mot_de_passe']);
    }

    /**
     * Trois demandes par heure pour un même SIREN avec une même adresse :
     * la quatrième n'envoie rien, avec la même réponse.
     */
    public function test_le_quatrieme_essai_dans_l_heure_pour_un_meme_siren_et_une_meme_adresse_est_refuse(): void
    {
        Mail::fake();

        foreach (range(1, 3) as $essai) {
            $this->demander()->assertHasNoFormErrors();
            $this->travel(61)->seconds();
        }

        $this->demander()->assertHasNoFormErrors()->assertSee(InscriptionSociete::MESSAGE_NEUTRE);

        $this->assertSame(3, DemandeInscription::query()->count());
        Mail::assertSent(DemandeInscriptionMail::class, 3);
    }

    /**
     * Aucune limite par SIREN seul : celui qui épuise les essais d'un
     * SIREN avec son adresse n'empêche pas le vrai titulaire de s'inscrire
     * avec la sienne.
     */
    public function test_un_tiers_ne_sature_pas_le_siren_d_une_entreprise(): void
    {
        Mail::fake();

        foreach (range(1, 4) as $essai) {
            $this->demander(['email' => 'tiers@ailleurs.fr']);
            $this->travel(61)->seconds();
        }

        $this->demander(['email' => 'jeanne@maboutique.fr'])->assertHasNoFormErrors();

        Mail::assertSent(DemandeInscriptionMail::class, fn (DemandeInscriptionMail $mail): bool => $mail->hasTo('jeanne@maboutique.fr'));
        $this->assertSame(1, DemandeInscription::query()->where('email', 'jeanne@maboutique.fr')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sirenInvalides(): array
    {
        return [
            'clé de contrôle fausse' => ['100000008'],
            'que des zéros' => ['000000000'],
            'huit chiffres' => ['10000000'],
            'avec des lettres' => ['10000000A'],
        ];
    }

    #[DataProvider('sirenInvalides')]
    public function test_un_siren_a_cle_invalide_ou_fait_de_zeros_est_refuse(string $siren): void
    {
        Mail::fake();

        $this->demander(['siren' => $siren])
            ->assertHasFormErrors(['siren' => fn (array $reglesEnEchec, array $messages): bool => in_array(SirenValide::MESSAGE, $messages, true)]);

        $this->assertSame(0, DemandeInscription::query()->count());
    }

    /**
     * Une adresse prise par un compte entre la demande et sa confirmation
     * (une autre demande confirmée avant) : la confirmation est refusée, le
     * lien reste valable, et rien n'est créé, ni société, ni compte.
     */
    public function test_une_confirmation_refusee_ne_laisse_ni_societe_ni_compte(): void
    {
        Mail::fake();
        $this->demander();
        $premierLien = $this->lienEnvoye();
        $this->travel(61)->seconds();
        $this->demander(['siren' => '200000008']);
        $secondLien = $this->lienEnvoye();
        $this->post($premierLien);
        auth()->logout();

        $this->post($secondLien)->assertOk()->assertSee('Cette adresse e-mail ne peut pas être utilisée.');

        $this->assertSame([1, 1, 0], [Societe::query()->count(), User::query()->count(), Magasin::query()->count()]);
        $this->assertNull(DemandeInscription::query()->where('jeton_hache', DemandeInscription::empreinte(basename(parse_url($secondLien, PHP_URL_PATH))))->sole()->consommee_le);
        $this->assertGuest();
    }

    public function test_le_panneau_societe_n_a_aucune_inscription(): void
    {
        $panneau = Filament::getPanel('societe');

        $this->assertSame([false, false], [$panneau->hasRegistration(), $panneau->hasTenantRegistration()]);
        $this->assertSame([false, false], [Route::has('filament.societe.auth.register'), Route::has('filament.societe.tenant.registration')]);
    }

    /**
     * Une demande s'efface 48 heures après son expiration, confirmée ou
     * non ; une demande plus récente reste.
     */
    public function test_les_demandes_expirees_sont_purgees(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        $demande = fn (string $expireLe, ?string $consommeeLe = null): DemandeInscription => tap((new DemandeInscription)->forceFill([
            'email' => fake()->unique()->safeEmail(),
            'jeton_hache' => hash('sha256', fake()->unique()->uuid()),
            'donnees' => ['siren' => self::SIREN],
            'expire_le' => $expireLe,
            'consommee_le' => $consommeeLe,
        ]))->save();
        $expireeDepuis49Heures = $demande('2026-10-07 11:00:00');
        $confirmeeEtExpireeDepuis49Heures = $demande('2026-10-07 11:00:00', '2026-10-06 12:00:00');
        $expireeDepuis47Heures = $demande('2026-10-07 13:00:00');
        $valable = $demande('2026-10-10 11:00:00');

        $this->artisan('app:purger-demandes-inscription')
            ->expectsOutput('2 demande(s) d\'inscription effacée(s).')
            ->assertSuccessful();

        $this->assertSame([$expireeDepuis47Heures->id, $valable->id], DemandeInscription::query()->orderBy('id')->pluck('id')->all());
        $this->assertModelMissing($expireeDepuis49Heures);
        $this->assertModelMissing($confirmeeEtExpireeDepuis49Heures);
    }

    /**
     * @param  array<string, string>  $remplacements
     * @return array<string, string>
     */
    private function inscription(array $remplacements = []): array
    {
        return [
            'siren' => self::SIREN,
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@maboutique.fr',
            'password' => 'mot-de-passe-sur',
            'passwordConfirmation' => 'mot-de-passe-sur',
            ...$remplacements,
        ];
    }

    /**
     * La demande d'inscription remplie et envoyée.
     *
     * @param  array<string, string>  $remplacements
     */
    private function demander(array $remplacements = []): Testable
    {
        return Livewire::test(InscriptionSociete::class)
            ->fillForm($this->inscription($remplacements))
            ->call('register');
    }

    /**
     * Le lien du dernier e-mail de demande envoyé.
     */
    private function lienEnvoye(): string
    {
        return Mail::sent(DemandeInscriptionMail::class)->last()->lien;
    }
}
