<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\Facture;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Les factures et les tickets à encaisser du tableau de bord, à ce jour :
 * ce qui reste dû, et la part en retard, celle des factures dont
 * l'échéance est passée dans le calendrier de leur magasin et qui doivent
 * encore quelque chose (voir Facture::estEnRetard() : réglée ou soldée par
 * un avoir, une facture n'est plus en retard nulle part). Comme
 * FacturesAEncaisserDeLaSociete, elle ne lit de chaque facture que son
 * identifiant, son magasin, son total TTC et son échéance, et de ses
 * imputations ce qu'elles ont réglé, dans les seuls magasins que le
 * périmètre permet pour les factures (voir Perimetre::exiger()) ; les
 * restants dus se calculent en centimes, jamais par une somme de la base.
 *
 * @phpstan-type Anciennete array{a_echoir: int, de_1_a_30: int, de_31_a_60: int, plus_de_60: int}
 */
final class FacturesDuTableauDeBord
{
    /**
     * Le nombre, le restant dû et le retard de ces factures à encaisser
     * (voir Facture::totauxAEncaisser()).
     *
     * @param  Collection<int, Facture>  $factures
     * @return array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}
     */
    public function totaux(Collection $factures): array
    {
        return Facture::totauxAEncaisser($factures);
    }

    /**
     * Ce qui reste dû de ces factures par ancienneté, en centimes : à
     * échoir (sans échéance, ou pas encore passée), puis en retard de 1 à
     * 30 jours, de 31 à 60 jours et de plus de 60 jours, comptés en jours
     * du calendrier du magasin de chaque facture.
     *
     * @param  Collection<int, Facture>  $factures
     * @return Anciennete
     */
    public function anciennete(Collection $factures): array
    {
        $tranches = ['a_echoir' => 0, 'de_1_a_30' => 0, 'de_31_a_60' => 0, 'plus_de_60' => 0];

        foreach ($factures as $facture) {
            $reste = $facture->resteDuEnCentimes();

            if ($reste <= 0) {
                continue;
            }

            $retard = $facture->date_echeance === null ? 0 : Calendrier::joursEntre($facture->date_echeance, $facture->magasin->calendrier()->aujourdhui());
            $tranche = match (true) {
                $retard <= 0 => 'a_echoir',
                $retard <= 30 => 'de_1_a_30',
                $retard <= 60 => 'de_31_a_60',
                default => 'plus_de_60',
            };
            $tranches[$tranche] += $reste;
        }

        return $tranches;
    }

    /**
     * Les factures à encaisser de ces magasins, chacune avec ses
     * imputations et son magasin.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return Collection<int, Facture>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function aEncaisser(Perimetre $perimetre, Collection $magasins): Collection
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Factures);

        if ($magasins->isEmpty()) {
            return new Collection;
        }

        $ids = $magasins->modelKeys();
        $parId = $magasins->keyBy(fn (Magasin $magasin): int => CleDuModele::de($magasin));
        $imputations = fn (HasMany $allocations): HasMany => $allocations
            ->withoutGlobalScope(MagasinScope::class)
            ->whereIn($allocations->qualifyColumn('magasin_id'), $ids)
            ->select(['id', 'facture_id', 'montant_alloue']);

        return Facture::withoutGlobalScope(MagasinScope::class)
            ->whereIn('factures.magasin_id', $ids)
            ->aEncaisser()
            ->with(['allocationsPaiement' => $imputations, 'allocationsAvoir' => $imputations])
            ->orderBy('id')
            ->get(['id', 'magasin_id', 'total_ttc', 'date_echeance'])
            ->each(fn (Facture $facture): Facture => $facture->setRelation('magasin', $parId[$facture->magasin_id]));
    }
}
