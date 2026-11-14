<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\CategorieEReporting;
use App\Domain\Facturation\Enums\MotifDeRegularisation;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * livraison de biens ou une prestation de services, selon sa nature ; une
 * vente en franchise en base, comme une vente en France à 0 % hors
 * franchise, se range de même selon la nature de sa ligne, au taux 0 %,
 * avec son motif (règles T2.5 de l'onglet « À faire vérifier par un
 * professionnel ») ; un export ou une livraison vers un DOM à un
 * particulier se range parmi les opérations non soumises à la TVA en
 * France, au taux 0 %, avec sa nature d'opération pour motif (T108.1,
 * T108.2).
 *
 * Les encaissements des prestations de services (la TVA d'un service est
 * due à l'encaissement), sans option pour les débits, se déclarent par
 * jour d'encaissement et par taux, pour leur montant TTC (T1.1, T1.2) :
 * chaque paiement d'un mode à sa date de réception, contre-passations
 * déduites, et chaque crédit d'avoir à la date où il est utilisé (Q13,
 * T107.2). Seule la part d'un crédit qui annule les lignes que son avoir
 * corrige sur ce ticket n'est pas un règlement : elle réduit la vente
 * (voir sansLesReductions()). D'une vente qui mêle biens et services, ou
 * plusieurs taux de services, chaque règlement se répartit au prorata du
 * TTC de chaque groupe restant après les avoirs, en cumul, si bien que le
 * dernier règlement retombe exactement sur le TTC de chaque service ;
 * seule la part des services se déclare (T1.3, T1.4, T9.3, T107.1) ; le
 * crédit d'un avoir qui s'impute sur le ticket qu'il corrige change ses
 * groupes le jour de son émission, et ce qu'il a encaissé se répartit de
 * nouveau (C-1) ; l'avoir d'une prestation déjà encaissée dont le crédit
 * reste au client se déduit le jour où ce crédit règle une autre vente
 * (T1.3) : voir EncaissementsDesPrestations. Une vente émise sous
 * l'option pour les débits, que son émetteur signé garde (voir Emetteur),
 * ne déclare aucun encaissement de ses services (T112.1), quoi que la
 * société choisisse ensuite : l'aperçu d'une journée passée ne change
 * jamais avec ce réglage. Les paiements des services d'un magasin en
 * franchise en base, vendus à 0 %, partiront à l'étape 2, tous les deux
 * mois (art. 242 nonies P de l'annexe II du CGI) : l'aperçu ne les compte
 * pas.
 *
 * Ils se lisent, comme ceux du récapitulatif de la clôture (voir
 * RecapitulatifDeCloture), dans les enregistrements du registre fiscal :
 * ceux qui suivent la clôture précédente, jusqu'à la sienne, sont les
 * mouvements de la clôture, chaque règlement comptant dans une seule
 * clôture ; ceux d'avant, des mêmes tickets, disent ce que chacun a déjà
 * encaissé et déclaré.
 *
 * Le magasin de démonstration n'est jamais déclaré. Toutes les lectures
 * restent dans le magasin actif (MagasinScope), celui de la clôture ; le
 * registre fiscal se lit par MagasinScope, et par le magasin de chaque
 * clôture dans journeesADeclarer().
 */
class EReportingDesParticuliers
{
    public const MOTIF_FRANCHISE = 'Franchise en base de TVA ('.RegimeTva::ARTICLE_FRANCHISE.')';

    public const MOTIF_TAUX_ZERO = 'Vente en France à 0 %';

    public const MENTION_ENCAISSEMENTS = 'Encaissements des prestations de services payées par des particuliers, déclarés par jour et par taux de TVA. Un crédit d\'avoir compte à la date où il est utilisé, sauf la part qui annule les lignes que son avoir corrige sur ce ticket ; l\'avoir d\'une prestation déjà encaissée se déduit à la date où son crédit règle une autre vente.';

    public const MENTION_DEMONSTRATION = 'Magasin de démonstration : jamais déclaré.';

    public const STATUT = 'Non transmis : plateforme pas encore raccordée (étape 2).';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function apercu(ClotureCaisse $cloture): ApercuEReporting
    {
        $journee = $cloture->date_cloture;

        if ($cloture->magasin->est_demonstration) {
            return new ApercuEReporting($journee, true, [], []);
        }

        return new ApercuEReporting($journee, false, $this->transactions($cloture), $this->encaissements($cloture));
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
     * magasin qui change ce qu'a encaissé un ticket déclaré qui vend un
     * service avec la TVA française : un paiement ou une contre-passation,
     * dans la chaîne des encaissements, par ce qu'il impute ou par le
     * crédit d'avoir qu'il impute, ou par le crédit qu'il impute d'un avoir
     * qui corrige un tel ticket (son utilisation pour une autre vente
     * déduit la prestation annulée) ; un avoir, dans la chaîne des ventes,
     * par le crédit qu'il impute à son émission sur un tel ticket, ou
     * ailleurs quand il en corrige un.
     *
     * @param  Builder<ClotureCaisse>  $clotures
     * @return Builder<ClotureCaisse>
     */
    private static function quiComptentUnReglementDeService(Builder $clotures): Builder
    {
        $ticketsAvecUnService = self::avecUnService(self::declares(Facture::query()))->select('factures.id');
        $avoirsQuiLesCorrigent = LigneAvoir::query()
            ->whereHas('ligneFacture', fn (Builder $lignes): Builder => $lignes->whereIn('facture_id', $ticketsAvecUnService))
            ->select('avoir_id');

        return $clotures
            ->whereExists(fn (QueryBuilder $enregistrements): QueryBuilder => self::enregistrementsDeLaCloture($enregistrements, ChaineFiscale::Encaissement, 'derniere_sequence_encaissement')
                ->where('enregistrements_fiscaux.document_type', (new Paiement)->getMorphClass())
                ->where(fn (QueryBuilder $paiements): QueryBuilder => $paiements
                    ->whereIn('enregistrements_fiscaux.document_id', PaiementAllocation::query()->whereIn('facture_id', $ticketsAvecUnService)->select('paiement_id'))
                    ->orWhereIn('enregistrements_fiscaux.document_id', AvoirAllocation::query()
                        ->whereNotNull('paiement_id')
                        ->where(fn (Builder $imputations): Builder => $imputations->whereIn('facture_id', $ticketsAvecUnService)->orWhereIn('avoir_id', $avoirsQuiLesCorrigent))
                        ->select('paiement_id'))))
            ->orWhereExists(fn (QueryBuilder $enregistrements): QueryBuilder => self::enregistrementsDeLaCloture($enregistrements, ChaineFiscale::Vente, 'derniere_sequence_vente')
                ->where('enregistrements_fiscaux.document_type', (new Avoir)->getMorphClass())
                ->whereIn('enregistrements_fiscaux.document_id', AvoirAllocation::query()
                    ->whereNull('paiement_id')
                    ->where(fn (Builder $imputations): Builder => $imputations->whereIn('facture_id', $ticketsAvecUnService)->orWhereIn('avoir_id', $avoirsQuiLesCorrigent))
                    ->select('avoir_id')));
    }

    /**
     * Les tickets qui vendent un service avec la TVA française, à un taux
     * non nul : les seuls dont l'aperçu déclare des encaissements.
     *
     * @param  Builder<Facture>  $tickets
     * @return Builder<Facture>
     */
    private static function avecUnService(Builder $tickets): Builder
    {
        return $tickets->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->where('nature', NatureLigne::Service->value)->where('taux_tva', '>', 0));
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
        return $enregistrements
            ->from('enregistrements_fiscaux')
            ->join('enregistrements_fiscaux as signee', fn (JoinClause $signee): JoinClause => self::evenementDeCloture($signee
                ->on('signee.magasin_id', '=', 'clotures_caisse.magasin_id')
                ->where('signee.document_type', (new ClotureCaisse)->getMorphClass())
                ->on('signee.document_id', '=', 'clotures_caisse.id'), 'signee'))
            ->whereColumn('enregistrements_fiscaux.magasin_id', 'clotures_caisse.magasin_id')
            ->where('enregistrements_fiscaux.chaine', $chaine->value)
            ->whereRaw('enregistrements_fiscaux.sequence <= '.self::sequenceSignee('signee', $derniereSequence))
            ->where('enregistrements_fiscaux.sequence', '>', fn (QueryBuilder $precedente): QueryBuilder => self::evenementDeCloture($precedente
                ->from('enregistrements_fiscaux')
                ->selectRaw('coalesce('.self::sequenceSignee('enregistrements_fiscaux', $derniereSequence).', 0)')
                ->whereColumn('enregistrements_fiscaux.magasin_id', 'signee.magasin_id')
                ->whereColumn('enregistrements_fiscaux.sequence', '<', 'signee.sequence'), 'enregistrements_fiscaux')
                ->orderByDesc('enregistrements_fiscaux.sequence')
                ->limit(1));
    }

    /**
     * Les événements de clôture du journal des événements (événement 50)
     * parmi ces enregistrements, lus sous cet alias.
     *
     * @template TRequete of QueryBuilder
     *
     * @param  TRequete  $evenements
     * @return TRequete
     */
    private static function evenementDeCloture(QueryBuilder $evenements, string $alias): QueryBuilder
    {
        $evenements->where("{$alias}.chaine", ChaineFiscale::Evenement->value)
            ->where("{$alias}.type", (string) CodeEvenement::CloturePeriode->value);

        return $evenements;
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
     * @return literal-string
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
     * @return list<array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null}>
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
     * le document : une vente en franchise en base, ou en France à 0 %, se
     * range selon la nature de sa ligne, avec son motif ; une nature
     * d'opération hors de la TVA française (export, livraison vers un DOM)
     * parmi les opérations non soumises à la TVA en France ; une vente en
     * France à son taux, selon la nature de sa ligne.
     *
     * @return array{categorie: CategorieEReporting, taux: int, ht: int, tva: int, motif: string|null}
     */
    private function transaction(Facture|Avoir $document, LigneFacture|LigneAvoir $ligne, int $sens): array
    {
        $taux = $this->calculateur->enCentiemes($ligne->taux_tva);
        [$categorie, $motif] = match (true) {
            $document->franchise_en_base => [CategorieEReporting::deLaNature($ligne->nature), self::MOTIF_FRANCHISE],
            $document->nature_operation->exonere() => [CategorieEReporting::NonSoumisesALaTvaEnFrance, $document->nature_operation->getLabel()],
            $taux === 0 => [CategorieEReporting::deLaNature($ligne->nature), self::MOTIF_TAUX_ZERO],
            default => [CategorieEReporting::deLaNature($ligne->nature), null],
        };

        return [
            'categorie' => $categorie,
            'taux' => $taux,
            'ht' => $sens * $this->calculateur->enCentiemes($ligne->montant_ht),
            'tva' => $sens * $this->calculateur->enCentiemes($ligne->montant_tva),
            'motif' => $motif,
        ];
    }

    /**
     * Les encaissements des prestations de services que la clôture compte,
     * par date et par taux (voir le commentaire de la classe et
     * EncaissementsDesPrestations) : les tickets suivis sont les tickets
     * déclarés qui vendent un service, réglés par un mouvement de la
     * clôture ou corrigés par un avoir dont un mouvement de la clôture
     * utilise le crédit ; leur historique se rejoue dans l'ordre du
     * registre, jusqu'à la fin de la clôture.
     *
     * @return list<array{date: string, taux: int, montant: int}>
     */
    private function encaissements(ClotureCaisse $cloture): array
    {
        $fenetre = self::fenetreSignee($cloture);
        $calendrier = $cloture->magasin->calendrier();
        $suivis = $this->ticketsSuivis($this->mouvements(self::enregistrementsDeLaFenetre($fenetre), $fenetre, $calendrier));

        if ($suivis->isEmpty()) {
            return [];
        }

        $historique = self::historique(ValeursLues::entiers($suivis->modelKeys()), $fenetre);
        $sousLOption = self::emisSousLOptionPourLesDebits($historique);
        $mouvements = $this->sansLesReductions($this->mouvements($historique, $fenetre, $calendrier));
        $avoirs = array_values(array_unique(array_filter(array_column($mouvements, 'avoir_id'))));
        $encaissements = new EncaissementsDesPrestations(
            $this->calculateur,
            $suivis->reject(fn (Facture $ticket): bool => in_array($ticket->getKey(), $sousLOption, true))->map(fn (Facture $ticket): array => $this->groupes($ticket))->all(),
            $this->lignesDesAvoirs($avoirs),
            ValeursLues::entiers(Avoir::query()->whereKey($avoirs)->where('motif_regularisation', MotifDeRegularisation::SirenCorrige)->pluck('id')),
        );

        foreach ($mouvements as $mouvement) {
            $encaissements->reglement($mouvement['facture_id'], $mouvement['date'], $mouvement['montant'], $mouvement['reduction'], $mouvement['avoir_id'], $mouvement['compte']);
        }

        return $encaissements->declares();
    }

    /**
     * Les ventes de ces enregistrements que leur émetteur signé a émises
     * sous l'option pour les débits (voir Emetteur et FigeSonEmetteur) :
     * leurs services ne déclarent aucun encaissement (T112.1), quoi que la
     * société ait choisi depuis, si bien que l'aperçu d'une journée passée
     * ne change jamais avec ce réglage.
     *
     * @param  Collection<int, EnregistrementFiscal>  $enregistrements
     * @return list<int>
     */
    private static function emisSousLOptionPourLesDebits(Collection $enregistrements): array
    {
        return array_values($enregistrements
            ->filter(fn (EnregistrementFiscal $enregistrement): bool => $enregistrement->chaine === ChaineFiscale::Vente
                && $enregistrement->type !== 'avoir'
                && Emetteur::duContenuSigne(ValeursLues::tableauDe(ValeursLues::tableauDe($enregistrement->donneesSignees(), 'contexte'), 'emetteur'))->optionDebitsTva)
            ->map(fn (EnregistrementFiscal $vente): int => (int) $vente->document_id)
            ->all());
    }

    /**
     * Les tickets déclarés qui vendent un service, avec leurs lignes, que
     * touchent ces mouvements de la clôture : ceux qu'ils règlent, et ceux
     * que corrigent les avoirs dont ils utilisent le crédit.
     *
     * @param  list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null, compte: bool}>  $mouvements
     * @return EloquentCollection<int, Facture>
     */
    private function ticketsSuivis(array $mouvements): EloquentCollection
    {
        $avoirs = array_values(array_unique(array_filter(array_column($mouvements, 'avoir_id'))));
        $factures = array_column($mouvements, 'facture_id');

        if ($factures === []) {
            return new EloquentCollection;
        }

        return self::avecUnService(self::declares(Facture::query()))
            ->where(fn (Builder $tickets): Builder => $tickets
                ->whereKey(array_values(array_unique($factures)))
                ->orWhereIn('factures.id', LigneFacture::query()
                    ->whereIn('id', LigneAvoir::query()->whereIn('avoir_id', $avoirs)->select('ligne_facture_id'))
                    ->select('facture_id')))
            ->with('lignes')
            ->get()
            ->keyBy('id');
    }

    /**
     * Les enregistrements du registre du magasin actif que la clôture
     * compte, dans ses deux chaînes, dans l'ordre du registre.
     *
     * @param  array{vente: array{0: int|null, 1: int|null}, encaissement: array{0: int|null, 1: int|null}}  $fenetre
     * @return Collection<int, EnregistrementFiscal>
     */
    private static function enregistrementsDeLaFenetre(array $fenetre): Collection
    {
        if (! self::compteQuelqueChose($fenetre['vente']) && ! self::compteQuelqueChose($fenetre['encaissement'])) {
            return new Collection;
        }

        return EnregistrementFiscal::query()
            ->where(fn (Builder $enregistrements): Builder => $enregistrements
                ->where(fn (Builder $ventes): Builder => self::dansLaFenetre($ventes, ChaineFiscale::Vente, $fenetre['vente']))
                ->orWhere(fn (Builder $encaissements): Builder => self::dansLaFenetre($encaissements, ChaineFiscale::Encaissement, $fenetre['encaissement'])))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * Les enregistrements du registre du magasin actif, jusqu'à la fin de
     * la clôture, qui changent ce qu'ont encaissé ces tickets : leur
     * émission, celle des avoirs qui les corrigent ou leur imputent un
     * crédit, les paiements et contre-passations qui les règlent ou
     * imputent le crédit d'un de ces avoirs, et l'émission des ventes
     * réglées par ce crédit ; dans l'ordre du registre, que suivent ses
     * deux chaînes, écrites sous le même verrou.
     *
     * @param  list<int>  $tickets
     * @param  array{vente: array{0: int|null, 1: int|null}, encaissement: array{0: int|null, 1: int|null}}  $fenetre
     * @return Collection<int, EnregistrementFiscal>
     */
    private static function historique(array $tickets, array $fenetre): Collection
    {
        $avoirsQuiCorrigent = LigneAvoir::query()
            ->whereIn('ligne_facture_id', LigneFacture::query()->whereIn('facture_id', $tickets)->select('id'))
            ->distinct()
            ->pluck('avoir_id')
            ->all();
        $avoirs = [...$avoirsQuiCorrigent, ...AvoirAllocation::query()->whereIn('facture_id', $tickets)->pluck('avoir_id')->all()];
        $paiements = [
            ...PaiementAllocation::query()->whereIn('facture_id', $tickets)->pluck('paiement_id')->all(),
            ...AvoirAllocation::query()
                ->whereNotNull('paiement_id')
                ->where(fn (Builder $imputations): Builder => $imputations->whereIn('facture_id', $tickets)->orWhereIn('avoir_id', $avoirsQuiCorrigent))
                ->pluck('paiement_id')
                ->all(),
        ];
        $factures = [...$tickets, ...AvoirAllocation::query()->whereNull('paiement_id')->whereIn('avoir_id', $avoirsQuiCorrigent)->pluck('facture_id')->all()];
        $jusquALaCloture = static fn (Builder $enregistrements, ChaineFiscale $chaine, ?int $jusquA): Builder => $enregistrements
            ->where('chaine', $chaine->value)
            ->where('sequence', '<=', $jusquA ?? 0);

        return EnregistrementFiscal::query()
            ->where(fn (Builder $enregistrements): Builder => $enregistrements
                ->where(fn (Builder $ventes): Builder => $jusquALaCloture($ventes, ChaineFiscale::Vente, $fenetre['vente'][1])
                    ->where(fn (Builder $documents): Builder => $documents
                        ->where(fn (Builder $emissions): Builder => $emissions->where('document_type', (new Facture)->getMorphClass())->whereIn('document_id', array_values(array_unique(ValeursLues::entiers($factures)))))
                        ->orWhere(fn (Builder $emissions): Builder => $emissions->where('document_type', (new Avoir)->getMorphClass())->whereIn('document_id', array_values(array_unique(ValeursLues::entiers($avoirs)))))))
                ->orWhere(fn (Builder $encaissements): Builder => $jusquALaCloture($encaissements, ChaineFiscale::Encaissement, $fenetre['encaissement'][1])
                    ->where('document_type', (new Paiement)->getMorphClass())
                    ->whereIn('document_id', array_values(array_unique(ValeursLues::entiers($paiements))))))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * La clôture compte-t-elle un enregistrement de cette chaîne ? Pas
     * quand sa fenêtre est vide : aucun enregistrement après la clôture
     * précédente.
     *
     * @param  array{0: int|null, 1: int|null}  $fenetre
     */
    private static function compteQuelqueChose(array $fenetre): bool
    {
        return $fenetre[1] !== null && $fenetre[1] !== $fenetre[0];
    }

    /**
     * Les enregistrements de cette chaîne dans sa fenêtre : après la
     * dernière séquence de la clôture précédente, jusqu'à la sienne ;
     * aucun quand elle n'en compte aucun.
     *
     * @param  Builder<EnregistrementFiscal>  $enregistrements
     * @param  array{0: int|null, 1: int|null}  $fenetre
     * @return Builder<EnregistrementFiscal>
     */
    private static function dansLaFenetre(Builder $enregistrements, ChaineFiscale $chaine, array $fenetre): Builder
    {
        [$apres, $jusquA] = $fenetre;

        if (! self::compteQuelqueChose($fenetre)) {
            return $enregistrements->whereRaw('1 = 0');
        }

        return $enregistrements
            ->where('chaine', $chaine->value)
            ->when($apres !== null, fn (Builder $suivants): Builder => $suivants->where('sequence', '>', $apres))
            ->where('sequence', '<=', $jusquA);
    }

    /**
     * La clôture compte-t-elle cet enregistrement ?
     *
     * @param  array{vente: array{0: int|null, 1: int|null}, encaissement: array{0: int|null, 1: int|null}}  $fenetre
     */
    private static function estCompte(EnregistrementFiscal $enregistrement, array $fenetre): bool
    {
        [$apres, $jusquA] = $fenetre[$enregistrement->chaine === ChaineFiscale::Vente ? 'vente' : 'encaissement'];

        return $jusquA !== null && $enregistrement->sequence <= $jusquA && ($apres === null || $enregistrement->sequence > $apres);
    }

    /**
     * Les mouvements que signent ces enregistrements, dans leur ordre, en
     * centimes : l'émission d'une vente la règle de ses paiements (en un
     * seul règlement daté du jour de l'émission) puis de chaque crédit
     * d'avoir plus ancien ; l'émission d'un avoir, de ses imputations,
     * datées du jour de son émission ; un paiement ou une contre-passation
     * règle à la date de sa réception, et le crédit d'avoir qu'il impute ou
     * rend est daté du jour de son enregistrement. Un paiement signé dans
     * le contexte de l'émission de sa vente, et écrit juste avant elle, ne
     * se compte qu'une fois. Un crédit d'avoir garde son avoir, et le
     * paiement qui le signe (null à l'émission d'une vente ou de l'avoir) ;
     * chaque mouvement dit si la clôture le compte.
     *
     * @param  Collection<int, EnregistrementFiscal>  $enregistrements  dans l'ordre du registre
     * @param  array{vente: array{0: int|null, 1: int|null}, encaissement: array{0: int|null, 1: int|null}}  $fenetre
     * @param  Calendrier  $calendrier  celui du magasin de la clôture, qui date chaque enregistrement
     * @return list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null, compte: bool}>
     */
    private function mouvements(Collection $enregistrements, array $fenetre, Calendrier $calendrier): array
    {
        $mouvements = [];
        // Les paiements d'une vente à son émission, que l'enregistrement de
        // la vente signe dans son contexte : écrits avant elle, dans la même
        // transaction, ils ne se comptent qu'une fois, avec elle.
        $paiementsALEmission = $enregistrements
            ->filter(fn (EnregistrementFiscal $enregistrement): bool => $enregistrement->chaine === ChaineFiscale::Vente && $enregistrement->type !== 'avoir')
            ->flatMap(fn (EnregistrementFiscal $vente): array => array_map(ValeurSimple::enEntier(...), array_column(ValeursLues::tableauDe(ValeursLues::tableauDe($vente->donneesSignees(), 'contexte'), 'reglements'), 'paiement_id')))
            ->all();

        foreach ($enregistrements as $enregistrement) {
            $compte = self::estCompte($enregistrement, $fenetre);
            $jour = $calendrier->jourDe($enregistrement->horodatage);

            if ($enregistrement->chaine === ChaineFiscale::Vente) {
                $contexte = ValeursLues::tableauDe($enregistrement->donneesSignees(), 'contexte');

                if ($enregistrement->type === 'avoir') {
                    foreach (ValeursLues::tableauDe($contexte, 'imputations') as $imputation) {
                        $imputation = ValeursLues::tableau($imputation);
                        $mouvements[] = self::mouvement(ValeurSimple::enEntier($imputation['facture_id']), $jour, ValeurSimple::enEntier($imputation['montant']), (int) $enregistrement->document_id, null, $compte);
                    }

                    continue;
                }

                $montant = (int) array_sum(array_column(ValeursLues::tableauDe($contexte, 'reglements'), 'montant'));

                if ($montant !== 0) {
                    $mouvements[] = self::mouvement((int) $enregistrement->document_id, $jour, $montant, null, null, $compte);
                }

                foreach (ValeursLues::tableauDe($contexte, 'credits_avoir') as $credit) {
                    $credit = ValeursLues::tableau($credit);
                    $mouvements[] = self::mouvement((int) $enregistrement->document_id, $jour, ValeurSimple::enEntier($credit['montant']), ValeurSimple::enEntier($credit['avoir_id']), null, $compte);
                }

                continue;
            }

            if (in_array((int) $enregistrement->document_id, $paiementsALEmission, true)) {
                continue;
            }

            $donnees = $enregistrement->donneesSignees();
            $recuLe = ValeursLues::texteDe(ValeursLues::tableauDe($donnees, 'paiement'), 'recu_le');

            foreach (ValeursLues::tableauDe($donnees, 'allocations') as $allocation) {
                $allocation = ValeursLues::tableau($allocation);
                $mouvements[] = self::mouvement(ValeurSimple::enEntier($allocation['facture_id']), $recuLe, ValeurSimple::enEntier($allocation['montant']), null, null, $compte);
            }

            foreach (ValeursLues::tableauDe($donnees, 'credits_imputes') as $credit) {
                $credit = ValeursLues::tableau($credit);
                $mouvements[] = self::mouvement(ValeurSimple::enEntier($credit['facture_id']), $jour, ValeurSimple::enEntier($credit['montant']), ValeurSimple::enEntier($credit['avoir_id']), (int) $enregistrement->document_id, $compte);
            }
        }

        return $mouvements;
    }

    /**
     * @return array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null, compte: bool}
     */
    private static function mouvement(int $factureId, string $date, int $montant, ?int $avoirId, ?int $paiementId, bool $compte): array
    {
        return ['facture_id' => $factureId, 'date' => $date, 'montant' => $montant, 'avoir_id' => $avoirId, 'paiement_id' => $paiementId, 'compte' => $compte];
    }

    /**
     * Les mouvements, chaque crédit qu'un avoir impute sur un ticket ou
     * une facture qu'il corrige diminué de la part qui annule les lignes
     * de cet avoir sur ce document, gardée à part (voir reductions()) :
     * cette part réduit la vente, elle ne la règle pas. Le reste du crédit,
     * venu des autres lignes de l'avoir, règle le document comme tout
     * crédit d'avoir (Q13). Un ticket dont la vente est réduite de cette
     * part encaisse ainsi, une fois soldé, exactement son net vendu.
     *
     * @param  list<array{facture_id: int, date: string, montant: int, avoir_id: int|null, paiement_id: int|null, compte: bool}>  $mouvements
     * @return list<array{facture_id: int, date: string, montant: int, reduction: int, avoir_id: int|null, paiement_id: int|null, compte: bool}>
     */
    private function sansLesReductions(array $mouvements): array
    {
        $corrections = array_map(
            fn (array $parDocument): array => array_map(fn (array $parGroupe): int => (int) array_sum($parGroupe), $parDocument),
            $this->lignesDesAvoirs(array_values(array_unique(array_filter(array_column($mouvements, 'avoir_id'))))),
        );
        $reductions = $this->reductions($corrections);

        return array_map(function (array $mouvement) use ($corrections, &$reductions): array {
            $reduction = 0;

            if ($mouvement['avoir_id'] !== null && isset($corrections[$mouvement['avoir_id']][$mouvement['facture_id']])) {
                $cle = self::cleDImputation($mouvement['avoir_id'], $mouvement['facture_id'], $mouvement['paiement_id']);
                $reductions[$cle] ??= [];
                $reduction = array_shift($reductions[$cle]) ?? 0;
            }

            return [...$mouvement, 'montant' => $mouvement['montant'] - $reduction, 'reduction' => $reduction];
        }, $mouvements);
    }

    /**
     * Ce que chacun de ces avoirs corrige de chaque ticket ou facture, en
     * centimes, par groupe de lignes de même nature et de même taux (voir
     * groupes()) : le TTC de ses lignes, comme le compte ImputationDuCredit,
     * par avoir puis par document, dans l'ordre de leur identifiant.
     *
     * @param  list<int>  $avoirs
     * @return array<int, array<int, array<string, int>>>
     */
    private function lignesDesAvoirs(array $avoirs): array
    {
        if ($avoirs === []) {
            return [];
        }

        $lignes = [];

        foreach (LigneAvoir::query()->whereIn('avoir_id', $avoirs)->with('ligneFacture')->get() as $ligne) {
            $avoirId = (int) $ligne->avoir_id;
            $factureId = (int) ValeurRequise::de($ligne->ligneFacture, 'la ligne de facture que corrige la ligne d\'avoir')->facture_id;
            $cle = self::cleDeGroupe($ligne->nature, $this->calculateur->enCentiemes($ligne->taux_tva));
            $lignes[$avoirId][$factureId][$cle] = ($lignes[$avoirId][$factureId][$cle] ?? 0) + $this->calculateur->enCentiemes($ligne->montant_ttc);
        }

        foreach ($lignes as $avoirId => $parDocument) {
            ksort($parDocument);
            $lignes[$avoirId] = $parDocument;
        }

        return $lignes;
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
     * l'émission de l'avoir ou d'une vente).
     *
     * @param  array<int, array<int, int>>  $corrections
     * @return array<string, list<int>>
     */
    private function reductions(array $corrections): array
    {
        if ($corrections === []) {
            return [];
        }

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
     * enregistré, en centimes, du plus fort taux au plus faible, les biens
     * avant les services d'un même taux : l'ordre de la répartition d'un
     * règlement (voir EncaissementsDesPrestations).
     *
     * @return array<string, array{nature: NatureLigne, taux: int, ttc: int}>
     */
    private function groupes(Facture $ticket): array
    {
        $groupes = [];

        foreach ($ticket->lignes as $ligne) {
            $taux = $this->calculateur->enCentiemes($ligne->taux_tva);
            $cle = self::cleDeGroupe($ligne->nature, $taux);
            $groupes[$cle] = [
                'nature' => $ligne->nature,
                'taux' => $taux,
                'ttc' => ($groupes[$cle]['ttc'] ?? 0) + $this->calculateur->enCentiemes($ligne->montant_ttc),
            ];
        }

        uasort($groupes, fn (array $a, array $b): int => [-$a['taux'], $a['nature'] === NatureLigne::Service] <=> [-$b['taux'], $b['nature'] === NatureLigne::Service]);

        return $groupes;
    }

    private static function cleDeGroupe(NatureLigne $nature, int $taux): string
    {
        return "{$nature->value}|{$taux}";
    }
}
