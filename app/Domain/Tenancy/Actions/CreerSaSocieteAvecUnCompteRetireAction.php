<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Une personne crée sa propre société avec le compte qu'une société a
 * retiré, ou qu'elle a elle-même refusé (voir CreerSaSocieteController) :
 * un compte n'appartient qu'à une seule société, et ne la quitte pour en
 * créer une que retiré, jamais avant.
 *
 * L'adresse et le mot de passe du compte se redemandent dans la requête
 * même, aucune session ne suffisant ; l'adresse doit avoir été confirmée
 * (un lien suivi) et le mot de passe choisi par la personne : un mot de
 * passe provisoire, que son auteur connaît, ou celui d'un compte refusé,
 * qui ne sert plus, n'ouvre rien ; la personne choisit d'abord le sien par
 * le lien « Mot de passe oublié ». Le compte, relu verrouillé dans la
 * transaction de la création, devient le propriétaire, actif, de la
 * société nouvelle et de son premier magasin, comme à une inscription (voir
 * CreerSocieteAction::creer()) ; la qualité de représentant prouvée pour
 * une autre société ne le suit pas, et ses sessions se ferment. Son départ
 * s'inscrit au journal de la société qu'il quitte, sans rien dire de la
 * nouvelle, et la création à celui de la nouvelle.
 */
class CreerSaSocieteAvecUnCompteRetireAction
{
    public const MESSAGE_IDENTIFIANTS = 'Adresse e-mail ou mot de passe incorrect.';

    public const MESSAGE_PAS_RETIRE = 'Votre compte appartient encore à une société : un compte n\'appartient qu\'à une seule société, et n\'en crée une qu\'une fois retiré par la sienne.';

    public const MESSAGE_ADRESSE_A_CONFIRMER = 'Choisissez d\'abord votre mot de passe par le lien « Mot de passe oublié » envoyé à votre adresse : il confirme votre adresse.';

    public function __construct(
        private readonly CreerSocieteAction $creerSociete,
        private readonly JournalAudit $journalAudit,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @param  array{siren?: mixed}  $societe
     * @param  array{nom?: mixed, code?: mixed, nic?: mixed}  $premierMagasin
     *
     * @throws ValidationException
     */
    public function handle(string $email, #[SensitiveParameter] string $motDePasse, array $societe, array $premierMagasin): Societe
    {
        $compte = User::query()->where('email', User::normaliserEmail($email))->first();

        if ($compte === null || ! Hash::check($motDePasse, $compte->password)) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_IDENTIFIANTS]);
        }

        self::verifier($compte);
        $quittee = null;

        $nouvelle = $this->creerSociete->creer(
            $societe,
            $premierMagasin,
            function (Societe $nouvelle) use ($compte, $motDePasse, &$quittee): User {
                $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();
                self::verifier($relu);

                if (! Hash::check($motDePasse, $relu->password)) {
                    throw ValidationException::withMessages(['email' => self::MESSAGE_IDENTIFIANTS]);
                }

                $quittee = (int) $relu->societe_id;
                $relu->forceFill([
                    'societe_id' => $nouvelle->getKey(),
                    'role_societe' => RoleSociete::Proprietaire,
                    'statut' => StatutCompte::Actif,
                    'statut_change_par' => $relu->getKey(),
                    'qualite_verifiee_le' => null,
                ])->save();
                $this->sessions->fermer($relu);

                return $relu;
            },
            function (Societe $nouvelle, User $proprietaire, Magasin $magasin) use (&$quittee): void {
                $this->journalAudit->inscrire(ActionAuditee::SocieteCreeeParUnCompteRetire, $proprietaire, null, null, [
                    'compte' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                    'siren' => $nouvelle->siren,
                    'societe_quittee' => ['id' => $quittee],
                ], $nouvelle);
            },
        );

        $compte->refresh();
        app(TenantContext::class)->runAsSociete(Societe::query()->findOrFail($quittee), fn () => $this->journalAudit->inscrire(ActionAuditee::SocieteCreeeParUnCompteRetire, $compte, null, null, [
            'compte' => ['id' => $compte->getKey(), 'nom' => $compte->name],
            'societe_quittee' => ['id' => $quittee],
        ]));

        return $nouvelle;
    }

    /**
     * @throws ValidationException
     */
    private static function verifier(User $compte): void
    {
        if (! $compte->estRetire()) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_PAS_RETIRE]);
        }

        if (! $compte->aConfirmeSonAdresse() || $compte->doitChangerSonMotDePasse()) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_ADRESSE_A_CONFIRMER]);
        }
    }
}
