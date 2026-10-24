<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * nouvel examen), une inscription déjà validée qui n'attend plus de
     * code, ni son refus ni son courrier de vérification (sans consulter
     * le répertoire Sirene).
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

        $this->assertSame($avant, $this->ecritures());
        $piece = app(TenantContext::class)->runAsSociete($societe, fn () => $piece->fresh());
        $this->assertNull($piece->effacee_le);
        Storage::disk(PiecesDeVerification::DISQUE)->assertExists((string) $piece->chemin);
        app(ServiceEnvoiRecommande::class)->rendreInjoignable(false);

        $validee = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($validee);
        $avant = $this->ecritures();
        $this->procedure('editeur:societe:refuser', $validee)
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
     * premier, puis le nouveau courrier demandé au quinzième jour ; son
     * propriétaire a déposé un extrait Kbis pour que l'éditeur traite son
     * cas à la main.
     */
    private function societeAvecUnePiece(): Societe
    {
        $societe = $this->inscrireUneSociete();
        $this->travel(15)->days();
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
