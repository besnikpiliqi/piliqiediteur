<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Services\ChiffrageDUnAvoirFournisseur;
use App\Domain\Achats\Services\EcartsDUnTaux;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Achats\Services\TvaDuFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreAvoirAction côté vente : l'avoir fournisseur ne
 * reçoit son numéro qu'à l'émission, moment où le stock est décrémenté
 * (voir AvoirFournisseurEmis/DecrementerStockApresEmissionAvoirFournisseur).
 * L'instant de l'émission s'enregistre en UTC (voir Instant) : les avoirs
 * d'une facture d'achat se lisent dans l'ordre de leur émission (voir
 * FactureAchat::avoirsFournisseurEmis()), et le numéro prend l'année du
 * calendrier de cet instant.
 * Un magasin suspendu ou fermé n'en émet plus (voir
 * Magasin::verifierQuIlEstOuvert()) : sa ligne se relit sous un verrou
 * partagé, juste après celle de sa société, que l'avoir verrouille en
 * tête comme son entrée au journal d'audit le ferait (voir
 * Societe::verrouillerAvantSonJournalDAudit()), et la fermeture du
 * magasin, qui la verrouille (voir FermerMagasinAction), passe donc tout
 * entière avant ou après l'émission.
 *
 * Il fait sortir du stock et recalcule le coût moyen, comme une
 * validation d'achat : son auteur est relu sous le verrou de sa ligne,
 * avant l'avoir et ses produits (voir LecteurDesDroits), et doit pouvoir
 * créer un avoir fournisseur. La facture d'achat qu'il crédite se
 * verrouille ensuite, puis les lignes d'achat qu'il retourne, puis ses
 * produits, par identifiant croissant, avant la numérotation, quand leur
 * stock se relit (voir AvoirFournisseur::verifierQueLeStockSuffit()) :
 * deux avoirs de la même facture d'achat s'émettent l'un après l'autre.
 * L'écran ne l'appelle qu'avec la création de l'avoir, dans la même
 * transaction (voir EnregistrerAvoirFournisseurAction).
 *
 * Ses montants s'arrêtent à l'émission (décision de l'éditeur du
 * 02/11/2026, T1, voir ChiffrageDUnAvoirFournisseur) : PILIQI propose, sur
 * les retours déjà émis, le HT et la TVA de chaque ligne ; ce qu'écrit la
 * note d'avoir du fournisseur, gardé sur le brouillon (le HT d'une ligne,
 * la TVA d'un taux), prime. Il ne rend jamais plus que la TVA restante de
 * chaque taux sur la facture d'achat (sa TVA moins ce que ses avoirs émis
 * en ont rendu), tolérance de 1 centime par ligne du taux comprise, ni plus
 * que la quantité retournable et en stock. Un écart au-delà de la tolérance
 * se confirme, sinon rien n'est émis ; confirmé, l'avoir est marqué « TVA à
 * vérifier » (voir TvaDuFournisseur).
 *
 * L'avoir émis s'inscrit au journal d'audit de la société, dans la chaîne
 * du magasin, en dernier verrou (voir JournalAudit) : son numéro, celui de
 * la facture d'achat qu'il crédite, le n° de l'avoir du fournisseur, ses
 * totaux, la TVA de chaque taux, sa marque et, pour chaque ligne, la
 * référence et la quantité retournées ; ni le fournisseur ni le motif, qui
 * restent sur l'avoir. Un avoir saisi à la main émis sans la note d'avoir
 * du fournisseur jointe est marqué « pièce du fournisseur manquante »
 * (décision de l'éditeur du 03/11/2026, F1 = b, voir PiecesDuFournisseur),
 * jusqu'à ce qu'une pièce lui soit ajoutée depuis sa fiche.
 */
class EmettreAvoirFournisseurAction
{
    public const REFUS_TVA_EN_TROP = 'Cet avoir rendrait %s de TVA à %s, plus que les %s qui restent de la facture d\'achat %s (tolérance de %s comprise) : vérifiez la note d\'avoir du fournisseur.';

    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly JournalAudit $journalAudit,
        private readonly ChiffrageDUnAvoirFournisseur $chiffrage,
        private readonly TvaDuFournisseur $tva,
        private readonly PiecesDuFournisseur $pieces,
    ) {}

    /**
     * @param  bool  $confirmation  les écarts avec la note d'avoir du fournisseur au-delà de la tolérance sont confirmés
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ConfirmationDesEcartsRequiseException
     */
    public function handle(User $auteur, AvoirFournisseur $avoirFournisseur, bool $confirmation = false): AvoirFournisseur
    {
        return DB::transaction(function () use ($auteur, $avoirFournisseur, $confirmation) {
            $magasin = app(TenantContext::class)->magasin();
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, AvoirFournisseur::class);

            return $this->operateur->pendant($compte, fn (): AvoirFournisseur => $this->emettre($compte, $avoirFournisseur, $confirmation));
        });
    }

    /**
     * @throws LogicException
     * @throws ConfirmationDesEcartsRequiseException
     */
    private function emettre(User $compte, AvoirFournisseur $avoirFournisseur, bool $confirmation): AvoirFournisseur
    {
        $avoirFournisseur = AvoirFournisseur::query()->whereKey($avoirFournisseur->getKey())->lockForUpdate()->firstOrFail();

        if (! $avoirFournisseur->statut->peutTransitionnerVers(StatutAvoirFournisseur::Emise)) {
            throw new LogicException("Impossible d'émettre un avoir fournisseur au statut [{$avoirFournisseur->statut->value}].");
        }

        $factureAchat = FactureAchat::query()->whereKey($avoirFournisseur->facture_achat_id)->lockForUpdate()->firstOrFail();
        $tvaEcrite = $avoirFournisseur->taux()->whereNotNull('montant_tva_saisi')->get()
            ->mapWithKeys(fn (TauxAvoirFournisseur $taux): array => [$this->calculateur->enCentiemes($taux->taux_tva) => $this->calculateur->enCentiemes($taux->montant_tva_saisi)])
            ->all();
        $chiffre = $this->arreterLesMontants($avoirFournisseur, $tvaEcrite);
        $this->refuserUnRetourDeTvaEnTrop($factureAchat, $chiffre);
        $avoirFournisseur->verifierQueLeStockSuffit();

        $maintenant = CarbonImmutable::now();
        $numero = $this->numerotation->suivant($avoirFournisseur->magasin, TypeDocument::AvoirFournisseur, $avoirFournisseur->magasin->calendrier()->anneeDe($maintenant));

        $avoirFournisseur->forceFill([
            'numero' => $numero,
            'statut' => StatutAvoirFournisseur::Emise,
            'date_emission' => $maintenant,
        ])->save();

        // Synchrone et dans la même transaction, voir le commentaire de
        // AvoirFournisseurEmis.
        AvoirFournisseurEmis::dispatch($avoirFournisseur);

        $this->tva->retenir($compte, $avoirFournisseur, $chiffre, new MontantsDuFournisseur($tvaEcrite, confirmation: $confirmation));

        $this->journalAudit->inscrire(ActionAuditee::AvoirFournisseurEmis, $compte, $avoirFournisseur->magasin, null, [
            'avoir' => $avoirFournisseur->numero,
            'facture_achat' => $factureAchat->numero,
            'numero_fournisseur' => $avoirFournisseur->numero_fournisseur,
            'total_ht' => $avoirFournisseur->total_ht,
            'total_tva' => $avoirFournisseur->total_tva,
            'total_ttc' => $avoirFournisseur->total_ttc,
            'taux' => $avoirFournisseur->taux()->get()->map(fn (TauxAvoirFournisseur $taux): array => [
                'taux_tva' => $taux->taux_tva,
                'base_ht' => $taux->base_ht,
                'montant_tva' => $taux->montant_tva,
                'montant_tva_calcule' => $taux->montant_tva_calcule,
                'montant_tva_saisi' => $taux->montant_tva_saisi,
            ])->values()->all(),
            'tva_a_verifier' => (bool) $avoirFournisseur->tva_a_verifier,
            'lignes' => $avoirFournisseur->lignes->map(fn (LigneAvoirFournisseur $ligne): array => [
                'reference' => $ligne->reference,
                'quantite' => $ligne->quantite,
            ])->values()->all(),
        ], $avoirFournisseur);

        $this->pieces->marquer($compte, $avoirFournisseur);

        return $avoirFournisseur;
    }

    /**
     * Un brouillon n'a encore aucune existence : entre sa création et son
     * émission, un autre avoir fournisseur a pu retourner les mêmes lignes,
     * ou le stock a pu être vendu. Ses quantités sont donc revérifiées et
     * ses montants arrêtés au moment de l'émission, sur le cumul des retours
     * déjà émis, avec ce qu'écrit la note d'avoir du fournisseur (voir
     * ChiffrageDUnAvoirFournisseur).
     *
     * @param  array<int, int>  $tvaEcrite  la TVA qu'écrit la note d'avoir, par taux
     *
     * @throws LogicException
     */
    private function arreterLesMontants(AvoirFournisseur $avoirFournisseur, array $tvaEcrite): DocumentChiffre
    {
        $retours = [];

        foreach ($avoirFournisseur->lignes as $ligneAvoir) {
            $ligneAchat = LigneAchat::query()
                ->with(['produit', 'factureAchat'])
                ->whereKey($ligneAvoir->ligne_achat_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restante = $ligneAchat->quantiteRetournableRestante();

            if ($ligneAvoir->quantite > $restante) {
                throw new LogicException("La ligne \"{$ligneAchat->description}\" a été retournée ou vendue entre-temps : il ne reste que {$restante} article(s) à retourner.");
            }

            $retours[CleDuModele::de($ligneAvoir)] = ['ligne_achat' => $ligneAchat, 'quantite' => $ligneAvoir->quantite, 'montant_ht_saisi' => $ligneAvoir->montant_ht_saisi];
        }

        $chiffre = $this->chiffrage->chiffrer($retours, $tvaEcrite);

        foreach ($avoirFournisseur->lignes as $ligneAvoir) {
            $ligneAvoir->forceFill(array_intersect_key($chiffre->lignes[CleDuModele::de($ligneAvoir)], array_flip(['montant_ht', 'montant_tva', 'montant_ttc'])))->save();
        }

        $avoirFournisseur->forceFill($chiffre->totaux);

        return $chiffre;
    }

    /**
     * Refuse un avoir qui rendrait, à un taux, plus de TVA que la facture
     * d'achat n'en porte encore : sa TVA à ce taux (la somme de ses lignes,
     * celle de la facture du fournisseur) moins ce que ses avoirs émis en
     * ont rendu, plus 1 centime par ligne de l'avoir à ce taux.
     *
     * @throws LogicException
     */
    private function refuserUnRetourDeTvaEnTrop(FactureAchat $factureAchat, DocumentChiffre $chiffre): void
    {
        $lignesAchat = $factureAchat->lignes()->get(['id', 'taux_tva', 'montant_tva']);
        $rendues = LigneAvoirFournisseur::query()
            ->whereIn('ligne_achat_id', $lignesAchat->modelKeys())
            ->whereHas('avoirFournisseur', fn ($avoir) => $avoir->where('statut', StatutAvoirFournisseur::Emise))
            ->get(['taux_tva', 'montant_tva']);

        foreach ($chiffre->parTaux as $taux => $montants) {
            $surLaFacture = $lignesAchat->where('taux_tva', $taux)->sum(fn (LigneAchat $ligne): int => $this->calculateur->enCentiemes($ligne->montant_tva));
            $dejaRendue = $rendues->where('taux_tva', $taux)->sum(fn (LigneAvoirFournisseur $ligne): int => $this->calculateur->enCentiemes($ligne->montant_tva));
            $tolerance = EcartsDUnTaux::TOLERANCE_DE_LA_TVA_PAR_LIGNE * count(array_filter($chiffre->lignes, fn (array $ligne): bool => $ligne['taux_tva'] === $taux));
            $rendue = $this->calculateur->enCentiemes($montants['montant_tva']);

            if ($rendue > $surLaFacture - $dejaRendue + $tolerance) {
                throw new LogicException(sprintf(
                    self::REFUS_TVA_EN_TROP,
                    $this->calculateur->enEuros($rendue),
                    CalculateurLigne::tauxEnTexte($this->calculateur->enCentiemes($taux)),
                    $this->calculateur->enEuros(max(0, $surLaFacture - $dejaRendue)),
                    $factureAchat->numero,
                    $this->calculateur->enEuros($tolerance),
                ));
            }
        }
    }
}
