<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Les factures à encaisser de chaque magasin, au tableau de bord de
 * l'espace d'administration de la société (voir TableauDeBord) : une
 * lecture hors du magasin courant, déclarée dans CLAUDE.md, la seule de ce
 * tableau de bord.
 *
 * Elle ne lit que les magasins réels de la portée de la personne (voir
 * User::magasinsDeSaPortee() : toute sa société pour le propriétaire, la
 * sélection d'un administrateur), jamais le magasin de démonstration ni
 * un magasin d'une autre société, et seulement ceux dont elle suit les
 * factures à encaisser, comme l'alerte du tableau de bord du magasin (voir
 * User::suitLesFacturesAEncaisserDans()) : ceux où elle voit les factures
 * et celles de toute l'équipe (toujours pour le propriétaire, les cases
 * cochées pour un administrateur), et dont elle peut donc ouvrir la liste
 * où mène chaque ligne. De chacun, elle ne rend que trois totaux : le nombre
 * de ses factures à encaisser (voir Facture::scopeAEncaisser()), leur
 * restant dû, et ceux en retard ; aucun nom de client, aucun numéro,
 * aucune facture prise à part. Elle ne lit pour cela, de chaque facture,
 * que son identifiant, son magasin, son total TTC et son échéance, et de
 * chacune de ses imputations que ce qu'elle a réglé : les restants dus se
 * calculent ici, en centimes, jamais par une somme que la base ferait sur
 * une colonne décimale.
 */
final class FacturesAEncaisserDeLaSociete
{
    /**
     * Les totaux de chaque magasin dont cette personne voit les factures à
     * encaisser, par identifiant de magasin, dans l'ordre des magasins ; un
     * magasin sans facture à encaisser a ses totaux à zéro.
     *
     * @return array<int, array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}>
     */
    public function pour(User $compte): array
    {
        $magasins = $compte->magasinsDeSaPortee()
            ->where('est_demonstration', false)
            ->orderBy('id')
            ->get()
            ->filter(fn (Magasin $magasin): bool => $compte->suitLesFacturesAEncaisserDans($magasin))
            ->values()
            ->modelKeys();

        if ($magasins === []) {
            return [];
        }

        $imputations = fn (HasMany $allocations): HasMany => $allocations
            ->withoutGlobalScope(MagasinScope::class)
            ->whereIn($allocations->qualifyColumn('magasin_id'), $magasins)
            ->select(['id', 'facture_id', 'montant_alloue']);

        $factures = Facture::withoutGlobalScope(MagasinScope::class)
            ->whereIn('factures.magasin_id', $magasins)
            ->aEncaisser()
            ->with(['allocationsPaiement' => $imputations, 'allocationsAvoir' => $imputations])
            ->get(['id', 'magasin_id', 'total_ttc', 'date_echeance']);

        return collect($magasins)
            ->mapWithKeys(fn (int $magasin): array => [$magasin => Facture::totauxAEncaisser($factures->where('magasin_id', $magasin))])
            ->all();
    }
}
