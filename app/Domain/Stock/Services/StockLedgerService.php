<?php

namespace App\Domain\Stock\Services;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Events\StockPasseSousLeSeuilDAlerte;
use App\Domain\Stock\Models\MouvementStock;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Point d'entrée UNIQUE pour toute modification de stock. Aucun autre
 * endroit du code ne doit écrire dans produits.stock_quantite ou dans
 * mouvements_stock directement. Les ventes, les avoirs, la validation des
 * factures d'achat et les avoirs fournisseur l'appellent par des listeners
 * de leurs événements ; la correction d'une facture d'achat déjà validée
 * l'appelle directement (voir ModifierFactureAchatAction).
 *
 * Le stock est valorisé au coût moyen unitaire pondéré (CMUP, voir
 * Produit::$cmup_ht), recalculé après chaque entrée achetée (voir
 * receptionner()) et après chaque retour au fournisseur, qui sort au coût
 * de sa ligne d'achat (voir retournerAuFournisseur()) : toute autre sortie
 * (vente, ajustement) et toute autre entrée (retour client, ajustement) se
 * fait au CMUP du moment, qui ne change donc pas. Une réception corrigée
 * après coup rejoue tout le grand livre du produit (voir
 * corrigerUneReception()).
 *
 * Seul à voir chaque mouvement, il signale aussi le passage d'un produit
 * à son seuil d'alerte (voir StockPasseSousLeSeuilDAlerte).
 */
