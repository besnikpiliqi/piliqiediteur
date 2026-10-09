<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
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
 * PARTICULIERS d'une journée donnée, moins les avoirs qui leur ont été
 * émis ce jour-là : la TVA d'une vente corrigée se régularise le jour de
 * l'avoir (art. 272 du CGI), et en caisse une correction est toujours une
 * opération en négatif, jamais une modification ni une suppression. Les ventes
 * et avoirs à des professionnels n'y figurent jamais, ils relèvent de
 * l'e-invoicing individuelle (voir Facture::relevantDeLeInvoicing). Une
 * journée ne peut être clôturée qu'une seule fois (contrainte unique
 * magasin_id/date_cloture) : la correction d'une vente déjà agrégée passe
 * par un avoir, compté dans la clôture du jour où il est émis, jamais par
 * une réouverture de la clôture.
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

            $ventes = Facture::query()->enAttenteDeCloture($dateCloture)->lockForUpdate()->get();
            $avoirs = Avoir::query()->enAttenteDeCloture($dateCloture)->lockForUpdate()->get();

            $cloture = ClotureCaisse::create(['date_cloture' => $dateCloture]);

            $numero = $this->numerotation->suivant($magasin, TypeDocument::ClotureCaisse, now()->format('Y'));

            $cloture->forceFill([
                'numero' => $numero,
                'nombre_ventes' => $ventes->count(),
                'nombre_avoirs' => $avoirs->count(),
                ...$this->calculateur->deduire($this->calculateur->cumuler($ventes), $this->calculateur->cumuler($avoirs)),
                'cloturee_a' => now(),
            ])->save();

            if ($ventes->isNotEmpty()) {
                Facture::query()
                    ->whereIn('id', $ventes->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            if ($avoirs->isNotEmpty()) {
                Avoir::query()
                    ->whereIn('id', $avoirs->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            return $cloture;
        });
    }
}
