<?php

namespace App\Policies;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VenteDuStockSeul;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * Confidentialité entre vendeurs (B16) : sans FACTURE_VIEW_TOUTES, un
 * utilisateur ne voit, n'envoie et n'encaisse que les factures qu'il a
 * lui-même créées (voir Facture::scopeVisiblesPar() pour les listes). Les
 * clients restent partagés (voir ClientPolicy) -- seul l'historique de
 * vente est cloisonné.
 *
 * La facture du stock vendu seul d'un magasin que sa société a fermé sans
 * le céder (voir VenteDuStockSeul) passe par les mêmes autorisations de
 * vente que toute facture : pour elle seule, le magasin fermé y est tenu
 * pour ouvert le temps de la question (voir enVenteDuStock()), jamais pour
 * une autre facture, et son brouillon ne se modifie pas : il s'émet ou se
 * supprime, la facture se refait depuis la liste (voir
 * FacturerLeStockAction). Le propriétaire, qui ne vend jamais, n'a aucune
 * de ces autorisations.
 */
class FacturePolicy extends MagasinPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_VIEW);
    }

    public function view(User $user, Facture $facture): bool
    {
        if (! $this->appartientAuMagasinActif($facture)) {
            return false;
        }

        if ($user->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            return true;
        }

        return $user->can(PermissionRegistry::FACTURE_VIEW) && $facture->cree_par === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    /**
     * Préparer la facture du stock vendu seul, depuis la liste des factures
     * d'un magasin que sa société a fermé sans le céder (voir
     * FacturerLeStockAction) : pouvoir créer et émettre une facture dans ce
     * magasin, tenu pour ouvert le temps de la question, jamais le
     * propriétaire ni un compte du cabinet lié, qui n'ont aucune
     * autorisation de vente. Un magasin ouvert fait une facture ordinaire.
     */
    public function facturerLeStock(User $user): bool
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->has() || ! $contexte->magasin()->peutFacturerSonStock()) {
            return false;
        }

        return app(VenteDuStockSeul::class)->commeSiOuvert($contexte->magasin(), fn (): bool => $user->can(PermissionRegistry::FACTURE_CREATE) && $user->can(PermissionRegistry::FACTURE_EMETTRE));
    }

    /**
     * Un brouillon n'a jamais reçu de numéro légal (voir delete()) : il
     * reste librement modifiable, lignes comprises, pour éviter d'obliger
     * l'utilisateur à tout ressaisir pour corriger une erreur de saisie.
     * Dès que la facture quitte ce statut, la modification redevient
     * refusée sans exception : une facture émise se corrige par un avoir
     * (voir Avoir), jamais en la réécrivant.
     */
    public function update(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture)
            && $facture->statut === StatutFacture::Brouillon
            && ! $facture->estLaVenteDuStockSeul()
            && $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    public function emettre(User $user, Facture $facture): bool
    {
        return $this->enVenteDuStock($facture, fn (): bool => $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_EMETTRE));
    }

    /**
     * « Émettre et encaisser » un brouillon fait hors de la caisse (R1) :
     * pouvoir l'émettre et enregistrer un encaissement dans ce magasin,
     * comme les deux gestes qu'il réunit. Jamais le propriétaire, qui ne
     * vend jamais, ni un compte du cabinet lié, qui n'a aucune de ces
     * autorisations.
     */
    public function emettreEtEncaisser(User $user, Facture $facture): bool
    {
        return $facture->statut === StatutFacture::Brouillon
            && $this->emettre($user, $facture)
            && $this->enVenteDuStock($facture, fn (): bool => $user->can(PermissionRegistry::PAIEMENT_CREER));
    }

    /**
     * Enregistrer une preuve de l'exonération d'une facture (voir
     * AjouterPreuveOperationAction) ou noter le bordereau de détaxe d'un
     * ticket (voir NoterBordereauDetaxeAction) : ce qui suit une vente
     * émise, hors du document signé, à qui est autorisé à noter les
     * preuves (le propriétaire compris, même là où il ne vend pas), sur une facture
     * qu'il voit.
     */
    public function justifier(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_JUSTIFIER);
    }

    /**
     * Envoyer par e-mail ou relancer : seulement une facture que
     * l'utilisateur voit (confidentialité par vendeur, voir view()).
     */
    public function envoyer(User $user, Facture $facture): bool
    {
        return $this->view($user, $facture) && $user->can(PermissionRegistry::FACTURE_ENVOYER);
    }

    /**
     * Encaisser une facture (action « Encaisser », voir FactureResource) :
     * une facture que l'utilisateur voit (un vendeur, les siennes), avec
     * l'autorisation d'enregistrer un paiement, tant qu'elle attend un
     * règlement et qu'il en reste quelque chose à payer.
     */
    public function encaisser(User $user, Facture $facture): bool
    {
        return $this->enVenteDuStock($facture, fn (): bool => $this->view($user, $facture)
            && $user->can(PermissionRegistry::PAIEMENT_CREER)
            && $facture->statut->attendUnReglement()
            && $facture->montantRestantEnCentimes() > 0);
    }

    /**
     * Un brouillon n'a jamais reçu de numéro (attribué uniquement à
     * l'émission, voir EmettreFactureAction) : il n'a donc aucune existence
     * légale ni fiscale, le supprimer ne casse aucune continuité de
     * numérotation. Dès que la facture quitte ce statut, sa suppression
     * est refusée sans exception et sans condition de rôle, y compris pour
     * le propriétaire : la loi impose la conservation de tout document
     * numéroté. Correction d'une facture déjà émise = avoir (voir
     * EnregistrerAvoirAction), jamais suppression.
     */
    public function delete(User $user, Facture $facture): bool
    {
        return $this->enVenteDuStock($facture, fn (): bool => $this->view($user, $facture)
            && $facture->statut === StatutFacture::Brouillon
            && $user->can(PermissionRegistry::FACTURE_CREATE));
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(PermissionRegistry::FACTURE_CREATE);
    }

    /**
     * Répond à la question en tenant le magasin fermé pour ouvert quand
     * cette facture est la facture du stock vendu seul d'un magasin qui peut
     * encore la faire (voir VenteDuStockSeul::pourLaFacture()), telle
     * quelle pour toute autre facture.
     *
     * @param  callable(): bool  $question
     */
    private function enVenteDuStock(Facture $facture, callable $question): bool
    {
        return app(VenteDuStockSeul::class)->pourLaFacture($facture, $question(...));
    }

    public function forceDelete(User $user, Facture $facture): bool
    {
        return false;
    }

    public function restore(User $user, Facture $facture): bool
    {
        return false;
    }
}
