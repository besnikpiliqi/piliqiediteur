<?php

namespace App\Domain\TableauDeBord\Enums;

use App\Domain\Authorization\PermissionRegistry;
use Filament\Support\Contracts\HasLabel;

/**
 * Les documents que compte un graphique ou un chiffre clé du tableau de
 * bord, et les autorisations qui les laissent tous voir dans un magasin
 * (CLAUDE.md, « Tableau de bord ») : un graphique ne compte un magasin que
 * si la personne y voit tous les documents de chacune de ses sources (voir
 * Perimetre::magasinsDe()) ; jamais les seules ventes qu'elle a faites.
 *
 * - les ventes : les factures et les tickets de toute l'équipe, et les
 *   avoirs qui les corrigent ;
 * - les achats : les factures d'achat et les avoirs fournisseur, dans une
 *   société qui a des articles ;
 * - les paiements : les encaissements de toute l'équipe ;
 * - le stock : les produits et la valeur de leur stock, dans une société
 *   qui a des articles ;
 * - les clients : leurs fiches, pour nommer les meilleurs ;
 * - les avoirs : ceux de toute l'équipe ;
 * - les factures : celles de toute l'équipe, pour ce qui reste à encaisser.
 *
 * Pour un cabinet lié ou l'expert-comptable déclaré, ces autorisations sont
 * celles que le client leur accorde, « voir les factures et les clôtures »
 * y étant une seule case (voir PermissionRegistry::PAIRES_DU_CABINET).
 */
enum SourceDesGraphiques: string implements HasLabel
{
    case Ventes = 'ventes';
    case Achats = 'achats';
    case Paiements = 'paiements';
    case Stock = 'stock';
    case Clients = 'clients';
    case Avoirs = 'avoirs';
    case Factures = 'factures';

    /**
     * Ce que la phrase grise dit qu'il manque dans un magasin qui n'est pas
     * compté : « vous n'y voyez pas toutes les ventes ».
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Ventes => 'toutes les ventes',
            self::Achats => 'les achats',
            self::Paiements => 'les paiements de toute l\'équipe',
            self::Stock => 'le stock',
            self::Clients => 'les fiches des clients',
            self::Avoirs => 'tous les avoirs',
            self::Factures => 'toutes les factures',
        };
    }

    /**
     * Les autorisations de magasin qui laissent voir tous ses documents.
     *
     * @return list<string>
     */
    public function autorisations(): array
    {
        return match ($this) {
            self::Ventes => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::AVOIR_VIEW],
            self::Achats => [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW],
            self::Paiements => [PermissionRegistry::PAIEMENT_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES],
            self::Stock => [PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK],
            self::Clients => [PermissionRegistry::CLIENT_VIEW],
            self::Avoirs => [PermissionRegistry::AVOIR_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES],
            self::Factures => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES],
        };
    }

    /**
     * Ces autorisations, dans un magasin où la personne a celles-ci,
     * laissent-elles voir tous les documents de la source ?
     *
     * @param  list<string>  $autorisations
     */
    public function estVueAvec(array $autorisations): bool
    {
        return array_diff($this->autorisations(), $autorisations) === [];
    }

    /**
     * Une source qui n'existe que dans une société qui a des articles.
     */
    public function exigeDesArticles(): bool
    {
        return $this === self::Achats || $this === self::Stock;
    }
}
