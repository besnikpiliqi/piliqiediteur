<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\ClotureCaisseEffectuee;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Agrège en un seul document fiscal toutes les ventes à des clients
 * PARTICULIERS d'une journée donnée -- les ventes à des professionnels
 * n'y figurent jamais, elles relèvent de l'e-invoicing individuelle (voir
 * Facture::relevantDeLeInvoicing). Une journée ne peut être clôturée
 * qu'une seule fois (contrainte unique magasin_id/date_cloture) : la
 * correction d'une vente déjà agrégée passe par un avoir sur la facture
 * d'origine, jamais par une réouverture de la clôture.
 */
class CloturerCaisseAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    public function handle(Magasin $magasin, CarbonInterface|string $date): ClotureCaisse
    {
        $dateCloture = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($magasin, $dateCloture) {
            if (ClotureCaisse::query()
                ->where('magasin_id', $magasin->getKey())
                ->where('date_cloture', $dateCloture)
                ->exists()) {
                throw new LogicException("La journée du {$dateCloture} a déjà été clôturée pour ce magasin.");
            }

            $factures = Facture::query()
                ->whereHas('client', fn ($query) => $query->where('type_client', TypeClient::Particulier))
                ->whereDate('date_emission', $dateCloture)
                ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
                ->whereNull('cloture_caisse_id')
                ->lockForUpdate()
                ->get();

            $cloture = ClotureCaisse::create(['date_cloture' => $dateCloture]);

            $numero = $this->numerotation->suivant($magasin, TypeDocument::ClotureCaisse, now()->format('Y'));

            $cloture->forceFill([
                'numero' => $numero,
                'nombre_ventes' => $factures->count(),
                'total_ht' => $factures->sum(fn (Facture $facture) => (float) $facture->total_ht),
                'total_tva' => $factures->sum(fn (Facture $facture) => (float) $facture->total_tva),
                'total_ttc' => $factures->sum(fn (Facture $facture) => (float) $facture->total_ttc),
                'cloturee_a' => now(),
            ])->save();

            if ($factures->isNotEmpty()) {
                Facture::query()
                    ->whereIn('id', $factures->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            ClotureCaisseEffectuee::dispatch($cloture);

            return $cloture;
        });
    }
}
