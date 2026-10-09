<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Devis;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même présentation que la facture (voir FacturePdfGenerator), depuis les
 * lignes et les totaux enregistrés du devis, jamais depuis l'état courant
 * du catalogue.
 */
class DevisPdfGenerator
{
    public function generer(Devis $devis): PdfDocument
    {
        $devis->loadMissing(['client', 'lignes', 'magasin']);

        return Pdf::loadView('devis.pdf', ['devis' => $devis]);
    }

    public function nomFichier(Devis $devis): string
    {
        $identifiant = $devis->numero ?? "brouillon-{$devis->id}";

        return "devis-{$identifiant}.pdf";
    }
}
