<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Achats\Services\TvaDuFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Même raisonnement que ModifierFactureAction côté vente pour un
 * brouillon : il n'a aucune existence légale ni impact sur le stock (voir
 * ValiderFactureAchatAction), il reste donc librement modifiable, lignes
 * comprises, pour éviter de tout ressaisir en cas d'erreur de saisie.
 *
 * Contrairement à la vente, une facture d'achat saisie manuellement reste
 * modifiable même après validation (voir FactureAchat::estModifiable()) :
 * la personne qui l'enregistre peut se tromper, contrairement à une
 * facture récupérée automatiquement par API ; la corriger demande alors le
 * droit de valider les factures d'achat (voir FactureAchatPolicy::update()).
 * Une facture déjà validée a toutefois déjà impacté le stock, le CMUP et
 * le fournisseur du produit (voir
 * IncrementerStockApresValidationFactureAchat) : toute modification de ses
 * lignes corrige donc la réception produit par produit plutôt que de la
 * réappliquer en aveugle (voir corrigerLaReception()), un nouveau produit
 * ajouté naissant tout de suite pour y entrer (voir
 * ConstructeurLigneAchat::enregistrer()).
 *
 * Comme CreerFactureAchatAction, elle reçoit le fournisseur déjà résolu par
 * une requête limitée au magasin, jamais un fournisseur_id brut venu du
 * formulaire ; elle le relit sous un verrou partagé, après la facture
 * d'achat : archivé depuis que l'écran l'a lu, ou par une requête
 * simultanée qui passe avant ce verrou, il est vu ; archivé ensuite, il
 * attend la fin de la correction (voir ArchiverUneFicheAction). Quand une
 * ligne change le taux de TVA d'un produit, le
 * registre fiscal du magasin est le premier verrou de sa transaction,
 * avant la facture d'achat (voir
 * ConstructeurLigneAchat::verrouillerLeRegistreSiUnTauxChange()).
 *
 * Un magasin suspendu ou fermé ne corrige plus ses factures d'achat (voir
 * Magasin::verifierQuIlEstOuvert()) : comme à la validation (voir
 * ValiderFactureAchatAction), sa ligne se relit sous un verrou partagé,
 * après le registre et la ligne de sa société (voir
 * Societe::verrouillerAvantSonJournalDAudit()) et avant tout le reste, et
 * la fermeture du magasin,
 * qui verrouille le registre puis cette ligne (voir FermerMagasinAction),
 * passe donc tout entière avant ou après la correction : une correction
 * qui attendait une cession est refusée, et aucune réception corrigée
 * n'entre dans le stock que la cession a sorti.
 *
 * Une facture déjà validée verrouille ensuite, par identifiant croissant,
 * les produits que sa réception a reçus et ceux que sa correction reçoit
 * (voir Produit::verrouillerPourLeStock()), avant de les dire encore au
 * catalogue : aucun ne s'archive au milieu de sa correction, qui ne
 * corrige jamais le stock d'un produit archivé ni ne finit sur un
 * produit introuvable.
 *
 * Son auteur est relu sous le verrou de sa ligne, après le magasin et
 * avant la facture d'achat et ses produits : il doit pouvoir saisir les
 * factures d'achat ; puis, la facture relue verrouillée (un brouillon
 * supprimé entre-temps est refusé) et encore modifiable, pouvoir la
 * modifier (voir LecteurDesDroits et
 * FactureAchatPolicy::update()) : le droit de valider, retiré pendant que
 * son écran restait ouvert, ne corrige plus une facture validée.
 *
 * Comme à la création (voir CreerFactureAchatAction), elle garde les
 * montants qu'écrit la facture du fournisseur, et un écart nouveau au-delà
 * de la tolérance se confirme (voir TvaDuFournisseur) ; la TVA de chaque
 * taux se retient en dernier, après la correction de la réception. La
 * correction d'une facture validée s'inscrit au journal d'audit de la
 * société, dans la chaîne du magasin, avec les anciennes et les nouvelles
 * valeurs de ce qu'elle change (lignes, bases et TVA de chaque taux,
 * totaux, voir FactureAchat::valeursAuditees()) : la TVA d'une facture
 * peut-être déjà déclarée ne se réécrit jamais sans trace (art. 271, II-3
 * du CGI).
 *
 * Une facture saisie à la main garde la date de la facture du fournisseur,
 * qui ne s'efface pas (voir FactureAchat::verifierLaDateDeLaSaisie()). Les
 * pièces du fournisseur déposées dans le formulaire s'ajoutent à celles
 * déjà jointes, en brouillon comme après la validation, et lèvent la marque
 * « pièce du fournisseur manquante » (voir PiecesDuFournisseur) ; une pièce
 * se retire depuis la fiche, seulement d'un brouillon.
 */
