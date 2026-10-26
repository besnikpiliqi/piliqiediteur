<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Génère toujours depuis les données déjà figées de la facture (numéro,
 * client, lignes, totaux), depuis l'émetteur et les règlements que son
 * contenu signé a retenus (voir FigeSonEmetteur et ReglementsALEmission)
 * -- jamais depuis l'état courant du catalogue, de la fiche du client, du
 * magasin ou des paiements reçus depuis. Une facture émise aujourd'hui
 * doit produire le même document dans un an, même si les produits
 * référencés, son client ou l'identité du magasin ont changé entre-temps.
 * Un brouillon imprime le magasin tel qu'il est. Le document est une page
 * A4 numérotée (voir MiseEnPageA4), avec l'identité de la société et les
 * mentions dans le pied de chaque page (voir PiedDePage), et ses lignes
 * dans un tableau qui tient toujours dans la largeur de la page (voir
 * TableauDesLignes).
 */
class FacturePdfGenerator
{
    public function __construct(private readonly MiseEnPageA4 $miseEnPage) {}

    /**
     * @throws DocumentSansEnregistrementSigneException
     * @throws VenteNonAutoriseeException
     */
    public function generer(Facture $facture): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($facture));
    }

    /**
     * La page que le PDF imprime : l'émetteur est lu avant elle, pour qu'un
     * document émis sans enregistrement signé soit refusé avant toute
     * impression. Rien ne s'imprime avant la validation de la société
     * (voir Magasin::verifierQuIlPeutImprimer()).
     *
     * @throws DocumentSansEnregistrementSigneException
     * @throws VenteNonAutoriseeException
     */
    public function html(Facture $facture): string
    {
        $facture->magasin->verifierQuIlPeutImprimer();
        $facture->loadMissing(['client', 'lignes', 'magasin']);

        $emetteur = $facture->emetteur();

        return view('factures.pdf', [
            'facture' => $facture,
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pour($facture, $emetteur),
            'tableau' => TableauDesLignes::pour($facture),
            'reglements' => $facture->reglementsALEmission(),
        ])->render();
    }

    public function nomFichier(Facture $facture): string
    {
        $identifiant = $facture->numero ?? "brouillon-{$facture->id}";
        $prefixe = $facture->estPourUnProfessionnel() ? 'facture' : 'ticket';

        return "{$prefixe}-{$identifiant}.pdf";
    }
}
