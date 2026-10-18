<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\CategorieEReporting;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * L'aperçu de ce que l'e-reporting des ventes aux particuliers (art. 290
 * et 290 A du CGI) déclarera pour la journée d'une clôture (point 26),
 * lu sans rien écrire ni rien recalculer : la plateforme agréée tient le
 * calendrier de l'e-reporting, et l'étape 2 enverra, une seule fois,
 * chaque clôture journalière ayant des opérations, depuis cet aperçu.
 * Rien n'est transmis avant elle.
 *
 * Les transactions sont les lignes enregistrées des tickets que la clôture
 * compte, moins celles de ses avoirs, additionnées en centimes par
 * catégorie, par taux et par motif ; leur total est donc exactement le net
 * de la clôture, qui additionne les mêmes documents (voir
 * CloturerCaisseAction). Une ligne vendue avec la TVA française est une
 * livraison de biens ou une prestation de services, selon sa nature ; une
 * vente sans TVA française (export expédié, livraison vers un DOM, et la
 * franchise en base, dont la catégorie reste à vérifier) se range dans les
 * opérations non soumises à la TVA en France, avec son motif : elle est à
 * déclarer, et sa transmission attend que l'étape 2 sache envoyer ce motif,
 * comme celle d'une vente en France à 0 %.
 *
 * Les encaissements des prestations de services (la TVA d'un service est
 * due à l'encaissement) sont les règlements que la clôture compte sur des
 * tickets qui vendent un service avec la TVA française : chaque paiement
 * d'un mode à sa date de réception, contre-passations déduites, et chaque
 * crédit d'avoir à la date où il est utilisé (Q13), sauf le crédit qu'un
 * avoir impute sur un ticket qu'il corrige, qui réduit la vente au lieu de
 * la régler. Ils se lisent, comme ceux du récapitulatif de la clôture (voir
 * RecapitulatifDeCloture), dans les enregistrements du registre fiscal qui
 * suivent ceux de la clôture précédente : chaque règlement compte dans une
 * seule clôture. Un ticket qui ne vend que des services à un même taux
 * déclare tout ce qu'il reçoit à ce taux ; un ticket qui mêle biens et
 * services, ou plusieurs taux de services, déclare la part de chaque
 * service quand il est réglé en une fois à son émission, et sinon son
 * règlement reste à répartir. Tous attendent la confirmation de la règle
 * par l'expert-comptable de la société.
 *
 * Le magasin de démonstration n'est jamais déclaré. Toutes les lectures
 * restent dans le magasin actif (MagasinScope), celui de la clôture.
 */
class EReportingDesParticuliers
{
    public const MENTION_TRANSMISSION_EN_ATTENTE = 'À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).';

    public const MENTION_CATEGORIE_A_VERIFIER = 'Catégorie à vérifier. '.self::MENTION_TRANSMISSION_EN_ATTENTE;

    public const MOTIF_FRANCHISE = 'Franchise en base de TVA ('.RegimeTva::ARTICLE_FRANCHISE.')';

    public const MOTIF_TAUX_ZERO = 'Vente en France à 0 %';

    public const MENTION_ENCAISSEMENTS = 'En attente de la confirmation de l\'expert-comptable de la société. Un crédit d\'avoir compte à la date où il est utilisé.';

    public const MENTION_A_REPARTIR = 'Règlements reçus en plusieurs fois d\'un ticket qui mêle biens et services, ou plusieurs taux de services : leur répartition entre biens et services, et entre taux, attend la règle de l\'expert-comptable de la société.';

    public const MENTION_DEMONSTRATION = 'Magasin de démonstration : jamais déclaré.';

    public const STATUT = 'Non transmis : plateforme pas encore raccordée (étape 2).';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function apercu(ClotureCaisse $cloture): ApercuEReporting
    {
        $journee = $cloture->date_cloture->toDateString();

        if ($cloture->magasin->est_demonstration) {
            return new ApercuEReporting($journee, true, [], [], []);
        }

        [$encaissementsDeServices, $encaissementsARepartir] = $this->encaissements($cloture);

        return new ApercuEReporting($journee, false, $this->transactions($cloture), $encaissementsDeServices, $encaissementsARepartir);
    }

    /**
     * Le total de l'aperçu est-il le net de la clôture (total_ht et
     * total_tva) ? Toujours, puisque les deux additionnent les mêmes
     * documents enregistrés : la fiche le montre comme un contrôle.
     */
    public function egaleLeNetDe(ApercuEReporting $apercu, ClotureCaisse $cloture): bool
    {
        return $apercu->totalHt() === $this->calculateur->enCentiemes($cloture->total_ht)
            && $apercu->totalTva() === $this->calculateur->enCentiemes($cloture->total_tva);
    }

    /**
     * Les journées clôturées du magasin qui ont des opérations à déclarer :
     * des ventes ou des avoirs aux particuliers, ou un règlement compté par
     * la clôture (un paiement, et le crédit d'avoir qu'il impute) sur un
     * ticket qui vend un service avec la TVA française. Aucune pour le
     * magasin de démonstration.
     *
     * @return Builder<ClotureCaisse>
     */
    public static function journeesADeclarer(Magasin $magasin): Builder
    {
        if ($magasin->est_demonstration) {
            return ClotureCaisse::query()->whereRaw('1 = 0');
        }

        $ticketsAvecUnService = self::declares(Facture::query())
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->where('nature', NatureLigne::Service->value)->where('taux_tva', '>', 0))
            ->select('factures.id');

        return ClotureCaisse::query()
            ->where('clotures_caisse.magasin_id', $magasin->getKey())
            ->where(fn (Builder $clotures): Builder => $clotures
                ->where('nombre_ventes', '>', 0)
                ->orWhere('nombre_avoirs', '>', 0)
                ->orWhereExists(fn (QueryBuilder $encaissements): QueryBuilder => $encaissements
                    ->from('enregistrements_fiscaux')
                    ->whereColumn('enregistrements_fiscaux.magasin_id', 'clotures_caisse.magasin_id')
                    ->where('enregistrements_fiscaux.chaine', ChaineFiscale::Encaissement->value)
                    ->where('enregistrements_fiscaux.document_type', (new Paiement)->getMorphClass())
                    ->whereColumn('enregistrements_fiscaux.sequence', '<=', 'clotures_caisse.derniere_sequence_encaissement')
                    ->where('enregistrements_fiscaux.sequence', '>', fn (QueryBuilder $precedente): QueryBuilder => $precedente
                        ->from('clotures_caisse as precedente')
                        ->selectRaw('coalesce(max(precedente.derniere_sequence_encaissement), 0)')
                        ->whereColumn('precedente.magasin_id', 'clotures_caisse.magasin_id')
                        ->whereColumn('precedente.date_cloture', '<', 'clotures_caisse.date_cloture'))
                    ->where(fn (QueryBuilder $reglements): QueryBuilder => $reglements
                        ->whereIn('enregistrements_fiscaux.document_id', PaiementAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->select('paiement_id'))
                        ->orWhereIn('enregistrements_fiscaux.document_id', AvoirAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->whereNotNull('paiement_id')->select('paiement_id')))));
    }

    /**
     * Les documents que déclare l'e-reporting des ventes aux particuliers :
     * seule fonction qui les choisit, d'après le type du client figé sur le
     * document à son émission. Une facture à un professionnel n'y entre
     * jamais : elle part comme facture électronique.
     *
     * @template TDocument of Facture|Avoir
     *
     * @param  Builder<TDocument>  $documents
     * @return Builder<TDocument>
     */
    private static function declares(Builder $documents): Builder
    {
        return $documents->where($documents->qualifyColumn('type_client'), TypeClient::Particulier->value);
    }

    /**
     * Les lignes des tickets de la clôture, moins celles de ses avoirs,
     * additionnées par catégorie, par taux et par motif, dans l'ordre des
     * catégories, du plus fort taux au plus faible, puis par motif.
     *
     * @return list<array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null, remarque: string|null}>
     */
    private function transactions(ClotureCaisse $cloture): array
    {
        $lignes = collect([
            ...self::declares($cloture->factures()->getQuery())->with('lignes')->get()
                ->flatMap(fn (Facture $ticket): array => $ticket->lignes->map(fn (LigneFacture $ligne): array => $this->transaction($ticket, $ligne, 1))->all()),
            ...self::declares($cloture->avoirs()->getQuery())->with('lignes')->get()
                ->flatMap(fn (Avoir $avoir): array => $avoir->lignes->map(fn (LigneAvoir $ligne): array => $this->transaction($avoir, $ligne, -1))->all()),
        ]);
        $ordre = array_flip(array_map(fn (CategorieEReporting $categorie): string => $categorie->value, CategorieEReporting::cases()));

        return $lignes
            ->groupBy(fn (array $ligne): string => implode('|', [$ligne['categorie']->value, $ligne['taux'], $ligne['motif']]))
            ->map(fn (Collection $groupe): array => [
                ...$groupe->first(),
                'ht' => (int) $groupe->sum('ht'),
                'tva' => (int) $groupe->sum('tva'),
            ])
            ->sort(fn (array $a, array $b): int => [$ordre[$a['categorie']->value], -$a['taux'], (string) $a['motif']] <=> [$ordre[$b['categorie']->value], -$b['taux'], (string) $b['motif']])
            ->values()
            ->all();
    }

    /**
     * La transaction d'une ligne, ses montants enregistrés en centimes, en
     * négatif pour un avoir ($sens = -1), sa catégorie et son motif selon
     * le document : franchise en base, nature d'opération hors de la TVA
     * française, ou vente en France à son taux.
     *
     * @return array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null, remarque: string|null}
     */
    private function transaction(Facture|Avoir $document, LigneFacture|LigneAvoir $ligne, int $sens): array
    {
        $taux = $this->calculateur->enCentiemes($ligne->taux_tva);
        [$categorie, $motif, $remarque] = match (true) {
            $document->franchise_en_base => [CategorieEReporting::NonSoumisesALaTvaEnFrance, self::MOTIF_FRANCHISE, self::MENTION_CATEGORIE_A_VERIFIER],
            $document->nature_operation->exonere() => [CategorieEReporting::NonSoumisesALaTvaEnFrance, $document->nature_operation->getLabel(), self::MENTION_TRANSMISSION_EN_ATTENTE],
            $taux === 0 => [CategorieEReporting::deLaNature($ligne->nature), self::MOTIF_TAUX_ZERO, self::MENTION_TRANSMISSION_EN_ATTENTE],
            default => [CategorieEReporting::deLaNature($ligne->nature), null, null],
        };

        return [
            'categorie' => $categorie,
            'taux' => $taux,
            'ht' => $sens * $this->calculateur->enCentiemes($ligne->montant_ht),
            'tva' => $sens * $this->calculateur->enCentiemes($ligne->montant_tva),
            'motif' => $motif,
            'remarque' => $remarque,
        ];
    }

    /**
     * Les encaissements des prestations de services que la clôture compte,
     * par date et par taux, et ceux qui restent à répartir, par date ; ni
     * l'un ni l'autre ne garde une somme nulle.
     *
     * @return array{0: list<array{date: string, taux: int, montant: int}>, 1: list<array{date: string, montant: int}>}
     */
    private function encaissements(ClotureCaisse $cloture): array
    {
        $precedente = ClotureCaisse::query()
            ->where('magasin_id', $cloture->magasin_id)
            ->whereDate('date_cloture', '<', $cloture->date_cloture->toDateString())
            ->orderByDesc('date_cloture')
            ->first();
        $reglements = $this->reglements(
            self::enregistrementsComptes(ChaineFiscale::Vente, $precedente?->derniere_sequence_vente, $cloture->derniere_sequence_vente),
            self::enregistrementsComptes(ChaineFiscale::Encaissement, $precedente?->derniere_sequence_encaissement, $cloture->derniere_sequence_encaissement),
        );
        $tickets = self::declares(Facture::query())->whereKey(array_unique(array_column($reglements, 'facture_id')))->with('lignes')->get()->keyBy('id');
        $ticketsCorriges = self::ticketsCorriges(array_values(array_unique(array_filter(array_column($reglements, 'avoir_id')))));
        $parTaux = [];
        $aRepartir = [];

        foreach ($reglements as $reglement) {
            $ticket = $tickets->get($reglement['facture_id']);

            if ($ticket === null || ($reglement['avoir_id'] !== null && in_array($reglement['facture_id'], $ticketsCorriges[$reglement['avoir_id']] ?? [], true))) {
                continue;
            }

            $groupes = $this->groupes($ticket);
            $services = array_filter($groupes, fn (array $groupe): bool => $groupe['nature'] === NatureLigne::Service && $groupe['taux'] > 0);

            if ($services === []) {
                continue;
            }

            if (count($groupes) === 1 || $reglement['solde_a_l_emission']) {
                foreach ($services as $service) {
                    $cle = "{$reglement['date']}|{$service['taux']}";
                    $parTaux[$cle] = ($parTaux[$cle] ?? 0) + (count($groupes) === 1 ? $reglement['montant'] : $service['ttc']);
                }

                continue;
            }

            $aRepartir[$reglement['date']] = ($aRepartir[$reglement['date']] ?? 0) + $reglement['montant'];
        }

        $parTaux = array_filter($parTaux);
        uksort($parTaux, function (string $a, string $b): int {
            [$dateA, $tauxA] = explode('|', $a);
            [$dateB, $tauxB] = explode('|', $b);

            return [$dateA, -(int) $tauxA] <=> [$dateB, -(int) $tauxB];
        });
        $aRepartir = array_filter($aRepartir);
        ksort($aRepartir);

        return [
            array_map(function (string $cle, int $montant): array {
                [$date, $taux] = explode('|', $cle);

                return ['date' => $date, 'taux' => (int) $taux, 'montant' => $montant];
            }, array_keys($parTaux), $parTaux),
            array_map(fn (string $date, int $montant): array => ['date' => $date, 'montant' => $montant], array_keys($aRepartir), $aRepartir),
        ];
    }

    /**
     * Les enregistrements de cette chaîne du registre fiscal du magasin
     * actif que la clôture compte : après la dernière séquence de la
     * clôture précédente, jusqu'à la sienne (aucun quand elle n'en compte
     * aucun).
     *
     * @return Collection<int, EnregistrementFiscal>
     */
    private static function enregistrementsComptes(ChaineFiscale $chaine, ?int $apres, ?int $jusquA): Collection
    {
        if ($jusquA === null || $jusquA === $apres) {
            return new Collection;
        }

        return EnregistrementFiscal::query()
            ->where('chaine', $chaine->value)
            ->when($apres !== null, fn (Builder $enregistrements): Builder => $enregistrements->where('sequence', '>', $apres))
            ->where('sequence', '<=', $jusquA)
            ->orderBy('sequence')
            ->get()
            ->toBase();
    }

    /**
     * Les règlements que signent ces enregistrements, en centimes : ce qui
     * règle un ticket ou une facture à son émission (paiements de ses parts
     * et crédits d'avoir), en un seul règlement daté du jour de l'émission
     * et noté soldé quand il couvre tout le document ; les imputations d'un
     * avoir, datées du jour de son émission ; puis ce qu'imputent les
     * autres paiements, chacun daté du jour de sa réception, et le crédit
     * d'avoir qu'ils imputent, daté du jour de leur enregistrement.
     *
     * @param  Collection<int, EnregistrementFiscal>  $ventes
     * @param  Collection<int, EnregistrementFiscal>  $encaissements
     * @return list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, solde_a_l_emission: bool}>
     */
    private function reglements(Collection $ventes, Collection $encaissements): array
    {
        $reglements = [];
        $paiementsALEmission = [];

        foreach ($ventes as $vente) {
            $contexte = $vente->contenuSigne()['donnees']['contexte'];

            if ($vente->type === 'avoir') {
                foreach ($contexte['imputations'] as $imputation) {
                    $reglements[] = self::reglement((int) $imputation['facture_id'], self::jourDe($vente), (int) $imputation['montant'], (int) $vente->document_id);
                }

                continue;
            }

            $paiementsALEmission = [...$paiementsALEmission, ...array_map('intval', array_column($contexte['reglements'], 'paiement_id'))];
            $montant = (int) array_sum(array_column($contexte['reglements'], 'montant')) + (int) array_sum(array_column($contexte['credits_avoir'], 'montant'));

            if ($montant !== 0) {
                $reglements[] = [
                    ...self::reglement((int) $vente->document_id, self::jourDe($vente), $montant, null),
                    'solde_a_l_emission' => $montant === $vente->contenuSigne()['donnees']['document']['totaux']['ttc'],
                ];
            }
        }

        foreach ($encaissements as $encaissement) {
            if (in_array((int) $encaissement->document_id, $paiementsALEmission, true)) {
                continue;
            }

            $donnees = $encaissement->contenuSigne()['donnees'];

            foreach ($donnees['allocations'] as $allocation) {
                $reglements[] = self::reglement((int) $allocation['facture_id'], $donnees['paiement']['recu_le'], (int) $allocation['montant'], null);
            }

            foreach ($donnees['credits_imputes'] as $credit) {
                $reglements[] = self::reglement((int) $credit['facture_id'], self::jourDe($encaissement), (int) $credit['montant'], (int) $credit['avoir_id']);
            }
        }

        return $reglements;
    }

    /**
     * @return array{facture_id: int, date: string, montant: int, avoir_id: int|null, solde_a_l_emission: bool}
     */
    private static function reglement(int $factureId, string $date, int $montant, ?int $avoirId): array
    {
        return ['facture_id' => $factureId, 'date' => $date, 'montant' => $montant, 'avoir_id' => $avoirId, 'solde_a_l_emission' => false];
    }

    /**
     * Le jour d'un enregistrement du registre fiscal, à l'heure de Paris
     * (AAAA-MM-JJ).
     */
    private static function jourDe(EnregistrementFiscal $enregistrement): string
    {
        return $enregistrement->horodatage->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * Les tickets et les factures que corrige chacun de ces avoirs, par
     * avoir : son crédit imputé sur l'un d'eux ne le règle pas, il le
     * réduit.
     *
     * @param  list<int>  $avoirs
     * @return array<int, list<int>>
     */
    private static function ticketsCorriges(array $avoirs): array
    {
        return LigneAvoir::query()
            ->whereIn('avoir_id', $avoirs)
            ->with('ligneFacture')
            ->get()
            ->groupBy('avoir_id')
            ->map(fn (Collection $lignes): array => $lignes->map(fn (LigneAvoir $ligne): int => (int) $ligne->ligneFacture->facture_id)->unique()->values()->all())
            ->all();
    }

    /**
     * Les lignes du ticket par nature et par taux, avec leur TTC
     * enregistré, en centimes.
     *
     * @return list<array{nature: NatureLigne, taux: int, ttc: int}>
     */
    private function groupes(Facture $ticket): array
    {
        return $ticket->lignes
            ->groupBy(fn (LigneFacture $ligne): string => $ligne->nature->value.'|'.$this->calculateur->enCentiemes($ligne->taux_tva))
            ->map(fn (Collection $lignes): array => [
                'nature' => $lignes->first()->nature,
                'taux' => $this->calculateur->enCentiemes($lignes->first()->taux_tva),
                'ttc' => (int) $lignes->sum(fn (LigneFacture $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ttc)),
            ])
            ->values()
            ->all();
    }
}
