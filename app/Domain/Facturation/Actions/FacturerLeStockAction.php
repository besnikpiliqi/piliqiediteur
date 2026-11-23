<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VenteDuStockSeul;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Prépare, dans un magasin que sa société a fermé sans le céder, la facture
 * de son stock vendu seul (P4.1, voir VenteDuStockSeul) : un brouillon de
 * facture, au nom de qui la fait, à un client professionnel du magasin,
 * avec une ligne par produit choisi, pour tout ce qu'il en reste en stock,
 * à son prix de vente TTC et au taux de TVA de sa fiche, calculée comme
 * toute vente (voir ConstructeurLigneDocument et CalculateurLigne : TVA
 * ligne par ligne, arrondie une fois au centime). Son auteur, relu sous le
 * verrou de sa ligne, doit pouvoir créer et émettre une facture dans ce
 * magasin, jamais le propriétaire, qui ne vend nulle part ; une remise
 * demandée sur toutes les lignes exige en plus l'autorisation d'accorder
 * des remises (R2, voir DerogationsDePrix). Le brouillon ne se modifie pas :
 * il s'émet, s'encaisse ou se supprime, et la facture se refait au besoin.
 *
 * Le magasin, relu sous un verrou partagé, doit être fermé par sa société
 * et pas cédé (voir Magasin::refusDeLaVenteDuStock()) : une cession, qui
 * verrouille sa ligne, passe tout entière avant la préparation, qui est
 * alors refusée, ou après elle. Les produits choisis se verrouillent, par
 * identifiant croissant : chacun doit suivre son stock, ne pas être
 * archivé et en avoir, jamais un service ; une seule facture du stock
 * attend d'être émise à la fois. La préparation s'inscrit au journal
 * d'audit (voir ActionAuditee::FactureDuStockPreparee).
 */
class FacturerLeStockAction
{
    public function __construct(
        private readonly CreerFactureAction $creerFacture,
        private readonly LecteurDesDroits $lecteur,
        private readonly VenteDuStockSeul $venteDuStock,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  list<int|string>  $produits  les identifiants des produits en stock à facturer
     * @param  string|null  $remisePourcentage  la remise de chaque ligne, en pourcentage (« 12,5 »), vide pour aucune
     *
     * @throws AuthorizationException quand l'auteur ne peut pas créer et émettre une facture dans ce magasin, ou accorder la remise demandée
     * @throws InvalidArgumentException quand la remise n'est pas un pourcentage valable
     * @throws LogicException quand le magasin ne peut pas faire cette facture, ou que le client ou un produit ne convient pas
     */
    public function handle(User $auteur, Client $client, array $produits, ?string $remisePourcentage = null): Facture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->venteDuStock->pendant($magasin, fn (): Facture => DB::transaction(function () use ($auteur, $client, $produits, $remisePourcentage, $magasin): Facture {
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $refus = $magasinRelu->refusDeLaVenteDuStock();

            if ($refus !== null) {
                throw new LogicException($refus);
            }

            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);
            $this->lecteur->exiger($compte, PermissionRegistry::FACTURE_EMETTRE, $magasin);
            $client = Client::relueDansLeMagasin($client, Client::REFUS_AUTRE_MAGASIN);

            if ($client->trashed() || $client->estComptoir() || ! $client->estProfessionnel()) {
                throw new LogicException(VenteDuStockSeul::REFUS_CLIENT);
            }

            $this->refuserUneSecondeFacture();
            $lignes = $this->lignesDesProduits($produits, $remisePourcentage);
            $facture = $this->creerFacture->handle($compte, $client, $lignes, [], venteDuStockSeul: true);
            $facture = ModeleRelu::de($facture);

            $this->journalAudit->inscrire(ActionAuditee::FactureDuStockPreparee, $compte, $magasin, null, [
                'client' => ['id' => $client->getKey(), 'nom' => $client->nom],
                'produits' => count($lignes),
                'remise_pourcentage' => $remisePourcentage === null || trim($remisePourcentage) === '' ? null : trim($remisePourcentage),
                'total_ttc' => $facture->total_ttc,
            ], $facture);

            return $facture;
        }));
    }

    /**
     * Une seule facture du stock attend d'être émise à la fois : le stock
     * facturé se facture une fois, et deux brouillons du même stock ne
     * s'émettraient pas tous les deux.
     *
     * @throws LogicException
     */
    private function refuserUneSecondeFacture(): void
    {
        if (Facture::query()->where('vente_du_stock_seul', true)->where('statut', StatutFacture::Brouillon)->lockForUpdate()->exists()) {
            throw new LogicException(VenteDuStockSeul::REFUS_DEJA_UN_BROUILLON);
        }
    }

    /**
     * Une ligne par produit choisi, pour tout son stock : ils doivent tous
     * suivre leur stock, en avoir et ne pas être archivés.
     *
     * @param  list<int|string>  $produits
     * @return list<array<string, mixed>>
     *
     * @throws LogicException
     */
    private function lignesDesProduits(array $produits, ?string $remisePourcentage): array
    {
        $identifiants = array_values(array_unique(array_map(intval(...), $produits)));

        if ($identifiants === [] || in_array(0, $identifiants, true)) {
            throw new LogicException('Choisissez au moins un produit en stock à facturer.');
        }

        $enStock = Produit::query()
            ->whereKey($identifiants)
            ->where('suivi_stock', true)
            ->where('stock_quantite', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($enStock->count() !== count($identifiants)) {
            throw new LogicException(VenteDuStockSeul::REFUS_LIGNE);
        }

        return array_values($enStock->map(fn (Produit $produit): array => [
            'produit_id' => CleDuModele::de($produit),
            'quantite' => $produit->stock_quantite,
            'remise_pourcentage' => $remisePourcentage,
        ])->all());
    }
}
