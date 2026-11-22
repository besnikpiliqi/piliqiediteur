<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\ContenuDeLEtat;
use App\Domain\Charges\Support\FiltresDeLEtat;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Support\Facades\DB;

/**
 * L'état des charges téléchargeable (modèle E3 choisi par l'éditeur le
 * 15/11/2026 : « E3, état et montant, les trois parties ») : depuis la page
 * « Charges » de la société, d'un client dans l'espace du cabinet, de
 * l'expert-comptable déclaré ou d'un responsable, le PDF (voir
 * EtatDesChargesEnPdf) ou l'Excel (voir EtatDesChargesEnExcel) de ce que
 * la page montre à la personne, avec ses filtres : le tableau « type de
 * charge × mois » (voir ChargesDeLaSociete::tableauDesMois()), chaque case
 * avec le TTC de ses charges, additionné ici en centimes ; la liste de la
 * vue (voir ChargesDeLaSociete::liste()) ; la TVA de chaque mois telle que
 * la page la compte (voir TvaDeLaPeriode::pourLaPageDesCharges()). Les
 * charges se lisent par les mêmes requêtes que la page, filtrées par la
 * société de l'accès et les magasins qu'il voit : le fichier ne contient
 * jamais une charge que la page ne montre pas à la même personne.
 *
 * Le droit est celui de voir la page, sans l'autorisation « télécharger
 * les documents par période » : l'état ne contient aucun document,
 * seulement ce que la page montre (décision de Claude, D76). Chaque
 * téléchargement s'inscrit au journal d'audit de la société des charges
 * (catégorie « opérations »), avec sa période, sa vue, son magasin, son
 * format et le nombre de charges de sa liste, jamais son contenu : au
 * journal du magasin quand l'état n'en couvre qu'un, sinon à celui de la
 * société ; un compte d'un cabinet lié l'y inscrit depuis l'espace de son
 * cabinet, la société du client posée comme société active.
 *
 * @phpstan-import-type TvaDeLaPage from TvaDeLaPeriode
 */
final class EtatDesCharges
{
    public function __construct(
        private readonly ChargesDeLaSociete $charges,
        private readonly EtatDesChargesEnPdf $pdf,
        private readonly EtatDesChargesEnExcel $excel,
        private readonly JournalAudit $journalAudit,
        private readonly TenantContext $contexte,
    ) {}

    /**
     * Ce que montre l'état de ces filtres pour cet accès, édité par cette
     * personne ($edition : « Édité le … par … »), avec cette TVA de chaque
     * mois, et son report du crédit quand la page le montre (null hors du
     * réel normal : en franchise en base, aucune TVA ne s'affiche, et avant
     * le choix du régime, l'état le dit).
     *
     * @param  TvaDeLaPage|null  $tva
     */
    public function contenu(AccesAuxCharges $acces, FiltresDeLEtat $filtres, ?array $tva, string $edition): ContenuDeLEtat
    {
        $tableau = $this->charges->tableauDesMois($acces, $filtres->mois, $filtres->magasin, $filtres->sansMagasin);
        $deLaPeriode = $this->lire($acces, $filtres, 'toutes');
        $liste = $filtres->vue === 'toutes' ? $deLaPeriode : $this->lire($acces, $filtres, $filtres->vue);

        $ttc = [];

        foreach ($deLaPeriode as $charge) {
            if ($charge->etat !== EtatDeLaCharge::PieceSansChiffres) {
                $cle = $charge->cleDuType();
                $ttc[$cle][$charge->mois_tva] = ($ttc[$cle][$charge->mois_tva] ?? 0) + $charge->total_ttc;
            }
        }

        $lignes = [];
        $totauxDuMois = array_fill_keys($filtres->mois, 0);

        foreach ($tableau as $cle => $ligne) {
            $cases = [];
            $total = 0;
            $dernier = null;

            foreach ($filtres->mois as $mois) {
                $case = $ligne['mois'][$mois] ?? null;

                if ($case === null) {
                    continue;
                }

                $montant = $ttc[$cle][$mois] ?? null;
                $cases[$mois] = ['etat' => $case['etat'], 'nombre' => $case['nombre'], 'ttc' => $montant];
                $total += $montant ?? 0;
                $totauxDuMois[$mois] += $montant ?? 0;
                $dernier = $case['etat'];
            }

            $lignes[] = ['cle' => $cle, 'libelle' => $ligne['libelle'], 'cases' => $cases, 'total' => $total, 'dernier_etat' => $dernier];
        }

        return new ContenuDeLEtat(
            denomination: $acces->societe->raison_sociale ?? 'SIREN '.$acces->societe->siren,
            siren: implode(' ', str_split((string) $acces->societe->siren, 3)),
            filtres: $filtres,
            edition: $edition,
            mois: $filtres->mois,
            lignes: $lignes,
            totauxDuMois: $totauxDuMois,
            total: array_sum($totauxDuMois),
            charges: $liste,
            tva: $tva,
            sansTva: match (true) {
                $acces->societe->regime_tva === null => 'Le régime de TVA de la société n\'est pas encore choisi : aucune TVA à afficher.',
                $acces->societe->estEnFranchiseDeTva() => 'Aucune TVA à afficher : la société est en franchise en base.',
                default => null,
            },
        );
    }

