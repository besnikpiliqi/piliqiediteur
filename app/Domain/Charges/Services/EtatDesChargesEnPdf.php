<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Support\ContenuDeLEtat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\MiseEnPageA4;

/**
 * Le PDF de l'état des charges (modèle E1 choisi par l'éditeur, voir
 * EtatDesCharges) : une vraie page A4 à l'horizontale, jamais réduite,
 * mise en pages et numérotée « Page x sur y » par le même moteur que les
 * autres PDF (voir MiseEnPageA4), avec la même police (DejaVu Sans,
 * embarquée dans dompdf, qui porte les symboles des états), le pied « PILIQI —
 * état des charges, document de travail » en bas de chaque page. Trois
 * parties : le tableau « type de charge × mois », chaque case avec la
 * pastille colorée de son état (le symbole dit l'état, même imprimé en
 * noir et blanc), le nombre de ses charges quand il y en a plusieurs et
 * leur TTC dessous (« — » pour des pièces sans chiffres), le total TTC de
 * chaque type et de chaque mois, et la légende ; la liste des charges de
 * la vue, sur autant de pages qu'il faut, ses titres répétés en haut de
 * chaque page et aucune ligne coupée ; la TVA de chaque mois telle que la
 * page la compte, avec ses phrases grises.
 */
final class EtatDesChargesEnPdf
{
    public function __construct(
        private readonly MiseEnPageA4 $miseEnPage,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Le contenu du fichier PDF.
     */
    public function generer(ContenuDeLEtat $contenu): string
    {
        return $this->miseEnPage->pdfALHorizontale($this->html($contenu))->output();
    }

    public function html(ContenuDeLEtat $contenu): string
    {
        return view('charges.etat-pdf', [
            'contenu' => $contenu,
            'euros' => fn (int $centimes): string => $this->calculateur->enEuros($centimes),
        ])->render();
    }
}