class ModifierFactureAchatAction
{
    public function __construct(
        private readonly ConstructeurLigneAchat $constructeurLignes,
        private readonly StockLedgerService $stockLedger,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly TvaDuFournisseur $tva,
        private readonly JournalAudit $journalAudit,
        private readonly PiecesDuFournisseur $pieces,
    ) {}

    /**
     * Les champs que le journal d'audit retient de la correction d'une
     * facture validée (voir ActionAuditee::FactureAchatModifiee).
     */
    private const CHAMPS_AUDITES = ['numero_fournisseur', 'date_facture', 'escompte_pourcentage', 'total_ht', 'total_tva', 'total_ttc', 'taux', 'tva_a_verifier', 'lignes'];

    /**
     * @param  array<array-key, array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     * @param  MontantsDuFournisseur|null  $montants  ce qu'écrit la facture du fournisseur au niveau du document ; aucun, les propositions de PILIQI
     * @param  list<PieceDeposee>  $pieces  les pièces du fournisseur ajoutées à la saisie (voir PiecesDuFournisseur)
     *
     * @throws AuthorizationException
     * @throws ConfirmationDesEcartsRequiseException
     */
    public function handle(User $auteur, FactureAchat $factureAchat, Fournisseur $fournisseur, array $lignes, array $attributs = [], ?MontantsDuFournisseur $montants = null, array $pieces = []): FactureAchat
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        $montants ??= MontantsDuFournisseur::aucun();

