<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\DemandeRattachement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use SensitiveParameter;

/**
 * La personne accepte, ou refuse, la proposition de rattachement qu'une
 * société lui a adressée (voir ProposerUnRattachementAction), depuis le
 * lien reçu à l'adresse de son compte (voir RattachementController).
 *
 * Accepter redemande l'adresse et le mot de passe du compte dans la
 * requête même : aucune session antérieure ne suffit, et un compte encore
 * au mot de passe provisoire (que son auteur connaît) ou refusé (dont le
 * mot de passe ne sert plus) choisit d'abord le sien par le lien « Mot de
 * passe oublié ». Tout se relit sous les verrous des registres des
 * magasins proposés, puis des lignes de l'auteur de la proposition et du
 * compte (voir JournalDesDroits), puis de la demande : la demande, encore
 * en attente ; le compte, toujours retiré ; l'auteur, toujours autorisé à
 * proposer ce rôle et ces affectations, avec des cases encore incluses
 * dans les siennes. Le compte rejoint alors la société, actif, avec ses
 * affectations (ou comme expert-comptable), son adresse confirmée par le
 * lien suivi ; ses notifications s'effacent et ses sessions se ferment ;
 * ses autres propositions en attente, dans d'autres sociétés, s'annulent.
 * Les droits donnés s'inscrivent au JET 130 de chaque magasin et au
 * journal d'audit de la société, et son départ au journal de la société
 * qu'il quitte. Rejoindre une autre société efface la qualité de
 * représentant prouvée pour la précédente.
 *
 * La demande se cherche par l'empreinte de son jeton, hors de toute
 * société (SocieteScope contourné, seule la demande de ce jeton est lue),
 * puis tout se fait dans la société qui propose.
 */
