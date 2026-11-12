<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
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
 * livraison de biens ou une prestation de services, selon sa nature. Une
 * vente sans TVA française se range dans les opérations non soumises à la
 * TVA en France, avec son motif : elle est à déclarer, et sa transmission
 * attend que l'étape 2 sache envoyer ce motif. C'est la catégorie que les
 * notes du projet donnent à l'export expédié et à la livraison vers un DOM
 * (cas V3 et V5) ; elle reste à vérifier pour la franchise en base (cas
 * V14) et pour une vente en France à 0 %, la France n'ayant pas de taux
 * zéro : une opération exonérée en France (art. 261 à 261 E du CGI) serait,
 * d'après ces notes, hors de l'e-reporting.
 *
 * Les encaissements des prestations de services (la TVA d'un service est
 * due à l'encaissement) sont les règlements que la clôture compte sur des
 * tickets qui vendent un service avec la TVA française : chaque paiement
 * d'un mode à sa date de réception, contre-passations déduites, et chaque
 * crédit d'avoir à la date où il est utilisé (Q13). Seule la part d'un
 * crédit qui annule les lignes que son avoir corrige sur ce ticket n'est
 * pas un règlement : elle réduit la vente, et ne pas la compter garde
 * égaux le net vendu du ticket et ce qu'il a encaissé (voir
 * sansLesReductions()). Ils se lisent, comme ceux du récapitulatif de la
 * clôture (voir RecapitulatifDeCloture), dans les enregistrements du
 * registre fiscal qui suivent ceux de la clôture précédente : chaque
 * règlement compte dans une seule clôture. Les règlements d'un même ticket
 * à une même date s'additionnent : un ticket qui ne vend que des services
 * à un même taux déclare tout ce qu'il reçoit à ce taux ; un ticket qui
 * mêle biens et services, ou plusieurs taux de services, déclare la part
 * de chaque service quand ses règlements de la date le soldent en entier,
 * ou rendent en entier un règlement qui le soldait, et sinon son règlement
 * reste à répartir. Tous attendent la confirmation de la règle par
 * l'expert-comptable de la société. L'avoir d'une prestation déjà
 * encaissée ne se déduit pas de ses encaissements, même quand son crédit
 * règle ensuite une autre vente : point soumis à l'éditeur.
 *
 * Le magasin de démonstration n'est jamais déclaré. Toutes les lectures
 * restent dans le magasin actif (MagasinScope), celui de la clôture ; le
 * registre fiscal se lit par MagasinScope, et par le magasin de chaque
 * clôture dans journeesADeclarer().
 */
class EReportingDesParticuliers
{
    public const MENTION_TRANSMISSION_EN_ATTENTE = 'À déclarer ; transmission en attente de la prise en charge de son motif d\'exonération (étape 2).';

    public const MENTION_CATEGORIE_A_VERIFIER = 'Catégorie à vérifier. '.self::MENTION_TRANSMISSION_EN_ATTENTE;

    public const MOTIF_FRANCHISE = 'Franchise en base de TVA ('.RegimeTva::ARTICLE_FRANCHISE.')';

    public const MOTIF_TAUX_ZERO = 'Vente en France à 0 %';

    public const MENTION_ENCAISSEMENTS = 'En attente de la confirmation de l\'expert-comptable de la société. Un crédit d\'avoir compte à la date où il est utilisé, sauf la part qui annule les lignes que son avoir corrige sur ce ticket. Point soumis à l\'éditeur : l\'avoir d\'une prestation déjà encaissée ne se déduit pas de ses encaissements, même quand son crédit règle ensuite une autre vente.';

    public const MENTION_A_REPARTIR = 'Règlements qui ne soldent pas en une fois, à une même date, un ticket qui mêle biens et services ou plusieurs taux de services : règlement partiel, reste dû d\'un ticket corrigé par un avoir, ou contre-passation d\'un tel règlement. Leur répartition entre biens et services, et entre taux, attend la règle de l\'expert-comptable de la société.';

