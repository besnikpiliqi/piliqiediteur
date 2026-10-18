<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même principe que FacturePdfGenerator : toujours depuis les données
 * déjà figées de l'avoir (client, lignes, totaux), l'émetteur et la
 * mention du bon d'avoir que son contenu signé a retenus (voir
 * FigeSonEmetteur et Avoir::mentionDuBonDAvoir()), jamais depuis l'état
 * courant du catalogue, de la fiche du client, du magasin, de la facture
 * créditée ou du crédit qui reste à l'avoir ; même page A4 (voir
 * MiseEnPageA4), même pied (voir PiedDePage).
 */
class AvoirPdfGenerator
{
    public function __construct(private readonly MiseEnPageA4 $miseEnPage) {}

    /**
     * @throws DocumentSansEnregistrementSigneException
     * @throws VenteNonAutoriseeException
     */
    public function generer(Avoir $avoir): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($avoir));
    }

    /**
     * La page que le PDF imprime, comme FacturePdfGenerator::html().
     *
     * @throws DocumentSansEnregistrementSigneException
     * @throws VenteNonAutoriseeException
     */
    public function html(Avoir $avoir): string
    {
        $avoir->magasin->verifierQuIlPeutImprimer();
        $avoir->loadMissing(['client', 'lignes.ligneFacture.facture', 'magasin']);

        $emetteur = $avoir->emetteur();

        return view('avoirs.pdf', ['avoir' => $avoir, 'emetteur' => $emetteur, 'pied' => PiedDePage::pour($avoir, $emetteur)])->render();
    }

    public function nomFichier(Avoir $avoir): string
    {
        $identifiant = $avoir->numero ?? "brouillon-{$avoir->id}";

        return "avoir-{$identifiant}.pdf";
    }
}
