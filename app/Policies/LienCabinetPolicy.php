<?php

namespace App\Policies;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;

/**
 * Les gestes sur un lien entre un cabinet d'expertise comptable et un
 * client, chacun selon la société de son auteur, où l'action le contrôle
 * (voir JournalDesDroits::exigerDansSaSociete()) :
 * - côté cabinet : voir un lien que lui ouvre AccesDesCabinets::liensOuverts()
 *   (son propriétaire voit aussi les demandes de son cabinet) ; accepter
 *   ou refuser la demande d'un client, retirer sa demande de rattachement,
 *   mettre fin à un lien actif (même un cabinet qui n'est plus vérifié) :
 *   son propriétaire seul ;
 * - côté client : son propriétaire seul, dans une société qui n'est pas
 *   un cabinet, ni révoquée, ni close (voir SocietePolicy::gererSonCabinet()) :
 *   voir, accepter ou refuser une demande de rattachement, retirer sa
 *   désignation, régler les autorisations, mettre fin au lien.
 */
class LienCabinetPolicy
{
    public function __construct(private readonly PorteeDesDroits $portee) {}

    public function viewAny(User $user): bool
    {
        return $user->peutAgir() && ($user->estDUnCabinet() || $this->gereSonCabinet($user, null));
    }

    public function view(User $user, LienCabinet $lien): bool
    {
        if ($this->estDuCabinet($user, $lien)) {
            return ($user->estProprietaire() && $lien->estUneDemande())
                || app(AccesDesCabinets::class)->liensOuverts($user)->whereKey($lien->getKey())->exists();
        }

        return $this->gereSonCabinet($user, $lien);
    }

    public function accepter(User $user, LienCabinet $lien): bool
    {
        return match ($lien->etat) {
            EtatDuLien::DemandeParLeCabinet => $this->gereSonCabinet($user, $lien),
            EtatDuLien::DemandeParLeClient => $this->proprietaireDuCabinet($user, $lien),
            default => false,
        };
    }

    public function refuser(User $user, LienCabinet $lien): bool
    {
        return $this->accepter($user, $lien);
    }

    public function retirer(User $user, LienCabinet $lien): bool
    {
        return match ($lien->etat) {
            EtatDuLien::DemandeParLeCabinet => $this->proprietaireDuCabinet($user, $lien),
            EtatDuLien::DemandeParLeClient => $this->gereSonCabinet($user, $lien),
            default => false,
        };
    }

    /**
     * Régler les autorisations et les magasins du lien en cours, actif ou
     * déclaré : le propriétaire du client.
     */
    public function reglerLesAutorisations(User $user, LienCabinet $lien): bool
    {
        return $lien->estEnCours() && $this->gereSonCabinet($user, $lien);
    }

    /**
     * Mettre fin à un lien actif ou retirer la déclaration de
     * l'expert-comptable de la société, ou redemander la fin d'un lien déjà
     * terminé, qui ne change alors rien (voir MettreFinAuLienAction) : le
     * propriétaire du client, ou, pour un lien, celui du cabinet.
     */
    public function mettreFin(User $user, LienCabinet $lien): bool
    {
        return in_array($lien->etat, [EtatDuLien::Actif, EtatDuLien::Declare, EtatDuLien::Termine], true) && ($this->gereSonCabinet($user, $lien) || $this->proprietaireDuCabinet($user, $lien));
    }

    /**
     * Ajouter une personne du cabinet déclaré, qui reçoit son compte
     * d'expert-comptable de la société : le propriétaire du client, pour sa
     * déclaration en attente (voir AjouterUnExpertComptableAction).
     */
    public function ajouterUnExpertComptable(User $user, LienCabinet $lien): bool
    {
        return $lien->estDeclare() && $this->gereSonCabinet($user, $lien);
    }

    /**
     * Confier ce client à des collaborateurs : le propriétaire du cabinet,
     * pour un lien actif que son cabinet lui ouvre.
     */
    public function confier(User $user, LienCabinet $lien): bool
    {
        return $lien->estActif() && $this->proprietaireDuCabinet($user, $lien) && app(AccesDesCabinets::class)->liensOuverts($user)->whereKey($lien->getKey())->exists();
    }

    private function estDuCabinet(User $user, LienCabinet $lien): bool
    {
        return $user->peutAgir() && $user->estDUnCabinet() && $user->societe_id === $lien->cabinet_id;
    }

    /**
     * Le propriétaire actif du cabinet de ce lien, dans un cabinet qui
     * n'est pas révoqué.
     */
    private function proprietaireDuCabinet(User $user, LienCabinet $lien): bool
    {
        return $this->estDuCabinet($user, $lien) && $user->estProprietaire() && $this->portee->gereSonEquipe($user);
    }

    /**
     * Le propriétaire du client de ce lien (de sa société, sans lien), qui
     * gère son cabinet.
     */
    private function gereSonCabinet(User $user, ?LienCabinet $lien): bool
    {
        $societe = Societe::query()->find($user->societe_id);

        return $societe !== null
            && ($lien === null || $lien->societe_id === $user->societe_id)
            && app(SocietePolicy::class)->gererSonCabinet($user, $societe);
    }
}
