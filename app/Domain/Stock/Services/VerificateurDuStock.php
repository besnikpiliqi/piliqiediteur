<?php

namespace App\Domain\Stock\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * La partie « stock » de la vérification de l'intégrité du magasin actif
 * (voir VerificateurIntegrite), chaque nuit, toujours complète, en
 * entiers, sans somme SQL sur une colonne décimale, sans jamais rien
 * recalculer ni réécrire : elle relève ce qu'une écriture faite en dehors
 * de PILIQI aurait changé malgré les gardes de la base (voir
 * GardesDuStock) :
 *
 * - le stock de chaque produit, archivé compris, égale la somme signée de
 *   son grand livre ;
 * - chaque facture d'achat validée, inventaire d'ouverture validé et avoir
 *   fournisseur émis a son entrée au journal d'audit chaîné de la société,
 *   tant qu'elle n'a pas pu s'effacer à son échéance, et ce que ses
 *   mouvements ont fait bouger de chaque produit égale ses lignes ;
 * - les lignes de chaque facture d'achat validée sont celles que le
 *   journal d'audit a inscrites en dernier (validation, puis corrections),
 *   tant qu'il en garde une ;
 * - le coût moyen de chaque produit est l'un de ceux que donnent son grand
 *   livre et ses achats validés, rejoués sur son histoire réelle (voir
 *   StockLedgerService::coutsMoyensPossibles()), et son fournisseur celui
 *   de son dernier achat validé.
 *
 * Le journal d'audit est une donnée de la société : il se lit filtré
 * explicitement par la société et par le magasin vérifié.
 */
class VerificateurDuStock
{
    private const PRODUITS_PAR_PAQUET = 200;

    public function __construct(
        private readonly StockLedgerService $stock,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @return list<AnomalieFiscale>
     */
    public function anomalies(Magasin $magasin): array
    {
        return [
            ...$this->stocksHorsDuGrandLivre(),
            ...$this->documentsSansLeurAudit($magasin),
            ...$this->mouvementsHorsDesLignes($magasin),
            ...$this->lignesDAchatHorsDeLAudit($magasin),
            ...$this->coutsEtFournisseursHorsDesAchats(),
        ];
    }

    /**
     * @return list<AnomalieFiscale>
     */
    private function stocksHorsDuGrandLivre(): array
    {
        $grandLivre = MouvementStock::query()->toBase()->selectRaw('produit_id, sum('.self::variation().') AS net')->groupBy('produit_id');

        return Produit::withTrashed()->toBase()
            ->leftJoinSub($grandLivre, 'grand_livre', 'grand_livre.produit_id', '=', 'produits.id')
            ->select(['produits.id', 'produits.reference', 'produits.nom', 'produits.stock_quantite'])
            ->selectRaw('coalesce(grand_livre.net, 0) AS net')
            ->whereRaw('produits.stock_quantite <> coalesce(grand_livre.net, 0)')
            ->orderBy('produits.id')
            ->get()
            ->map(fn (stdClass $produit): AnomalieFiscale => self::anomalie(
                'Le stock du produit '.self::libelleDuProduit($produit).' est de '.ValeurSimple::enEntier($produit->stock_quantite).' article(s), au lieu des '.ValeurSimple::enEntier($produit->net).' de son grand livre.',
                ['produit_id' => ValeurSimple::enEntier($produit->id)],
            ))
            ->values()
            ->all();
    }

    /**
     * Chaque facture d'achat validée, inventaire d'ouverture validé et
     * avoir fournisseur émis a son entrée au journal d'audit, tant qu'elle
     * n'a pas pu s'effacer à son échéance (voir PurgerJournalAudit), quand
     * le document reste : seul est contrôlé un document validé ou émis
     * après l'horizon de conservation de la catégorie de son entrée (voir
     * horizonDeConservation()).
     *
     * @return list<AnomalieFiscale>
     */
    private function documentsSansLeurAudit(Magasin $magasin): array
    {
        $anomalies = [];

        foreach ([
            [FactureAchat::query()->where('statut', StatutFactureAchat::Validee->value), ActionAuditee::FactureAchatValidee, 'date_validation', 'La facture d\'achat'],
            [InventaireOuverture::query()->where('statut', StatutInventaireOuverture::Valide->value), ActionAuditee::InventaireOuvertureValide, 'date_validation', 'L\'inventaire d\'ouverture'],
            [AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise->value), ActionAuditee::AvoirFournisseurEmis, 'date_emission', 'L\'avoir fournisseur'],
        ] as [$documents, $action, $colonneDeLInstant, $libelle]) {
            $alias = $documents->getModel()->getMorphClass();
            $table = $documents->getModel()->getTable();
            $horizon = self::horizonDeConservation($action);

            foreach ($documents->toBase()
                ->select(["{$table}.id", "{$table}.numero"])
                ->when($horizon !== null, fn (QueryBuilder $recents): QueryBuilder => $recents->where("{$table}.{$colonneDeLInstant}", '>', Instant::enBase(ValeurRequise::de($horizon, 'l\'horizon de conservation de son entrée'))))
                ->whereNotExists(fn (QueryBuilder $audit): QueryBuilder => $this->entreesDAudit($audit, $magasin, $action)
                    ->where('journal_audit.cible_type', $alias)
                    ->whereColumn('journal_audit.cible_id', "{$table}.id"))
                ->orderBy("{$table}.id")
                ->get() as $document) {
                $anomalies[] = self::anomalie(
                    "{$libelle} ".(ValeursLues::texteOuNul($document->numero) ?? '#'.ValeurSimple::enEntier($document->id)).' a fait bouger le stock sans son entrée au journal d\'audit : elle n\'a pas été écrite par PILIQI.',
                    ['document_type' => $alias, 'document_id' => ValeurSimple::enEntier($document->id)],
                );
            }
        }

        return $anomalies;
    }

