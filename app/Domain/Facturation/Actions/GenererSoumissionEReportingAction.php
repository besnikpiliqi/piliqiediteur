<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\SoumissionEReportingGeneree;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use App\Integrations\Pdp\Contracts\PeriodeEReporting;
use Illuminate\Support\Facades\DB;

/**
 * Agrège en UNE soumission toutes les clôtures de caisse d'une période
 * d'e-reporting (voir CalculateurPeriodeEReporting), au lieu de transmettre
 * chaque clôture quotidienne isolément au PDP -- l'e-reporting n'est pas
 * une obligation journalière (voir le docblock de SoumissionEReporting).
 *
 * Idempotent par construction : une contrainte unique (magasin_id,
 * periode_debut, periode_fin) empêche de régénérer deux fois la même
 * période (voir la migration create_soumissions_ereporting_table), et
 * chaque clôture agrégée est marquée (soumission_ereporting_id) pour ne
 * jamais être reprise dans une période suivante.
 */
class GenererSoumissionEReportingAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    public function handle(Magasin $magasin, PeriodeEReporting $periode): ?SoumissionEReporting
    {
        if (SoumissionEReporting::query()
            ->where('magasin_id', $magasin->getKey())
            ->whereDate('periode_debut', $periode->debut->toDateString())
            ->whereDate('periode_fin', $periode->fin->toDateString())
            ->exists()) {
            return null;
        }

        return DB::transaction(function () use ($magasin, $periode) {
            $clotures = ClotureCaisse::query()
                ->where('magasin_id', $magasin->getKey())
                ->whereDate('date_cloture', '>=', $periode->debut->toDateString())
                ->whereDate('date_cloture', '<=', $periode->fin->toDateString())
                ->whereNull('soumission_ereporting_id')
                ->lockForUpdate()
                ->get();

            $numero = $this->numerotation->suivant($magasin, TypeDocument::SoumissionEReporting, $periode->debut->format('Y'));

            $soumission = SoumissionEReporting::create([
                'periode_debut' => $periode->debut->toDateString(),
                'periode_fin' => $periode->fin->toDateString(),
                'date_limite_transmission' => $periode->dateLimiteTransmission->toDateString(),
            ]);

            $soumission->forceFill([
                'numero' => $numero,
                'nombre_clotures' => $clotures->count(),
                ...$this->calculateur->cumuler($clotures),
                'generee_le' => now(),
            ])->save();

            if ($clotures->isNotEmpty()) {
                ClotureCaisse::query()->whereIn('id', $clotures->pluck('id'))->update(['soumission_ereporting_id' => $soumission->id]);
            }

            SoumissionEReportingGeneree::dispatch($soumission);

            return $soumission;
        });
    }
}
