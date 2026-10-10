<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Génère toujours depuis les données déjà figées de la facture (numéro,
 * client, lignes, totaux) -- jamais depuis l'état courant du catalogue ou
 * de la fiche du client. Une facture émise aujourd'hui doit produire le
 * même document dans un an, même si les produits référencés ou son client
 * ont changé entre-temps.
 */
class FacturePdfGenerator
{
    public function generer(Facture $facture): PdfDocument
    {
        $facture->loadMissing(['client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir']);

        return Pdf::loadView('factures.pdf', ['facture' => $facture]);
    }

    public function nomFichier(Facture $facture): string
    {
        $identifiant = $facture->numero ?? "brouillon-{$facture->id}";
        $prefixe = $facture->estPourUnProfessionnel() ? 'facture' : 'ticket';

        return "{$prefixe}-{$identifiant}.pdf";
    }
}
