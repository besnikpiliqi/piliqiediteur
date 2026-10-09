<?php

namespace App\Domain\Authorization;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * L'unique Gate::before de l'application (voir TenancyServiceProvider).
 *
 * Il fait d'abord ce que faisait celui de Spatie Permission : une
 * autorisation de PermissionRegistry se vérifie sur les permissions du
 * membre dans le magasin actif (voir MagasinTeamResolver), et toute autre
 * question passe aux policies. Il le remplace au lieu de s'y ajouter
 * (permission.register_permission_check_method à false) : celui de Spatie
 * répond « oui » à une autorisation détenue avant tout autre rappel, et le
 * refus d'un magasin en lecture seule n'aurait jamais lieu.
 *
 * Quand le magasin actif est suspendu ou fermé (voir
 * StatutMagasin::estEnLectureSeule()), il refuse tout ce qui n'y reste pas
 * permis, quels que soient les droits du membre :
 * - viewAny et view, sur tout modèle : consulter et télécharger ;
 * - clôturer la journée (ClotureCaissePolicy::cloturer()) et fermer sa
 *   caisse (ClotureCaissePersonnellePolicy::fermer()) : les clôtures
 *   continuent jusqu'au bout ;
 * - tout geste sur l'équipe (MembreEquipePolicy) : couper un accès reste
 *   possible ;
 * - les autorisations de PermissionRegistry::permisesEnLectureSeule().
 * Une policy qui, pour une consultation, demande une autre autorisation la
 * voit donc refusée : une consultation refusée reste sûre.
 *
 * Il n'a aucun état : le magasin actif se relit à chaque question, dans le
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
    ];

    /**
     * @param  array<int, mixed>  $arguments  le modèle (ou sa classe) sur lequel porte la question, s'il y en a un
     */
    public function __invoke(User $user, string $ability, array $arguments = []): ?bool
    {
        if ($this->magasinActifEnLectureSeule() && ! $this->estPermisEnLectureSeule($ability, $arguments[0] ?? null)) {
            return false;
        }

        return $user->checkPermissionTo($ability) ?: null;
    }

    private function magasinActifEnLectureSeule(): bool
    {
        $contexte = app(TenantContext::class);

        return $contexte->has() && $contexte->magasin()->statut->estEnLectureSeule();
    }

    private function estPermisEnLectureSeule(string $ability, mixed $cible): bool
    {
        return in_array($ability, self::CONSULTATIONS, true)
            || (isset(self::CLOTURES[$ability]) && self::porteSur($cible, self::CLOTURES[$ability]))
            || self::porteSur($cible, MagasinUser::class)
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
