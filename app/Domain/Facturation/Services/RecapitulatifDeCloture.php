<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif d'une clôture du jour (points 15, 21 et 24), établi une
 * seule fois, à la clôture, sous le verrou du registre fiscal du magasin
 * (voir CloturerCaisseAction), en centimes entiers, depuis les montants
 * enregistrés, jamais recalculés :
 *
 * - les ventes aux particuliers (tickets) et leurs avoirs, chacun avec son
 *   nombre, son HT, sa TVA, son TTC et le détail par taux (en centièmes de
 *   point, du plus fort au plus faible), les avoirs en négatif : leur
 *   somme est le net de la clôture, base de l'e-reporting ;
 * - les factures aux professionnels et leurs avoirs, mêmes rubriques, à
 *   titre d'information : toutes les ventes entrent dans les clôtures,
 *   mais elles ne sont pas dans le net, et partiront une à une comme
 *   factures électroniques ;
 * - les encaissements par mode de paiement, dans l'ordre de la caisse
 *   (espèces, carte bancaire, chèque, virement, puis prélèvement), chacun
 *   avec son libellé du jour, son nombre de paiements et de
 *   contre-passations et son montant, contre-passations déduites ;
 * - le crédit d'avoir imputé, à part : il ne fait entrer aucun argent ;
 * - les grands totaux perpétuels du magasin après le dernier document
 *   qu'elle compte.
 *
 * Ce que la clôture compte se lit dans le registre fiscal (voir
 * RegistreFiscal::enregistrementsJusquA()) : les enregistrements des
 * chaînes des ventes et des encaissements qui suivent ceux de la clôture
 * précédente (ses dernières séquences) et dont l'horodatage ne dépasse pas
 * la fin de la journée du calendrier. Chaque document et chaque
 * paiement comptent ainsi dans une seule clôture, sans rien écrire sur
 * eux : un paiement ou une facture à un professionnel enregistrés après
 * la clôture de leur journée comptent dans la suivante, et un paiement
 * daté d'un jour passé compte le jour où il est enregistré. Les tickets et
 * leurs avoirs, refusés une fois leur journée clôturée (voir
 * ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()), sont ceux que
 * la clôture regroupe (voir Facture::scopeEnAttenteDeCloture() et
 * Avoir::scopeEnAttenteDeCloture()).
 *
 * Le mode et le montant de chaque paiement, et le crédit d'avoir imputé,
 * sont ceux que signe le registre : chaque imputation d'un crédit d'avoir
 * est signée une seule fois, par la facture qu'elle règle à son émission,
 * par l'avoir qui l'impute à son émission, ou par le paiement qui l'impute
 * (voir ContenuFiscal), et compte dans la clôture de la journée de cet
 * enregistrement. Les grands totaux sont ceux que signe le dernier
 * enregistrement de la chaîne des ventes que la clôture compte : un
 * document enregistré après la clôture de sa journée n'y entre pas, il
 * entre dans les grands totaux de la clôture suivante.
 */
