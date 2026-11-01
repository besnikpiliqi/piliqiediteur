<?php

namespace Tests\Feature\Editeur;

use App\Domain\Cabinets\Actions\ConstaterLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\VoieDeVerificationOrdre;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Mail\InscriptionCloseMail;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\Ordre\AnnuaireTenuParLEditeur;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Le cadre commun des procédures exceptionnelles de l'éditeur (voir
 * ProcedureDeLEditeur et RegistreDesOperateurs), essayé sur l'envoi d'un
 * courrier recommandé et sur l'examen des pièces d'un courrier non reçu,
 * suivi d'un nouveau courrier : une procédure exige sa
 * justification et sa pièce, la confirmation retapée et un opérateur
 * authentifié par son secret, dans un terminal interactif ; refusée, elle
 * n'écrit rien ; faite, elle s'inscrit au journal d'audit avec son
 * origine, sa justification et ses opérateurs. La société se désigne par
 * son identifiant ou son uuid, rien d'autre.
 */
class ProceduresTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Courrier non reçu, pièces examinées par l\'éditeur';

    private const PIECE = 'DOSSIER-2026-0042';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => self::empreinte('secret-d-alice', PASSWORD_ARGON2ID),
            'bruno' => self::empreinte('secret-de-bruno', PASSWORD_BCRYPT),
        ]]);
    }

    public function test_une_procedure_sans_justification_ni_piece_est_refusee(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        $this->envoyerUnCourrier($societe, ['--justification' => 'Trop court'])
            ->expectsOutputToContain('Une procédure de l\'éditeur exige sa justification')
            ->assertFailed();
        $this->envoyerUnCourrier($societe, ['--piece' => ''])
            ->expectsOutputToContain('Une procédure de l\'éditeur exige sa justification')
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * Sans le SIREN retapé, ou en mode non interactif (où l'opérateur ne
     * peut saisir son secret dans un champ masqué), rien n'est fait.
     */
    public function test_une_procedure_sans_confirmation_est_refusee(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        $this->envoyerUnCourrier($societe, ['--confirmer' => '000000018'])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->envoyerUnCourrier($societe, ['--confirmer' => null])
            ->expectsQuestion("Retapez le SIREN {$societe->siren} pour confirmer", '000000018')
            ->assertFailed();
        $this->envoyerUnCourrier($societe, ['--confirmer' => null, '--no-interaction' => true])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->envoyerUnCourrier($societe, ['--no-interaction' => true])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * Un opérateur inconnu, un secret faux ou vide, ou une empreinte qui
     * n'est pas un Argon2id sont refusés, sans rien écrire.
     */
    public function test_un_operateur_inconnu_ou_un_secret_faux_est_refuse(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        foreach ([['mallory', 'secret-d-alice'], ['alice', 'secret-de-bruno'], ['alice', ''], ['bruno', 'secret-de-bruno']] as [$nom, $secret]) {
            $this->envoyerUnCourrier($societe, operateur: $nom, secret: $secret)
                ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * Une procédure refusée après l'authentification de son opérateur
     * n'écrit rien non plus : un motif de courrier inconnu, un courrier qui
     * ne peut pas partir après l'examen des pièces (qui restent, pour un
     * nouvel examen), une clôture sans motif de l'éditeur (absent, inconnu,
     * ou le délai expiré, qui n'est pas le sien), une inscription déjà
     * validée qui n'attend plus de code, ni sa clôture ni son courrier de
     * vérification (sans consulter le répertoire Sirene).
     */
    public function test_une_procedure_refusee_n_ecrit_rien(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->societeAvecUnePiece();
        $piece = app(TenantContext::class)->runAsSociete($societe, fn () => PieceVerification::query()->sole());
        $avant = $this->ecritures();

        $this->procedure('editeur:courrier:envoyer', $societe, ['--motif' => 'motif_invente'])
            ->expectsOutputToContain('Motif inconnu.')
            ->assertFailed();
        app(ServiceEnvoiRecommande::class)->rendreInjoignable();
        $this->procedure('editeur:societe:examiner', $societe)->assertFailed();
        foreach ([[], ['--motif' => 'motif_invente'], ['--motif' => MotifCloture::DelaiExpire->value]] as $motif) {
            $this->procedure('editeur:societe:clore', $societe, $motif)
                ->expectsOutputToContain(CloreLInscriptionAction::MESSAGE_MOTIF_INCONNU)
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
        $piece = app(TenantContext::class)->runAsSociete($societe, fn () => $piece->fresh());
        $this->assertNull($piece->effacee_le);
        Storage::disk(PiecesDeVerification::DISQUE)->assertExists((string) $piece->chemin);
        app(ServiceEnvoiRecommande::class)->rendreInjoignable(false);

        $validee = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($validee);
        $avant = $this->ecritures();
        $this->procedure('editeur:societe:clore', $validee, ['--motif' => 'refus'])
            ->expectsOutputToContain(CloreLInscriptionAction::MESSAGE_N_ATTEND_PLUS)
            ->assertFailed();
        $this->procedure('editeur:courrier:envoyer', $validee, ['--motif' => 'verification'])
            ->expectsOutputToContain(EnvoyerCodeDeConfirmationAction::MESSAGE_N_ATTEND_PLUS)
            ->assertFailed();
        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * La procédure faite s'inscrit au journal d'audit de la société,
     * origine « éditeur », avec sa justification, sa pièce, son opérateur,
     * l'utilisateur système, l'hôte et la version du module fiscal, comme
     * la ligne qu'elle ajoute au registre des vérifications. L'examen des
     * pièces efface les pièces et fait partir un nouveau courrier, après le
     * contrôle de l'entreprise au répertoire Sirene, inscrit lui aussi au
     * nom de l'éditeur, sans valider la société. La société se désigne
     * aussi par son uuid.
     */
    public function test_une_procedure_faite_s_inscrit_au_journal_d_audit_avec_son_origine_et_son_operateur(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->societeAvecUnePiece();
        $courriers = count(app(ServiceEnvoiRecommande::class)->envois());

        $this->procedure('editeur:societe:examiner', $societe, ['societe' => $societe->uuid])
            ->expectsOutputToContain('Examen inscrit, pièces effacées ; le courrier recommandé est parti')
            ->assertSuccessful();

        $operateur = [
            'operateurs' => ['alice'],
            'piece' => self::PIECE,
            'utilisateur_systeme' => get_current_user(),
            'hote' => (string) gethostname(),
            'version_module' => ModuleFiscal::VERSION,
        ];
        [$ligne, $entree, $consultation] = app(TenantContext::class)->runAsSociete($societe, function (): array {
            $ligne = VerificationSociete::query()->where('type', TypeVerification::Manuelle->value)->sole();

            return [
                $ligne,
                EntreeAudit::query()->where('action', ActionAuditee::VerificationEnregistree->value)->where('cible_type', $ligne->getMorphClass())->where('cible_id', $ligne->id)->sole(),
                EntreeAudit::query()->where('action', ActionAuditee::VerificationEnregistree->value)->orderByDesc('id')->first(),
            ];
        });
        $this->assertSame(['nouveau_courrier_apres_examen_des_pieces', 'editeur', $operateur], [$ligne->details['decision'], $ligne->fournisseur, $ligne->operateur]);
        $this->assertSame(['editeur', self::JUSTIFICATION, $operateur], [$entree->origine, $entree->justification, $entree->operateur]);
        $this->assertSame([TypeVerification::SireneUnite->value, 'editeur', self::JUSTIFICATION, $operateur], [$consultation->nouvelles_valeurs['type'], $consultation->origine, $consultation->justification, $consultation->operateur]);
        $this->assertCount($courriers + 1, app(ServiceEnvoiRecommande::class)->envois());
        $this->assertSame([0, true], [app(TenantContext::class)->runAsSociete($societe, fn (): int => PieceVerification::query()->whereNull('effacee_le')->count()), $societe->fresh()->attendSonCode()]);
    }

    /**
     * La clôture pour un type d'activité choisi par erreur, à la demande du
     * propriétaire : l'inscription est close avec ce motif, inscrite au
     * journal d'audit au nom de l'éditeur, son SIREN libéré ; le
     * propriétaire est prévenu dans l'espace de sa société et par e-mail,
     * à l'adresse qu'il perd, qu'il peut inscrire de nouveau son entreprise
     * avec le bon type, la même adresse et le même SIREN.
     */
    public function test_editeur_societe_clore_au_motif_type_errone_clot_l_inscription_et_previent_le_proprietaire(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete(email: 'jeanne@maboutique.fr');
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();

        $this->procedure('editeur:societe:clore', $societe, ['--motif' => MotifCloture::TypeErrone->value])
            ->expectsOutputToContain('Inscription close : Type d\'activité choisi par erreur, à la demande du propriétaire.')
            ->assertSuccessful();

        $close = $societe->fresh();
        $this->assertSame([true, MotifCloture::TypeErrone, false], [$close->estClose(), $close->motif_cloture, Societe::sirenPris((string) $societe->siren)]);
        $cloture = app(TenantContext::class)->runAsSociete($societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::InscriptionClose->value)->sole());
        $this->assertSame([MotifCloture::TypeErrone->value, 'editeur', self::JUSTIFICATION], [$cloture->nouvelles_valeurs['motif'], $cloture->origine, $cloture->justification]);
        $reinscription = 'Votre entreprise peut s\'inscrire de nouveau avec le bon type d\'activité, avec la même adresse e-mail et le même SIREN, tous deux libérés';
        $avis = $proprietaire->notifications()->sole()->data;
        $this->assertSame('L\'inscription de votre société est close', $avis['title']);
        $this->assertStringContainsString($reinscription, $avis['body']);
        $mail = Mail::sent(InscriptionCloseMail::class)->sole();
        $this->assertTrue($mail->hasTo('jeanne@maboutique.fr'));
        $mail->assertSeeInText($reinscription);
    }

    /**
     * Une société validée n'attend plus de code : sa clôture pour un type
     * d'activité choisi par erreur est refusée, et rien ne s'écrit ; son
     * type ne change jamais.
     */
    public function test_editeur_societe_clore_au_motif_type_errone_refuse_une_societe_validee(): void
    {
        $validee = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($validee);
        $avant = $this->ecritures();

        $this->procedure('editeur:societe:clore', $validee, ['--motif' => MotifCloture::TypeErrone->value])
            ->expectsOutputToContain(CloreLInscriptionAction::MESSAGE_N_ATTEND_PLUS)
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
        $this->assertSame([true, false, TypeDActivite::Commerce], [$validee->fresh()->estValidee(), $validee->fresh()->estClose(), $validee->fresh()->type_activite]);
    }

    /**
     * La vérification par l'éditeur de l'inscription d'un cabinet à
     * l'Ordre suit le cadre commun : refusée sans justification ni pièce,
     * sans le SIREN retapé ou sans opérateur, sans rien écrire ; faite,
     * elle pose l'inscription, voie « éditeur », efface la demande en
     * attente et s'inscrit au journal d'audit du cabinet, origine
     * « éditeur », avec sa justification et son opérateur ; le propriétaire
     * est prévenu.
     */
    public function test_editeur_cabinet_verifier_ordre_exige_justification_piece_siren_et_operateur_et_s_inscrit_a_l_audit(): void
    {
        $this->app->instance(AnnuaireDeLOrdre::class, new AnnuaireTenuParLEditeur);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $proprietaire = $this->saisirLeCodeDuCourrier($cabinet);
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet->fresh(), ['numero_ordre' => '75-00042', 'conseil_regional' => 'Paris Île-de-France']);
        app(TenantContext::class)->clear();
        $avant = $this->ecritures();
        $options = ['--numero' => '75-00042', '--conseil' => 'Paris Île-de-France'];

        $this->artisan('editeur:cabinet:verifier-ordre', ['societe' => (string) $cabinet->id, ...$options, '--confirmer' => $cabinet->siren])->assertFailed();
        $this->artisan('editeur:cabinet:verifier-ordre', ['societe' => (string) $cabinet->id, ...$options, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => '000000000'])->assertFailed();
        $this->artisan('editeur:cabinet:verifier-ordre', ['societe' => (string) $cabinet->id, ...$options, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => $cabinet->siren])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'faux-secret')
            ->assertFailed();
        $this->assertSame($avant, $this->ecritures());
        $this->assertFalse($cabinet->fresh()->estUnCabinetVerifie());

        $this->procedure('editeur:cabinet:verifier-ordre', $cabinet, $options)
            ->expectsOutputToContain('Inscription à l\'Ordre vérifiée.')
            ->assertSuccessful();

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame(['75-00042', 'Paris Île-de-France', VoieDeVerificationOrdre::Editeur, null], [$verification->numero_ordre, $verification->conseil_regional_ordre, $verification->ordre_voie, $verification->ordre_demande_le]);
        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        $entree = app(TenantContext::class)->runAsSociete($cabinet, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::OrdreVerifie->value)->sole());
        $this->assertSame(['editeur', self::JUSTIFICATION, ['alice'], 'editeur'], [$entree->origine, $entree->justification, $entree->operateur['operateurs'], $entree->nouvelles_valeurs['voie']]);
        $this->assertContains('L\'éditeur a vérifié l\'inscription de votre cabinet au tableau de l\'Ordre.', $proprietaire->notifications()->get()->pluck('data.body')->all());
    }

    public function test_editeur_cabinet_verifier_ordre_refuse_une_societe_qui_n_est_pas_un_cabinet(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        $this->procedure('editeur:cabinet:verifier-ordre', $societe, ['--numero' => '75-00042', '--conseil' => 'Paris'])
            ->expectsOutputToContain(ConstaterLInscriptionALOrdreAction::MESSAGE_PAS_UN_CABINET)
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
        $this->assertSame(0, DB::table('cabinets')->count());
    }

    /**
     * Le refus par l'éditeur efface la demande en attente, note son motif,
     * que la page du cabinet montre, s'inscrit au journal d'audit et
     * prévient le propriétaire avec le motif, dans sa cloche et par e-mail.
     */
    public function test_editeur_cabinet_refuser_ordre_previent_le_proprietaire_avec_le_motif(): void
    {
        $this->app->instance(AnnuaireDeLOrdre::class, new AnnuaireTenuParLEditeur);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $proprietaire = $this->saisirLeCodeDuCourrier($cabinet);
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet->fresh(), ['numero_ordre' => '75-00042', 'conseil_regional' => 'Paris']);
        app(TenantContext::class)->clear();
        Notification::fake();
        $motif = 'Aucune inscription à ce numéro au tableau de l\'Ordre de la région Île-de-France.';

        $this->procedure('editeur:cabinet:refuser-ordre', $cabinet, ['--motif' => $motif])->assertSuccessful();

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame([null, null, $motif], [$verification->ordre_demande_le, $verification->ordre_verifie_le, $verification->ordre_motif_refus]);
        $entree = app(TenantContext::class)->runAsSociete($cabinet, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::OrdreRefuse->value)->sole());
        $this->assertSame([['motif' => $motif], 'editeur'], [$entree->nouvelles_valeurs, $entree->origine]);
        Notification::assertSentTo($proprietaire, AvisDuCabinet::class, fn (AvisDuCabinet $avis, array $canaux): bool => $avis->titre === 'Inscription à l\'Ordre refusée'
            && str_contains($avis->texte, $motif)
            && $canaux === ['database', 'mail']);
    }

    public function test_une_societe_inconnue_est_refusee(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        foreach ([(string) ($societe->id + 1000), (string) Str::uuid()] as $inconnue) {
            $this->artisan('editeur:courrier:envoyer', ['societe' => $inconnue])
                ->expectsOutputToContain('Société inconnue : donnez son identifiant ou son uuid.')
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
    }

    public function test_un_texte_qui_n_est_ni_un_identifiant_ni_un_uuid_est_refuse(): void
    {
        $societe = $this->inscrireUneSociete();
        $avant = $this->ecritures();

        foreach (['abc', "{$societe->id}abc", "{$societe->id} OR 1=1", (string) $societe->siren.'-'] as $texte) {
            $this->artisan('editeur:courrier:envoyer', ['societe' => $texte])
                ->expectsOutputToContain('Société inconnue : donnez son identifiant ou son uuid.')
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * Une inscription dont les deux courriers ne sont pas parvenus : le
     * premier, le 5 octobre, puis le nouveau courrier demandé au quinzième
     * jour ; son propriétaire a déposé un extrait Kbis pour que l'éditeur
     * traite son cas à la main.
     */
    private function societeAvecUnePiece(): Societe
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);
        app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe->fresh(), TypePiece::Kbis, 'Extrait Kbis');
        app(TenantContext::class)->clear();

        return $societe->fresh();
    }

    /**
     * L'envoi par l'éditeur d'un courrier de vérification à la société,
     * avec les options données en plus de celles d'une procédure complète.
     *
     * @param  array<string, mixed>  $options
     */
    private function envoyerUnCourrier(Societe $societe, array $options = [], ?string $operateur = null, ?string $secret = null): PendingCommand
    {
        $parametres = array_filter([
            'societe' => (string) $societe->id,
            '--motif' => 'verification',
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
            ...$options,
        ], fn (mixed $valeur): bool => $valeur !== null);
        $commande = $this->artisan('editeur:courrier:envoyer', $parametres);

        return $operateur === null
            ? $commande
            : $commande->expectsQuestion('Nom de l\'opérateur', $operateur)->expectsQuestion('Secret de l\'opérateur', $secret);
    }

    /**
     * Une procédure complète sur la société, lancée par l'opératrice alice.
     *
     * @param  array<string, mixed>  $parametres
     */
    private function procedure(string $commande, Societe $societe, array $parametres = []): PendingCommand
    {
        return $this->artisan($commande, [
            'societe' => (string) $societe->id,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
            ...$parametres,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice');
    }

    /**
     * Ce qu'une procédure écrirait : lignes du registre des vérifications,
     * entrées du journal d'audit, codes envoyés, pièces effacées, sociétés
     * validées ou closes.
     *
     * @return array<string, int>
     */
    private function ecritures(): array
    {
        return [
            'verifications' => DB::table('verifications_societe')->count(),
            'audit' => DB::table('journal_audit')->count(),
            'codes' => DB::table('codes_confirmation')->count(),
            'pieces_effacees' => DB::table('pieces_verification')->whereNotNull('effacee_le')->count(),
            'decisions' => DB::table('societes')->whereNotNull('validee_le')->orWhereNotNull('close_le')->count(),
        ];
    }

    /**
     * L'empreinte d'un secret, au coût le plus bas pour les tests.
     */
    private static function empreinte(string $secret, string $algorithme): string
    {
        return $algorithme === PASSWORD_ARGON2ID
            ? password_hash($secret, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])
            : password_hash($secret, $algorithme, ['cost' => 4]);
    }
}