class AccepterUnRattachementAction
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public const MESSAGE_IDENTIFIANTS = 'Adresse e-mail ou mot de passe incorrect.';

    public const MESSAGE_COMPTE_PAS_LIBRE = 'Votre compte appartient encore à une société : demandez-lui d\'abord de vous retirer de son équipe.';

    public const MESSAGE_CHOISIR_SON_MOT_DE_PASSE = 'Choisissez d\'abord votre mot de passe par le lien « Mot de passe oublié » envoyé à votre adresse, puis revenez accepter.';

    public const MESSAGE_AUTEUR = 'Cette proposition n\'est plus valable : la personne qui l\'a faite ne peut plus vous rattacher ainsi.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly AffectationsDesMembres $affectations,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * La demande en attente de ce jeton, cherchée par son empreinte hors de
     * toute société ; null pour un lien expiré, déjà servi, annulé ou
     * inconnu.
     */
    public static function demandeEnAttente(string $jeton): ?DemandeRattachement
    {
        $demande = DemandeRattachement::withoutGlobalScope(SocieteScope::class)->where('jeton_hache', DemandeRattachement::empreinte($jeton))->first();

        return $demande !== null && $demande->estEnAttente() ? $demande : null;
    }

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(string $jeton, string $email, #[SensitiveParameter] string $motDePasse): User
    {
        $demande = self::demandeEnAttente($jeton) ?? throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
        $compte = User::query()->findOrFail($demande->user_id);

        if (! hash_equals($compte->email, User::normaliserEmail($email)) || ! Hash::check($motDePasse, $compte->password)) {
            throw new AuthorizationException(self::MESSAGE_IDENTIFIANTS);
        }

        $societe = Societe::query()->findOrFail($demande->societe_id);
        $quittee = null;
        $ecrites = [];

        app(TenantContext::class)->runAsSociete($societe, function () use ($demande, $compte, $societe, $motDePasse, &$quittee, &$ecrites): void {
            $demandees = $demande->role_societe === RoleSociete::Membre ? array_filter($this->affectations->lire($societe, $demande->affectationsProposees())) : [];
            $magasinsVises = $demande->role_societe === RoleSociete::ExpertComptable
                ? Magasin::query()->where('societe_id', $societe->getKey())->get()
                : array_map(fn (array $demandee): Magasin => $demandee['magasin'], $demandees);
            $auteur = User::query()->findOrFail($demande->demande_par);

            $this->journalDesDroits->modifier($auteur, ActionAuditee::RattachementAccepte, [$compte], $magasinsVises, function (User $auteurRelu, array $relus) use ($demande, $societe, $motDePasse, $demandees, &$quittee, &$ecrites): void {
                [$compteRelu] = $relus;
                $demandeRelue = DemandeRattachement::query()->whereKey($demande->getKey())->lockForUpdate()->first();

                if ($demandeRelue === null || ! $demandeRelue->estEnAttente()) {
                    throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
                }

                if (! $compteRelu->estRetire() || ! $compteRelu->estMembre()) {
                    throw new AuthorizationException(self::MESSAGE_COMPTE_PAS_LIBRE);
                }

                if ($compteRelu->doitChangerSonMotDePasse()) {
                    throw new AuthorizationException(self::MESSAGE_CHOISIR_SON_MOT_DE_PASSE);
                }

                if (! Hash::check($motDePasse, $compteRelu->password)) {
                    throw new AuthorizationException(self::MESSAGE_IDENTIFIANTS);
                }

                if ((int) $auteurRelu->societe_id !== $societe->getKey()
                    || ! $this->portee->peutProposerUnRattachement($auteurRelu, $demandeRelue->role_societe, array_map(fn (array $demandee): RoleMagasin => $demandee['role'], $demandees))
                    || ! $this->portee->peutRecevoirUnRattachement($auteurRelu, $compteRelu)) {
                    throw new AuthorizationException(self::MESSAGE_AUTEUR);
                }

                $verifiees = $demandeRelue->role_societe === RoleSociete::Membre ? $this->affectations->verifierPourUnNouveauCompte($auteurRelu, $demandees) : [];
                $ancienneSociete = (int) $compteRelu->societe_id;
                $quittee = $ancienneSociete === $societe->getKey() ? null : ['societe' => $ancienneSociete, 'avant' => ['nom' => $compteRelu->name, 'role_societe' => RoleSociete::Membre->value, 'statut' => StatutCompte::Retire->value, 'autorisations_societe' => [], 'magasins' => []]];

                $compteRelu->forceFill([
                    'societe_id' => $societe->getKey(),
                    'role_societe' => $demandeRelue->role_societe,
                    'statut' => StatutCompte::Actif,
                    'statut_change_par' => $auteurRelu->getKey(),
                    'email_verified_at' => now(),
                    ...($quittee === null ? [] : ['qualite_verifiee_le' => null]),
                ])->save();

                foreach ($verifiees as $affectation) {
                    $ecrites[] = $this->affectations->ecrire($auteurRelu, $compteRelu, $affectation['magasin'], $affectation['role'], $affectation['autorisations']);
                }

                $compteRelu->effacerToutesSesNotifications();
                $this->sessions->fermer($compteRelu);
                $demandeRelue->forceFill(['acceptee_le' => now()])->save();
            });
        });

        $compte->refresh();
        $this->annulerSesAutresPropositions($compte, $demande);

        if ($quittee !== null) {
            app(TenantContext::class)->runAsSociete(Societe::query()->findOrFail($quittee['societe']), fn () => $this->journalAudit->inscrire(
                ActionAuditee::RattachementAccepte,
                $compte,
                null,
                ['droits' => [$compte->getKey() => $quittee['avant']]],
                ['droits' => [$compte->getKey() => null]],
            ));
        }

        $this->affectations->prevenirDesNominations($compte, $ecrites);

        return $compte;
    }

    /**
     * La personne refuse la proposition : elle ne sert plus, et son auteur,
     * s'il est encore actif dans la société, en est prévenu. Le lien suffit :
     * refuser ne donne aucun droit.
     *
     * @throws AuthorizationException
     */
    public function refuser(string $jeton): void
    {
        $demande = self::demandeEnAttente($jeton) ?? throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
        $societe = Societe::query()->findOrFail($demande->societe_id);

        app(TenantContext::class)->runAsSociete($societe, function () use ($demande, $societe): void {
            DB::transaction(function () use ($demande): void {
                $demandeRelue = DemandeRattachement::query()->whereKey($demande->getKey())->lockForUpdate()->first();

                if ($demandeRelue === null || ! $demandeRelue->estEnAttente()) {
                    throw new AuthorizationException(self::MESSAGE_LIEN_INVALIDE);
                }

                $demandeRelue->forceFill(['refusee_le' => now()])->save();
            });

            $auteur = User::query()->find($demande->demande_par);

            if ($auteur !== null && $auteur->estActif() && (int) $auteur->societe_id === $societe->getKey()) {
                $auteur->notify(new AvisDeLEquipe($societe, 'Proposition refusée', "La personne à qui vous avez proposé de rejoindre l'équipe, le {$demande->created_at->setTimezone(config('app.timezone'))->format('d/m/Y')}, a refusé votre proposition."));
            }
        });
    }

    /**
     * Les autres propositions encore en attente pour ce compte, dans toute
     * société, ne servent plus : chacune s'annule dans sa société. Seules
     * les sociétés qui en ont une pour ce compte sont lues, par l'identifiant
     * du compte.
     */
    private function annulerSesAutresPropositions(User $compte, DemandeRattachement $acceptee): void
    {
        $societes = DemandeRattachement::withoutGlobalScope(SocieteScope::class)
            ->where('user_id', $compte->getKey())
            ->whereKeyNot($acceptee->getKey())
            ->whereNull('acceptee_le')
            ->whereNull('refusee_le')
            ->whereNull('annulee_le')
            ->distinct()
            ->pluck('societe_id');

        foreach (Societe::query()->whereKey($societes)->get() as $societe) {
            app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(fn () => DemandeRattachement::query()
                ->where('user_id', $compte->getKey())
                ->whereNull('acceptee_le')
                ->whereNull('refusee_le')
                ->whereNull('annulee_le')
                ->lockForUpdate()
                ->get()
                ->each(fn (DemandeRattachement $autre): bool => $autre->forceFill(['annulee_le' => now()])->save())));
        }
    }
}
