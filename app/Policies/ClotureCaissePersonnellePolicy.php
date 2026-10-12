<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * Aucune permission dédiée n'est nécessaire pour fermer SA PROPRE caisse :
 * quiconque vend (facture.create) ou encaisse (paiement.creer) la ferme et
 * consulte ses relevés, voir le docblock de ClotureCaissePersonnelle, le
 * comptable comme le vendeur. Qui peut clôturer la journée
 * (cloture_caisse.gerer) ferme aussi celle d'un autre vendeur du magasin,
 * pour lui (voir fermerLaCaisseDe()), depuis la page des relevés, qui lui
 * est donc ouverte même s'il ne vend ni n'encaisse ; et toute caisse
 * encore ouverte se ferme d'office avec la journée (voir
 * FermerLesCaissesPersonnellesAction). Quiconque a
 * cloture_caisse.view_toutes (le propriétaire, un comptable...) voit les
 * relevés de toute l'équipe, pour la réconciliation de fin de journée ; tout autre membre
 * ne voit que les siens, même quand il ferme la caisse d'un autre (voir
 * ListClotureCaissePersonnelles).
 */
class ClotureCaissePersonnellePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tientUneCaisse($user)
            || $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)
            || $user->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    public function view(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        if (! $this->appartientAuMagasinActif($cloture)) {
            return false;
        }

        return $user->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) || $cloture->vendeur_id === $user->getKey();
    }

    public function fermer(User $user): bool
    {
        return $this->tientUneCaisse($user);
    }

    /**
     * « Fermer la caisse d'un vendeur » : qui peut clôturer la journée
     * ferme celle d'un autre membre du magasin actif, pour lui ; la sienne
     * se ferme par fermer(). Jamais celle d'un compte d'un autre magasin,
     * dont le relevé révélerait le nom dans la liste de celui-ci. Permis
     * dans un magasin suspendu ou fermé, comme toute clôture (voir
     * ControleDesDroits).
     */
    public function fermerLaCaisseDe(User $user, User $vendeur): bool
    {
        return ! $vendeur->is($user)
            && $user->can(PermissionRegistry::CLOTURE_CAISSE_GERER)
            && $this->tientSaCaisseDansLeMagasinActif($vendeur);
    }

    public function update(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function delete(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    public function restore(User $user, ClotureCaissePersonnelle $cloture): bool
    {
        return false;
    }

    /**
     * Le droit de vendre ou d'encaisser dans le magasin actif, lu sur ses
     * autorisations dans ce magasin plutôt que demandé à la Gate : dans un
     * magasin en lecture seule, celle-ci refuse facture.create et
     * paiement.creer (voir ControleDesDroits), mais chacun ferme toujours sa
     * caisse et consulte toujours ses relevés. Le propriétaire les a toutes
     * dans les magasins de sa société, et seulement là.
     */
    private function tientUneCaisse(User $user): bool
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->has()) {
            return false;
        }

        return array_intersect([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::PAIEMENT_CREER], $user->autorisationsDans($contexte->magasin())) !== [];
    }

    /**
     * Le vendeur a sa caisse dans le magasin actif : un compte de sa
     * société qui y a des droits, quel que soit l'état de son accès (le
     * propriétaire, un administrateur de tous les magasins, un compte
     * affecté à ce magasin), ou qui y a créé une facture ou enregistré un
     * paiement (un membre qui n'y est plus affecté y garde sa caisse
     * ouverte jusqu'à ce qu'elle soit fermée). Les factures et les
     * paiements se lisent sous MagasinScope, dans ce seul magasin.
     */
    private function tientSaCaisseDansLeMagasinActif(User $vendeur): bool
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->has() || (int) $vendeur->societe_id !== (int) $contexte->magasin()->societe_id) {
            return false;
        }

        return $vendeur->estProprietaire()
            || $vendeur->affectationQuiVautDans($contexte->magasin()) !== null
            || Facture::query()->where('cree_par', $vendeur->getKey())->exists()
            || Paiement::query()->where('cree_par', $vendeur->getKey())->exists();
    }
}
