<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Génère toujours depuis les données déjà figées de la facture (numéro,
 * lignes snapshot, totaux) -- jamais depuis l'état courant du catalogue.
 * Une facture émise aujourd'hui doit produire EXACTEMENT le même PDF dans
 * un an, même si les produits référencés ont changé entre-temps.
 */
class FacturePdfGenerator
{
    public function generer(Facture $facture): PdfDocument
    {
        $facture->loadMissing(['client', 'lignes', 'magasin']);

        return Pdf::loadView('factures.pdf', ['facture' => $facture]);
    }

    public function nomFichier(Facture $facture): string
    {
        $identifiant = $facture->numero ?? "brouillon-{$facture->id}";

        return "facture-{$identifiant}.pdf";
    }
}
