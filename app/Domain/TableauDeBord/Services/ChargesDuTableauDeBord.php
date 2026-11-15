<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les charges de la société que compte la TVA du tableau de bord (lot
 * CHARGES ; voir TvaDeLaPeriode) : les validées et les à valider, de ces
 * mois de TVA, que le périmètre compte (voir Perimetre::chargesComptees()) ;
 * de chacune, son mois, son état, la TVA autoliquidée d'un fournisseur
 * établi hors de France et sa TVA déductible, en centimes. Une lecture
 * hors de la société courante (la page d'un client dans l'espace d'un
 * cabinet), filtrée par la société et les magasins du périmètre, limitée
 * aux colonnes nommées, en une requête.
 *
 * @phpstan-type ChargeDeLaTva array{mois: string, validee: bool, autoliquidee: int, deductible: int}
 */
final class ChargesDuTableauDeBord
{
    /**
     * @param  list<string>  $mois
     * @return list<ChargeDeLaTva>|null null quand le compte ne voit pas les charges
     */
    public function pourLaTva(Perimetre $perimetre, array $mois): ?array
    {
        $comptees = $perimetre->chargesComptees();

        if ($comptees === null) {
            return null;
        }

        if ($mois === [] || ($comptees['magasins'] === [] && ! $comptees['de_la_societe'])) {
            return [];
        }

        return self::lire($perimetre->societe, $mois, $comptees['magasins'], $comptees['de_la_societe']);
    }

    /**
     * Toutes les charges de la société de ces mois de TVA, de tous ses
     * magasins et de « Toute la société », comme son propriétaire les voit
     * (voir TvaDeLaPeriode::deLaSociete()).
     *
     * @param  list<string>  $mois
     * @return list<ChargeDeLaTva>
     */
    public function deLaSociete(Societe $societe, array $mois): array
    {
        return $mois === [] ? [] : self::lire($societe, $mois, null, true);
    }

    /**
     * @param  list<string>  $mois
     * @param  list<int>|null  $magasins  null pour tous ses magasins
     * @return list<ChargeDeLaTva>
     */
    private static function lire(Societe $societe, array $mois, ?array $magasins, bool $deLaSociete): array
    {
        $charges = Charge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->whereIn('etat', [EtatDeLaCharge::Validee->value, EtatDeLaCharge::AValider->value])
            ->whereIn('mois_tva', $mois)
            ->when($magasins !== null, fn (Builder $requete): Builder => $requete->where(function (Builder $visibles) use ($magasins, $deLaSociete): void {
                $visibles->whereIn('magasin_id', $magasins === null || $magasins === [] ? [0] : $magasins);

                if ($deLaSociete) {
                    $visibles->orWhereNull('magasin_id');
                }
            }))
            ->when($magasins === null && ! $deLaSociete, fn (Builder $requete): Builder => $requete->whereNotNull('magasin_id'))
            ->orderBy('id')
            ->toBase()
            ->get(['mois_tva', 'etat', 'fournisseur_hors_france', 'total_tva', 'total_tva_deductible']);

        $lues = [];

        foreach ($charges as $charge) {
            $lues[] = [
                'mois' => ValeurSimple::enTexte($charge->mois_tva),
                'validee' => ValeurSimple::enTexte($charge->etat) === EtatDeLaCharge::Validee->value,
                'autoliquidee' => (bool) $charge->fournisseur_hors_france ? ValeurSimple::enEntier($charge->total_tva) : 0,
                'deductible' => ValeurSimple::enEntier($charge->total_tva_deductible),
            ];
        }

        return $lues;
    }
}
