<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Models\Avoir;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même principe que FacturePdfGenerator : toujours depuis les données
 * déjà figées de l'avoir (client, lignes, totaux) et l'émetteur que son
 * contenu signé a retenu (voir FigeSonEmetteur), jamais depuis l'état
 * courant du catalogue, de la fiche du client, du magasin ou de la
 * facture créditée.
 */
class AvoirPdfGenerator
{
    /**
     * @throws DocumentSansEnregistrementSigneException
     */
    public function generer(Avoir $avoir): PdfDocument
    {
        return Pdf::loadHTML($this->html($avoir));
    }

    /**
     * La page que le PDF imprime, comme FacturePdfGenerator::html().
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function html(Avoir $avoir): string
    {
        $avoir->loadMissing(['client', 'lignes.ligneFacture.facture', 'magasin']);

        return view('avoirs.pdf', ['avoir' => $avoir, 'emetteur' => $avoir->emetteur()])->render();
    }

    public function nomFichier(Avoir $avoir): string
    {
        $identifiant = $avoir->numero ?? "brouillon-{$avoir->id}";

        return "avoir-{$identifiant}.pdf";
    }
}
