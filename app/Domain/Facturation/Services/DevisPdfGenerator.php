<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Devis;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même présentation que la facture (voir FacturePdfGenerator), depuis les
 * lignes et les totaux enregistrés du devis, jamais depuis l'état courant
 * du catalogue. Un devis n'est pas signé au registre fiscal : il imprime
 * le magasin tel qu'il est aujourd'hui (voir Devis::emetteur()).
 */
class DevisPdfGenerator
{
    public function generer(Devis $devis): PdfDocument
    {
        return Pdf::loadHTML($this->html($devis));
    }

    /**
     * La page que le PDF imprime.
     */
    public function html(Devis $devis): string
    {
        $devis->loadMissing(['client', 'lignes', 'magasin']);

        return view('devis.pdf', ['devis' => $devis, 'emetteur' => $devis->emetteur()])->render();
    }

    public function nomFichier(Devis $devis): string
    {
        $identifiant = $devis->numero ?? "brouillon-{$devis->id}";

        return "devis-{$identifiant}.pdf";
    }
}