    public const MENTION_DEMONSTRATION = 'Magasin de démonstration : jamais déclaré.';

    public const STATUT = 'Non transmis : plateforme pas encore raccordée (étape 2).';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function apercu(ClotureCaisse $cloture): ApercuEReporting
    {
        $journee = $cloture->date_cloture;

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
     * Les journées clôturées du magasin actif qui ont quelque chose à
     * déclarer, exactement celles dont l'aperçu le dit
     * (ApercuEReporting::aDeclarer()) : celles qui comptent une vente ou un
     * avoir à un particulier, dont l'aperçu a des transactions, et, parmi
     * les autres, celles dont l'aperçu montre un encaissement de prestation
     * de services. Ces dernières se cherchent parmi les seules clôtures qui
     * comptent un enregistrement du registre réglant un ticket déclaré qui
     * vend un service avec la TVA française (un paiement ou une
     * contre-passation, et le crédit d'avoir qu'ils imputent, ou l'avoir,
     * fait à tout client, qui impute son crédit à son émission), puis leur
     * aperçu les garde ou les écarte : des règlements qui s'annulent le
     * même jour ne déclarent rien. Aucune pour le magasin de démonstration.
     *
     * @return Builder<ClotureCaisse>
     */
    public function journeesADeclarer(Magasin $magasin): Builder
    {
        if ($magasin->est_demonstration) {
            return ClotureCaisse::query()->whereRaw('1 = 0');
        }

        $avecDesTransactions = fn (Builder $clotures): Builder => $clotures->where('clotures_caisse.nombre_ventes', '>', 0)->orWhere('clotures_caisse.nombre_avoirs', '>', 0);
        $parLeursEncaissements = ClotureCaisse::query()
            ->with('magasin')
            ->where('clotures_caisse.magasin_id', $magasin->getKey())
            ->whereNot($avecDesTransactions)
            ->where(fn (Builder $clotures): Builder => self::quiComptentUnReglementDeService($clotures))
            ->get()
            ->filter(fn (ClotureCaisse $cloture): bool => $this->apercu($cloture)->aDeclarer())
            ->modelKeys();

        return ClotureCaisse::query()
            ->with('magasin')
            ->where('clotures_caisse.magasin_id', $magasin->getKey())
            ->where(fn (Builder $clotures): Builder => $avecDesTransactions($clotures)->orWhereIn('clotures_caisse.id', $parLeursEncaissements));
    }

    /**
     * Les clôtures qui comptent un enregistrement du registre de leur
     * magasin réglant un ticket déclaré qui vend un service avec la TVA
     * française : un paiement ou une contre-passation, dans la chaîne des
     * encaissements, par ce qu'il impute ou par le crédit d'avoir qu'il
     * impute ; un avoir, dans la chaîne des ventes, par le crédit qu'il
     * impute à son émission.
     *
     * @param  Builder<ClotureCaisse>  $clotures
     * @return Builder<ClotureCaisse>
     */
    private static function quiComptentUnReglementDeService(Builder $clotures): Builder
    {
        $ticketsAvecUnService = self::declares(Facture::query())
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->where('nature', NatureLigne::Service->value)->where('taux_tva', '>', 0))
            ->select('factures.id');

        return $clotures
            ->whereExists(fn (QueryBuilder $enregistrements): QueryBuilder => self::enregistrementsDeLaCloture($enregistrements, ChaineFiscale::Encaissement, 'derniere_sequence_encaissement')
                ->where('enregistrements_fiscaux.document_type', (new Paiement)->getMorphClass())
                ->where(fn (QueryBuilder $paiements): QueryBuilder => $paiements
                    ->whereIn('enregistrements_fiscaux.document_id', PaiementAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->select('paiement_id'))
                    ->orWhereIn('enregistrements_fiscaux.document_id', AvoirAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->whereNotNull('paiement_id')->select('paiement_id'))))
            ->orWhereExists(fn (QueryBuilder $enregistrements): QueryBuilder => self::enregistrementsDeLaCloture($enregistrements, ChaineFiscale::Vente, 'derniere_sequence_vente')
                ->where('enregistrements_fiscaux.document_type', (new Avoir)->getMorphClass())
                ->whereIn('enregistrements_fiscaux.document_id', AvoirAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->whereNull('paiement_id')->select('avoir_id')));
    }

