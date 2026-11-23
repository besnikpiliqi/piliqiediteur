<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Closure;
use LogicException;

/**
 * La seule vente que fait encore un magasin que sa société a fermé sans
 * le céder : la facture du stock vendu seul (P4.1, décision de Claude du
 * 23/11/2026, D76). Le stock d'un magasin vendu seul, ou à un acheteur qui
 * n'est pas redevable de la TVA, ne se transmet pas sans TVA (art. 257 bis
 * du CGI, puis CIBS, art. L. 211-107 et L. 211-108, dès le 01/01/2027,
 * voir MessageDeCession) : il se facture avec TVA, avant que le magasin ne
 * soit cédé, et le stock qui reste sort ensuite à la cession (voir
 * FermerMagasinAction). Or un magasin fermé est en lecture seule : plus
 * aucune vente (voir Magasin::estEnLectureSeule()). Cette facture en est
 * l'unique exception, et le magasin ne l'accepte que dans les limites que
 * voici, que la base reprend pour ce qu'elle peut (voir la migration des
 * factures) :
 * - le magasin est fermé par sa société, temporairement ou pour cessation,
 *   jamais cédé, et rien d'autre ne le met en lecture seule : ni
 *   suspension de l'éditeur, ni société suspendue, résiliée, révoquée ou
 *   close, ni société qui attend sa validation, ni établissement pas
 *   encore confirmé, ni assujetti unique (voir
 *   Magasin::refusDeLaVenteDuStock()) ;
 * - elle est faite par un compte autorisé à vendre dans ce magasin, jamais
 *   le propriétaire, qui ne vend nulle part : ses droits et la base
 *   (voir GardesDeLaVente) le disent comme pour toute vente ;
 * - elle est à un professionnel, jamais au client comptoir ni à un
 *   particulier : un ticket est compté par la clôture de sa journée (voir
 *   CloturerCaisseAction), et un magasin fermé n'en produit plus ; la
 *   facture à un professionnel n'entre dans aucun ticket Z ;
 * - elle vend la TVA française du taux de chaque produit, sans devis, sans
 *   rectification et sans nature d'opération hors de la France ;
 * - elle ne vend que des produits qui suivent leur stock, un par ligne,
 *   archivés exclus, chacun pour tout ce qu'il en reste : jamais un
 *   service, jamais plus que le stock (même pour une personne autorisée à
 *   vendre sans stock, un magasin fermé n'a plus de stock à rendre
 *   négatif), jamais un produit sans stock.
 * Le brouillon de cette facture se crée depuis la liste des factures (voir
 * FacturerLeStockAction) ; il s'émet, s'encaisse en un ou plusieurs modes
 * et se supprime par les gestes ordinaires de sa fiche, que le magasin
 * fermé accepte pour lui seul. Tout autre geste de vente, un avoir, la
 * contre-passation d'un paiement, un e-mail au client ou une relance, reste
 * refusé tant que le magasin est fermé : la société le rouvre pour cela.
 *
 * Le magasin fermé ne devient pas ouvert pour autant : ce service ne fait
 * que le tenir pour ouvert, le temps d'une opération que le domaine fait
 * lui-même (voir pendant()), au nom de ce magasin et de lui seul. Il est
 * lié par scoped() : une requête ou un job ne voit jamais celui d'un autre.
 */
class VenteDuStockSeul
{
    /**
     * Le message d'un magasin qui n'a plus de produit en stock à facturer.
     */
    public const REFUS_AUCUN_STOCK = 'Ce magasin n\'a plus aucun produit en stock à facturer : le stock des produits archivés ou négatif ne se vend pas, il sort à la cession.';

    public const REFUS_CLIENT = 'La facture du stock se fait à un client professionnel du magasin, jamais à un particulier ni au client comptoir.';

    public const REFUS_DEJA_UN_BROUILLON = 'Une facture du stock attend déjà d\'être émise : émettez-la, ou supprimez-la pour en refaire une.';

    public const REFUS_LIGNE = 'La facture du stock ne vend que des produits du magasin qui suivent leur stock, chacun une seule fois, pour tout ce qu\'il en reste : choisissez-les parmi ceux qui sont en stock.';

    public const REFUS_SERVICE = 'La facture du stock ne vend aucun service : seuls les produits en stock se facturent.';

    public const REFUS_STOCK_CHANGE = 'Le stock de « %s » ne suffit plus à cette facture : supprimez-la et refaites-la depuis la liste des factures.';

    /**
     * Les magasins que ce service tient pour ouverts en ce moment, par
     * identifiant, avec le nombre d'opérations imbriquées qui les tiennent
     * ainsi.
     *
     * @var array<int, int>
     */
    private array $magasinsTenusPourOuverts = [];

