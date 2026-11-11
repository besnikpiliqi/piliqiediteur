<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
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
 * émises avant l'instant de l'examen, exactement celles de sa liste (voir
 * ExaminerSirenAction) ; les tickets restent tels quels
 * (BOI-TVA-DECLA-30-20-20-20). Chacune se régularise une fois, par deux
 * gestes, dans son magasin, quand il peut vendre, dans l'ordre que l'on
 * veut :
 * - son avoir de régularisation (voir FaireLAvoirDeRegularisationAction),
 *   fait comme tout avoir, jamais par un compte d'un cabinet lié : il
 *   crédite ce qui reste à créditer de chaque ligne, ce que les avoirs
 *   déjà émis n'en ont pas crédité (voir lignesDeLAvoir()), sans retour en
 *   stock, imprime l'émetteur figé de la facture (l'ancien SIREN) et la
 *   mention de la régularisation ;
 * - sa facture rectificative (voir EmettreLaFactureRectificativeAction),
 *   émise par un compte autorisé à créer et émettre une facture dans ce
 *   magasin, jamais par le propriétaire ni par un compte d'un cabinet lié :
 *   elle reprend ces mêmes lignes et montants (voir
 *   lignesDeLaRectificative()), sans sortie de stock, sous l'identité
 *   corrigée de la société ; émise avant l'avoir, elle l'attend (voir
 *   StatutFacture::EnAttenteDeRegularisation) : elle ne reçoit ni
 *   paiement ni crédit, n'est ni relancée ni en retard, et ne compte pas
 *   parmi les factures à encaisser, ce que le client doit encore se
 *   réglant sur la facture.
 * Le crédit de l'avoir ne règle que ce qui reste dû de la facture, puis sa
 * rectificative, avant toute autre imputation, et lui reste réservé tant
 * qu'elle manque ; tant qu'elle l'attend, la rectificative ne reçoit aucun
 * autre crédit du client (voir ImputationDuCredit et
 * AppliquerAvoirsDisponiblesAction). Quel que soit l'ordre des deux
 * gestes, elle reçoit ainsi ce que la facture avait reçu, et reste due de
 * ce que le client y devait encore. Ce crédit n'est jamais un
 * encaissement : il ne s'inscrit dans aucun e-reporting (seuls
 * les tickets y entrent, voir EReportingDesParticuliers), et la facture
 * électronique de l'étape 2 ne le déclarera pas comme tel, la
 * rectificative reprenant les encaissements déjà déclarés de la facture.
 *
 * Une facture désignée ne reçoit plus d'avoir ordinaire : un retour se
 * fait sur sa rectificative, une fois l'avoir de régularisation émis (voir
 * refusDUnAvoirOrdinaire()) ; il rend au stock ce que la vente de la
 * facture en a sorti (voir Facture::quantiteSortieDuStock()).
 *
 * Toutes les lectures restent dans le magasin et la société actifs
 * (MagasinScope, SocieteScope), la dernière décision d'examen se lisant
 * par la relation de la société de la facture, qui filtre par elle.
 */
class RegularisationDUneFacture
{
    public const REFUS_NON_DESIGNEE = 'Cette facture n\'est pas désignée par l\'examen du SIREN de la société : elle ne se régularise pas.';

    public const REFUS_RETOUR_AVANT_REGULARISATION = 'Régularisez d\'abord la facture %s : un retour se fait ensuite sur sa facture rectificative.';

    public const REFUS_FACTURE_RECTIFIEE = 'La facture %s a sa facture rectificative : un retour se fait sur la rectificative.';

    public const REFUS_RETOUR_RECTIFICATIVE_EN_ATTENTE = 'La facture rectificative %s attend l\'avoir de régularisation de la facture %s : un retour s\'y fait une fois cet avoir émis.';

    public const REFUS_PAIEMENT_EN_ATTENTE = 'La facture rectificative %s attend l\'avoir de régularisation de la facture %s : ce que le client doit encore se règle sur cette facture.';

    public const ATTENTE_DE_L_AVOIR = 'Elle attend l\'avoir de régularisation de la facture %1$s, qui la réglera de ce que la facture %1$s a reçu : d\'ici là, ce que le client doit encore se règle sur la facture %1$s.';

    public const REFUS_AVOIR_DEJA_EMIS = 'L\'avoir de régularisation de cette facture est déjà émis.';

    public const REFUS_RECTIFICATIVE_DEJA_EMISE = 'La facture rectificative de cette facture est déjà émise.';

    public const REFUS_RIEN_A_REGULARISER = 'Les lignes de cette facture ont déjà été entièrement créditées par des avoirs : elle n\'a plus rien à régulariser.';

