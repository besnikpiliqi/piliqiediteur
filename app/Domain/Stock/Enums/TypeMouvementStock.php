<?php

namespace App\Domain\Stock\Enums;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Model;

/**
 * Chaque type dit à lui seul le sens du mouvement (voir estEntree()) : le
 * stock d'un produit se recalcule donc toujours depuis son grand livre,
 * sans lire la note d'aucun mouvement. L'inventaire d'ouverture fait
 * entrer, à son coût saisi, le stock existant au démarrage d'un magasin
 * (voir StockLedgerService::entrerALInventaireDOuverture()) ; la sortie de
 * cession fait sortir, au coût moyen, le stock d'un magasin cédé (voir
 * StockLedgerService::sortirPourCession()), que l'annulation de la cession
 * par l'éditeur fait rentrer au même coût (voir
 * StockLedgerService::rentrerApresAnnulationDeCession()). Chaque mouvement
 * est justifié par un document de son magasin, que son type désigne (voir
 * justificatif()).
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
    case EntreeAnnulationCession = 'entree_annulation_cession';

    /**
     * true = entre en stock (+), false = sort du stock (-).
     */
    public function estEntree(): bool
    {
        return match ($this) {
            self::ReceptionAchat, self::InventaireOuverture, self::RetourClient, self::AjustementEntree, self::EntreeAnnulationCession => true,
            self::Vente, self::RetourFournisseur, self::AjustementSortie, self::SortieCession => false,
        };
    }

    /**
     * Le mouvement garde-t-il son coût unitaire HT ? Celui d'un inventaire
     * d'ouverture (son coût saisi), d'un retour au fournisseur (celui de sa
     * ligne d'achat), d'une sortie de cession et de sa rentrée (le coût
     * moyen) ; les autres passent au coût moyen du moment, sans le garder.
     * La base l'exige (voir GardesDuStock).
     */
    public function porteSonCout(): bool
    {
        return match ($this) {
            self::InventaireOuverture, self::RetourFournisseur, self::SortieCession, self::EntreeAnnulationCession => true,
            self::Vente, self::ReceptionAchat, self::RetourClient, self::AjustementEntree, self::AjustementSortie => false,
        };
    }

    /**
     * Ce que $quantite articles de ce mouvement changent au stock.
     */
    public function variation(int $quantite): int
    {
        return $this->estEntree() ? $quantite : -$quantite;
    }

    /**
     * La sorte de document de son magasin qui justifie un mouvement de ce
     * type, sa source (voir StockLedgerService et GardesDuStock) : la
     * facture émise pour une vente, la ligne de l'avoir émis pour un retour
     * client, la facture d'achat validée pour une réception et pour
     * l'ajustement qui la corrige, l'inventaire d'ouverture validé, l'avoir
     * fournisseur émis pour un retour au fournisseur, le magasin cédé pour
     * la sortie de cession et sa rentrée. Aucun mouvement ne se passe de
     * document.
     *
     * @return class-string<Model>
     */
    public function justificatif(): string
    {
        return match ($this) {
            self::Vente => Facture::class,
            self::RetourClient => LigneAvoir::class,
            self::ReceptionAchat, self::AjustementEntree, self::AjustementSortie => FactureAchat::class,
            self::InventaireOuverture => InventaireOuverture::class,
            self::RetourFournisseur => AvoirFournisseur::class,
            self::SortieCession, self::EntreeAnnulationCession => Magasin::class,
        };
    }

    /**
     * Le nom stable (Relation::morphMap()) de ce document, tel que le grand
     * livre l'enregistre dans source_type.
     */
    public function aliasDuJustificatif(): string
    {
        $justificatif = $this->justificatif();

        return (new $justificatif)->getMorphClass();
    }

    /**
     * Un ajustement, qui corrige une réception d'achat validée.
     */
    public function estUnAjustement(): bool
    {
        return $this === self::AjustementEntree || $this === self::AjustementSortie;
    }
}
