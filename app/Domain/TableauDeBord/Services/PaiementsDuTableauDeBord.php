<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\JoursDesMagasins;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Les encaissements du tableau de bord : chaque paiement au jour où il est
 * reçu, avec son mode et son montant TTC enregistré, une contre-passation
 * comptant en négatif ; et, à part, le crédit d'avoir imputé sur une
 * vente, au jour de son imputation, qui ne fait entrer aucun argent
 * (CLAUDE.md, « Paiements et avoirs »). Une lecture hors du magasin
 * courant, limitée aux magasins que le périmètre permet pour les
 * paiements (voir Perimetre::exiger()) et aux colonnes nommées : deux
 * requêtes.
 *
 * @phpstan-type Encaissement array{magasin: int, jour: string, mode: string, montant: int}
 * @phpstan-type CreditImpute array{magasin: int, jour: string, montant: int}
 */
final class PaiementsDuTableauDeBord
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * @param  Collection<int, Magasin>  $magasins
     * @return array{paiements: list<Encaissement>, credits: list<CreditImpute>}
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function encaissements(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Paiements);

        if ($magasins->isEmpty()) {
            return ['paiements' => [], 'credits' => []];
        }

        $jours = new JoursDesMagasins($magasins, $du, $au);
        [$debut, $fin] = $jours->bornes();
        $paiements = [];
        $credits = [];

        foreach (DB::table('paiements')->whereIn('magasin_id', $magasins->modelKeys())->whereBetween('recu_le', [$du, $au])->orderBy('id')->get(['magasin_id', 'recu_le', 'mode_paiement', 'montant']) as $paiement) {
            $paiements[] = [
                'magasin' => ValeurSimple::enEntier($paiement->magasin_id),
                'jour' => substr(ValeurSimple::enTexte($paiement->recu_le), 0, 10),
                'mode' => ValeurSimple::enTexte($paiement->mode_paiement),
                'montant' => $this->calculateur->enCentiemes($paiement->montant),
            ];
        }

        foreach (DB::table('avoir_allocations')->whereIn('magasin_id', $magasins->modelKeys())->where('created_at', '>=', $debut)->where('created_at', '<', $fin)->orderBy('id')->get(['magasin_id', 'created_at', 'montant_alloue']) as $imputation) {
            $magasin = ValeurSimple::enEntier($imputation->magasin_id);
            $jour = $jours->jourDe($magasin, ValeurSimple::enTexte($imputation->created_at));

            if ($jour !== null) {
                $credits[] = ['magasin' => $magasin, 'jour' => $jour, 'montant' => $this->calculateur->enCentiemes($imputation->montant_alloue)];
            }
        }

        return ['paiements' => $paiements, 'credits' => $credits];
    }
}
