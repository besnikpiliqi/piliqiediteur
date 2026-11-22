<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Charges\Support\ReportDuCreditDeTva;
use App\Domain\Charges\Support\TvaReportee;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChargesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * La TVA à payer d'une société, réelle et estimée, par mois (décision de
 * l'éditeur du 14/11/2026, lot CHARGES) : chaque vente et chaque achat
 * dans le mois de son magasin (son calendrier), chaque charge dans son
 * mois de TVA (voir MontantsDeTva) :
 * - la TVA collectée : les ventes nettes des avoirs, plus l'autoliquidation
 *   des charges validées d'un fournisseur établi hors de France ;
 * - la TVA déductible des achats de marchandises (factures d'achat validées
 *   moins avoirs fournisseur), et celle des charges validées ;
 * - la TVA du mois réelle : la collectée moins ces déductibles ;
 * - la TVA du mois estimée : la réelle, avec aussi les charges à valider.
 * Puis le report du crédit (lot TVA27, voir ReportDuCreditDeTva) : de la
 * TVA de chaque période (le mois, ou le trimestre civil au trimestriel),
 * on retire le crédit reporté de la période d'avant ; positive, c'est la
 * TVA à payer, négative, le crédit à reporter, la réelle et l'estimation
 * chacune dans sa chaîne, depuis le début de la chaîne quelle que soit la
 * période affichée, avec le crédit de la déclaration que la société a
 * indiquée (voir CreditsDeTva::ancre()). Le report ne se calcule que sur
 * toute la société (voir Perimetre::reporteLeCreditDeTva()).
 * Les montants sont ceux des documents et des charges, en centimes,
 * additionnés ici, jamais par une somme de la base sur une colonne
 * décimale. Une société en franchise en base, ou dont le régime n'est pas
 * encore choisi, n'a pas de TVA qui s'affiche (décision de l'éditeur du
 * 18/11/2026). L'état de TVA officiel (CA3, exigibilité exacte) viendra
 * avec la comptabilité (étape 5), qui reprendra les charges validées et le
 * crédit reporté.
 *
 * @phpstan-import-type DocumentDeVente from VentesDuTableauDeBord
 * @phpstan-import-type DocumentDAchat from AchatsDuTableauDeBord
 * @phpstan-import-type ChargeDeLaTva from ChargesDuTableauDeBord
 *
 * @phpstan-type TvaDeLaPage array{montants: array<string, MontantsDeTva>, report: TvaReportee|null, avec_les_ventes: bool, sans_celles_de_la_societe: bool, magasins_non_comptes: string|null}
 */
final class TvaDeLaPeriode
{
    public function __construct(
        private readonly VentesDuTableauDeBord $ventes,
        private readonly AchatsDuTableauDeBord $achats,
        private readonly ChargesDuTableauDeBord $charges,
        private readonly CreditsDeTva $credits,
    ) {}

    /**
     * La TVA de chacun de ces mois, depuis ces documents déjà lus, et ces
     * charges (null : sans les charges), ceux de ces magasins seulement ;
     * les charges de « Toute la société » seulement quand elles sont
     * comptées : jamais la TVA déductible d'un magasin qui n'est pas compté,
     * ni celle de toute la société contre les ventes de quelques magasins.
     *
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<int>  $magasins
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $ventes
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $achats
     * @param  list<ChargeDeLaTva>|null  $charges
     * @return array<string, MontantsDeTva>
     */
    public static function parMois(array $mois, array $magasins, array $ventes, array $achats, ?array $charges, bool $avecCellesDeLaSociete = true): array
    {
        $permis = array_flip($magasins);
        $sommes = array_fill_keys($mois, ['ventes' => 0, 'achats' => 0, 'autoliquidee_validee' => 0, 'deductible_validee' => 0, 'autoliquidee_a_valider' => 0, 'deductible_a_valider' => 0]);

        foreach (['ventes' => $ventes, 'achats' => $achats] as $source => $documents) {
            foreach ($documents as $document) {
                $leMois = substr($document['jour'], 0, 7);

                if (isset($permis[$document['magasin']], $sommes[$leMois])) {
                    $sommes[$leMois][$source] += in_array($document['document'], ['avoir', 'avoir_fournisseur'], true) ? -$document['tva'] : $document['tva'];
                }
            }
        }

        foreach ($charges ?? [] as $charge) {
            $comptee = $charge['magasin'] === null ? $avecCellesDeLaSociete : isset($permis[$charge['magasin']]);

            if ($comptee && isset($sommes[$charge['mois']])) {
                $etat = $charge['validee'] ? 'validee' : 'a_valider';
                $sommes[$charge['mois']]['autoliquidee_'.$etat] += $charge['autoliquidee'];
                $sommes[$charge['mois']]['deductible_'.$etat] += $charge['deductible'];
            }
        }

        $montants = [];

        foreach ($sommes as $leMois => $somme) {
            $montants[$leMois] = new MontantsDeTva($somme['ventes'], $somme['autoliquidee_validee'], $somme['achats'], $somme['deductible_validee'], $somme['autoliquidee_a_valider'], $somme['deductible_a_valider'], $charges !== null);
        }

        return $montants;
    }

