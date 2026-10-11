<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Génère toujours depuis les données déjà figées de la facture (numéro,
 * client, lignes, totaux) et depuis l'émetteur que son contenu signé a
 * retenu (voir FigeSonEmetteur) -- jamais depuis l'état courant du
 * catalogue, de la fiche du client ou du magasin. Une facture émise
 * aujourd'hui doit produire le même document dans un an, même si les
 * produits référencés, son client ou l'identité du magasin ont changé
 * entre-temps. Un brouillon imprime le magasin tel qu'il est.
 */
class FacturePdfGenerator
{
    /**
     * @throws DocumentSansEnregistrementSigneException
     * @throws VenteNonAutoriseeException
     */
    public function generer(Facture $facture): PdfDocument
    {
        return Pdf::loadHTML($this->html($facture));
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
        $facture->loadMissing(['client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir']);

        return view('factures.pdf', ['facture' => $facture, 'emetteur' => $facture->emetteur()])->render();
    }

    public function nomFichier(Facture $facture): string
    {
        $identifiant = $facture->numero ?? "brouillon-{$facture->id}";
        $prefixe = $facture->estPourUnProfessionnel() ? 'facture' : 'ticket';

        return "{$prefixe}-{$identifiant}.pdf";
    }
}
