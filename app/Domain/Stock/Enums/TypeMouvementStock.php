<?php

namespace App\Domain\Stock\Enums;

/**
 * Chaque type dit à lui seul le sens du mouvement (voir estEntree()) : le
 * stock d'un produit se recalcule donc toujours depuis son grand livre,
 * sans lire la note d'aucun mouvement. L'inventaire d'ouverture fait
 * entrer, à son coût saisi, le stock existant au démarrage d'un magasin
 * (voir StockLedgerService::entrerALInventaireDOuverture()) ; la sortie de
 * cession fait sortir, au coût moyen, le stock d'un magasin cédé (voir
 * StockLedgerService::sortirPourCession()).
 */
enum TypeMouvementStock: string
{
    case Vente = 'vente';
    case ReceptionAchat = 'reception_achat';
    case InventaireOuverture = 'inventaire_ouverture';
    case RetourFournisseur = 'retour_fournisseur';
    case RetourClient = 'retour_client';
    case AjustementEntree = 'ajustement_entree';
    case AjustementSortie = 'ajustement_sortie';
    case SortieCession = 'sortie_cession';

    /**
     * true = entre en stock (+), false = sort du stock (-).
     */
    public function estEntree(): bool
    {
        return match ($this) {
            self::ReceptionAchat, self::InventaireOuverture, self::RetourClient, self::AjustementEntree => true,
            self::Vente, self::RetourFournisseur, self::AjustementSortie, self::SortieCession => false,
        };
    }

    /**
     * Ce que $quantite articles de ce mouvement changent au stock.
     */
    public function variation(int $quantite): int
    {
        return $this->estEntree() ? $quantite : -$quantite;
    }
}
