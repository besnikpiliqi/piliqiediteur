<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Avoir;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même principe que FacturePdfGenerator : toujours depuis les données
 * déjà figées de l'avoir (client, lignes, totaux), jamais depuis l'état
 * courant du catalogue, de la fiche du client ou de la facture créditée.
 */
class AvoirPdfGenerator
{
    public function generer(Avoir $avoir): PdfDocument
    {
        $avoir->loadMissing(['client', 'lignes.ligneFacture.facture', 'magasin']);

        return Pdf::loadView('avoirs.pdf', ['avoir' => $avoir]);
    }

    public function nomFichier(Avoir $avoir): string
    {
        $identifiant = $avoir->numero ?? "brouillon-{$avoir->id}";

        return "avoir-{$identifiant}.pdf";
    }
}