class StockLedgerService
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Entrée d'une réception d'achat, au coût réellement payé (voir
     * LigneAchat::coutUnitaireHt()) : le CMUP devient la moyenne du stock
     * en place et de cette entrée, pondérée par leurs quantités. Un stock
     * vide ou négatif ne pèse rien, le CMUP repart alors du coût de
     * l'entrée -- comme pour un produit qui ne suit pas son stock, dont le
     * coût est celui du dernier achat.
     */
    public function receptionner(Produit $produit, int $quantite, float $coutUnitaireHt, Model $source, string $note): ?MouvementStock
    {
        return DB::transaction(function () use ($produit, $quantite, $coutUnitaireHt, $source, $note) {
            $produitVerrouille = $this->verrouiller($produit);

            if (! $produitVerrouille->suivi_stock) {
                $this->changerLeCmup($produit, $produitVerrouille, $coutUnitaireHt);

                return null;
            }

            $this->changerLeCmup($produit, $produitVerrouille, $this->cmupApresUneEntree(
                $produitVerrouille->stock_quantite,
                (float) $produitVerrouille->cmup_ht,
                $quantite,
                $coutUnitaireHt,
            ));

            return $this->enregistrer($produit, TypeMouvementStock::ReceptionAchat, $quantite, $source, $note);
        });
    }

    /**
     * Correction d'une réception déjà validée ($reception : la facture
     * d'achat corrigée), pour un produit qui y figurait avant la correction
     * ou y figure après, avec le suivi du stock qu'il a une fois ses lignes
     * enregistrées.
     *
     * La quantité : ce que la réception a réellement fait entrer se lit
     * dans le grand livre (ses mouvements, dont elle est la source), et
     * seul l'écart avec la quantité corrigée passe par un ajustement, dont
     * elle est aussi la source -- même si le produit ne suit plus son
     * stock : le grand livre garde ce que dit la facture. Un produit qui
     * figurait déjà sur la réception sans qu'aucun mouvement n'en vienne
     * n'y est jamais entré (il ne suivait pas son stock à la validation) :
     * rien à corriger, même s'il le suit désormais. Un produit ajouté par la
     * correction n'entre en stock que s'il le suit, comme à la validation
     * d'une nouvelle réception (voir receptionner()).
     *
     * Le coût : pour un produit qui suit son stock, le CMUP se recalcule en
     * rejouant tout son grand livre (voir cmupRejoue()), chaque réception
     * entrant au coût actuel de ses lignes et chaque retour au fournisseur
     * sortant au coût de sa ligne d'achat. Les articles déjà vendus sont
     * sortis à leur CMUP du moment et ne reviennent pas : 10 reçus à 2 €,
     * 10 à 10 € (CMUP 6), 10 vendus, puis la seconde réception corrigée à
     * 5 € -- le stock restant vaut 3,50 € l'article. Un produit qui ne suit
     * pas son stock n'a pas de quantités à pondérer : il coûte sa dernière
     * ligne achetée (voir coutDeLaDerniereLigneAchetee()).
     *
     * @param  array<int|string, list<array{quantite: int, cout_unitaire: float}>>  $lotsDesReceptions  les lignes de chaque réception du produit (par identifiant de sa source), de la plus ancienne à la plus récente
     */
    public function corrigerUneReception(
        Produit $produit,
        Model $reception,
        bool $figuraitSurLaReception,
        int $quantiteRecue,
        array $lotsDesReceptions,
        string $note,
    ): void {
        DB::transaction(function () use ($produit, $reception, $figuraitSurLaReception, $quantiteRecue, $lotsDesReceptions, $note) {
            $produitVerrouille = $this->verrouiller($produit);
            $grandLivre = $produitVerrouille->mouvementsStock()->orderBy('id')->get();
            $mouvementsDeLaReception = $grandLivre->filter(fn (MouvementStock $mouvement): bool => $this->vientDe($mouvement, $reception->getMorphClass(), $reception->getKey()));
            $entreEnStock = $figuraitSurLaReception ? $mouvementsDeLaReception->isNotEmpty() : $produitVerrouille->suivi_stock;

            if ($entreEnStock) {
                $ecart = $quantiteRecue - $this->variationTotale($mouvementsDeLaReception);

                if ($ecart !== 0) {
                    $grandLivre->push($this->ajuster($produitVerrouille, $produitVerrouille->stock_quantite + $ecart, $note, $reception));
                    $produit->setAttribute('stock_quantite', $produitVerrouille->stock_quantite)->syncOriginalAttribute('stock_quantite');
                }
            }

            $cout = $produitVerrouille->suivi_stock
                ? $this->cmupRejoue($produitVerrouille, $grandLivre, $reception->getMorphClass(), $lotsDesReceptions)
                : $this->coutDeLaDerniereLigneAchetee($lotsDesReceptions);

            if ($cout !== null) {
                $this->changerLeCmup($produit, $produitVerrouille, $cout);
            }
        });
    }

    /**
     * Retour au fournisseur d'articles d'une ligne d'achat, par un avoir
     * fournisseur ($source) : ils sortent du stock au coût de cette ligne
     * ($coutUnitaireHt, voir LigneAchat::coutUnitaireHt()), et le CMUP du
     * stock restant se recalcule (voir
     * CalculateurLigne::coutMoyenApresUneSortie()) -- 10 reçus à 2 €, 10 à
     * 10 € (CMUP 6 €), les 10 à 10 € retournés : il en reste 10 à 2 €. Sans
     * stock restant, le CMUP ne change pas ; un stock ne vaut jamais moins
     * que rien. Le mouvement garde ce coût de sortie, pour qu'une réception
     * corrigée après coup rejoue le retour tel quel (voir cmupRejoue()).
     */
    public function retournerAuFournisseur(Produit $produit, int $quantite, float $coutUnitaireHt, Model $source, string $note): MouvementStock
    {
        $this->verifierLeMouvement($produit, $quantite);

        return DB::transaction(function () use ($produit, $quantite, $coutUnitaireHt, $source, $note) {
            $produitVerrouille = $this->verrouiller($produit);
            $cmup = $this->calculateur->coutMoyenApresUneSortie(
                max($produitVerrouille->stock_quantite, 0),
                (float) $produitVerrouille->cmup_ht,
                $quantite,
                $coutUnitaireHt,
            );

            if ($cmup !== null) {
                $this->changerLeCmup($produit, $produitVerrouille, $cmup);
            }

            return $this->appliquer($produit, TypeMouvementStock::RetourFournisseur, $quantite, $source, $note, $coutUnitaireHt);
        });
    }

    /**
     * Retour client d'articles vendus, par un avoir : n'entre en
     * stock que ce que la vente en a fait sortir et qui n'y est pas encore
     * revenu ($quantiteEncoreSortie, lue sous le verrou du produit pour que
     * deux retours simultanés ne rendent pas deux fois les mêmes articles).
     * Un article vendu sans suivi du stock n'en est jamais sorti : rien n'y
     * revient.
     *
     * @param  Closure(): int  $quantiteEncoreSortie
     */
    public function retournerDeLaVente(Produit $produit, int $quantite, Closure $quantiteEncoreSortie, Model $source, string $note): ?MouvementStock
    {
        return DB::transaction(function () use ($produit, $quantite, $quantiteEncoreSortie, $source, $note) {
            $this->verrouiller($produit);
            $quantiteRetournee = min($quantite, $quantiteEncoreSortie());

            return $quantiteRetournee > 0
                ? $this->enregistrer($produit, TypeMouvementStock::RetourClient, $quantiteRetournee, $source, $note)
                : null;
        });
    }

    /**
     * Enregistre un mouvement typé (vente, réception, retour client...) et
     * met à jour le cache produits.stock_quantite de façon atomique. Un
     * retour fournisseur, qui sort à son propre coût, passe par
     * retournerAuFournisseur().
     */
    public function enregistrer(
        Produit $produit,
        TypeMouvementStock $type,
        int $quantite,
        ?Model $source = null,
        ?string $note = null,
    ): MouvementStock {
        if ($type === TypeMouvementStock::RetourFournisseur) {
            throw new InvalidArgumentException('Un retour fournisseur passe par retournerAuFournisseur(), avec le coût de sa ligne d\'achat.');
        }

        $this->verifierLeMouvement($produit, $quantite);

        return $this->appliquer($produit, $type, $quantite, $source, $note);
    }

    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function verifierLeMouvement(Produit $produit, int $quantite): void
    {
        if ($quantite <= 0) {
            throw new InvalidArgumentException("La quantité d'un mouvement de stock doit être strictement positive.");
        }

        if (! $produit->suivi_stock) {
            throw new LogicException("Le produit [{$produit->id}] ne suit pas son stock (suivi_stock = false).");
        }
    }

    /**
     * Correction manuelle (inventaire physique, casse...) ou d'une
     * réception ($source : la facture d'achat corrigée) : on part de la
     * quantité cible plutôt que d'un delta, pour coller à l'usage ("j'ai
     * compté 42 articles") plutôt qu'à une mécanique interne. Le sens de
     * l'écart donne le type du mouvement (entrée ou sortie).
     */
    public function ajuster(Produit $produit, int $quantiteCible, string $note, ?Model $source = null): MouvementStock
    {
        $delta = $quantiteCible - $produit->stock_quantite;

        if ($delta === 0) {
            throw new InvalidArgumentException('Aucun écart à ajuster.');
        }

        $type = $delta > 0 ? TypeMouvementStock::AjustementEntree : TypeMouvementStock::AjustementSortie;

        return $this->appliquer($produit, $type, abs($delta), $source, $note);
    }

    /**
     * @param  float|null  $coutUnitaireHt  le coût de sortie d'un retour fournisseur (voir retournerAuFournisseur())
     */
    private function appliquer(
        Produit $produit,
        TypeMouvementStock $type,
        int $quantite,
        ?Model $source,
        ?string $note,
        ?float $coutUnitaireHt = null,
    ): MouvementStock {
        return DB::transaction(function () use ($produit, $type, $quantite, $source, $note, $coutUnitaireHt) {
            $produitVerrouille = $this->verrouiller($produit);

            $nouvelleQuantite = $produitVerrouille->stock_quantite + $type->variation($quantite);

            $mouvement = MouvementStock::create([
                'produit_id' => $produitVerrouille->id,
                'type' => $type,
                'quantite' => $quantite,
                'cout_unitaire_ht' => $coutUnitaireHt,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'survenu_le' => now(),
                'cree_par' => app(OperateurCourant::class)->personnePosee()?->getKey(),
                'note' => $note,
            ]);

            $etaitEnAlerte = $produitVerrouille->enAlerteStock();
            $produitVerrouille->forceFill(['stock_quantite' => $nouvelleQuantite])->save();
            $produit->setAttribute('stock_quantite', $nouvelleQuantite)->syncOriginalAttribute('stock_quantite');

            // Une seule alerte au passage du seuil, pas une à chaque vente
            // tant que le stock reste bas.
            if (! $etaitEnAlerte && $produitVerrouille->enAlerteStock()) {
                StockPasseSousLeSeuilDAlerte::dispatch($produitVerrouille);
            }

            return $mouvement;
        });
    }

    /**
     * Le CMUP du produit si chaque réception avait été saisie juste dès le
     * départ : le grand livre rejoué dans l'ordre, chaque réception entrée
     * en stock entrant à sa place, au coût actuel de ses lignes, une ligne
     * après l'autre comme à sa validation (voir receptionner()). Ses
     * mouvements (ajustements de correction compris) disent toujours la
     * quantité de ses lignes, puisque chaque correction les y ramène (voir
     * corrigerUneReception()) ; une réception qui n'est jamais entrée en
     * stock, ou dont le produit a été retiré, n'en a fait entrer aucun.
     * Chaque retour au fournisseur sort au coût de sa ligne d'achat, gardé
     * dans son mouvement, et recalcule le CMUP comme à son émission (voir
     * retournerAuFournisseur()) ; les autres sorties et entrées ne changent
     * pas le CMUP, et un stock vide ou négatif ne pèse rien.
     *
     * Le stock que le grand livre n'explique pas (aucun en pratique : seul
     * ce service fait varier le stock, toujours par un mouvement) est
     * valorisé au CMUP actuel. Sans aucune réception à rejouer, le coût
     * est celui de la dernière ligne achetée, comme pour un produit qui ne
     * suit pas son stock.
     *
     * @param  Collection<int, MouvementStock>  $grandLivre  dans l'ordre
     * @param  array<int|string, list<array{quantite: int, cout_unitaire: float}>>  $lotsDesReceptions
     */
    private function cmupRejoue(Produit $produit, Collection $grandLivre, string $typeDeReception, array $lotsDesReceptions): ?float
    {
        $stock = $produit->stock_quantite - $this->variationTotale($grandLivre);
        $cmup = (float) $produit->cmup_ht;
        $receptionsVues = [];
        $uneReceptionAPese = false;

        foreach ($grandLivre as $mouvement) {
            $receptionId = $mouvement->source_type === $typeDeReception ? $mouvement->source_id : null;

            if ($receptionId === null) {
                if ($mouvement->type === TypeMouvementStock::RetourFournisseur) {
                    $cmup = $this->calculateur->coutMoyenApresUneSortie(max($stock, 0), $cmup, $mouvement->quantite, (float) $mouvement->cout_unitaire_ht) ?? $cmup;
                }

                $stock += $mouvement->type->variation($mouvement->quantite);

                continue;
            }

            if (isset($receptionsVues[$receptionId])) {
                continue;
            }

            $receptionsVues[$receptionId] = true;
            $quantite = $this->variationTotale($grandLivre->filter(fn (MouvementStock $autre): bool => $this->vientDe($autre, $typeDeReception, $receptionId)));

            if ($quantite <= 0 || ($lotsDesReceptions[$receptionId] ?? []) === []) {
                $stock += $quantite;

                continue;
            }

            foreach ($lotsDesReceptions[$receptionId] as $lot) {
                $cmup = $this->cmupApresUneEntree($stock, $cmup, $lot['quantite'], $lot['cout_unitaire']);
                $stock += $lot['quantite'];
            }

            $uneReceptionAPese = true;
        }

        return $uneReceptionAPese ? $cmup : $this->coutDeLaDerniereLigneAchetee($lotsDesReceptions);
    }

    /**
     * Le coût de la dernière ligne de la réception la plus récente, null
     * s'il n'y en a aucune.
     *
     * @param  array<int|string, list<array{quantite: int, cout_unitaire: float}>>  $lotsDesReceptions
     */
    private function coutDeLaDerniereLigneAchetee(array $lotsDesReceptions): ?float
    {
        $derniereReception = end($lotsDesReceptions);

        return $derniereReception === false || $derniereReception === [] ? null : (float) end($derniereReception)['cout_unitaire'];
    }

    private function cmupApresUneEntree(int $stock, float $cmup, int $quantite, float $coutUnitaireHt): float
    {
        return $this->calculateur->coutMoyenPondere([
            ['quantite' => max($stock, 0), 'cout_unitaire' => $cmup],
            ['quantite' => $quantite, 'cout_unitaire' => $coutUnitaireHt],
        ]) ?? $coutUnitaireHt;
    }

    private function vientDe(MouvementStock $mouvement, string $typeDeSource, int|string $sourceId): bool
    {
        return $mouvement->source_type === $typeDeSource && (string) $mouvement->source_id === (string) $sourceId;
    }

    /**
     * @param  iterable<MouvementStock>  $mouvements
     */
    private function variationTotale(iterable $mouvements): int
    {
        $variation = 0;

        foreach ($mouvements as $mouvement) {
            $variation += $mouvement->type->variation($mouvement->quantite);
        }

        return $variation;
    }

    /**
     * Verrou pessimiste : sérialise les écritures concurrentes sur ce
     * produit (ex: deux ventes simultanées de la dernière unité en stock)
     * pour empêcher toute race condition. Archivé compris : une facture ou
     * un avoir de vente émis après l'archivage de son produit le fait
     * toujours sortir ou entrer en stock (voir LigneFacture::produit() et
     * LigneAvoir::produit()).
     */
    private function verrouiller(Produit $produit): Produit
    {
        return Produit::withTrashed()->whereKey($produit->getKey())->lockForUpdate()->firstOrFail();
    }

    private function changerLeCmup(Produit $produit, Produit $produitVerrouille, float $cmup): void
    {
        $produitVerrouille->forceFill(['cmup_ht' => $cmup])->save();
        $produit->setAttribute('cmup_ht', $produitVerrouille->cmup_ht)->syncOriginalAttribute('cmup_ht');
    }
}