    /**
     * Les enregistrements de cette chaîne du registre du magasin de la
     * clôture qu'elle compte, dans la fenêtre que signe sa clôture au
     * journal des événements (événement 50) : après la dernière séquence
     * que signe la clôture précédente, jusqu'à la sienne. Jamais lus sur
     * les lignes des tickets Z, qu'une écriture directe tromperait ; un
     * ticket Z qu'aucune clôture n'a signé ne compte rien.
     *
     * @param  'derniere_sequence_vente'|'derniere_sequence_encaissement'  $derniereSequence
     */
    private static function enregistrementsDeLaCloture(QueryBuilder $enregistrements, ChaineFiscale $chaine, string $derniereSequence): QueryBuilder
    {
        $evenementDeCloture = static fn (QueryBuilder $evenements, string $alias): QueryBuilder => $evenements
            ->where("{$alias}.chaine", ChaineFiscale::Evenement->value)
            ->where("{$alias}.type", (string) CodeEvenement::CloturePeriode->value);

        return $enregistrements
            ->from('enregistrements_fiscaux')
            ->join('enregistrements_fiscaux as signee', fn (JoinClause $signee): JoinClause => $evenementDeCloture($signee
                ->on('signee.magasin_id', '=', 'clotures_caisse.magasin_id')
                ->where('signee.document_type', (new ClotureCaisse)->getMorphClass())
                ->on('signee.document_id', '=', 'clotures_caisse.id'), 'signee'))
            ->whereColumn('enregistrements_fiscaux.magasin_id', 'clotures_caisse.magasin_id')
            ->where('enregistrements_fiscaux.chaine', $chaine->value)
            ->whereRaw('enregistrements_fiscaux.sequence <= '.self::sequenceSignee('signee', $derniereSequence))
            ->where('enregistrements_fiscaux.sequence', '>', fn (QueryBuilder $precedente): QueryBuilder => $evenementDeCloture($precedente
                ->from('enregistrements_fiscaux')
                ->selectRaw('coalesce('.self::sequenceSignee('enregistrements_fiscaux', $derniereSequence).', 0)')
                ->whereColumn('enregistrements_fiscaux.magasin_id', 'signee.magasin_id')
                ->whereColumn('enregistrements_fiscaux.sequence', '<', 'signee.sequence'), 'enregistrements_fiscaux')
                ->orderByDesc('enregistrements_fiscaux.sequence')
                ->limit(1));
    }

    /**
     * L'expression SQL de la dernière séquence que signe l'événement de
     * clôture $alias (derniere_sequence_vente ou
     * derniere_sequence_encaissement de ses détails), ou NULL quand il
     * n'en signe pas : un contenu qui n'est pas un objet JSON, ou une
     * séquence qui n'est pas un entier, ne fait échouer aucune requête
     * (la vérification de l'intégrité le signale).
     *
     * @param  'signee'|'enregistrements_fiscaux'  $alias  l'événement de la clôture, ou celui de la précédente, lu dans sa propre sous-requête
     * @param  'derniere_sequence_vente'|'derniere_sequence_encaissement'  $derniereSequence
     */
    private static function sequenceSignee(string $alias, string $derniereSequence): string
    {
        $valeur = "(CASE WHEN {$alias}.contenu IS JSON OBJECT THEN {$alias}.contenu::jsonb #>> '{donnees,details,{$derniereSequence}}' END)";

        return "(CASE WHEN {$valeur} ~ '^[0-9]{1,18}$' THEN {$valeur}::bigint END)";
    }

