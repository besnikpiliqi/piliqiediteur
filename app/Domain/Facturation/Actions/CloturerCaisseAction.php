<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
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
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    public function handle(Magasin $magasin, CarbonInterface|string $date): ClotureCaisse
    {
        $dateCloture = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($magasin, $dateCloture) {
            // whereDate() plutôt qu'une égalité directe : le cast 'date'
            // d'Eloquent sérialise la colonne en "Y-m-d H:i:s" à l'écriture,
            // une comparaison de chaîne stricte contre "Y-m-d" seul ne
            // matcherait jamais (SQLite n'a pas de type DATE strict qui
            // normaliserait ça pour nous, contrairement à MySQL/Postgres).
            if (ClotureCaisse::query()
                ->where('magasin_id', $magasin->getKey())
                ->whereDate('date_cloture', $dateCloture)
                ->exists()) {
                throw new LogicException("La journée du {$dateCloture} a déjà été clôturée.");
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
                ...$this->calculateur->cumuler($factures),
                'cloturee_a' => now(),
            ])->save();

            if ($factures->isNotEmpty()) {
                Facture::query()
                    ->whereIn('id', $factures->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            return $cloture;
        });
    }
}
