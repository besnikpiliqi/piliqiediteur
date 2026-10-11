<?php

namespace Tests\Feature\Editeur;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\LeverUnSignalAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Le cadre commun des procédures exceptionnelles de l'éditeur (voir
 * ProcedureDeLEditeur et RegistreDesOperateurs), essayé sur la levée d'un
 * signal et la validation sur pièces : une procédure exige sa
 * justification et sa pièce, la confirmation retapée et un opérateur
 * authentifié par son secret, dans un terminal interactif ; refusée, elle
 * n'écrit rien ; faite, elle s'inscrit au journal d'audit avec son
 * origine, sa justification et ses opérateurs. La société se désigne par
 * son identifiant ou son uuid, rien d'autre.
 */
class ProceduresTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'Doute levé après l\'examen du dossier par l\'éditeur';

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
        $societe = $this->societeEnDoute();
        $avant = $this->ecritures();

        $this->leverLeDoute($societe, ['--justification' => 'Trop court'])
            ->expectsOutputToContain('Une procédure de l\'éditeur exige sa justification')
            ->assertFailed();
        $this->leverLeDoute($societe, ['--piece' => ''])
            ->expectsOutputToContain('Une procédure de l\'éditeur exige sa justification')
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
        $this->assertSame([SignalDeRisque::DecisionEditeur], $this->signaux($societe));
    }

    /**
     * Sans le SIREN retapé, ou en mode non interactif (où l'opérateur ne
     * peut saisir son secret dans un champ masqué), rien n'est fait.
     */
    public function test_une_procedure_sans_confirmation_est_refusee(): void
    {
        $societe = $this->societeEnDoute();
        $avant = $this->ecritures();

        $this->leverLeDoute($societe, ['--confirmer' => '000000018'])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->leverLeDoute($societe, ['--confirmer' => null])
            ->expectsQuestion("Retapez le SIREN {$societe->siren} pour confirmer", '000000018')
            ->assertFailed();
        $this->leverLeDoute($societe, ['--confirmer' => null, '--no-interaction' => true])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->leverLeDoute($societe, ['--no-interaction' => true])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();

        $this->assertSame($avant, $this->ecritures());
        $this->assertSame([SignalDeRisque::DecisionEditeur], $this->signaux($societe));
    }

    /**
     * Un opérateur inconnu, un secret faux ou vide, ou une empreinte qui
     * n'est pas un Argon2id sont refusés, sans rien écrire.
     */
    public function test_un_operateur_inconnu_ou_un_secret_faux_est_refuse(): void
    {
        $societe = $this->societeEnDoute();
        $avant = $this->ecritures();

        foreach ([['mallory', 'secret-d-alice'], ['alice', 'secret-de-bruno'], ['alice', ''], ['bruno', 'secret-de-bruno']] as [$nom, $secret]) {
            $this->leverLeDoute($societe, operateur: $nom, secret: $secret)
                ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
        $this->assertSame([SignalDeRisque::DecisionEditeur], $this->signaux($societe));
    }

    /**
     * Une procédure refusée après l'authentification de son opérateur
     * n'écrit rien non plus : un signal inconnu, un courrier qui ne peut
     * pas partir après l'examen des pièces (qui restent, pour un nouvel
     * examen), une société déjà validée.
     */
    public function test_une_procedure_refusee_n_ecrit_rien(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->societeEnDoute();
        $piece = app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Kbis, 'Extrait Kbis');
        $avant = $this->ecritures();

        $this->procedure('editeur:societe:lever-signal', $societe, ['signal' => 'signal_invente'])
            ->expectsOutputToContain('Signal inconnu')
            ->assertFailed();
        app(ServiceEnvoiRecommande::class)->rendreInjoignable();
        $this->procedure('editeur:societe:valider', $societe)->assertFailed();

        $this->assertSame($avant, $this->ecritures());
        $this->assertNull($piece->fresh()->effacee_le);
        Storage::disk(PiecesDeVerification::DISQUE)->assertExists((string) $piece->chemin);
        app(ServiceEnvoiRecommande::class)->rendreInjoignable(false);

        $validee = $this->inscrireUneSociete();
        $this->verifierLIdentiteDuProprietaire($validee);
        $avant = $this->ecritures();
        $this->procedure('editeur:societe:signaler', $validee)
            ->expectsOutputToContain('Cette société est déjà validée ou refusée.')
            ->assertFailed();
        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * La procédure faite s'inscrit au journal d'audit de la société,
     * origine « éditeur », avec sa justification, sa pièce, son opérateur,
     * l'utilisateur système, l'hôte et la version du module fiscal, comme
     * la ligne qu'elle ajoute au registre des vérifications. La société se
     * désigne aussi par son uuid.
     */
    public function test_une_procedure_faite_s_inscrit_au_journal_d_audit_avec_son_origine_et_son_operateur(): void
    {
        $societe = $this->societeEnDoute();

        $this->procedure('editeur:societe:lever-signal', $societe, ['societe' => $societe->uuid, 'signal' => SignalDeRisque::DecisionEditeur->value])
            ->expectsOutputToContain('Signal levé')
            ->assertSuccessful();

        $operateur = [
            'operateurs' => ['alice'],
            'piece' => self::PIECE,
            'utilisateur_systeme' => get_current_user(),
            'hote' => (string) gethostname(),
            'version_module' => ModuleFiscal::VERSION,
        ];
        [$ligne, $entree] = app(TenantContext::class)->runAsSociete($societe, fn (): array => [
            VerificationSociete::query()->where('type', TypeVerification::Manuelle->value)->sole(),
            EntreeAudit::query()->where('action', ActionAuditee::VerificationEnregistree->value)->orderByDesc('id')->first(),
        ]);
        $this->assertSame([SignalDeRisque::DecisionEditeur, 'editeur', $operateur], [$ligne->signal, $ligne->fournisseur, $ligne->operateur]);
        $this->assertSame(['editeur', self::JUSTIFICATION, $operateur], [$entree->origine, $entree->justification, $entree->operateur]);
        $this->assertSame([], $this->signaux($societe));
    }

    public function test_une_societe_inconnue_est_refusee(): void
    {
        $societe = $this->societeEnDoute();
        $avant = $this->ecritures();

        foreach ([(string) ($societe->id + 1000), (string) Str::uuid()] as $inconnue) {
            $this->artisan('editeur:societe:lever-signal', ['societe' => $inconnue, 'signal' => SignalDeRisque::DecisionEditeur->value])
                ->expectsOutputToContain('Société inconnue : donnez son identifiant ou son uuid.')
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
    }

    public function test_un_texte_qui_n_est_ni_un_identifiant_ni_un_uuid_est_refuse(): void
    {
        $societe = $this->societeEnDoute();
        $avant = $this->ecritures();

        foreach (['abc', "{$societe->id}abc", "{$societe->id} OR 1=1", (string) $societe->siren.'-'] as $texte) {
            $this->artisan('editeur:societe:lever-signal', ['societe' => $texte, 'signal' => SignalDeRisque::DecisionEditeur->value])
                ->expectsOutputToContain('Société inconnue : donnez son identifiant ou son uuid.')
                ->assertFailed();
        }

        $this->assertSame($avant, $this->ecritures());
    }

    /**
     * Une société en cours de vérification, sur laquelle l'éditeur a
     * constaté un doute.
     */
    private function societeEnDoute(): Societe
    {
        $societe = $this->inscrireUneSociete();
        app(LeverUnSignalAction::class)->signalerUnDoute($societe, new InterventionDeLEditeur('Doute sur l\'inscription de cette société', 'DOSSIER-2026-0041', ['alice']));

        return $societe;
    }

    /**
     * La levée du doute de l'éditeur sur la société, avec les options
     * données en plus de celles d'une procédure complète.
     *
     * @param  array<string, mixed>  $options
     */
    private function leverLeDoute(Societe $societe, array $options = [], ?string $operateur = null, ?string $secret = null): PendingCommand
    {
        $parametres = array_filter([
            'societe' => (string) $societe->id,
            'signal' => SignalDeRisque::DecisionEditeur->value,
            '--justification' => self::JUSTIFICATION,
            '--piece' => self::PIECE,
            '--confirmer' => $societe->siren,
            ...$options,
        ], fn (mixed $valeur): bool => $valeur !== null);
        $commande = $this->artisan('editeur:societe:lever-signal', $parametres);

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
     * @return list<SignalDeRisque>
     */
    private function signaux(Societe $societe): array
    {
        return app(EvaluateurDeVerification::class)->evaluer($societe->fresh())->signauxActifs;
    }

    /**
     * Ce qu'une procédure écrirait : lignes du registre des vérifications,
     * entrées du journal d'audit, codes envoyés, pièces effacées, sociétés
     * validées ou refusées.
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
            'decisions' => DB::table('societes')->whereNotNull('validee_le')->orWhereNotNull('refusee_le')->count(),
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