    /**
     * La fenêtre de chaque chaîne que compte un ticket Z, telle que la
     * signe sa clôture au journal des événements : la dernière séquence
     * que signe la clôture précédente (null avant la première vente ou le
     * premier encaissement) et la sienne. Un ticket Z qu'aucune clôture
     * n'a signé ne compte rien.
     *
     * @return array{vente: array{0: int|null, 1: int|null}, encaissement: array{0: int|null, 1: int|null}}
     */
    private static function fenetreSignee(ClotureCaisse $cloture): array
    {
        $evenements = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::CloturePeriode->value);
        $signee = (clone $evenements)->where('document_type', $cloture->getMorphClass())->where('document_id', $cloture->getKey())->first();

        if ($signee === null) {
            return ['vente' => [null, null], 'encaissement' => [null, null]];
        }

        $precedente = (clone $evenements)->where('sequence', '<', $signee->sequence)->orderByDesc('sequence')->first();
        $sequence = static fn (?EnregistrementFiscal $evenement, string $champ): ?int => $evenement === null ? null : ValeursLues::entierOuNulDe(ValeursLues::tableauDe($evenement->donneesSignees(), 'details'), $champ);

        return [
            'vente' => [$sequence($precedente, 'derniere_sequence_vente'), $sequence($signee, 'derniere_sequence_vente')],
            'encaissement' => [$sequence($precedente, 'derniere_sequence_encaissement'), $sequence($signee, 'derniere_sequence_encaissement')],
        ];
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
        $groupes = [];

        // Une transaction par catégorie, taux et motif : la première de
        // chacune, ses montants additionnés à ceux des suivantes.
        foreach ($lignes as $ligne) {
            $cle = implode('|', [$ligne['categorie']->value, $ligne['taux'], $ligne['motif']]);
            $groupes[$cle] = array_key_exists($cle, $groupes)
                ? [...$groupes[$cle], 'ht' => $groupes[$cle]['ht'] + $ligne['ht'], 'tva' => $groupes[$cle]['tva'] + $ligne['tva']]
                : $ligne;
        }

        uasort($groupes, fn (array $a, array $b): int => [$ordre[$a['categorie']->value], -$a['taux'], (string) $a['motif']] <=> [$ordre[$b['categorie']->value], -$b['taux'], (string) $b['motif']]);

