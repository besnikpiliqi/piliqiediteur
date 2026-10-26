<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Les destinataires d'une alerte d'un magasin (stock bas ou négatif...) :
 * le propriétaire de la société, les responsables du magasin, qui ont
 * toutes ses autorisations sans case, et toute autre personne qui peut
 * ouvrir le magasin et y a l'autorisation, par la case de son affectation
 * à ce magasin ou, pour un administrateur, à tous les magasins (vendeurs,
 * observateurs, administrateurs) ; jamais un compte d'une autre société,
 * ni un compte suspendu, retiré ou qui n'a pas encore choisi son mot de
 * passe, ni un expert-comptable, qui n'a que les consultations (voir
 * User::peutOuvrir() et User::autorisationsDans()).
 *
 * Lecture hors du magasin courant, filtrée par la société et par le
 * magasin, limitée aux comptes : les affectations n'ont pas de scope
 * global (voir Affectation).
 */
final class DestinatairesDesAlertes
{
    /**
     * @return Collection<int, User>
     */
    public function autorisesDans(Magasin $magasin, string $autorisation): Collection
    {
        $affectationsQuiLaDonnent = Affectation::query()
            ->where('societe_id', $magasin->societe_id)
            ->where(fn ($affectations) => $affectations
                ->where(fn ($responsables) => $responsables->where('magasin_id', $magasin->getKey())->where('role', RoleMagasin::Responsable->value))
                ->orWhere(fn ($cochees) => $cochees
                    ->where(fn ($ici) => $ici->where('magasin_id', $magasin->getKey())->orWhereNull('magasin_id'))
                    ->whereIn('id', DB::table('autorisations_affectations')->where('autorisation', $autorisation)->select('affectation_id'))))
            ->select('user_id');

        return User::query()
            ->where('societe_id', $magasin->societe_id)
            ->where(fn ($comptes) => $comptes
                ->where('role_societe', RoleSociete::Proprietaire->value)
                ->orWhereIn('id', $affectationsQuiLaDonnent))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $compte): bool => $compte->peutOuvrir($magasin) && in_array($autorisation, $compte->autorisationsDans($magasin), true))
            ->values();
    }
}
