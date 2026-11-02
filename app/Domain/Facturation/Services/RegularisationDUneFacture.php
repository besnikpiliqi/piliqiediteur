<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Models\EntreeAudit;
use Illuminate\Database\Eloquent\Builder;

/**
 * La régularisation d'une facture après la correction exceptionnelle du
 * SIREN de la société par l'éditeur (voir ExaminerSirenAction et
 * CorrigerSirenAction ; décision de l'éditeur du 01/11/2026, réponse 1a),
 * règle à faire confirmer par l'expert-comptable de la société le jour où
 * le cas se présente.
 *
 * Seules se régularisent les factures que désigne la dernière décision
 * d'examen du SIREN de la société, inscrite au journal d'audit
 * (SirenExamine), quand elle a conclu à une erreur que la correction a
 * réparée (le SIREN de la société est désormais le SIREN correct de
 * l'examen) : les factures à un professionnel de ses magasins réels,
 * émises jusqu'à l'instant de l'examen, exactement celles de sa liste ;
 * les tickets restent tels quels (BOI-TVA-DECLA-30-20-20-20). Chacune se
 * régularise une fois, par deux gestes, dans son magasin, quand il peut
 * vendre, dans l'ordre que l'on veut :
 * - son avoir de régularisation (voir FaireLAvoirDeRegularisationAction),
 *   fait comme tout avoir, jamais par un compte d'un cabinet lié : il
 *   crédite tout ce que les avoirs déjà émis n'ont pas crédité de chaque
 *   ligne (voir lignesDeLAvoir()), sans retour en stock, imprime
 *   l'émetteur figé de la facture (l'ancien SIREN) et la mention de la
 *   régularisation ;
 * - sa facture rectificative (voir EmettreLaFactureRectificativeAction),
 *   émise par un compte autorisé à créer et émettre une facture dans ce
 *   magasin, jamais par le propriétaire ni par un compte d'un cabinet lié :
 *   elle reprend ces mêmes lignes et montants (voir
 *   lignesDeLaRectificative()), sans sortie de stock, sous l'identité
 *   corrigée de la société.
 * Le crédit de l'avoir ne règle que ce qui reste dû de la facture, puis sa
 * rectificative, avant toute autre imputation, et lui reste réservé tant
 * qu'elle manque (voir ImputationDuCredit et
 * AppliquerAvoirsDisponiblesAction) : jamais un encaissement, il ne
 * s'inscrit dans aucun e-reporting (seuls les tickets y entrent, voir
 * EReportingDesParticuliers), et la facture électronique de l'étape 2 ne
 * le déclarera pas comme tel, la rectificative reprenant les encaissements
 * déjà déclarés de la facture.
 *
 * Toutes les lectures restent dans le magasin et la société actifs
 * (MagasinScope, SocieteScope).
 */
class RegularisationDUneFacture
{
    public const REFUS_NON_DESIGNEE = 'Cette facture n\'est pas désignée par l\'examen du SIREN de la société : elle ne se régularise pas.';

    public const REFUS_AVOIR_DEJA_EMIS = 'L\'avoir de régularisation de cette facture est déjà émis.';

    public const REFUS_RECTIFICATIVE_DEJA_EMISE = 'La facture rectificative de cette facture est déjà émise.';

    public const REFUS_RIEN_A_REGULARISER = 'Les lignes de cette facture ont déjà été entièrement créditées par des avoirs : elle n\'a plus rien à régulariser.';

    public const MOTIF_DE_L_AVOIR = 'Régularisation de la facture %s après la correction du SIREN du vendeur';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * La facture est-elle désignée par la dernière décision d'examen du
     * SIREN de sa société, une erreur que la correction a réparée ?
     */
    public function estDesignee(Facture $facture): bool
    {
        if ($facture->numero === null || $facture->date_emission === null || ! $facture->estPourUnProfessionnel() || $facture->magasin->est_demonstration) {
            return false;
        }

        $examen = EntreeAudit::query()->where('action', ActionAuditee::SirenExamine->value)->orderByDesc('id')->first();

        return $examen !== null
            && ($examen->nouvelles_valeurs['decision'] ?? null) === DecisionExamenSiren::Erreur->value
            && ($examen->nouvelles_valeurs['siren_correct'] ?? null) === (string) $facture->magasin->societe->siren
            && $facture->date_emission <= Instant::depuisLaBase((string) $examen->nouvelles_valeurs['documents_emis_jusqu_au']);
    }

