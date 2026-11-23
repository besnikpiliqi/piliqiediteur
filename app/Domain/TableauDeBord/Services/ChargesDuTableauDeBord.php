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
 * de chacune, son magasin (aucun pour « Toute la société »), son mois, son
 * état, la TVA autoliquidée d'un fournisseur établi hors de France et sa
 * TVA déductible, en centimes ; la TVA de chaque graphique n'en compte
 * ensuite que celles des magasins qu'il compte (voir
 * TvaDeLaPeriode::parMois()). Une lecture hors de la société courante (la
 * page d'un client dans l'espace d'un cabinet), filtrée par la société et
 * les magasins du périmètre, limitée aux colonnes nommées, en une requête.
 *
 * @phpstan-type ChargeDeLaTva array{magasin: int|null, mois: string, validee: bool, autoliquidee: int, deductible: int}
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
     * Les charges validées et à valider de cette société, de ces mois de
     * TVA, de ces magasins et, quand $deLaSociete, de « Toute la société » :
     * celles que la TVA compte. La page des charges les lit ainsi pour qui
     * ne voit pas les graphiques, parmi les charges qu'il voit.
     *
     * @param  list<string>  $mois
     * @param  list<int>  $magasins
     * @return list<ChargeDeLaTva>
     */
    public static function lire(Societe $societe, array $mois, array $magasins, bool $deLaSociete): array
    {
        $charges = Charge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->whereIn('etat', [EtatDeLaCharge::Validee->value, EtatDeLaCharge::AValider->value])
            ->whereIn('mois_tva', $mois)
            ->where(function (Builder $visibles) use ($magasins, $deLaSociete): void {
                $visibles->whereIn('magasin_id', $magasins === [] ? [0] : $magasins);

                if ($deLaSociete) {
                    $visibles->orWhereNull('magasin_id');
                }
            })
            ->orderBy('id')
            ->toBase()
            ->get(['magasin_id', 'mois_tva', 'etat', 'fournisseur_hors_france', 'total_tva', 'total_tva_deductible']);

        $lues = [];

        foreach ($charges as $charge) {
            $lues[] = [
                'magasin' => ValeurSimple::enEntierOuNul($charge->magasin_id),
                'mois' => ValeurSimple::enTexte($charge->mois_tva),
                'validee' => ValeurSimple::enTexte($charge->etat) === EtatDeLaCharge::Validee->value,
                'autoliquidee' => (bool) $charge->fournisseur_hors_france ? ValeurSimple::enEntier($charge->total_tva) : 0,
                'deductible' => ValeurSimple::enEntier($charge->total_tva_deductible),
            ];
        }

        return $lues;
    }
}
