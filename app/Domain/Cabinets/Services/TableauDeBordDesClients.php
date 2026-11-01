<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le tableau « Vos clients » du tableau de bord d'un cabinet d'expertise
 * comptable (voir TableauDeBordDuCabinet) : une lecture d'autres sociétés
 * que CLAUDE.md permet, celle des clients liés que le cabinet ouvre au
 * compte (voir AccesDesCabinets::liensOuverts() : aucun tant que le cabinet
 * n'est pas vérifié ; pour un collaborateur, ses seuls clients confiés).
 * De chaque client, son identité réduite (dénomination, SIREN, ville du
 * siège, mois de fin d'exercice, statut), le nombre de ses magasins réels
 * et la date du lien ; et ses factures à encaisser (nombre, restant dû,
 * dont en retard, en centimes), sommées sur ses magasins réels dont le
 * compte suit les factures à encaisser (voir
 * User::suitLesFacturesAEncaisserDans() : le client accorde au cabinet les
 * factures et celles de toute l'équipe), null quand il n'en suit aucun ;
 * aucun nom de client final, aucun numéro de document. Chaque requête
 * filtre explicitement par les clients, les magasins ou les liens, et ne
 * lit que les colonnes nommées ; les restants dus se calculent en
 * centimes, jamais par une somme de la base (voir
 * Facture::totauxAEncaisser()).
 */
final class TableauDeBordDesClients
{
    public function __construct(private readonly AccesDesCabinets $acces) {}

    /**
     * @return list<array{lien: string, client: Societe, actif_depuis: mixed, magasins: int, factures: array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}|null}>
     */
    public function pour(User $compte): array
    {
        $liens = $this->acces->liensOuverts($compte)->orderBy('liens_cabinet.id')->get(['liens_cabinet.id', 'liens_cabinet.uuid', 'liens_cabinet.societe_id', 'liens_cabinet.actif_depuis']);

        if ($liens->isEmpty()) {
            return [];
        }

        $clients = Societe::query()->whereKey($liens->pluck('societe_id')->all())
            ->get(['id', 'uuid', 'siren', 'raison_sociale', 'siege_ville', 'mois_fin_exercice', 'statut', 'validee_le', 'close_le'])
            ->keyBy('id');
        $magasins = Magasin::query()->whereIn('societe_id', $clients->keys()->all())->where('est_demonstration', false)->orderBy('id')
            ->get(['id', 'societe_id', 'statut', 'territoire', 'est_demonstration']);
        $suivis = $magasins->filter(fn (Magasin $magasin): bool => $compte->suitLesFacturesAEncaisserDans($magasin))->keyBy('id');
        $factures = $this->facturesAEncaisser($suivis->keys()->all())
            ->each(fn (Facture $facture): Facture => $facture->setRelation('magasin', $suivis[$facture->magasin_id]));

        return $liens
            ->sortBy(fn (LienCabinet $lien): string => mb_strtolower((string) $clients[$lien->societe_id]->raison_sociale))
            ->map(function (LienCabinet $lien) use ($clients, $magasins, $suivis, $factures): array {
                $siens = $suivis->where('societe_id', $lien->societe_id)->keys()->all();

                return [
                    'lien' => (string) $lien->uuid,
                    'client' => $clients[$lien->societe_id],
                    'actif_depuis' => $lien->actif_depuis,
                    'magasins' => $magasins->where('societe_id', $lien->societe_id)->count(),
                    'factures' => $siens === [] ? null : Facture::totauxAEncaisser($factures->whereIn('magasin_id', $siens)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Les factures à encaisser de ces magasins, avec ce que chacune de
     * leurs imputations a réglé.
     *
     * @param  list<int>  $magasins
     * @return Collection<int, Facture>
     */
    private function facturesAEncaisser(array $magasins): Collection
    {
        if ($magasins === []) {
            return new Collection;
        }

        $imputations = fn (HasMany $allocations): HasMany => $allocations
            ->withoutGlobalScope(MagasinScope::class)
            ->whereIn($allocations->qualifyColumn('magasin_id'), $magasins)
            ->select(['id', 'facture_id', 'montant_alloue']);

        return Facture::withoutGlobalScope(MagasinScope::class)
            ->whereIn('factures.magasin_id', $magasins)
            ->aEncaisser()
            ->with(['allocationsPaiement' => $imputations, 'allocationsAvoir' => $imputations])
            ->get(['id', 'magasin_id', 'total_ttc', 'date_echeance']);
    }
}
