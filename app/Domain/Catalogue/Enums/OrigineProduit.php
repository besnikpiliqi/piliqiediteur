<?php

namespace App\Domain\Catalogue\Enums;

/**
 * Les deux seules validations dont un produit peut naître (voir
 * OrigineDesProduits) : celle d'une facture d'achat, qui crée les
 * nouveaux produits de ses lignes (voir
 * ConstructeurLigneAchat::creerLesNouveauxProduits()), et celle d'un
 * inventaire d'ouverture, qui crée ceux du stock existant au démarrage du
 * magasin (voir ValiderInventaireOuvertureAction).
 */
enum OrigineProduit: string
{
    case Achat = 'achat';
    case InventaireOuverture = 'inventaire_ouverture';
}
