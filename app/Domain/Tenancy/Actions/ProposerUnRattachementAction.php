<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Mail\PropositionDeRattachementMail;
use App\Domain\Tenancy\Mail\RattachementImpossibleMail;
use App\Domain\Tenancy\Models\DemandeRattachement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Une société propose à une personne, par l'adresse de son compte, de
 * rejoindre son équipe avec ce compte : un compte n'appartient qu'à une
 * seule société, et seul un compte retiré (par une société, ou par son
 * propre refus) peut en rejoindre une autre, ou revenir dans la même,
 * avec l'accord de la personne (voir AccepterUnRattachementAction).
 *
 * Proposer suit les règles d'une création (voir
 * PorteeDesDroits::peutProposerUnRattachement()) : comme expert-comptable,
 * le propriétaire seul ; comme membre, le rôle de chaque magasin que
 * l'auteur peut donner, plusieurs magasins par le propriétaire seul, et
 * jamais des cases au-delà des siennes. Le rôle et les affectations
 * proposés se notent dans la demande, revérifiés à l'acceptation.
 *
 * L'auteur reçoit toujours la même réponse, et rien ne lui dit si
 * l'adresse est celle d'un compte, ni lequel : compte retiré, la personne
 * reçoit la proposition, valable sept jours (une proposition encore en
 * attente de la même société pour ce compte est annulée) ; compte encore
 * actif ou suspendu dans une autre société, elle apprend seule qu'elle
 * doit d'abord en être retirée ; adresse inconnue, rien ne part. Le
 * journal d'audit retient l'adresse tapée, jamais ce qu'elle désigne.
 * Le compte visé se lit hors de la société par son adresse, et seulement
 * son statut, son rôle, sa société et qui lui a donné son statut, sans
 * verrou : l'acceptation relit tout sous les siens.
 */
class ProposerUnRattachementAction
{
    use AgitDansLaSociete;

    public const REPONSE = 'Si cette adresse correspond à un compte qui peut rejoindre votre société, la personne a reçu une proposition par e-mail.';

    public const MESSAGE_EXPERT_SANS_MAGASIN = 'Un expert-comptable n\'est affecté à aucun magasin : il lit tous les magasins de la société.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly AffectationsDesMembres $affectations,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}>  $affectations  par identifiant de magasin ; aucune pour un expert-comptable
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $email, RoleSociete $role, array $affectations): void
    {
        $email = Validator::make(['email' => User::normaliserEmail($email)], ['email' => ['required', 'string', 'email', 'max:255']])->validate()['email'];

        if ($role === RoleSociete::ExpertComptable && $affectations !== []) {
            throw new LogicException(self::MESSAGE_EXPERT_SANS_MAGASIN);
        }

        $envoi = $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $societe, $email, $role, $affectations): ?array {
            $demandees = $role === RoleSociete::Membre ? array_filter($this->affectations->lire($societe, $affectations)) : [];

            return $this->journalDesDroits->sousVerrouDesComptes($auteur, [], function (User $auteurRelu) use ($societe, $email, $role, $demandees): ?array {
                if ((int) $auteurRelu->societe_id !== $societe->getKey() || ! $this->portee->peutProposerUnRattachement($auteurRelu, $role, array_map(fn (array $demandee): RoleMagasin => $demandee['role'], $demandees))) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $verifiees = $role === RoleSociete::Membre ? $this->affectations->verifierPourUnNouveauCompte($auteurRelu, $demandees) : [];
                $compte = User::query()->where('email', $email)->first(['id', 'email', 'societe_id', 'role_societe', 'statut', 'statut_change_par']);
                $envoi = match (true) {
                    $compte === null => null,
                    $this->portee->peutRecevoirUnRattachement($auteurRelu, $compte) => ['email' => $compte->email, 'mail' => $this->proposer($auteurRelu, $societe, $compte, $role, $verifiees)],
                    (int) $compte->societe_id === $societe->getKey() => null,
                    default => ['email' => $compte->email, 'mail' => new RattachementImpossibleMail($societe->getFilamentName())],
                };

                $this->journalAudit->inscrire(ActionAuditee::RattachementPropose, $auteurRelu, null, null, [
                    'compte' => ['email' => $email],
                    'role_societe' => $role->value,
                    'magasins' => array_values(array_map(fn (array $affectation): array => ['id' => $affectation['magasin']->getKey(), 'nom' => $affectation['magasin']->nom, 'role' => $affectation['role']->value], $verifiees)),
                ]);

                return $envoi;
            });
        });

        if ($envoi !== null) {
            Mail::to($envoi['email'])->send($envoi['mail']);
        }
    }

    /**
     * La demande, et l'e-mail qui en porte le lien.
     *
     * @param  array<int, array{magasin: Magasin, role: RoleMagasin, autorisations: list<string>}>  $affectations
     */
    private function proposer(User $auteur, Societe $societe, User $compte, RoleSociete $role, array $affectations): Mailable
    {
        DemandeRattachement::query()
            ->where('user_id', $compte->getKey())
            ->whereNull('acceptee_le')
            ->whereNull('refusee_le')
            ->whereNull('annulee_le')
            ->lockForUpdate()
            ->get()
            ->each(fn (DemandeRattachement $precedente): bool => $precedente->forceFill(['annulee_le' => now()])->save());

        $jeton = Str::random(64);
        $demande = tap((new DemandeRattachement)->forceFill([
            'user_id' => $compte->getKey(),
            'demande_par' => $auteur->getKey(),
            'role_societe' => $role,
            'affectations' => array_values(array_map(fn (array $affectation): array => [
                'magasin_id' => (int) $affectation['magasin']->getKey(),
                'role' => $affectation['role']->value,
                'autorisations' => $affectation['autorisations'],
            ], $affectations)),
            'jeton_hache' => DemandeRattachement::empreinte($jeton),
            'expire_le' => now()->addDays(DemandeRattachement::DUREE_DE_VALIDITE_EN_JOURS),
        ]))->save();

        return new PropositionDeRattachementMail(
            nomDeLAuteur: $auteur->name,
            societe: $societe->getFilamentName(),
            role: $role === RoleSociete::ExpertComptable
                ? 'comme expert-comptable, avec la lecture de tous ses magasins'
                : implode(', ', array_map(fn (array $affectation): string => mb_strtolower($affectation['role']->getLabel()).' du magasin '.$affectation['magasin']->nom, $affectations)),
            lien: rtrim((string) config('app.url'), '/').route('rattachement.afficher', ['jeton' => $jeton], absolute: false),
            expireLe: $demande->expire_le->setTimezone(config('app.timezone'))->format('d/m/Y'),
        );
    }
}
