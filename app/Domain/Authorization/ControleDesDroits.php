<?php

namespace App\Domain\Authorization;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * L'unique Gate::before de l'application (voir TenancyServiceProvider).
 *
 * Un compte qui n'est pas actif n'a aucun droit, où que ce soit.
 *
 * Dans un magasin actif, il répond pour toute autorisation de
 * PermissionRegistry : seulement dans un magasin de la société du compte
 * (jamais ailleurs, propriétaire compris) ; oui pour le propriétaire de la
 * société, qui a tous les droits sans case ni rattachement ; pour un
 * membre, oui s'il a la permission dans ce magasin (Spatie en mode teams,
 * voir MagasinTeamResolver), sinon la question passe aux policies. Il
 * remplace le rappel de Spatie au lieu de s'y ajouter
 * (permission.register_permission_check_method à false) : celui de Spatie
 * répondrait « oui » avant tout autre rappel, et les refus ci-dessous
 * n'auraient jamais lieu.
 *
 * Quel que soit le compte, il refuse dans ce magasin :
 * - en lecture seule (voir Magasin::estEnLectureSeule()), tout ce qui n'y
 *   reste pas permis : viewAny et view, sur tout modèle (consulter et
 *   télécharger) ; clôturer la journée (ClotureCaissePolicy::cloturer()),
 *   fermer sa caisse et celle d'un vendeur
 *   (ClotureCaissePersonnellePolicy::fermer() et fermerLaCaisseDe()) : les
 *   clôtures continuent jusqu'au bout ; tout geste sur l'équipe
 *   (MembreEquipePolicy) : couper un accès reste possible, sauf dans une
 *   société révoquée, en lecture seule totale ; les autorisations de
 *   PermissionRegistry::permisesEnLectureSeule(), celles de l'équipe
 *   exceptées dans une société révoquée ;
 * - tant que le magasin ne peut pas vendre (société en cours de
 *   vérification, établissement pas encore confirmé, voir
 *   Magasin::peutVendre()), les autorisations des opérations commerciales
 *   (PermissionRegistry::reserveesAuxOperationsCommerciales()).
 * Une policy qui, pour une consultation, demande une autre autorisation la
 * voit donc refusée : une consultation refusée reste sûre.
 *
 * Dans l'espace d'administration d'une société (une société posée sans
 * magasin), il refuse tout au compte d'une autre société, et tout ce qui
 * n'est pas une consultation quand la société est en lecture seule
 * (suspendue, résiliée, révoquée ou refusée, voir
 * Societe::estEnLectureSeule()) ; sinon les policies répondent, en mode
 * strict (voir SocietePolicy et RessourceDuPanneauSociete).
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
        if (! $user->estActif()) {
            return false;
        }

        $contexte = app(TenantContext::class);

        if (! $contexte->has()) {
            if (! $contexte->aUneSociete()) {
                return null;
            }

            $societe = $contexte->societe();

            if ($societe->getKey() !== (int) $user->societe_id) {
                return false;
            }

            return $societe->estEnLectureSeule() && ! in_array($ability, self::CONSULTATIONS, true) ? false : null;
        }

        $magasin = $contexte->magasin();

        if ((int) $magasin->societe_id !== (int) $user->societe_id) {
            return false;
        }

        if ($magasin->estEnLectureSeule() && ! $this->estPermisEnLectureSeule($ability, $arguments[0] ?? null, $magasin->societe->statut === StatutSociete::Revoquee)) {
            return false;
        }

        if (! $magasin->peutVendre() && in_array($ability, PermissionRegistry::reserveesAuxOperationsCommerciales(), true)) {
            return false;
        }

        if (! in_array($ability, PermissionRegistry::toutes(), true)) {
            return null;
        }

        return $user->estProprietaire() || $user->checkPermissionTo($ability) ?: null;
    }

    private function estPermisEnLectureSeule(string $ability, mixed $cible, bool $societeRevoquee): bool
    {
        $gesteSurLEquipe = self::porteSur($cible, MagasinUser::class)
            || in_array($ability, [PermissionRegistry::EQUIPE_CREER, PermissionRegistry::EQUIPE_MODIFIER], true);

        if ($societeRevoquee && $gesteSurLEquipe && ! in_array($ability, self::CONSULTATIONS, true)) {
            return false;
        }

        return in_array($ability, self::CONSULTATIONS, true)
            || (isset(self::CLOTURES[$ability]) && self::porteSur($cible, self::CLOTURES[$ability]))
            || $gesteSurLEquipe
            || in_array($ability, PermissionRegistry::permisesEnLectureSeule(), true);
    }

    /**
     * @param  class-string  $modele
     */
    private static function porteSur(mixed $cible, string $modele): bool
    {
        return $cible instanceof $modele || $cible === $modele;
    }
}
