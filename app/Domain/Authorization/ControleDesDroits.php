<?php

namespace App\Domain\Authorization;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * L'unique Gate::before de l'application (voir TenancyServiceProvider).
 * Il ne répond jamais « oui » d'office à une question de policy, pas même
 * pour le propriétaire : les règles qui valent pour tous (lecture seule,
 * validation de la société, document émis, journée clôturée) s'appliquent
 * à lui comme aux autres, et c'est par ses autorisations, toutes sans
 * case, qu'il passe les policies.
 *
 * Un compte qui ne peut pas agir n'a aucun droit, où que ce soit :
 * suspendu ou retiré, ou avec un mot de passe provisoire (voir
 * User::peutAgir()).
 *
 * Un expert-comptable lit et n'écrit rien, quelles que soient les lignes
 * que garderait la base : aucune autorisation de société ; dans un magasin
 * réel de sa société, les seules consultations (voir
 * PermissionRegistry::consultations()) ; à toute question de policy autre
 * qu'une consultation (viewAny, view), y compris une question future, un
 * refus.
 *
 * Une autorisation de société (voir AutorisationsSociete) ne vaut que dans
 * la société du compte : celle du contexte, sinon la sienne. Le propriétaire
 * les a toutes, un administrateur celles que le propriétaire a cochées pour
 * lui, tout autre compte aucune ; une société en lecture seule ne garde que
 * celles qui y restent permises (nommer et retirer les responsables, sauf
 * dans une société révoquée). Aucune ne suffit seule pour un geste sur un
 * magasin : les policies et les actions vérifient en plus la portée de
 * l'administrateur.
 *
 * Dans l'espace d'administration d'une société (une société posée sans
 * magasin), il refuse tout au compte d'une autre société ; quand la
 * société est en lecture seule (suspendue, résiliée, révoquée ou refusée,
 * voir Societe::estEnLectureSeule()), tout ce qui n'est ni une
 * consultation ni un geste sur l'équipe (un compte, voir MembrePolicy), et,
 * dans une société révoquée, tout ce qui n'est pas une consultation ; sinon
 * les policies répondent, en mode strict (voir RessourceDuPanneauSociete).
 *
 * Dans un magasin actif, il répond pour toute autorisation de
 * PermissionRegistry : seulement dans un magasin de la société du compte
 * (jamais ailleurs, propriétaire compris) ; oui s'il l'a dans ce magasin
 * (voir User::autorisationsDans() : toutes pour le propriétaire et un
 * responsable du magasin, les consultations pour un expert-comptable, ses
 * cases pour tout autre compte), sinon la question passe aux policies.
 * Quel que soit le compte, il refuse dans ce magasin :
 * - en lecture seule (voir Magasin::estEnLectureSeule()), tout ce qui n'y
 *   reste pas permis : viewAny et view, sur tout modèle (consulter et
 *   télécharger) ; clôturer la journée (ClotureCaissePolicy::cloturer()),
 *   fermer sa caisse et celle d'un vendeur
 *   (ClotureCaissePersonnellePolicy::fermer() et fermerLaCaisseDe()) : les
 *   clôtures continuent jusqu'au bout ; les autorisations de
 *   PermissionRegistry::permisesEnLectureSeule() ; un geste sur l'équipe
 *   du magasin (une affectation ou un compte), sauf dans une société
 *   révoquée ;
 * - tant que le magasin ne peut pas vendre (société en cours de
 *   vérification, établissement pas encore confirmé, voir
 *   Magasin::peutVendre()), les autorisations des opérations commerciales
 *   (PermissionRegistry::reserveesAuxOperationsCommerciales()).
 * Une policy qui, pour une consultation, demande une autre autorisation la
 * voit donc refusée : une consultation refusée reste sûre.
 *
 * Il n'a aucun état : le contexte se relit à chaque question, dans le
 * TenantContext de la requête ou du job en cours (lié en scoped()).
 */
final class ControleDesDroits
{
    private const CONSULTATIONS = ['viewAny', 'view'];

