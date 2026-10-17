<?php

namespace App\Filament\App\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'alerte des factures à encaisser, en tête du tableau de bord du
 * magasin, juste après la bienvenue : les factures et les tickets émis
 * dont il reste quelque chose à régler (voir Facture::scopeAEncaisser()),
 * y compris ceux qu'une contre-passation a rouverts, que rien d'autre ne
 * signale. Elle dit leur nombre et leur restant dû, dont ceux en retard,
 * en rouge, puis les dix plus anciens par échéance, chacun avec son
 * client, son restant dû et, s'il a reçu une contre-passation, le badge
 * « Paiement contre-passé », et mène à la liste filtrée « À encaisser »
 * (voir FactureResource). Les restants dus se calculent en centimes,
 * depuis les imputations chargées avec les factures, jamais par une somme
 * que la base ferait sur une colonne décimale.
 *
 * Qui la voit (voir canView()), parmi les factures qu'il voit (voir
 * Facture::scopeVisiblesPar()) :
 * - le propriétaire, un responsable du magasin et un expert-comptable,
 *   qui voient toutes les factures du magasin ;
 * - un administrateur, seulement s'il voit celles de toute l'équipe ;
 * - un vendeur ou un observateur, seulement s'il est autorisé à
 *   contre-passer un paiement : les siennes, ou toutes avec « voir celles
 *   de toute l'équipe ».
 * Elle lit les cases cochées dans ce magasin, pas le droit d'agir du
 * moment : dans un magasin en lecture seule, où plus rien ne se
 * contre-passe, la facture reste due et l'alerte reste.
 */
class FacturesAEncaisserApercu extends Widget
{
    /**
     * Combien de factures l'alerte énumère, les plus anciennes par échéance.
     */
    public const NOMBRE_ENUMEREES = 10;

    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.app.widgets.factures-a-encaisser-apercu';

    public static function canView(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        if (! $compte instanceof User || ! $magasin instanceof Magasin) {
            return false;
        }

        $cochees = $compte->autorisationsDans($magasin);

        return $compte->estMembre()
            ? in_array(PermissionRegistry::PAIEMENT_CONTRE_PASSER, $cochees, true) && in_array(PermissionRegistry::FACTURE_VIEW, $cochees, true)
            : in_array(PermissionRegistry::FACTURE_VIEW_TOUTES, $cochees, true);
    }

    /**
     * « 1 234,50 € », depuis un montant en centimes.
     */
    public function enEuros(int $centimes): string
    {
        $absolu = abs($centimes);

        return ($centimes < 0 ? '-' : '').number_format(intdiv($absolu, 100), 0, ',', ' ').','.str_pad((string) ($absolu % 100), 2, '0', STR_PAD_LEFT).' €';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $factures = Facture::query()
            ->aEncaisser()
            ->visiblesPar(auth()->user())
            ->with(['allocationsPaiement:id,facture_id,montant_alloue', 'allocationsAvoir:id,facture_id,montant_alloue'])
            ->withExists(['allocationsPaiement as paiement_contre_passe' => fn (Builder $allocations): Builder => $allocations
                ->whereHas('paiement', fn (Builder $paiements): Builder => $paiements->whereNotNull('contre_passe_paiement_id'))])
            ->orderBy('date_echeance')
            ->orderBy('id')
            ->get();

        return [
            'totaux' => Facture::totauxAEncaisser($factures),
            'plusAnciennes' => $factures->take(self::NOMBRE_ENUMEREES),
            'urlDeLaListe' => FactureResource::urlDesFacturesAEncaisser(),
        ];
    }
}