    /**
     * Le magasin est-il tenu pour ouvert par l'opération en cours ?
     * Magasin::motifDeLectureSeule() le demande.
     */
    public function estEnCoursPour(Magasin $magasin): bool
    {
        return isset($this->magasinsTenusPourOuverts[CleDuModele::de($magasin)]);
    }

    /**
     * Exécute l'opération en tenant ce magasin pour ouvert, sans rien
     * contrôler : celle de Magasin::refusDeLaVenteDuStock() pour savoir ce
     * que le magasin refuserait s'il était ouvert, et pendant(). Jamais
     * pour un autre appelant, qui passe par pendant().
     *
     * @template TRetour
     *
     * @param  Closure(): TRetour  $operation
     * @return TRetour
     */
    public function commeSiOuvert(Magasin $magasin, Closure $operation): mixed
    {
        $cle = CleDuModele::de($magasin);
        $this->magasinsTenusPourOuverts[$cle] = ($this->magasinsTenusPourOuverts[$cle] ?? 0) + 1;

        try {
            return $operation();
        } finally {
            if (--$this->magasinsTenusPourOuverts[$cle] === 0) {
                unset($this->magasinsTenusPourOuverts[$cle]);
            }
        }
    }

    /**
     * Exécute l'opération de la facture du stock vendu seul en tenant le
     * magasin pour ouvert, à condition qu'il soit fermé par sa société,
     * pas cédé, et sans autre cause de lecture seule (voir
     * Magasin::refusDeLaVenteDuStock()), dont le refus se dit tel quel.
     *
     * @template TRetour
     *
     * @param  Closure(): TRetour  $operation
     * @return TRetour
     *
     * @throws LogicException quand le magasin ne peut pas faire cette facture
     */
    public function pendant(Magasin $magasin, Closure $operation): mixed
    {
        $refus = $magasin->refusDeLaVenteDuStock();

        if ($refus !== null) {
            throw new LogicException($refus);
        }

        return $this->commeSiOuvert($magasin, $operation);
    }

    /**
     * Cette facture est-elle la facture du stock vendu seul d'un magasin
     * qui peut encore la faire (voir Magasin::refusDeLaVenteDuStock()) ?
     * Les droits et les actions lui accordent alors ce qu'un magasin fermé
     * refuse à tout autre document. Le drapeau de la facture se relit en
     * base : une facture déjà chargée ne devient jamais la facture du stock
     * par une valeur changée en mémoire.
     */
    public function concerne(Facture $facture): bool
    {
        return $facture->estLaVenteDuStockSeul()
            && $facture->magasin->refusDeLaVenteDuStock() === null
            && Facture::query()->whereKey($facture->getKey())->where('vente_du_stock_seul', true)->exists();
    }

    /**
     * Exécute l'opération sur cette facture : tenant son magasin pour
     * ouvert quand c'est la facture du stock vendu seul d'un magasin qui
     * peut encore la faire, telle quelle sinon (le magasin ouvert, ou
     * fermé pour une autre facture, la refuse comme toujours).
     *
     * @template TRetour
     *
     * @param  Closure(): TRetour  $operation
     * @return TRetour
     */
    public function pourLaFacture(Facture $facture, Closure $operation): mixed
    {
        return $this->concerne($facture) ? $this->commeSiOuvert($facture->magasin, $operation) : $operation();
    }

    /**
     * Les lignes de la facture du stock, relues sous le verrou du registre
     * à son émission : chacune un produit du magasin qui suit son stock, une
     * seule fois, en quantité qui ne dépasse pas ce qu'il en reste, jamais
     * un service. Un magasin fermé n'a plus d'achat ni d'inventaire : son
     * stock ne bouge plus que par ses ventes, et aucune vente sans stock n'y
     * est permise, quelle que soit l'autorisation de l'auteur.
     *
     * @throws LogicException
     */
    public function verifierLaFacture(Facture $facture): void
    {
        $lignes = $facture->lignes()->get();

        if ($lignes->isEmpty() || $lignes->contains(fn (LigneFacture $ligne): bool => $ligne->service_id === null && $ligne->produit_id === null)) {
            throw new LogicException(self::REFUS_LIGNE);
        }

        if ($lignes->contains(fn (LigneFacture $ligne): bool => $ligne->service_id !== null)) {
            throw new LogicException(self::REFUS_SERVICE);
        }

        $produits = Produit::withTrashed()->whereKey($lignes->pluck('produit_id'))->get()->keyBy('id');

        if ($produits->count() !== $lignes->count()) {
            throw new LogicException(self::REFUS_LIGNE);
        }

        foreach ($lignes as $ligne) {
            $produit = $produits->get($ligne->produit_id);

            if ($produit === null || ! $produit->suivi_stock || $produit->stock_quantite < $ligne->quantite) {
                throw new LogicException(sprintf(self::REFUS_STOCK_CHANGE, $ligne->description));
            }
        }
    }
}