    public const MOTIF_DE_L_AVOIR = 'Régularisation de la facture %s après la correction du SIREN du vendeur';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * La facture est-elle désignée par la dernière décision d'examen du
     * SIREN de sa société (voir Societe::dernierExamenDuSiren()), une
     * erreur que la correction a réparée : une facture à un professionnel
     * d'un magasin réel, émise avant l'instant de l'examen (une facture
     * émise dans sa seconde n'est pas de sa liste, et la correction attend
     * alors un nouvel examen, voir ExaminerSirenAction et
     * CorrigerSirenAction) ?
     */
    public function estDesignee(Facture $facture): bool
    {
        if ($facture->numero === null || $facture->date_emission === null || ! $facture->estPourUnProfessionnel() || $facture->magasin->est_demonstration) {
            return false;
        }

        $examen = $facture->magasin->societe->dernierExamenDuSiren;

        return $examen !== null
            && ($examen->nouvelles_valeurs['decision'] ?? null) === DecisionExamenSiren::Erreur->value
            && ($examen->nouvelles_valeurs['siren_correct'] ?? null) === (string) $facture->magasin->societe->siren
            && $facture->date_emission < Instant::depuisLaBase(ValeurSimple::enTexte($examen->nouvelles_valeurs['documents_emis_avant']));
    }

    /**
     * Pourquoi cette facture ne reçoit pas d'avoir ordinaire, du fait de sa
     * régularisation ; null quand rien ne l'en empêche :
     * - une facture rectificative qui attend l'avoir de régularisation de
     *   la facture qu'elle rectifie (voir
     *   StatutFacture::EnAttenteDeRegularisation) : le retour s'y fait une
     *   fois cet avoir émis ;
     * - une facture qui a sa rectificative : le retour se fait sur celle-ci,
     *   qui en a repris les lignes ;
     * - une facture désignée qui n'a pas encore sa rectificative : un avoir
     *   ordinaire la corrigerait sous le SIREN corrigé, qu'elle n'a jamais
     *   porté, et sa régularisation n'en reprendrait plus toutes les lignes ;
     *   elle se régularise d'abord.
     * Seul son avoir de régularisation la crédite alors (voir
     * CreerAvoirAction).
     */
    public function refusDUnAvoirOrdinaire(Facture $facture): ?string
    {
        return match (true) {
            $facture->statut === StatutFacture::EnAttenteDeRegularisation => sprintf(self::REFUS_RETOUR_RECTIFICATIVE_EN_ATTENTE, $facture->numero, ValeursLues::texteOuNul($facture->factureRectifiee()->value('numero'))),
            $facture->rectificative !== null => sprintf(self::REFUS_FACTURE_RECTIFIEE, $facture->numero),
            $this->estDesignee($facture) => sprintf(self::REFUS_RETOUR_AVANT_REGULARISATION, $facture->numero),
            default => null,
        };
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
        return array_values($facture->lignes()->get()
            ->map(fn (LigneFacture $ligne): array => ['ligne_facture_id' => CleDuModele::de($ligne), 'quantite' => $ligne->quantiteCreditableRestante()])
            ->filter(fn (array $ligne): bool => $ligne['quantite'] > 0)
            ->all());
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
     * @return list<array{produit_id: int|null, service_id: int|null, nature: NatureLigne, description: string, reference: string|null, quantite: int, prix_unitaire_ht: numeric-string, prix_unitaire_ttc: numeric-string, taux_tva: numeric-string, taux_tva_du_prix: numeric-string, remise_pourcentage: numeric-string, montant_ht: numeric-string, montant_tva: numeric-string, montant_ttc: numeric-string, ordre: int}>
     */
    public function lignesDeLaRectificative(Facture $facture): array
    {
        $lignes = [];

        foreach ($facture->lignes()->get() as $ligne) {
            $creditees = LigneAvoir::query()
                ->where('ligne_facture_id', $ligne->getKey())
                ->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->where('statut', StatutAvoir::Emise)->where('regularisation', false))
                ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);
            $quantite = $ligne->quantite - ValeursLues::entier($creditees->sum('quantite'));

            if ($quantite <= 0) {
                continue;
            }

            $reste = fn (string $colonne): string => ValeursLues::nombre($this->calculateur->enDecimal(
                $this->calculateur->enCentiemes($ligne->getAttribute($colonne)) - (int) $creditees->sum(fn (LigneAvoir $creditee): int => $this->calculateur->enCentiemes($creditee->getAttribute($colonne))),
            ));

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
