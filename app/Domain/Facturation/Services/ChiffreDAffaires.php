<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Tva\Services\CalculateurLigne;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le chiffre d'affaires d'une période, net des avoirs (B17) : les ventes
 * émises sur la période (tickets et factures, quel que soit leur règlement)
 * moins les avoirs émis sur la même période, quelle que soit la date des
 * ventes qu'ils corrigent -- un retour du 2 novembre sur une vente
 * d'octobre se déduit en novembre, comme dans la clôture de la journée
 * (voir CloturerCaisseAction). Les HT, les TVA et les TTC se cumulent
 * séparément, au centime, tels qu'enregistrés sur les documents (voir
 * CalculateurLigne::cumuler() et deduire()).
 *
 * Pour un vendeur (B16, « Mes ventes ») : ses ventes, moins les lignes
 * d'avoir qui les corrigent, quel que soit l'auteur de l'avoir -- un avoir
 * du propriétaire sur la vente d'un vendeur réduit les ventes de ce
 * vendeur, jamais celles du propriétaire.
 *
 * Lu sous MagasinScope : seulement le magasin actif. Sans dépendance à
 * l'interface, il servira aussi aux rapports.
 */
class ChiffreDAffaires
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Le net de $debut à $fin, bornes comprises, avec le nombre de ventes
     * et celui des avoirs déduits.
     *
     * @return array{ht: float, tva: float, ttc: float, factures: int, avoirs: int}
     */
    public function netSurPeriode(CarbonInterface $debut, CarbonInterface $fin, ?User $vendeur = null): array
    {
        $factures = Facture::query()
            ->emises()
            ->emisEntre($debut, $fin)
            ->when($vendeur !== null, fn (Builder $query) => $query->where('cree_par', $vendeur->getKey()))
            ->get(['total_ht', 'total_tva', 'total_ttc']);

        [$totauxDesAvoirs, $nombreDAvoirs] = $vendeur === null
            ? $this->avoirsDuMagasin($debut, $fin)
            : $this->avoirsSurLesVentesDe($vendeur, $debut, $fin);

        $net = $this->calculateur->deduire($this->calculateur->cumuler($factures), $totauxDesAvoirs);

        return [
            'ht' => $net['total_ht'],
            'tva' => $net['total_tva'],
            'ttc' => $net['total_ttc'],
            'factures' => $factures->count(),
            'avoirs' => $nombreDAvoirs,
        ];
    }

    /**
     * @return array{0: array{total_ht: float, total_tva: float, total_ttc: float}, 1: int}
     */
    private function avoirsDuMagasin(CarbonInterface $debut, CarbonInterface $fin): array
    {
        $avoirs = $this->avoirsEmis(Avoir::query(), $debut, $fin)->get(['total_ht', 'total_tva', 'total_ttc']);

        return [$this->calculateur->cumuler($avoirs), $avoirs->count()];
    }

    /**
     * Les lignes d'avoir émises sur la période qui corrigent des ventes du
     * vendeur, et le nombre d'avoirs qui les portent.
     *
     * @return array{0: array{total_ht: float, total_tva: float, total_ttc: float}, 1: int}
     */
    private function avoirsSurLesVentesDe(User $vendeur, CarbonInterface $debut, CarbonInterface $fin): array
    {
        $lignes = LigneAvoir::query()
            ->whereHas('avoir', fn (Builder $avoirs) => $this->avoirsEmis($avoirs, $debut, $fin))
            ->whereHas('ligneFacture.facture', fn (Builder $factures) => $factures->where('cree_par', $vendeur->getKey()))
            ->get(['avoir_id', 'montant_ht', 'montant_tva', 'montant_ttc']);

        return [$this->calculateur->totaliser($lignes), $lignes->unique('avoir_id')->count()];
    }

    /**
     * @param  Builder<Avoir>  $avoirs
     * @return Builder<Avoir>
     */
    private function avoirsEmis(Builder $avoirs, CarbonInterface $debut, CarbonInterface $fin): Builder
    {
        return $avoirs->where('statut', StatutAvoir::Emise)->emisEntre($debut, $fin);
    }
}