        return array_values($groupes);
    }

    /**
     * La transaction d'une ligne, ses montants enregistrés en centimes, en
     * négatif pour un avoir ($sens = -1), sa catégorie et son motif selon
     * le document : franchise en base, nature d'opération hors de la TVA
     * française, vente en France à 0 %, ou vente en France à son taux.
     *
     * @return array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null, remarque: string|null}
     */
    private function transaction(Facture|Avoir $document, LigneFacture|LigneAvoir $ligne, int $sens): array
    {
        $taux = $this->calculateur->enCentiemes($ligne->taux_tva);
        [$categorie, $motif, $remarque] = match (true) {
            $document->franchise_en_base => [CategorieEReporting::NonSoumisesALaTvaEnFrance, self::MOTIF_FRANCHISE, self::MENTION_CATEGORIE_A_VERIFIER],
            $document->nature_operation->exonere() => [CategorieEReporting::NonSoumisesALaTvaEnFrance, $document->nature_operation->getLabel(), self::MENTION_TRANSMISSION_EN_ATTENTE],
            $taux === 0 => [CategorieEReporting::NonSoumisesALaTvaEnFrance, self::MOTIF_TAUX_ZERO, self::MENTION_CATEGORIE_A_VERIFIER],
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
     * l'un ni l'autre ne garde une somme nulle. Les règlements d'un même
     * ticket à une même date s'additionnent d'abord : ce qu'un ticket reçoit
     * ce jour-là, contre-passations déduites.
     *
     * @return array{0: list<array{date: string, taux: int, montant: int}>, 1: list<array{date: string, montant: int}>}
     */
    private function encaissements(ClotureCaisse $cloture): array
    {
        $fenetre = self::fenetreSignee($cloture);
        $reglements = $this->sansLesReductions($this->reglements(
            self::enregistrementsComptes(ChaineFiscale::Vente, ...$fenetre['vente']),
            self::enregistrementsComptes(ChaineFiscale::Encaissement, ...$fenetre['encaissement']),
            $cloture->magasin->calendrier(),
        ));
        $tickets = self::declares(Facture::query())->whereKey(array_unique(array_column($reglements, 'facture_id')))->with('lignes')->get()->keyBy('id');
        $recus = [];

        foreach ($reglements as $reglement) {
            if ($tickets->has($reglement['facture_id'])) {
                $cle = "{$reglement['facture_id']}|{$reglement['date']}";
                $recus[$cle] = ($recus[$cle] ?? 0) + $reglement['montant'];
            }
        }

        $parTaux = [];
        $aRepartir = [];

        foreach ($recus as $cle => $montant) {
            [$factureId, $date] = explode('|', $cle);
            $groupes = $this->groupes(ValeurRequise::de($tickets->get((int) $factureId), 'le ticket d\'un encaissement de la journée'));
            $services = array_filter($groupes, fn (array $groupe): bool => $groupe['nature'] === NatureLigne::Service && $groupe['taux'] > 0);

            if ($montant === 0 || $services === []) {
                continue;
            }

            if (count($groupes) === 1) {
                $parTaux["{$date}|{$groupes[0]['taux']}"] = ($parTaux["{$date}|{$groupes[0]['taux']}"] ?? 0) + $montant;

                continue;
            }

            if (abs($montant) === (int) array_sum(array_column($groupes, 'ttc'))) {
                foreach ($services as $service) {
                    $parTaux["{$date}|{$service['taux']}"] = ($parTaux["{$date}|{$service['taux']}"] ?? 0) + ($montant <=> 0) * $service['ttc'];
                }

                continue;
            }

            $aRepartir[$date] = ($aRepartir[$date] ?? 0) + $montant;
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
     * Les règlements que signent ces enregistrements, dans leur ordre, en
     * centimes : ce qui règle un ticket ou une facture à son émission
     * (paiements de ses parts et crédits d'avoir d'avoirs plus anciens), en
     * un seul règlement daté du jour de l'émission ; les imputations d'un
     * avoir, datées du jour de son émission ; puis ce qu'imputent les
     * autres paiements et les contre-passations, chacun daté du jour de sa
     * réception, et le crédit d'avoir qu'ils imputent ou rendent, daté du
     * jour de leur enregistrement. Un crédit d'avoir garde son avoir, et le
     * paiement qui le signe (null à l'émission de l'avoir).
     *
     * @param  Collection<int, EnregistrementFiscal>  $ventes
     * @param  Collection<int, EnregistrementFiscal>  $encaissements
     * @param  Calendrier  $calendrier  celui du magasin de la clôture, qui date chaque enregistrement
     * @return list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null}>
     */
    private function reglements(Collection $ventes, Collection $encaissements, Calendrier $calendrier): array
    {
        $reglements = [];
        $paiementsALEmission = [];

        foreach ($ventes as $vente) {
            $contexte = ValeursLues::tableauDe($vente->donneesSignees(), 'contexte');

            if ($vente->type === 'avoir') {
                foreach (ValeursLues::tableauDe($contexte, 'imputations') as $imputation) {
                    $imputation = ValeursLues::tableau($imputation);
                    $reglements[] = self::reglement(ValeurSimple::enEntier($imputation['facture_id']), $calendrier->jourDe($vente->horodatage), ValeurSimple::enEntier($imputation['montant']), (int) $vente->document_id, null);
                }

                continue;
            }

            $reglementsALEmission = ValeursLues::tableauDe($contexte, 'reglements');
            $paiementsALEmission = [...$paiementsALEmission, ...array_map(ValeurSimple::enEntier(...), array_column($reglementsALEmission, 'paiement_id'))];
            $montant = (int) array_sum(array_column($reglementsALEmission, 'montant')) + (int) array_sum(array_column(ValeursLues::tableauDe($contexte, 'credits_avoir'), 'montant'));

            if ($montant !== 0) {
                $reglements[] = self::reglement((int) $vente->document_id, $calendrier->jourDe($vente->horodatage), $montant, null, null);
            }
        }

        foreach ($encaissements as $encaissement) {
            if (in_array((int) $encaissement->document_id, $paiementsALEmission, true)) {
                continue;
            }

            $donnees = $encaissement->donneesSignees();
            $recuLe = ValeursLues::texteDe(ValeursLues::tableauDe($donnees, 'paiement'), 'recu_le');

            foreach (ValeursLues::tableauDe($donnees, 'allocations') as $allocation) {
                $allocation = ValeursLues::tableau($allocation);
                $reglements[] = self::reglement(ValeurSimple::enEntier($allocation['facture_id']), $recuLe, ValeurSimple::enEntier($allocation['montant']), null, null);
            }

            foreach (ValeursLues::tableauDe($donnees, 'credits_imputes') as $credit) {
                $credit = ValeursLues::tableau($credit);
                $reglements[] = self::reglement(ValeurSimple::enEntier($credit['facture_id']), $calendrier->jourDe($encaissement->horodatage), ValeurSimple::enEntier($credit['montant']), ValeurSimple::enEntier($credit['avoir_id']), (int) $encaissement->document_id);
            }
        }

        return $reglements;
    }

    /**
     * @return array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null}
     */
    private static function reglement(int $factureId, string $date, int $montant, ?int $avoirId, ?int $paiementId): array
    {
        return ['facture_id' => $factureId, 'date' => $date, 'montant' => $montant, 'avoir_id' => $avoirId, 'paiement_id' => $paiementId];
    }

    /**
     * Les règlements, chaque crédit qu'un avoir impute sur un ticket ou une
     * facture qu'il corrige diminué de la part qui annule les lignes de cet
     * avoir sur ce document (voir reductions()) : cette part réduit la
     * vente, elle ne la règle pas. Le reste du crédit, venu des autres
     * lignes de l'avoir, règle le document comme tout crédit d'avoir
     * (Q13). Un ticket dont la vente est réduite de cette part encaisse
     * ainsi, une fois soldé, exactement son net vendu.
     *
     * @param  list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null}>  $reglements
     * @return list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null}>
     */
    private function sansLesReductions(array $reglements): array
    {
        $corrections = $this->corrections(array_values(array_unique(array_filter(array_column($reglements, 'avoir_id')))));

        if ($corrections === []) {
            return $reglements;
        }

        $reductions = $this->reductions($corrections);

        foreach ($reglements as $indice => $reglement) {
            if ($reglement['avoir_id'] === null || ! isset($corrections[$reglement['avoir_id']][$reglement['facture_id']])) {
                continue;
            }

            $cle = self::cleDImputation($reglement['avoir_id'], $reglement['facture_id'], $reglement['paiement_id']);
            $reductions[$cle] ??= [];
            $reglements[$indice]['montant'] -= array_shift($reductions[$cle]) ?? 0;
        }

        return $reglements;
    }

    /**
     * Ce que chacun de ces avoirs corrige de chaque ticket ou facture, en
     * centimes : le TTC de ses lignes sur ce document, comme le compte
     * ImputationDuCredit, par avoir puis par document.
     *
     * @param  list<int>  $avoirs
     * @return array<int, array<int, int>>
     */
    private function corrections(array $avoirs): array
    {
        if ($avoirs === []) {
            return [];
        }

        $corrections = [];

        foreach (LigneAvoir::query()->whereIn('avoir_id', $avoirs)->with('ligneFacture')->get() as $ligne) {
            $avoirId = (int) $ligne->avoir_id;
            $factureId = (int) ValeurRequise::de($ligne->ligneFacture, 'la ligne de facture que corrige la ligne d\'avoir')->facture_id;
            $corrections[$avoirId][$factureId] = ($corrections[$avoirId][$factureId] ?? 0) + $this->calculateur->enCentiemes($ligne->montant_ttc);
        }

        return $corrections;
    }

    /**
     * La part de chaque imputation de ces avoirs sur un document qu'ils
     * corrigent qui annule leurs lignes sur ce document, en centimes, dans
     * l'ordre où les imputations ont été faites (toutes sous le verrou du
     * registre du magasin, donc dans l'ordre du registre) : ce qui reste de
     * la correction (le TTC des lignes de l'avoir sur ce document, moins ce
     * que les imputations précédentes du même avoir sur ce document en ont
     * déjà annulé), au plus le montant imputé. L'imputation négative par
     * laquelle une contre-passation rend un crédit rend la part
     * qu'annulait l'imputation correspondante du paiement contre-passé,
     * dans le même ordre (voir ContrePasserPaiementAction). Rangées par
     * avoir, document et paiement qui signe l'imputation (0 pour
     * l'émission de l'avoir).
     *
     * @param  array<int, array<int, int>>  $corrections
     * @return array<string, list<int>>
     */
    private function reductions(array $corrections): array
    {
        $imputations = AvoirAllocation::query()
            ->whereIn('avoir_id', array_keys($corrections))
            ->with('paiement')
            ->orderBy('id')
            ->get();
        $dejaAnnule = [];
        $reductionsDesPaiements = [];
        $reductions = [];

        foreach ($imputations as $imputation) {
            $avoirId = (int) $imputation->avoir_id;
            $factureId = (int) $imputation->facture_id;

            if (! isset($corrections[$avoirId][$factureId])) {
                continue;
            }

            $paiementId = $imputation->paiement_id === null ? null : (int) $imputation->paiement_id;
            $cle = self::cleDImputation($avoirId, $factureId, $paiementId);
            $montant = $this->calculateur->enCentiemes($imputation->montant_alloue);

            if ($montant >= 0) {
                $reduction = min($montant, max(0, $corrections[$avoirId][$factureId] - ($dejaAnnule["{$avoirId}|{$factureId}"] ?? 0)));

                if ($paiementId !== null) {
                    $reductionsDesPaiements[$cle][] = $reduction;
                }
            } else {
                $cleDOrigine = self::cleDImputation($avoirId, $factureId, (int) $imputation->paiement?->contre_passe_paiement_id);
                $reductionsDesPaiements[$cleDOrigine] ??= [];
                $reduction = -(array_shift($reductionsDesPaiements[$cleDOrigine]) ?? 0);
            }

            $dejaAnnule["{$avoirId}|{$factureId}"] = ($dejaAnnule["{$avoirId}|{$factureId}"] ?? 0) + $reduction;
            $reductions[$cle][] = $reduction;
        }

        return $reductions;
    }

    private static function cleDImputation(int $avoirId, int $factureId, ?int $paiementId): string
    {
        return "{$avoirId}|{$factureId}|".($paiementId ?? 0);
    }

    /**
     * Les lignes du ticket par nature et par taux, avec leur TTC
     * enregistré, en centimes.
     *
     * @return list<array{nature: NatureLigne, taux: int, ttc: int}>
     */
    private function groupes(Facture $ticket): array
    {
        return array_values($ticket->lignes
            ->groupBy(fn (LigneFacture $ligne): string => $ligne->nature->value.'|'.$this->calculateur->enCentiemes($ligne->taux_tva))
            ->map(fn (Collection $lignes): array => [
                'nature' => ValeurRequise::de($lignes->first(), 'la première ligne d\'un groupe')->nature,
                'taux' => $this->calculateur->enCentiemes(ValeurRequise::de($lignes->first(), 'la première ligne d\'un groupe')->taux_tva),
                'ttc' => (int) $lignes->sum(fn (LigneFacture $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ttc)),
            ])
            ->all());
    }
}