        return DB::transaction(function () use ($auteur, $factureAchat, $fournisseur, $lignes, $attributs, $montants, $pieces) {
            $this->constructeurLignes->verrouillerLeRegistreSiUnTauxChange($lignes);
            $magasin = app(TenantContext::class)->magasin();
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, FactureAchat::class);

            // Verrouillée comme à sa validation (voir
            // ValiderFactureAchatAction) : une validation simultanée ne peut
            // ni être ignorée ni être appliquée deux fois.
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(FactureAchat::REFUS_INTROUVABLE);

            if (! $factureAchat->estModifiable()) {
                throw new LogicException('Cette facture d\'achat n\'est plus modifiable.');
            }

            $this->lecteur->exiger($compte, 'update', $magasin, $factureAchat);

            $estValidee = $factureAchat->statut === StatutFactureAchat::Validee;
            $fournisseur = Fournisseur::withTrashed()->whereKey($fournisseur->getKey())->sharedLock()->firstOrFail();

            // Les produits que la réception a reçus et ceux que sa correction
            // reçoit, relus sous verrou avant d'être contrôlés : un archivage
            // simultané passe tout entier avant la correction, qui le voit,
            // ou après elle.
            if ($estValidee) {
                Produit::verrouillerPourLeStock([...$factureAchat->lignes()->pluck('produit_id'), ...array_column($lignes, 'produit_id')]);
            }

            if ($estValidee && ($produitArchive = $factureAchat->produitArchive()) !== null) {
                throw new LogicException("Le produit « {$produitArchive->nom} » a été archivé : restaurez-le depuis le catalogue avant de corriger cette facture d'achat.");
            }

            // Corriger la réception rattache ses produits au fournisseur de
            // leur dernier achat validé (voir corrigerLaReception()) :
            // archivé, comme à la validation, il ne le devient pas sans être
            // restauré. Un brouillon, sans réception, reste modifiable.
            if ($estValidee && $fournisseur->trashed()) {
                throw new LogicException("Le fournisseur « {$fournisseur->nom} » a été archivé : restaurez-le depuis la liste des fournisseurs avant de corriger cette facture d'achat.");
            }

            // Les produits que la réception a reçus avant sa correction ; un
            // brouillon n'a rien reçu.
            $produitsAvant = $estValidee
                ? array_values(array_unique(ValeursLues::entiers($factureAchat->lignes->pluck('produit_id'))))
                : [];
            $avant = $estValidee ? array_intersect_key($factureAchat->valeursAuditees(), array_flip(self::CHAMPS_AUDITES)) : [];

            return $this->operateur->pendant($compte, function () use ($compte, $factureAchat, $fournisseur, $lignes, $attributs, $montants, $pieces, $estValidee, $produitsAvant, $avant): FactureAchat {
                $factureAchat->fill([...$attributs, 'fournisseur_id' => $fournisseur->getKey()])->verifierLaDateDeLaSaisie();
                $factureAchat->save();

                $document = $this->constructeurLignes->enregistrer($compte, $factureAchat, $lignes, $montants);

                if ($estValidee) {
                    $this->corrigerLaReception(ModeleRelu::de($factureAchat, 'lignes'), $produitsAvant);
                }

                $this->tva->retenir($compte, $factureAchat, $document, $montants);

                if ($estValidee) {
                    $this->journalAudit->inscrireLesChangements(ActionAuditee::FactureAchatModifiee, $compte, $factureAchat->magasin, $avant, array_intersect_key($factureAchat->valeursAuditees(), array_flip(self::CHAMPS_AUDITES)), $factureAchat);
                }

                $this->pieces->joindre($compte, $factureAchat, $pieces);

                return ModeleRelu::de($factureAchat, 'lignes');
            });
        });
    }

    /**
     * Ne rejoue PAS un nouveau mouvement de réception (qui fausserait
     * l'historique) : chaque produit de la facture, avant ou après la
     * correction, voit sa réception corrigée dans le grand livre et son
     * coût recalculé (voir StockLedgerService::corrigerUneReception()),
     * une fois ses lignes enregistrées : c'est ce que la réception a
     * réellement fait entrer en stock, et non le suivi du stock coché ou
     * décoché sur sa fiche ou sa ligne, qui dit ce qui se corrige. Chacun
     * reprend aussi le fournisseur de son dernier achat validé -- pas
     * forcément celui de cette facture, si elle n'est pas la plus récente --
     * et n'en a plus aucun quand il n'en reste aucun.
     *
     * @param  list<int>  $produitsAvant
     */
    private function corrigerLaReception(FactureAchat $factureAchat, array $produitsAvant): void
    {
        $quantitesApres = $factureAchat->lignes
            ->groupBy('produit_id')
            ->map(fn (Collection $lignesDuProduit): int => ValeursLues::entier($lignesDuProduit->sum('quantite')));

        foreach (array_unique([...$produitsAvant, ...$quantitesApres->keys()->all()]) as $produitId) {
            $produit = Produit::query()->findOrFail($produitId);

            $this->stockLedger->corrigerUneReception(
                $produit,
                $factureAchat,
                in_array($produitId, $produitsAvant, true),
                $quantitesApres->get($produitId, 0),
                $this->lotsDesReceptions($produit),
                "Correction de la facture d'achat {$factureAchat->numero}",
            );

            $dernierAchat = $produit->dernierAchatValide();
            $produit->rattacherAuFournisseur($dernierAchat === null ? null : ValeurRequise::de($dernierAchat->factureAchat, 'la facture d\'achat du dernier achat validé')->fournisseur_id);
        }
    }

    /**
     * Les lignes de chaque facture d'achat validée du produit, au coût que
     * chacune a réellement payé (voir LigneAchat::coutUnitaireHt()), de la
     * réception la plus ancienne à la plus récente.
     *
     * @return array<int, list<array{quantite: int, cout_unitaire: float}>>
     */
    private function lotsDesReceptions(Produit $produit): array
    {
        $lots = [];

        foreach ($produit->achatsValides()->with('factureAchat')->get() as $ligne) {
            $lots[$ligne->facture_achat_id][] = ['quantite' => $ligne->quantite, 'cout_unitaire' => $ligne->coutUnitaireHt()];
        }

        return $lots;
    }
}
