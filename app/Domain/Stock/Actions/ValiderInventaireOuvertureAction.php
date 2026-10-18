<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Enums\OrigineProduit;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Services\OrigineDesProduits;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\LigneInventaireOuverture;
use App\Domain\Stock\Services\ConstructeurLigneInventaire;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Valide un inventaire d'ouverture, une fois pour toutes : ses nouveaux
 * produits naissent, dans l'ordre de ses lignes et dans le contexte
 * d'origine d'un inventaire d'ouverture (voir OrigineDesProduits), à leur
 * coût saisi, sans fournisseur ; chaque ligne fait entrer son stock au
 * coût saisi, sans TVA, si son produit suit son stock, un produit qui ne
 * le suit pas ne recevant que ce coût (voir
 * StockLedgerService::entrerALInventaireDOuverture()) ; chaque ligne
 * garde alors le suivi du stock de son produit et la valeur du stock
 * qu'elle fait entrer, l'inventaire leur somme (voir
 * ConstructeurLigneInventaire::valoriser()) ; l'inventaire reçoit son
 * numéro dans la série INV du magasin ; et la validation s'inscrit au
 * journal d'audit de la société, dans la chaîne du magasin : son numéro,
 * la valeur hors taxe du stock entré et, pour chaque ligne, la référence,
 * la quantité inventoriée, si elle est entrée en stock (le produit suit
 * son stock), le coût unitaire et si le produit est né de l'inventaire.
 * Un refus (référence prise entre-temps, produit reçu ou archivé depuis
 * la saisie) annule toute la validation.
 *
 * Un inventaire d'ouverture se valide jusqu'à la première vente du
 * magasin (voir InventaireOuverture::verifierQuIlSeSaisitEncore()) : le
 * registre fiscal du magasin, que toute vente verrouille en premier, est
 * le premier verrou de la transaction (voir RegistreFiscal::verrouiller()),
 * et la première vente passe donc tout entière avant la validation, qui
 * est alors refusée, ou après elle. Suivent la ligne du magasin, sous un
 * verrou partagé (un magasin suspendu ou fermé ne valide plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur, relu sous le verrou
 * de sa ligne (voir LecteurDesDroits) : il doit pouvoir valider les achats
 * et créer des produits (voir InventaireOuverturePolicy::valider()). Les
 * produits existants de ses lignes se verrouillent, par identifiant
 * croissant, après l'inventaire et avant la numérotation (voir
 * Produit::verrouillerPourLeStock()), et se relisent jamais reçus : une
 * facture d'achat qui les a reçus depuis la saisie refuse la validation.
 *
 * La validation demandée par une personne porte un jeton (R3, voir
 * ActionsUniques) : répétée après une réponse perdue, elle rend
 * l'inventaire déjà validé, sans créer ses produits ni faire entrer son
 * stock une seconde fois ; sans jeton, l'inventaire déjà validé est
 * refusé.
 */