    /**
     * Le report du crédit sur la TVA de ces mois de cette société (voir
     * ReportDuCreditDeTva) : la TVA de chaque mois, du premier mois de la
     * chaîne (ou d'avant) au dernier compté, sans trou ; avec, pour ancre,
     * la déclaration la plus récente de la société, que l'appelant a lue
     * une fois pour sa page (voir CreditsDeTva::ancre()). Null hors du
     * réel normal.
     *
     * @param  array<string, MontantsDeTva>  $parMois
     */
    public function reporter(Societe $societe, array $parMois, bool $avecLesCharges, ?CreditDeTva $ancre): ?TvaReportee
    {
        $cadence = $societe->regime_tva?->cadence();

        if ($cadence === null) {
            return null;
        }

        return ReportDuCreditDeTva::calculer(
            $cadence,
            CreditsDeTva::premierePeriode($societe, $cadence),
            $ancre === null ? null : ['periode' => $ancre->periode, 'credit' => $ancre->credit()],
            $parMois,
            $avecLesCharges,
        );
    }

    /**
     * La TVA de ce mois « AAAA-MM » telle que ce périmètre la montre (voir
     * desMoisDuPerimetre()) ; null pour une société dont la TVA ne s'affiche
     * pas (franchise en base, régime à choisir).
     *
     * @throws LogicException un mois illisible
     */
    public function duPerimetre(Perimetre $perimetre, string $mois): ?MontantsDeTva
    {
        return $this->desMoisDuPerimetre($perimetre, [$mois])[$mois] ?? null;
    }

