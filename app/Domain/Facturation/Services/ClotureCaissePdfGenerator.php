<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le PDF d'une clôture du jour, son ticket Z, que le téléchargement des
 * documents par période range dans son dossier (voir ArchiveDesDocuments) :
 * une vraie page A4 en portrait comme les autres PDF (voir MiseEnPageA4),
 * le magasin en tête, son numéro, sa journée, l'instant de la clôture et
 * qui l'a déclenchée, puis son récapitulatif tel qu'il a été écrit à la
 * clôture, jamais recalculé (voir ClotureCaisse::rubriques()) : ventes et
 * avoirs aux particuliers avec le détail de chaque taux, leur net (base de
 * l'e-reporting), factures et avoirs aux professionnels pour information,
 * encaissements par mode, crédit d'avoir utilisé à part et grands totaux.
 * L'identité de la société est dans le pied de chaque page ; la
 * démonstration marque les siens (voir Emetteur::filigrane()).
 */
class ClotureCaissePdfGenerator
{
    public function __construct(
        private readonly MiseEnPageA4 $miseEnPage,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @throws VenteNonAutoriseeException
     */
    public function generer(ClotureCaisse $cloture): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($cloture));
    }

    /**
     * @throws VenteNonAutoriseeException
     */
    public function html(ClotureCaisse $cloture): string
    {
        $magasin = $cloture->magasin;
        $magasin->verifierQuIlPeutImprimer();
        $emetteur = Emetteur::duMagasin($magasin);
        $euros = fn (int $centimes): string => $this->calculateur->enEuros($centimes);

        return view('clotures.pdf', [
            'cloture' => $cloture,
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pourUnRecu($emetteur),
            'details' => array_values(array_filter([
                $cloture->numero,
                'Journée du '.Calendrier::afficherJour($cloture->date_cloture, 'd/m/Y'),
                $cloture->cloturee_a === null ? null : 'Clôturée le '.$magasin->calendrier()->afficher($cloture->cloturee_a, 'd/m/Y à H:i'),
                'Déclenchée par '.($cloture->cree_par_nom ?? 'la clôture automatique, à minuit'),
            ])),
            'rubriques' => array_map(fn (array $rubrique): array => [
                'rubrique' => $rubrique['rubrique'],
                'nombre' => $rubrique['nombre'],
                'ht' => $euros($rubrique['ht']),
                'tva' => $euros($rubrique['tva']),
                'ttc' => $euros($rubrique['ttc']),
                'detail' => $rubrique['detail'],
            ], $cloture->rubriques()),
            'encaissements' => array_map(fn (array $mode): array => [...$mode, 'montant' => $euros($mode['montant'])], $cloture->recapitulatif['encaissements']),
            'totalEncaisse' => $euros($cloture->totalEncaisseEnCentimes()),
            'creditAvoir' => $euros($cloture->recapitulatif['credit_avoir_impute']),
            'grandTotalReel' => $euros($cloture->recapitulatif['grands_totaux']['perpetuel_reel']),
            'grandTotalAbsolu' => $euros($cloture->recapitulatif['grands_totaux']['perpetuel_absolu']),
        ])->render();
    }

    public function nomFichier(ClotureCaisse $cloture): string
    {
        return "{$cloture->numero}.pdf";
    }
}
