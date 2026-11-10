<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * Le relevé des encaissements d'un magasin pour un mois, dans les jours de
 * ce mois que couvre la période du téléchargement (décision de l'éditeur du
 * 03/11/2026, E1 ; voir ArchiveDesDocuments) : une vraie page A4 en
 * portrait comme les autres PDF (voir MiseEnPageA4), le magasin en tête,
 * puis les encaissements par mode, dans l'ordre de la caisse (espèces,
 * carte bancaire, chèque, virement, puis prélèvement), chacun avec son
 * nombre de paiements et de contre-passations et son montant,
 * contre-passations déduites ; le crédit d'avoir utilisé, à part, qui ne
 * fait entrer aucun argent ; puis chaque paiement (jour, mode, référence,
 * documents qu'il règle, montant). Seulement ce que la personne voit (voir
 * DocumentsDeLaPeriode::paiementsVisibles()), chaque jour dans le
 * calendrier du magasin, chaque montant tel qu'il est enregistré,
 * additionné en centimes. Le magasin actif est celui du relevé.
 */
class ReleveDesEncaissementsPdfGenerator
{
    public function __construct(
        private readonly MiseEnPageA4 $miseEnPage,
        private readonly CalculateurLigne $calculateur,
        private readonly DocumentsDeLaPeriode $documents,
    ) {}

    /**
     * @param  string  $mois  AAAA-MM
     * @param  string  $du  le premier jour de la période, AAAA-MM-JJ
     * @param  string  $au  le dernier jour de la période, AAAA-MM-JJ
     *
     * @throws VenteNonAutoriseeException
     */
    public function generer(User $compte, Magasin $magasin, string $mois, string $du, string $au): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($compte, $magasin, $mois, $du, $au));
    }

    /**
     * @throws VenteNonAutoriseeException
     */
    public function html(User $compte, Magasin $magasin, string $mois, string $du, string $au): string
    {
        $magasin->verifierQuIlPeutImprimer();
        $calendrier = $magasin->calendrier();
        $premier = max($du, $mois.'-01');
        $dernier = min($au, DocumentsDeLaPeriode::dernierJourDuMois($mois));
        $paiements = $this->documents->paiementsVisibles($compte, $calendrier, $premier, $dernier)
            ->with(['allocations.facture:id,numero'])
            ->get();
        [$debut] = $calendrier->bornesDuJour($premier);
        [, $fin] = $calendrier->bornesDuJour($dernier);
        $credit = AvoirAllocation::query()
            ->where('created_at', '>=', $debut)
            ->where('created_at', '<', $fin)
            ->whereIn('facture_id', Facture::query()->visiblesPar($compte)->select('id'))
            ->pluck('montant_alloue')
            ->sum(fn (mixed $montant): int => $this->calculateur->enCentiemes($montant));
        $emetteur = Emetteur::duMagasin($magasin);
        $euros = fn (int $centimes): string => $this->calculateur->enEuros($centimes);

        return view('encaissements.pdf', [
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pourUnRecu($emetteur),
            'titre' => 'Relevé des encaissements',
            'details' => [
                ucfirst(Calendrier::nomDuMois((int) substr($mois, 5, 2))).' '.substr($mois, 0, 4),
                'Du '.Calendrier::afficherJour($premier, 'd/m/Y').' au '.Calendrier::afficherJour($dernier, 'd/m/Y'),
            ],
            'modes' => $this->parMode($paiements, $euros),
            'total' => $euros($this->total($paiements)),
            'credit' => $euros((int) $credit),
            'paiements' => $paiements->map(fn (Paiement $paiement): array => [
                'jour' => $calendrier->afficher(ValeurRequise::de($paiement->created_at, 'la date d\'enregistrement du paiement'), 'd/m/Y'),
                'mode' => $paiement->mode_paiement->getLabel().($paiement->estUneContrePassation() ? ' (contre-passation)' : ''),
                'reference' => $paiement->reference,
                'documents' => $paiement->allocations->map(fn (PaiementAllocation $allocation): ?string => $allocation->facture?->numero)->filter()->implode(', '),
                'montant' => $euros($this->calculateur->enCentiemes($paiement->montant)),
            ])->all(),
        ])->render();
    }

    public function nomFichier(Magasin $magasin, string $mois): string
    {
        return "encaissements-{$magasin->code}-{$mois}.pdf";
    }

    /**
     * Les encaissements de chaque mode qui en a, dans l'ordre de la caisse.
     *
     * @param  Collection<int, Paiement>  $paiements
     * @param  callable(int): string  $euros
     * @return list<array{libelle: string, paiements: int, contre_passations: int, montant: string}>
     */
    private function parMode(Collection $paiements, callable $euros): array
    {
        $modes = [];

        foreach ([...ModePaiement::aLaCaisse(), ModePaiement::Prelevement] as $mode) {
            $duMode = $paiements->filter(fn (Paiement $paiement): bool => $paiement->mode_paiement === $mode);

            if ($duMode->isNotEmpty()) {
                $modes[] = [
                    'libelle' => $mode->getLabel(),
                    'paiements' => $duMode->reject(fn (Paiement $paiement): bool => $paiement->estUneContrePassation())->count(),
                    'contre_passations' => $duMode->filter(fn (Paiement $paiement): bool => $paiement->estUneContrePassation())->count(),
                    'montant' => $euros($this->total($duMode)),
                ];
            }
        }

        return $modes;
    }

    /**
     * @param  Collection<int, Paiement>  $paiements
     */
    private function total(Collection $paiements): int
    {
        return (int) $paiements->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant));
    }
}