    /**
     * Les gestes de clôture, chacun sur son modèle.
     */
    private const CLOTURES = [
        'cloturer' => ClotureCaisse::class,
        'fermer' => ClotureCaissePersonnelle::class,
        'fermerLaCaisseDe' => ClotureCaissePersonnelle::class,
    ];

    /**
     * @param  array<int, mixed>  $arguments  le modèle (ou sa classe) sur lequel porte la question, s'il y en a un
     */
    public function __invoke(User $user, string $ability, array $arguments = []): ?bool
    {
        if (! $user->peutAgir()) {
            return false;
        }

        $contexte = app(TenantContext::class);
        $estDeSociete = in_array($ability, AutorisationsSociete::toutes(), true);

        if ($user->estExpertComptable() && ($estDeSociete || ! $this->estUneConsultationPermise($ability))) {
            return false;
        }

        if ($estDeSociete) {
            return $this->autorisationDeSociete($user, $ability, $contexte->aUneSociete() ? $contexte->societe() : $user->societe);
        }

        if (! $contexte->has()) {
            if (! $contexte->aUneSociete()) {
                return null;
            }

            return $this->dansLEspaceDeLaSociete($user, $ability, $arguments[0] ?? null, $contexte->societe());
        }

        $magasin = $contexte->magasin();

        if ((int) $magasin->societe_id !== (int) $user->societe_id) {
            return false;
        }

        if ($magasin->estEnLectureSeule() && ! $this->estPermisEnLectureSeule($ability, $arguments[0] ?? null, $magasin->societe)) {
            return false;
        }

        if (! $magasin->peutVendre() && in_array($ability, PermissionRegistry::reserveesAuxOperationsCommerciales(), true)) {
            return false;
        }

        if (! in_array($ability, PermissionRegistry::toutes(), true)) {
            return null;
        }

        return in_array($ability, $user->autorisationsDans($magasin), true) ?: null;
    }

    /**
     * Ce qu'un expert-comptable peut demander : une consultation de
     * PermissionRegistry, ou une question de consultation à une policy.
     */
    private function estUneConsultationPermise(string $ability): bool
    {
        return in_array($ability, PermissionRegistry::consultations(), true) || in_array($ability, self::CONSULTATIONS, true);
    }

    private function autorisationDeSociete(User $user, string $ability, Societe $societe): ?bool
    {
        if ($societe->getKey() !== (int) $user->societe_id) {
            return false;
        }

        if ($societe->estEnLectureSeule() && ! in_array($ability, AutorisationsSociete::permisesEnLectureSeule($societe->statut === StatutSociete::Revoquee), true)) {
            return false;
        }

        return $user->aLAutorisationDeSociete($ability) ?: null;
    }

    private function dansLEspaceDeLaSociete(User $user, string $ability, mixed $cible, Societe $societe): ?bool
    {
        if ($societe->getKey() !== (int) $user->societe_id) {
            return false;
        }

        if (! $societe->estEnLectureSeule() || in_array($ability, self::CONSULTATIONS, true)) {
            return null;
        }

        return self::porteSurLEquipe($cible) && $societe->statut !== StatutSociete::Revoquee ? null : false;
    }

    private function estPermisEnLectureSeule(string $ability, mixed $cible, Societe $societe): bool
    {
        return in_array($ability, self::CONSULTATIONS, true)
            || (isset(self::CLOTURES[$ability]) && self::porteSur($cible, self::CLOTURES[$ability]))
            || in_array($ability, PermissionRegistry::permisesEnLectureSeule(), true)
            || (self::porteSurLEquipe($cible) && $societe->statut !== StatutSociete::Revoquee);
    }

    /**
     * Un geste sur l'équipe : un compte (voir MembrePolicy), ou une
     * affectation à un magasin (voir AffectationPolicy).
     */
    private static function porteSurLEquipe(mixed $cible): bool
    {
        return self::porteSur($cible, User::class) || self::porteSur($cible, Affectation::class);
    }

    /**
     * @param  class-string  $modele
     */
    private static function porteSur(mixed $cible, string $modele): bool
    {
        return $cible instanceof $modele || $cible === $modele;
    }
}
