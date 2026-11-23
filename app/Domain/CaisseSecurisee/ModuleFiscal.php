<?php

namespace App\Domain\CaisseSecurisee;

/**
 * Le module fiscal de la caisse sécurisée (art. 286 I-3° bis du CGI) :
 * signature, chaînage, journal des événements, clôtures et archives des
 * données de caisse.
 *
 * Sa version, « majeure.mineure », est une constante du code : aucun
 * réglage, aucun magasin ne peut la changer. Toute modification du module
 * fiscal en change la version : la mineure pour une modification sans
 * incidence sur les conditions d'inaltérabilité, de sécurisation, de
 * conservation et d'archivage, la majeure sinon. Une version majeure ne se
 * note jamais comme une mineure.
 *
 * La 3.0 (lot DEMO-COPIE, D.7) : le site de démonstration, mode
 * d'entraînement de la caisse, marque « demonstration » l'en-tête de chaque
 * enregistrement de ses copies et l'émetteur figé de leurs documents, et
 * le vérificateur du site réel signale un enregistrement, un émetteur
 * figé, un JET 100 ou un JET 105 de démonstration dans une base réelle.
 * Le format du contenu signé du site réel ne change pas.
 *
 * La 3.1 (lot IDENT, décision P14.7) : l'émetteur figé d'un document
 * (ticket, facture, avoir, devis, reçu de paiement) porte, quand la société
 * en déclare au moins une, le bloc « mentions_facultatives » (liquidation,
 * location-gérance, EIRL et son objet, assujetti unique). Version mineure :
 * le bloc s'ajoute à l'émetteur sans toucher à l'inaltérabilité, et celui
 * d'une société qui n'en déclare aucune garde sa forme.
 *
 * La référence légale qu'affiche l'écran de la caisse sécurisée est elle
 * aussi une constante du code : sa mise à jour (renumérotation du code
 * général des impôts) passe par une nouvelle version mineure.
 */
final class ModuleFiscal
{
    public const VERSION = '3.1';

    public const REFERENCE_LEGALE = 'Article 286, I, 3° bis du code général des impôts';
}