class ValiderInventaireOuvertureAction
{
    public const REFUS_DEJA_VALIDE = 'Cet inventaire d\'ouverture est déjà validé.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly ConstructeurLigneInventaire $constructeurLignes,
        private readonly DocumentNumberingService $numerotation,
        private readonly StockLedgerService $stock,
        private readonly OrigineDesProduits $origine,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly ActionsUniques $actionsUniques,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, InventaireOuverture $inventaire, ?string $jeton = null): InventaireOuverture
    {
        $magasin = app(TenantContext::class)->magasin();
        $verrou = function () use ($magasin, $auteur): void {
            $this->registre->verrouiller($magasin);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            ActionsUniques::verrouDe($auteur)();
        };

        return $this->actionsUniques->executer($jeton, ActionsUniques::VALIDER_INVENTAIRE_OUVERTURE, $auteur, ['inventaire_ouverture_id' => $inventaire->getKey()], $verrou, fn (): InventaireOuverture => DB::transaction(function () use ($auteur, $inventaire, $magasin): InventaireOuverture {
            $this->registre->verrouiller($magasin);
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $magasinRelu->verifierQuIlEstOuvert();
            InventaireOuverture::verifierQuIlSeSaisitEncore($magasinRelu);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, InventaireOuverture::class);

            return $this->operateur->pendant($compte, fn (): InventaireOuverture => $this->valider($compte, $magasin, $inventaire));
        }));
    }

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function valider(User $compte, Magasin $magasin, InventaireOuverture $inventaire): InventaireOuverture
    {
        $inventaire = InventaireOuverture::query()->whereKey($inventaire->getKey())->lockForUpdate()->firstOrFail();

        if (! $inventaire->estBrouillon()) {
            throw new LogicException(self::REFUS_DEJA_VALIDE);
        }

        $this->lecteur->exiger($compte, 'valider', $magasin, $inventaire);
        $lignes = $inventaire->lignes()->get();

        if ($lignes->isEmpty()) {
            throw new InvalidArgumentException('Un inventaire d\'ouverture doit contenir au moins une ligne.');
        }

        Produit::verrouillerPourLeStock($lignes->pluck('produit_id'));
        $lignes->whereNotNull('produit_id')->each(fn (LigneInventaireOuverture $ligne): Produit => $this->constructeurLignes->produitJamaisRecu($ligne->produit_id));
        $produitsCrees = $lignes->whereNull('produit_id')->map(fn (LigneInventaireOuverture $ligne): int => $this->creerLeProduit($ligne))->values()->all();

        $lignes = $inventaire->lignes()->with('produit')->get();
        $this->constructeurLignes->valoriser($inventaire, $lignes);

        $inventaire->forceFill([
            'numero' => $this->numerotation->suivant($magasin, TypeDocument::InventaireOuverture, now()->format('Y')),
            'statut' => StatutInventaireOuverture::Valide,
            'date_validation' => now(),
            'valide_par' => $compte->getKey(),
        ])->save();

        foreach ($lignes as $ligne) {
            $this->stock->entrerALInventaireDOuverture($ligne->produit, $ligne->quantite, (float) $ligne->cout_unitaire_ht, $inventaire, "Inventaire d'ouverture {$inventaire->numero}");
        }

        $this->journalAudit->inscrire(ActionAuditee::InventaireOuvertureValide, $compte, $magasin, null, [
            'inventaire' => $inventaire->numero,
            'valeur_ht' => $inventaire->valeur_ht,
            'lignes' => $lignes->map(fn (LigneInventaireOuverture $ligne): array => [
                'reference' => $ligne->reference,
                'quantite' => $ligne->quantite,
                'suivi_stock' => $ligne->suivi_stock,
                'cout_unitaire_ht' => $ligne->cout_unitaire_ht,
                'produit_cree' => in_array($ligne->getKey(), $produitsCrees, true),
            ])->values()->all(),
        ], $inventaire);

        return $inventaire->setRelation('lignes', $lignes);
    }

    /**
     * Crée le nouveau produit de la ligne, depuis sa fiche, à son coût
     * saisi, sans fournisseur, dans le contexte d'origine d'un inventaire
     * d'ouverture ; sa référence, saisie ou générée, est prise à ce
     * moment-là (voir Produit). La ligne reçoit alors le produit et sa
     * référence, et rend son identifiant.
     */
    private function creerLeProduit(LigneInventaireOuverture $ligne): int
    {
        $produit = (new Produit($ligne->nouveau_produit))->forceFill(['cmup_ht' => $ligne->cout_unitaire_ht]);
        $this->origine->pendant(OrigineProduit::InventaireOuverture, fn (): bool => $produit->save());

        $ligne->update(['produit_id' => $produit->getKey(), 'reference' => $produit->reference, 'nouveau_produit' => null]);

        return (int) $ligne->getKey();
    }
}
