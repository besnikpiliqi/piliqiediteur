<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Support\ValeurRequise;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le PDF d'un reçu de paiement (R2, voir RecusDePaiement), une vraie page
 * A4 en portrait comme les autres PDF (voir MiseEnPageA4) : le magasin qui
 * encaisse en tête, le titre (« Reçu de paiement » ou « Contre-passation
 * de paiement »), sa référence et le jour de l'encaissement dans le
 * calendrier du magasin, le client tel que le premier document réglé l'a
 * figé, chaque part (date, mode, référence, montant) puis chaque crédit
 * d'avoir imputé ou rendu (le numéro de l'avoir), le total encaissé, chaque
 * document réglé avec son numéro, ce qui lui est imputé et ce qui en
 * restait dû après cet encaissement, la mention « Ce reçu n'est pas une
 * facture. », et l'identité de la société dans le pied de chaque page.
 *
 * Tout se lit dans ce que le reçu, ses paiements et leurs imputations de
 * crédit ont enregistré à sa naissance, jamais recalculé ni relu sur le
 * magasin, la société ou le client d'aujourd'hui : deux téléchargements
 * donnent le même contenu, et le document réglé n'en change jamais. Le
 * magasin de démonstration marque les siens « Démonstration — sans
 * valeur » (voir Emetteur::filigrane()). Rien ne s'imprime avant la
 * validation de la société (voir Magasin::verifierQuIlPeutImprimer()).
 */
class RecuPaiementPdfGenerator
{
    public function __construct(
        private readonly MiseEnPageA4 $miseEnPage,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @throws VenteNonAutoriseeException
     */
    public function generer(RecuPaiement $recu): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($recu));
    }

    /**
     * @throws VenteNonAutoriseeException
     */
    public function html(RecuPaiement $recu): string
    {
        $recu->magasin->verifierQuIlPeutImprimer();
        $recu->loadMissing(['magasin', 'paiements', 'lignes.facture']);
        $emetteur = $recu->emetteur();
        $date = Calendrier::afficherJour($recu->recu_le, 'd/m/Y');
        $credits = AvoirAllocation::query()
            ->with('avoir')
            ->whereIn('paiement_id', $recu->paiements->modelKeys())
            ->orderBy('id')
            ->get();
        $total = $recu->totalEnCentimes();
        $credit = $credits->sum(fn (AvoirAllocation $allocation): int => $this->calculateur->enCentiemes($allocation->montant_alloue));

        return view('recus.pdf', [
            'recu' => $recu,
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pourUnRecu($emetteur),
            'date' => $date,
            'reglements' => TableauDesReglements::surLaPage([
                ...$recu->paiements->map(fn (Paiement $paiement): array => [
                    'date' => $date,
                    'mode' => $paiement->mode_paiement->getLabel(),
                    'reference' => $paiement->reference,
                    'montant' => $this->calculateur->enCentiemes($paiement->montant),
                ])->all(),
                ...$credits->map(fn (AvoirAllocation $allocation): array => [
                    'date' => $date,
                    'mode' => 'Avoir',
                    'reference' => ValeurRequise::de($allocation->avoir, 'l\'avoir de l\'imputation')->numero,
                    'montant' => $this->calculateur->enCentiemes($allocation->montant_alloue),
                ])->all(),
            ]),
            'totaux' => array_filter([
                ['libelle' => $total < 0 ? 'Total contre-passé' : 'Total encaissé', 'montant' => $this->calculateur->enEuros($total)],
                $credit === 0 ? null : ['libelle' => $credit < 0 ? 'Crédit d\'avoir rendu' : 'Crédit d\'avoir imputé', 'montant' => $this->calculateur->enEuros($credit)],
            ]),
            'documents' => $recu->lignes->map(fn (LigneRecuPaiement $ligne): array => [
                'numero' => ValeurRequise::de($ligne->facture, 'la facture d\'une ligne du reçu')->numero,
                'libelle' => ValeurRequise::de($ligne->facture, 'la facture d\'une ligne du reçu')->libelleDocument(),
                'impute' => $this->calculateur->enEuros($this->calculateur->enCentiemes($ligne->montant) + $this->calculateur->enCentiemes($ligne->credit)),
                'reste_du' => $this->calculateur->enEuros($this->calculateur->enCentiemes($ligne->reste_du)),
            ])->all(),
        ])->render();
    }

    public function nomFichier(RecuPaiement $recu): string
    {
        return "recu-{$recu->numero}.pdf";
    }
}