    /**
     * La TVA de chacun de ces mois « AAAA-MM » telle que ce périmètre la
     * montre (voir PerimetreDesGraphiques) : les ventes et les achats des
     * magasins où il voit tous ces documents, et les charges qu'il compte
     * (voir Perimetre::chargesComptees()) de ces magasins, et de « Toute la
     * société » quand tous ses magasins sont comptés ; les documents lus
     * une seule fois, du premier jour du premier mois au dernier jour du
     * dernier ; null pour une société dont la TVA ne s'affiche pas
     * (franchise en base, régime à choisir). La page des charges la montre
     * pour le mois choisi, et son état téléchargeable pour chaque mois de
     * la période.
     *
     * @param  list<string>  $mois
     * @return array<string, MontantsDeTva>|null
     *
     * @throws LogicException un mois illisible
     */
    public function desMoisDuPerimetre(Perimetre $perimetre, array $mois): ?array
    {
        foreach ($mois as $unMois) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $unMois) !== 1) {
                throw new LogicException('Le mois s\'écrit « AAAA-MM ».');
            }
        }

        if (! $perimetre->montreLaTva()) {
            return null;
        }

        if ($mois === []) {
            return [];
        }

        [$du, $au] = [min($mois).'-01', Calendrier::ajouterJours(Mois::decaler(max($mois), 1).'-01', -1)];
        $articles = $perimetre->societe->aDesArticles();
        $magasins = self::magasinsDeLaTva($perimetre);
        $ids = CleDuModele::desModeles($magasins);

        return self::parMois(
            $mois,
            $ids,
            $magasins->isEmpty() ? [] : $this->ventes->documents($perimetre, $magasins, $du, $au),
            $magasins->isEmpty() || ! $articles ? [] : $this->achats->documents($perimetre, $magasins, $du, $au),
            $this->charges->pourLaTva($perimetre, $mois),
            $perimetre->compteLesChargesDeLaSociete($ids),
        );
    }

    /**
     * La TVA de ces mois telle que ce périmètre la montre, et, quand il
     * reporte le crédit (voir Perimetre::reporteLeCreditDeTva()), le report
     * depuis le début de la chaîne jusqu'au plus récent de ces mois qui
     * n'est pas à venir : les documents lus une seule fois, du premier mois
     * de la chaîne (ou du premier de ces mois) au dernier de ces mois ; un
     * mois à venir n'a pas de report. Null pour une société dont la TVA ne
     * s'affiche pas.
     *
     * @param  list<string>  $mois
     * @return array{montants: array<string, MontantsDeTva>, report: TvaReportee|null}|null
     *
     * @throws LogicException un mois illisible
     */
    public function avecLeReport(Perimetre $perimetre, array $mois): ?array
    {
        if (! $perimetre->reporteLeCreditDeTva() || $mois === []) {
            $montants = $this->desMoisDuPerimetre($perimetre, $mois);

            return $montants === null ? null : ['montants' => $montants, 'report' => null];
        }

        $cadence = $perimetre->societe->regime_tva?->cadence() ?? throw new LogicException('Le report du crédit se calcule au réel normal.');
        $ceMois = Mois::duJour($perimetre->aujourdhui());
        $lus = Mois::du(min(CreditsDeTva::premierePeriode($perimetre->societe, $cadence), min($mois)), max($mois));
        $montants = $this->desMoisDuPerimetre($perimetre, $lus) ?? [];
        $chaine = array_filter($montants, static fn (string $unMois): bool => $unMois <= $ceMois, ARRAY_FILTER_USE_KEY);

        return [
            'montants' => array_intersect_key($montants, array_flip($mois)),
            'report' => $this->reporter($perimetre->societe, $chaine, $perimetre->charges !== null, $this->credits->ancre($perimetre->societe)),
        ];
    }

    /**
     * La TVA de ces mois telle que la page des charges la montre, sur le
     * magasin de son filtre ($magasin, ou « Toute la société » seulement
     * avec $sansMagasin) : pour qui voit les graphiques de son écran
     * ($perimetre, déjà restreint au magasin du filtre), celle de son
     * tableau de bord, ventes et achats compris, avec le report du crédit
     * quand elle compte toute la société (voir avecLeReport()) ; pour les
     * autres, celle des seules charges que l'accès voit, sans les ventes ni
     * les achats, qu'ils ne voient pas, ni le report ; null dans une
     * société dont la TVA ne s'affiche pas (franchise en base, régime à
     * choisir). Avec elle : si elle compte les ventes, si elle laisse de
     * côté les charges de « Toute la société » parce qu'un magasin n'est
     * pas compté, et la phrase grise des magasins qu'elle ne compte pas.
     *
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<int>  $magasinsVus  les magasins que l'accès voit
     * @return TvaDeLaPage|null
     *
     * @throws LogicException un mois illisible
     */
    public function pourLaPageDesCharges(AccesAuxCharges $acces, ?Perimetre $perimetre, array $mois, ?int $magasin, bool $sansMagasin, array $magasinsVus): ?array
    {
        if ($acces->societe->regime_tva?->cadence() === null) {
            return null;
        }

        if ($perimetre !== null) {
            $tva = $this->avecLeReport($perimetre, $mois);

            return $tva === null ? null : [
                'montants' => $tva['montants'],
                'report' => $tva['report'],
                'avec_les_ventes' => true,
                'sans_celles_de_la_societe' => $perimetre->laisseLesChargesDeLaSociete($perimetre->magasinsDeLaTva()),
                'magasins_non_comptes' => $perimetre->phraseDesMagasinsNonComptes(...$perimetre->sourcesDeLaTva()),
            ];
        }

        $magasins = match (true) {
            $magasin !== null => [$magasin],
            $sansMagasin => [],
            default => $magasinsVus,
        };
        $deLaSociete = $acces->chargesDeLaSociete && $magasin === null;
        $charges = ChargesDuTableauDeBord::lire($acces->societe, $mois, $magasins, $deLaSociete);

        return ['montants' => self::parMois($mois, $magasins, [], [], $charges, $deLaSociete), 'report' => null, 'avec_les_ventes' => false, 'sans_celles_de_la_societe' => false, 'magasins_non_comptes' => null];
    }

    /**
     * @return Collection<int, Magasin>
     */
    private static function magasinsDeLaTva(Perimetre $perimetre): Collection
    {
        return $perimetre->magasinsAvec(...$perimetre->sourcesDeLaTva());
    }
}
