<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même présentation que la facture (voir FacturePdfGenerator), depuis les
 * lignes et les totaux enregistrés du devis, jamais depuis l'état courant
 * du catalogue, sur la même page A4 numérotée (voir MiseEnPageA4), avec
 * le même tableau des lignes (voir TableauDesLignes). Un
 * devis n'est pas signé au registre fiscal : il imprime le magasin tel
 * qu'il est aujourd'hui (voir Devis::emetteur()).
 */
class DevisPdfGenerator
{
    public function __construct(private readonly MiseEnPageA4 $miseEnPage) {}

    /**
     * @throws VenteNonAutoriseeException
     */
    public function generer(Devis $devis): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($devis));
    }

    /**
     * La page que le PDF imprime, rien avant la validation de la société
     * (voir Magasin::verifierQuIlPeutImprimer()).
     *
     * @throws VenteNonAutoriseeException
     */
    public function html(Devis $devis): string
    {
        $devis->magasin->verifierQuIlPeutImprimer();
        $devis->loadMissing(['client', 'lignes', 'magasin']);

        $emetteur = $devis->emetteur();

        return view('devis.pdf', [
            'devis' => $devis,
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pour($devis, $emetteur),
            'tableau' => TableauDesLignes::pour($devis),
        ])->render();
    }

    public function nomFichier(Devis $devis): string
    {
        $identifiant = $devis->numero ?? "brouillon-{$devis->id}";

        return "devis-{$identifiant}.pdf";
    }
}
