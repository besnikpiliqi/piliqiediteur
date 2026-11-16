<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * La régularisation d'une facture après la correction exceptionnelle du
 * SIREN de la société par l'éditeur (voir ExaminerSirenAction et
 * CorrigerSirenAction ; décision de l'éditeur du 01/11/2026, réponse 1a),
 * règle de l'onglet « À faire vérifier par un professionnel » (T27.1 à
 * T27.5, T68.2), appliquée ; une réponse d'un professionnel la
 * remplacerait.
 *
 * Seules se régularisent les factures que désigne la dernière décision
 * d'examen du SIREN de la société, inscrite au journal d'audit
 * (SirenExamine), quand elle a conclu à une erreur que la correction a
 * réparée (le SIREN de la société est désormais le SIREN correct de
 * l'examen) : les factures à un professionnel de ses magasins,
 * émises avant l'instant de l'examen, exactement celles de sa liste (voir
 * ExaminerSirenAction) ; les tickets restent tels quels
 * (BOI-TVA-DECLA-30-20-20-20). Chacune se régularise une fois, par deux
 * gestes, dans son magasin, quand il peut vendre, toujours dans cet ordre
 * (T68.1) :
 * - son avoir de régularisation (voir FaireLAvoirDeRegularisationAction),
 *   fait comme tout avoir, jamais par un compte d'un cabinet lié : il
 *   crédite ce qui reste à créditer de chaque ligne, ce que les avoirs
 *   déjà émis n'en ont pas crédité (voir lignesDeLAvoir()), sans retour en
 *   stock, imprime l'émetteur figé de la facture (l'ancien SIREN) et la
 *   mention de la régularisation ; son crédit règle d'abord ce qui reste
 *   dû de la facture ;
 * - puis sa facture rectificative (voir
 *   EmettreLaFactureRectificativeAction), qui ne s'émet qu'une fois cet
 *   avoir émis (voir REFUS_RECTIFICATIVE_AVANT_L_AVOIR), par un compte
 *   autorisé à créer et émettre une facture dans ce magasin, jamais par le
 *   propriétaire ni par un compte d'un cabinet lié : elle reprend les
 *   lignes et montants que l'avoir a crédités (voir
 *   lignesDeLaRectificative()), sans sortie de stock, sous l'identité
 *   corrigée de la société, avec la date de la livraison ou de la
 *   prestation d'origine (T27.2, voir MentionsFiscales) ; ce qui reste du
 *   crédit de l'avoir la règle aussitôt, dans la transaction signée de son
 *   émission (voir AppliquerAvoirsDisponiblesAction), et son PDF montre ce
 *   crédit et le vrai reste dû.
 * Le crédit de l'avoir ne règle que ce qui reste dû de la facture, puis sa
 * rectificative, avant toute autre imputation, et lui reste réservé tant
 * qu'elle manque (voir ImputationDuCredit et
 * AppliquerAvoirsDisponiblesAction). La rectificative reçoit ainsi ce que
 * la facture avait reçu, et reste due de ce que le client y devait encore.
 * Un paiement de la facture contre-passé ensuite y rouvre un dû que ce
 * crédit referme : il quitte la rectificative pour la facture, qui reste
 * soldée, et la rectificative porte le dû rouvert (T68.3, voir
 * ImputationDuCredit::reportsDesRegularisations()) ; une facture
 * régularisée n'est jamais relancée (voir refusDeLaRelance()). Ce crédit
 * n'est jamais un encaissement : il ne s'inscrit dans aucun e-reporting
 * (seuls les tickets y entrent, voir EReportingDesParticuliers), et la
 * facture électronique de l'étape 2 ne le déclarera pas comme tel, la
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

    public const REFUS_AVOIR_DEJA_EMIS = 'L\'avoir de régularisation de cette facture est déjà émis.';

    public const REFUS_RECTIFICATIVE_DEJA_EMISE = 'La facture rectificative de cette facture est déjà émise.';

    public const REFUS_RECTIFICATIVE_AVANT_L_AVOIR = 'Faites d\'abord l\'avoir de régularisation de la facture %s : la facture rectificative ne s\'émet qu\'après lui, qui la règle de ce que la facture a reçu.';

    public const REFUS_RELANCE_DE_LA_FACTURE_REGULARISEE = 'La facture %s est régularisée par l\'avoir %s : elle ne se relance pas.';

    public const REFUS_RIEN_A_REGULARISER = 'Les lignes de cette facture ont déjà été entièrement créditées par des avoirs : elle n\'a plus rien à régulariser.';

    public const MOTIF_DE_L_AVOIR = 'Régularisation de la facture %s après la correction du SIREN du vendeur';

    /**
     * L'attribut d'une facture lue par une liste qui dit si son avoir de
     * régularisation est émis (voir avecLExistenceDeLAvoirDeRegularisation()).
     */
    private const AVOIR_DE_REGULARISATION_EXISTE = 'avoir_de_regularisation_exists';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * La facture est-elle désignée par la dernière décision d'examen du
     * SIREN de sa société (voir Societe::dernierExamenDuSiren()), une
     * erreur que la correction a réparée : une facture à un professionnel
     * d'un magasin, émise avant l'instant de l'examen (une facture
     * émise dans sa seconde n'est pas de sa liste, et la correction attend
     * alors un nouvel examen, voir ExaminerSirenAction et
     * CorrigerSirenAction) ?
     */
    public function estDesignee(Facture $facture): bool
    {
        if ($facture->numero === null || $facture->date_emission === null || ! $facture->estPourUnProfessionnel()) {
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
     * - une facture qui a sa rectificative : le retour se fait sur celle-ci,
     *   qui en a repris les lignes ;
     * - une facture désignée qui n'a pas encore sa rectificative et a
     *   encore quelque chose à régulariser (une ligne à créditer, ou son
     *   avoir de régularisation émis, sa rectificative restant à émettre) :
     *   un avoir ordinaire la corrigerait sous le SIREN corrigé, qu'elle n'a
     *   jamais porté, et sa régularisation n'en reprendrait plus toutes les
     *   lignes ; elle se régularise d'abord.
     * Seul son avoir de régularisation la crédite alors (voir
     * CreerAvoirAction). Une facture désignée dont des avoirs ordinaires
     * ont crédité toutes les lignes avant l'examen n'a plus rien à
     * régulariser (voir refusDeLAvoir()) : rien ne la retient ici, et
     * « Nouvel avoir » dit qu'elle n'a plus rien à rendre.
     */
    public function refusDUnAvoirOrdinaire(Facture $facture): ?string
    {
        return match (true) {
            $facture->rectificative !== null => sprintf(self::REFUS_FACTURE_RECTIFIEE, $facture->numero),
            $this->estDesignee($facture) && ($facture->possedeDesLignesCreditables() || $this->avoirDeRegularisation($facture) !== null) => sprintf(self::REFUS_RETOUR_AVANT_REGULARISATION, $facture->numero),
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
     * elle n'est pas désignée, sa rectificative est déjà émise, des avoirs
     * ont déjà crédité toutes ses lignes, ou son avoir de régularisation
     * n'est pas encore émis (T68.1) ; null quand rien ne l'empêche.
     */
    public function refusDeLaRectificative(Facture $facture): ?string
    {
        return match (true) {
            ! $this->estDesignee($facture) => self::REFUS_NON_DESIGNEE,
            $facture->rectificative()->exists() => self::REFUS_RECTIFICATIVE_DEJA_EMISE,
            $this->lignesDeLaRectificative($facture) === [] => self::REFUS_RIEN_A_REGULARISER,
            $this->avoirDeRegularisation($facture) === null => sprintf(self::REFUS_RECTIFICATIVE_AVANT_L_AVOIR, $facture->numero),
            default => null,
        };
    }

    /**
     * Pourquoi cette facture ne se relance pas, du fait de sa
     * régularisation ; null quand rien ne l'en empêche : une facture dont
     * l'avoir de régularisation est émis, après la correction du SIREN ou
     * pour une exonération non prouvée, est régularisée, son dû se portant
     * sur sa facture rectificative (voir
     * ImputationDuCredit::reportsDesRegularisations()) : elle ne se relance
     * jamais, ni à la main ni d'elle-même (voir RelancerFacturesImpayees).
     */
    public function refusDeLaRelance(Facture $facture): ?string
    {
        $avoir = $this->avoirDeRegularisation($facture);

        return $avoir === null ? null : sprintf(self::REFUS_RELANCE_DE_LA_FACTURE_REGULARISEE, $facture->numero, $avoir->numero);
    }

    /**
     * La facture est-elle régularisée, son avoir de régularisation émis
     * (voir refusDeLaRelance()) ? Lu sur l'attribut que la liste des
     * factures charge avec sa page (voir avecLExistenceDeLAvoirDeRegularisation()),
     * sans une requête par facture, sinon en base.
     */
    public function estRegularisee(Facture $facture): bool
    {
        return array_key_exists(self::AVOIR_DE_REGULARISATION_EXISTE, $facture->getAttributes())
            ? (bool) $facture->getAttribute(self::AVOIR_DE_REGULARISATION_EXISTE)
            : $this->avoirDeRegularisation($facture) !== null;
    }

    /**
     * Ce qu'une requête de factures ajoute à son withExists() pour lire,
     * avec la page, si chacune a son avoir de régularisation émis (voir
     * estRegularisee()) : une ligne de la facture que crédite un avoir de
     * régularisation émis, dans le magasin actif.
     *
     * @return array<string, Closure(Builder<LigneFacture>): Builder<LigneFacture>>
     */
    public static function avecLExistenceDeLAvoirDeRegularisation(): array
    {
        return ['lignes as '.self::AVOIR_DE_REGULARISATION_EXISTE => fn (Builder $lignes): Builder => $lignes->whereHas(
            'lignesAvoir.avoir',
            fn (Builder $avoirs): Builder => $avoirs->whereNotNull('motif_regularisation')->where('statut', StatutAvoir::Emise),
        )];
    }

    /**
     * L'avoir de régularisation émis de cette facture, s'il existe : au
     * plus un, ses lignes créditant tout ce qui restait à créditer. Relu
     * verrouillé quand son crédit va s'imputer (voir
     * AppliquerAvoirsDisponiblesAction et
     * ImputationDuCredit::reportsDesRegularisations()).
     */
    public function avoirDeRegularisation(Facture $facture, bool $verrouille = false): ?Avoir
    {
        return Avoir::query()
            ->whereNotNull('motif_regularisation')
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
     * que son avoir de régularisation, émis avant elle, a crédité (le
     * dernier retour d'une ligne en rend le solde exact, voir
     * LigneFacture::montantsDesRetours()). Aucune quand tout a été crédité.
     *
     * @return list<array{produit_id: int|null, service_id: int|null, nature: NatureLigne, description: string, reference: string|null, quantite: int, prix_unitaire_ht: numeric-string, prix_unitaire_ttc: numeric-string, taux_tva: numeric-string, taux_tva_du_prix: numeric-string, remise_pourcentage: numeric-string, montant_ht: numeric-string, montant_tva: numeric-string, montant_ttc: numeric-string, ordre: int}>
     */
    public function lignesDeLaRectificative(Facture $facture): array
    {
        $lignes = [];

        foreach ($facture->lignes()->get() as $ligne) {
            $creditees = LigneAvoir::query()
                ->where('ligne_facture_id', $ligne->getKey())
                ->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->where('statut', StatutAvoir::Emise)->whereNull('motif_regularisation'))
                ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);
            $quantite = $ligne->quantite - ValeursLues::entier($creditees->sum('quantite'));

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