    /**
     * Ce que les mouvements de chaque facture d'achat, inventaire
     * d'ouverture et avoir fournisseur ont fait bouger de chaque produit,
     * comparé à ses lignes ; un produit que le document n'a jamais fait
     * entrer ni sortir (il ne suivait pas son stock) n'est pas compté.
     *
     * @return list<AnomalieFiscale>
     */
    private function mouvementsHorsDesLignes(Magasin $magasin): array
    {
        $anomalies = [];
        $sources = [
            (new FactureAchat)->getMorphClass() => ['lignes_achat', 'facture_achat_id', 1, 'la facture d\'achat'],
            (new InventaireOuverture)->getMorphClass() => ['lignes_inventaire_ouverture', 'inventaire_ouverture_id', 1, 'l\'inventaire d\'ouverture'],
            (new AvoirFournisseur)->getMorphClass() => ['lignes_avoir_fournisseur', 'avoir_fournisseur_id', -1, 'l\'avoir fournisseur'],
        ];

        foreach ($sources as $alias => [$tableDesLignes, $colonne, $sens, $libelle]) {
            $nets = MouvementStock::query()->toBase()
                ->selectRaw('source_id, produit_id, sum('.self::variation().') AS net')
                ->where('source_type', $alias)
                ->groupBy('source_id', 'produit_id')
                ->orderBy('source_id')
                ->orderBy('produit_id')
                ->get();
            $lignes = DB::table($tableDesLignes)
                ->where('magasin_id', $magasin->getKey())
                ->whereIn($colonne, $nets->pluck('source_id')->unique()->all())
                ->selectRaw("{$colonne} AS source_id, produit_id, sum(quantite) AS quantite")
                ->groupBy($colonne, 'produit_id')
                ->get()
                ->mapWithKeys(fn (stdClass $ligne): array => [ValeurSimple::enEntier($ligne->source_id).'-'.ValeurSimple::enEntier($ligne->produit_id) => ValeurSimple::enEntier($ligne->quantite)]);

            foreach ($nets as $net) {
                $attendu = $lignes->get(ValeurSimple::enEntier($net->source_id).'-'.ValeurSimple::enEntier($net->produit_id), 0);

                if ($sens * ValeurSimple::enEntier($net->net) !== $attendu) {
                    $anomalies[] = self::anomalie(
                        "Les mouvements du produit #{$net->produit_id} venus de {$libelle} #{$net->source_id} font ".($sens * ValeurSimple::enEntier($net->net))." article(s), au lieu des {$attendu} de ses lignes.",
                        ['document_type' => $alias, 'document_id' => ValeurSimple::enEntier($net->source_id), 'produit_id' => ValeurSimple::enEntier($net->produit_id)],
                    );
                }
            }
        }

        return $anomalies;
    }