class RecapitulatifDeCloture
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Le récapitulatif de la journée qui se clôture, et les dernières
     * séquences des chaînes des ventes et des encaissements qu'il compte,
     * dont la clôture suivante partira (celles de la clôture précédente,
     * ou null, quand la journée n'en compte aucune).
     *
     * @param  string  $journee  la journée clôturée, AAAA-MM-JJ
     * @param  EloquentCollection<int, Facture>  $tickets  les tickets de la journée
     * @param  EloquentCollection<int, Avoir>  $avoirsDeTickets  les avoirs du jour sur des tickets
     * @param  ClotureCaisse|null  $precedente  la dernière clôture du magasin, null pour la première
     * @return array{recapitulatif: array<string, mixed>, derniere_sequence_vente: int|null, derniere_sequence_encaissement: int|null}
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function etablir(Magasin $magasin, string $journee, EloquentCollection $tickets, EloquentCollection $avoirsDeTickets, ?ClotureCaisse $precedente): array
    {
        $finDeLaJournee = Calendrier::deParis()->bornesDuJour($journee)[1]->subSecond();
        $ventes = $this->registre->enregistrementsJusquA($magasin, ChaineFiscale::Vente, $precedente?->derniere_sequence_vente, $finDeLaJournee);
        $encaissements = $this->registre->enregistrementsJusquA($magasin, ChaineFiscale::Encaissement, $precedente?->derniere_sequence_encaissement, $finDeLaJournee);
        $derniereSequenceVente = $ventes->last()?->sequence ?? $precedente?->derniere_sequence_vente;

        return [
            'recapitulatif' => [
                'ventes_particuliers' => $this->rubrique($tickets, 1),
                'avoirs_particuliers' => $this->rubrique($avoirsDeTickets, -1),
                'factures_professionnels' => $this->rubrique(Facture::query()->whereKey(self::documents($ventes, 'facture'))->get(), 1),
                'avoirs_professionnels' => $this->rubrique(Avoir::query()->whereKey(self::documents($ventes, 'avoir'))->where('type_client', TypeClient::Professionnel)->get(), -1),
                'encaissements' => self::encaissementsParMode($encaissements),
                'credit_avoir_impute' => self::creditImpute($ventes, $encaissements),
                'grands_totaux' => $this->registre->grandsTotauxApres($magasin, $derniereSequenceVente),
            ],
            'derniere_sequence_vente' => $derniereSequenceVente,
            'derniere_sequence_encaissement' => $encaissements->last()?->sequence ?? $precedente?->derniere_sequence_encaissement,
        ];
    }

    /**
     * Le récapitulatif d'une journée sans aucune opération ni aucune vente
     * avant elle : tout à zéro, chaque mode de paiement compris.
     *
     * @return array<string, mixed>
     */
    public static function vide(): array
    {
        $rubrique = ['nombre' => 0, 'ht' => 0, 'tva' => 0, 'ttc' => 0, 'par_taux' => []];

        return [
            'ventes_particuliers' => $rubrique,
            'avoirs_particuliers' => $rubrique,
            'factures_professionnels' => $rubrique,
            'avoirs_professionnels' => $rubrique,
            'encaissements' => self::encaissementsParMode(new Collection),
            'credit_avoir_impute' => 0,
            'grands_totaux' => ['perpetuel_reel' => 0, 'perpetuel_absolu' => 0],
        ];
    }

    /**
     * Les modes de paiement dans l'ordre du récapitulatif : ceux de la
     * caisse, puis les autres (le prélèvement).
     *
     * @return list<ModePaiement>
     */
    private static function modesDansLOrdre(): array
    {
        $aLaCaisse = ModePaiement::aLaCaisse();

        return [...$aLaCaisse, ...array_values(array_filter(ModePaiement::cases(), fn (ModePaiement $mode): bool => ! in_array($mode, $aLaCaisse, true)))];
    }

    /**
     * Le nombre de documents, leurs totaux et le détail par taux de leurs
     * lignes, tels qu'enregistrés, en centimes ; $sens vaut -1 pour des
     * avoirs, qui s'écrivent en négatif.
     *
     * @param  EloquentCollection<int, Facture>|EloquentCollection<int, Avoir>  $documents
     * @return array{nombre: int, ht: int, tva: int, ttc: int, par_taux: list<array{taux: int, ht: int, tva: int, ttc: int}>}
     */
    private function rubrique(EloquentCollection $documents, int $sens): array
    {
        $documents->loadMissing('lignes');

        return [
            'nombre' => $documents->count(),
            ...$this->montants($documents, 'total', $sens),
            'par_taux' => $documents->toBase()
                ->flatMap(fn (Facture|Avoir $document): Collection => $document->lignes->toBase())
                ->groupBy(fn (LigneFacture|LigneAvoir $ligne): int => $this->calculateur->enCentiemes($ligne->taux_tva))
                ->sortKeysDesc()
                ->map(fn (Collection $lignes, int $taux): array => ['taux' => $taux, ...$this->montants($lignes, 'montant', $sens)])
                ->values()
                ->all(),
        ];
    }

    /**
     * La somme des HT, des TVA et des TTC enregistrés de ces documents
     * (colonnes total_*) ou de ces lignes (colonnes montant_*), en
     * centimes.
     *
     * @param  Collection<int, Facture|Avoir|LigneFacture|LigneAvoir>  $elements
     * @return array{ht: int, tva: int, ttc: int}
     */
    private function montants(Collection $elements, string $prefixe, int $sens): array
    {
        $somme = fn (string $colonne): int => $sens * (int) $elements->sum(fn (Facture|Avoir|LigneFacture|LigneAvoir $element): int => $this->calculateur->enCentiemes($element->{"{$prefixe}_{$colonne}"}));

        return ['ht' => $somme('ht'), 'tva' => $somme('tva'), 'ttc' => $somme('ttc')];
    }

    /**
     * Les identifiants des documents de ce type (« facture » pour une
     * facture à un professionnel, « avoir ») que ces enregistrements de la
     * chaîne des ventes ont signés.
     *
     * @param  Collection<int, EnregistrementFiscal>  $ventes
     * @return list<int>
     */
    private static function documents(Collection $ventes, string $type): array
    {
        return $ventes->where('type', $type)->map(fn (EnregistrementFiscal $vente): int => (int) $vente->document_id)->values()->all();
    }

    /**
     * Les encaissements de ces enregistrements de la chaîne des
     * encaissements, par mode, tels qu'ils les signent : le nombre de
     * paiements, celui des contre-passations et leur montant net, en
     * centimes (une contre-passation, signée en négatif dans le mode du
     * paiement qu'elle annule, s'en déduit). Chaque mode y figure, même
     * sans paiement ; un mode signé qui ne serait plus un mode de
     * paiement s'y ajoute sous son code.
     *
     * @param  Collection<int, EnregistrementFiscal>  $encaissements
     * @return list<array{mode: string, libelle: string, paiements: int, contre_passations: int, montant: int}>
     */
    private static function encaissementsParMode(Collection $encaissements): array
    {
        $paiements = $encaissements->map(fn (EnregistrementFiscal $encaissement): array => [
            'mode' => $encaissement->contenuSigne()['donnees']['paiement']['mode'],
            'montant' => $encaissement->contenuSigne()['donnees']['paiement']['montant'],
            'contre_passation' => $encaissement->contenuSigne()['donnees']['contre_passe'] !== null,
        ]);
        $codes = array_values(array_unique([
            ...array_map(fn (ModePaiement $mode): string => $mode->value, self::modesDansLOrdre()),
            ...$paiements->pluck('mode')->all(),
        ]));

        return array_map(function (string $code) use ($paiements): array {
            $duMode = $paiements->where('mode', $code);

            return [
                'mode' => $code,
                'libelle' => ModePaiement::tryFrom($code)?->getLabel() ?? $code,
                'paiements' => $duMode->where('contre_passation', false)->count(),
                'contre_passations' => $duMode->where('contre_passation', true)->count(),
                'montant' => (int) $duMode->sum('montant'),
            ];
        }, $codes);
    }

    /**
     * Le crédit d'avoir que ces enregistrements imputent, en centimes :
     * celui qu'une facture reçoit à son émission (crédit du client, bons
     * d'avoir présentés), celui qu'un avoir impute à son émission sur des
     * factures, et celui qu'un paiement impute ou qu'une contre-passation
     * rend (en négatif) ou réimpute.
     *
     * @param  Collection<int, EnregistrementFiscal>  $ventes
     * @param  Collection<int, EnregistrementFiscal>  $encaissements
     */
    private static function creditImpute(Collection $ventes, Collection $encaissements): int
    {
        $somme = fn (array $imputations): int => (int) array_sum(array_column($imputations, 'montant'));

        return (int) $ventes->sum(fn (EnregistrementFiscal $vente): int => $somme($vente->contenuSigne()['donnees']['contexte'][$vente->type === 'avoir' ? 'imputations' : 'credits_avoir']))
            + (int) $encaissements->sum(fn (EnregistrementFiscal $encaissement): int => $somme($encaissement->contenuSigne()['donnees']['credits_imputes']));
    }
}
