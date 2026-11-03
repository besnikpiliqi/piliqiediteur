<?php

namespace App\Domain\Authorization;

use App\Models\User;
use Closure;

/**
 * La personne au nom de laquelle les opérations en cours s'inscrivent au
 * registre fiscal et au journal des événements (voir RegistreFiscal), avec
 * trois états :
 * - une personne, posée par pendant() : l'auteur qu'une action a relu en
 *   base et contrôlé (voir LecteurDesDroits), jamais la personne connectée
 *   à sa place ;
 * - le système, posé par pendantLeSysteme() : une clôture automatique,
 *   une fermeture d'office, une relance automatique, un devis qui expire ;
 *   l'opérateur signé est nul, même pendant une requête authentifiée ;
 * - rien de posé : seuls les événements techniques hors action
 *   (ouverture et fin de session) s'inscrivent alors au nom de la personne
 *   connectée (voir actuel()) ; une vente, un avoir, un encaissement ou une
 *   clôture est refusé (voir RegistreFiscal). L'abandon d'une vente et une
 *   ligne retirée s'inscrivent au nom du vendeur relu par le panier de la
 *   caisse (voir PanierDeCaisse) ou de l'auteur relu par l'action du
 *   brouillon (voir SupprimerFactureAction et ModifierFactureAction).
 *
 * Lié en scoped() (voir AppServiceProvider), comme TenantContext : chaque
 * requête et chaque job repart de rien. Chaque état posé se restaure à la
 * fin de son opération, même après une exception : une opération du
 * système appelée pendant celle d'une personne, ou l'inverse, n'emporte
 * jamais l'état de l'autre.
 */
final class OperateurCourant
{
    private bool $pose = false;

    private ?User $operateur = null;

    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     */
    public function pendant(User $operateur, Closure $operation): mixed
    {
        return $this->poser(true, $operateur, $operation);
    }

    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     */
    public function pendantLeSysteme(Closure $operation): mixed
    {
        return $this->poser(true, null, $operation);
    }

    /**
     * La personne posée ; null sous pendantLeSysteme(), sans jamais revenir
     * à la personne connectée ; si rien n'est posé, la personne connectée,
     * pour les seuls événements techniques hors action.
     */
    public function actuel(): ?User
    {
        if ($this->pose) {
            return $this->operateur;
        }

        $connecte = auth()->user();

        return $connecte instanceof User ? $connecte : null;
    }

    public function estPose(): bool
    {
        return $this->pose;
    }

    /**
     * La personne posée, et elle seule : null sous pendantLeSysteme() et
     * quand rien n'est posé. Ce qu'une ligne d'historique enregistre comme
     * son auteur quand elle naît d'une action, comme le mouvement de stock
     * d'une vente ou d'une réception (voir StockLedgerService).
     */
    public function personnePosee(): ?User
    {
        return $this->pose ? $this->operateur : null;
    }

    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     */
    private function poser(bool $pose, ?User $operateur, Closure $operation): mixed
    {
        [$poseAvant, $operateurAvant] = [$this->pose, $this->operateur];
        [$this->pose, $this->operateur] = [$pose, $operateur];

        try {
            return $operation();
        } finally {
            [$this->pose, $this->operateur] = [$poseAvant, $operateurAvant];
        }
    }
}