    /**
     * Les lignes de chaque facture d'achat validée, comparées au dernier
     * état que le journal d'audit en a inscrit (sa validation, puis
     * chaque correction qui les a changées).
     *
     * @return list<AnomalieFiscale>
     */
    private function lignesDAchatHorsDeLAudit(Magasin $magasin): array
    {
        $alias = (new FactureAchat)->getMorphClass();
        $inscrites = [];

        foreach ($this->entreesDAudit(DB::table('journal_audit'), $magasin, ActionAuditee::FactureAchatValidee, ActionAuditee::FactureAchatModifiee)
            ->where('journal_audit.cible_type', $alias)
            ->orderBy('journal_audit.id')
            ->get(['journal_audit.cible_id', 'journal_audit.nouvelles_valeurs']) as $entree) {
            $valeurs = json_decode(ValeursLues::texteOuNul($entree->nouvelles_valeurs) ?? 'null', true);

            if (is_array($valeurs) && array_key_exists('lignes', $valeurs)) {
                $inscrites[ValeurSimple::enEntier($entree->cible_id)] = $valeurs['lignes'];
            }
        }

        $anomalies = [];

        FactureAchat::query()->where('statut', StatutFactureAchat::Validee->value)->with(['lignes', 'taux'])->chunkById(100, function (Collection $achats) use ($inscrites, &$anomalies): void {
            foreach ($achats as $achat) {
                if (! $achat instanceof FactureAchat || ! array_key_exists($achat->getKey(), $inscrites)) {
                    continue;
                }

                if (self::cleesTriees($achat->valeursAuditees()['lignes']) !== self::cleesTriees($inscrites[$achat->getKey()])) {
                    $anomalies[] = self::anomalie(
                        "Les lignes de la facture d'achat {$achat->numero} ne sont plus celles que le journal d'audit a inscrites : elles ont été réécrites en dehors de PILIQI.",
                        ['document_type' => $achat->getMorphClass(), 'document_id' => ValeurSimple::enEntier($achat->getKey())],
                    );
                }
            }
        });

        return $anomalies;
    }

    /**
     * Le coût moyen et le fournisseur de chaque produit, par paquets.
     *
     * @return list<AnomalieFiscale>
     */
    private function coutsEtFournisseursHorsDesAchats(): array
    {
        $anomalies = [];

        Produit::withTrashed()->orderBy('id')->chunkById(self::PRODUITS_PAR_PAQUET, function (Collection $produits) use (&$anomalies): void {
            $grandsLivres = MouvementStock::query()->whereIn('produit_id', $produits->modelKeys())->orderBy('id')->get()->groupBy('produit_id');
            $achats = LigneAchat::query()
                ->whereIn('produit_id', $produits->modelKeys())
                ->whereHas('factureAchat', fn ($factureAchat) => $factureAchat->where('statut', StatutFactureAchat::Validee->value))
                ->with('factureAchat')
                ->orderBy(FactureAchat::query()->select('rang_validation')->whereColumn('factures_achat.id', 'lignes_achat.facture_achat_id'))
                ->orderBy('ordre')
                ->orderBy('id')
                ->get()
                ->groupBy('produit_id');

            foreach ($produits as $produit) {
                if (! $produit instanceof Produit) {
                    continue;
                }

                $lignes = $achats->get($produit->getKey(), new Collection);
                $couts = $this->stock->coutsMoyensPossibles($produit, $grandsLivres->get($produit->getKey(), new Collection), $this->stock->lotsDe($lignes));
                $enBase = $this->calculateur->enDixMilliemes($produit->cmup_ht);

                if ($couts !== [] && ! in_array($enBase, $couts, true)) {
                    $anomalies[] = self::anomalie(
                        'Le coût moyen du produit '.self::libelleDuProduit($produit).' est de '.self::enEuros($enBase).' au lieu de '.implode(' ou ', array_map(self::enEuros(...), $couts)).' que rejouent son grand livre et ses achats.',
                        ['produit_id' => ValeurSimple::enEntier($produit->getKey())],
                    );
                }

                $dernier = $lignes->last();
                $fournisseur = $dernier instanceof LigneAchat ? $dernier->factureAchat?->fournisseur_id : null;

                if ((int) $fournisseur !== (int) $produit->fournisseur_id) {
                    $anomalies[] = self::anomalie(
                        'Le fournisseur du produit '.self::libelleDuProduit($produit).' est #'.($produit->fournisseur_id ?? '—').' au lieu de #'.($fournisseur ?? '—').', celui de son dernier achat validé.',
                        ['produit_id' => ValeurSimple::enEntier($produit->getKey())],
                    );
                }
            }
        });

        return $anomalies;
    }