    /**
     * Pourquoi l'avoir de régularisation de cette facture ne se fait pas :
     * elle n'est pas désignée, son avoir est déjà émis, ou des avoirs ont
     * déjà crédité toutes ses lignes ; null quand rien ne l'empêche.
     */
    public function refusDeLAvoir(Facture $facture): ?string
    {
        return match (true) {
            ! $this->estDesignee($facture) => self::REFUS_NON_DESIGNEE,
            $this->avoirDeRegularisation($facture) !== null => self::REFUS_AVOIR_DEJA_EMIS,
            $this->lignesDeLAvoir($facture) === [] => self::REFUS_RIEN_A_REGULARISER,
            default => null,
        };
    }

    /**
     * Pourquoi la facture rectificative de cette facture ne s'émet pas :
     * elle n'est pas désignée, sa rectificative est déjà émise, ou des
     * avoirs ont déjà crédité toutes ses lignes ; null quand rien ne
     * l'empêche.
     */
    public function refusDeLaRectificative(Facture $facture): ?string
    {
        return match (true) {
            ! $this->estDesignee($facture) => self::REFUS_NON_DESIGNEE,
            $facture->rectificative()->exists() => self::REFUS_RECTIFICATIVE_DEJA_EMISE,
            $this->lignesDeLaRectificative($facture) === [] => self::REFUS_RIEN_A_REGULARISER,
            default => null,
        };
    }

    /**
     * L'avoir de régularisation émis de cette facture, s'il existe : au
     * plus un, ses lignes créditant tout ce qui restait à créditer. Relu
     * verrouillé quand son crédit va s'imputer (voir
     * AppliquerAvoirsDisponiblesAction).
     */
    public function avoirDeRegularisation(Facture $facture, bool $verrouille = false): ?Avoir
    {
        return Avoir::query()
            ->where('regularisation', true)
            ->where('statut', StatutAvoir::Emise)
            ->whereHas('lignes.ligneFacture', fn (Builder $lignes): Builder => $lignes->where('facture_id', $facture->getKey()))
            ->when($verrouille, fn (Builder $avoirs): Builder => $avoirs->lockForUpdate())
            ->first();
    }

    /**
     * Les lignes de l'avoir de régularisation : chaque ligne de la facture
     * pour tout ce que les avoirs déjà émis n'en ont pas crédité, dans
     * leur ordre ; aucune quand tout l'a été.
     *
     * @return list<array{ligne_facture_id: int, quantite: int}>
     */
    public function lignesDeLAvoir(Facture $facture): array
    {
        return $facture->lignes()->get()
            ->map(fn (LigneFacture $ligne): array => ['ligne_facture_id' => (int) $ligne->getKey(), 'quantite' => $ligne->quantiteCreditableRestante()])
            ->filter(fn (array $ligne): bool => $ligne['quantite'] > 0)
            ->values()
            ->all();
    }

    /**
     * Les lignes de la facture rectificative, dans leur ordre : chaque
     * ligne de la facture, avec sa référence, sa désignation, ses prix, son
     * taux et sa remise, pour ce que les avoirs émis qui ne la régularisent
     * pas n'en ont pas crédité, quantité et montants (le HT, la TVA et le
     * TTC de la ligne moins ceux de ces avoirs, en centimes) : exactement ce
     * que son avoir de régularisation crédite, qu'il soit émis avant ou
     * après elle (le dernier retour d'une ligne en rend le solde exact, voir
     * LigneFacture::montantsDuRetour()). Aucune quand tout a été crédité.
     *
     * @return list<array<string, mixed>>
     */
    public function lignesDeLaRectificative(Facture $facture): array
    {
        $lignes = [];

        foreach ($facture->lignes()->get() as $ligne) {
            $creditees = LigneAvoir::query()
                ->where('ligne_facture_id', $ligne->getKey())
                ->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->where('statut', StatutAvoir::Emise)->where('regularisation', false))
                ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);
            $quantite = $ligne->quantite - (int) $creditees->sum('quantite');

            if ($quantite <= 0) {
                continue;
            }

            $reste = fn (string $colonne): string => $this->calculateur->enDecimal(
                $this->calculateur->enCentiemes($ligne->getAttribute($colonne)) - (int) $creditees->sum(fn (LigneAvoir $creditee): int => $this->calculateur->enCentiemes($creditee->getAttribute($colonne))),
            );

            $lignes[] = [
                'produit_id' => $ligne->produit_id,
                'service_id' => $ligne->service_id,
                'nature' => $ligne->nature,
                'description' => $ligne->description,
                'reference' => $ligne->reference,
                'quantite' => $quantite,
                'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
                'taux_tva' => $ligne->taux_tva,
                'taux_tva_du_prix' => $ligne->taux_tva_du_prix,
                'remise_pourcentage' => $ligne->remise_pourcentage,
                'montant_ht' => $reste('montant_ht'),
                'montant_tva' => $reste('montant_tva'),
                'montant_ttc' => $reste('montant_ttc'),
                'ordre' => count($lignes),
            ];
        }

        return $lignes;
    }
}