    /**
     * Le fichier de l'état dans ce format, inscrit au journal d'audit de la
     * société des charges.
     *
     * @param  TvaDeLaPage|null  $tva
     * @return array{nom: string, type: string, contenu: string}
     */
    public function telecharger(AccesAuxCharges $acces, FiltresDeLEtat $filtres, ?array $tva, string $edition, FormatDeLEtat $format): array
    {
        $contenu = $this->contenu($acces, $filtres, $tva, $edition);
        $fichier = $format === FormatDeLEtat::Pdf ? $this->pdf->generer($contenu) : $this->excel->generer($contenu);

        $this->contexte->runAsSociete($acces->societe, fn () => DB::transaction(function () use ($acces, $filtres, $format, $contenu): void {
            Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($acces->societe));
            $magasin = $this->magasinDuJournal($acces, $filtres);

            $this->journalAudit->inscrire(ActionAuditee::EtatDesChargesTelecharge, $acces->compte, $magasin, null, [
                'periode' => ['du' => $filtres->mois[0] ?? null, 'au' => $filtres->mois[count($filtres->mois) - 1] ?? null, 'libelle' => $filtres->periode],
                'vue' => $filtres->vue,
                'magasin' => $filtres->sansMagasin ? 'Toute la société' : ($magasin->code ?? ($filtres->magasin === null ? null : $filtres->libelleDuMagasin)),
                'format' => $format->value,
                'nombre_charges' => count($contenu->charges),
            ]);
        }));

        return ['nom' => $contenu->nomDuFichier($format), 'type' => $format->typeMime(), 'contenu' => $fichier];
    }

    /**
     * Les charges de la liste de cette vue, avec l'instant de leur
     * validation (la colonne « Validée le » de l'Excel).
     *
     * @return list<Charge>
     */
    private function lire(AccesAuxCharges $acces, FiltresDeLEtat $filtres, string $vue): array
    {
        return array_values($this->charges->liste($acces, $filtres->mois, $filtres->magasin, $filtres->sansMagasin, $vue)
            ->addSelect('charges.validee_le')
            ->get()
            ->all());
    }

    /**
     * Le magasin au journal duquel l'état s'inscrit : celui du filtre, ou
     * le seul que l'accès voit quand il ne voit pas « Toute la société »
     * (un responsable) ; sinon aucun, au journal de la société.
     */
    private function magasinDuJournal(AccesAuxCharges $acces, FiltresDeLEtat $filtres): ?Magasin
    {
        $magasin = $filtres->magasin ?? (! $acces->chargesDeLaSociete && $acces->magasins !== null && count($acces->magasins) === 1 ? $acces->magasins[0] : null);

        return $magasin === null || $filtres->sansMagasin ? null : Magasin::query()->whereKey($magasin)->where('societe_id', $acces->societe->getKey())->first();
    }
}