    /**
     * L'instant avant lequel l'entrée au journal d'audit de cette action a
     * pu s'effacer à son échéance : une entrée s'efface une fois passée la
     * durée de conservation de sa catégorie, comptée à l'heure murale de
     * Paris depuis son inscription (voir JournalAudit), qui suit la
     * validation ou l'émission de son document dans la même transaction ;
     * un jour de marge couvre les changements d'heure et les années
     * bissextiles. Null pour une catégorie conservée jusqu'à la fin de la
     * relation.
     */
    private static function horizonDeConservation(ActionAuditee $action): ?CarbonImmutable
    {
        $duree = $action->categorie()->dureeDeConservation();

        if ($duree === null) {
            return null;
        }

        $paris = Calendrier::deParis();

        return $paris->decaler($paris->decaler(CarbonImmutable::now(), $duree->copy()->invert()), CarbonInterval::day());
    }

    /**
     * Les entrées du journal d'audit de la société du magasin, dans la
     * chaîne de ce magasin, de ces actions : filtre explicite, le journal
     * étant une donnée de la société.
     */
    private function entreesDAudit(QueryBuilder $audit, Magasin $magasin, ActionAuditee ...$actions): QueryBuilder
    {
        return $audit->from('journal_audit')
            ->where('journal_audit.societe_id', $magasin->societe_id)
            ->where('journal_audit.magasin_id', $magasin->getKey())
            ->whereIn('journal_audit.action', array_map(static fn (ActionAuditee $action): string => $action->value, $actions));
    }

    /**
     * La variation signée d'un mouvement, en SQL, depuis son type (voir
     * TypeMouvementStock::estEntree()).
     */
    private static function variation(): string
    {
        $entrees = implode(', ', array_map(static fn (TypeMouvementStock $type): string => "'{$type->value}'", array_filter(TypeMouvementStock::cases(), static fn (TypeMouvementStock $type): bool => $type->estEntree())));

        return "CASE WHEN type IN ({$entrees}) THEN quantite ELSE -quantite END";
    }

    /**
     * Ces valeurs, les clés de chaque tableau triées, comme le journal
     * d'audit les inscrit (contenu canonique, voir JournalAudit).
     */
    private static function cleesTriees(mixed $valeurs): mixed
    {
        if (! is_array($valeurs)) {
            return $valeurs;
        }

        $triees = array_map(self::cleesTriees(...), $valeurs);

        if (! array_is_list($triees)) {
            ksort($triees);
        }

        return $triees;
    }

    private static function libelleDuProduit(stdClass|Model $produit): string
    {
        return ValeursLues::texte($produit->reference).' ('.ValeursLues::texte($produit->nom).')';
    }

    private static function enEuros(int $dixMilliemes): string
    {
        return intdiv($dixMilliemes, 10000).','.str_pad((string) abs($dixMilliemes % 10000), 4, '0', STR_PAD_LEFT).' €';
    }

    /**
     * @param  array<string, int|string|null>  $reference
     */
    private static function anomalie(string $message, array $reference): AnomalieFiscale
    {
        return AnomalieFiscale::defaut('stock', $message, $reference);
    }
}
