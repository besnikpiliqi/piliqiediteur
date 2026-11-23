<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;

/**
 * Le message qui précède la confirmation de la cession d'un magasin (P4.1
 * et P4.2, décision de Claude du 23/11/2026, D76) : il donne la valeur du
 * stock au coût moyen, une valeur comptable et jamais un prix, et distingue
 * les deux cas que la cession ne confond pas.
 * - Le magasin est cédé avec son activité à un repreneur redevable de la
 *   TVA : une transmission d'universalité, sans TVA (art. 257 bis du CGI),
 *   qui devient la transmission d'une activité économique autonome dès le
 *   01/01/2027 (CIBS, art. L. 211-107 et L. 211-108) ; elle ne se facture
 *   pas et son montant hors taxe se déclare en « Autres opérations non
 *   imposables » (BOI-TVA-DECLA-20-30-20, § 20).
 * - Le stock est vendu seul, ou à un acheteur qui n'est pas redevable de
 *   la TVA : une vente ordinaire (BOI-TVA-CHAMP-10-10-50-10, § 10 et 40), à
 *   facturer avec TVA avant la cession par un compte autorisé à vendre
 *   dans le magasin, même fermé (voir VenteDuStockSeul). Une société en
 *   franchise en base la facture sans TVA (art. 293 B du CGI).
 * La valeur est celle que la cession sort : chaque produit qui suit son
 * stock et en a, archivé compris, au coût moyen qu'il a (voir
 * FermerMagasinAction::sortirLeStock()). Les mêmes phrases accompagnent,
 * après la cession, la notification que le propriétaire reçoit (voir
 * StockDuMagasinCede). Un expert-comptable les vérifie sans rien bloquer :
 * c'est lui qui choisit entre les deux cas, d'après l'acte de cession.
 */
class MessageDeCession
{
    /**
     * Le jour où le code des impositions sur les biens et services (CIBS)
     * remplace l'article 257 bis du CGI pour la transmission d'une activité
     * économique autonome.
     */
    public const DEBUT_DU_CIBS = '2027-01-01';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * La valeur hors taxe, en centimes, du stock que la cession du magasin
     * sortirait aujourd'hui : la somme, produit par produit, de la valeur
     * de son stock au coût moyen, archivés compris, comme la valeur du
     * stock que montre la liste des produits. Lue dans le contexte du
     * magasin, jamais celui d'un autre, sans le nom d'aucun produit ;
     * un produit en stock négatif ne compte pas : il n'a aucun mouvement.
     */
    public function valeurDuStockEnCentimes(Magasin $magasin): int
    {
        $produits = app(TenantContext::class)->runAs($magasin, fn () => Produit::withTrashed()
            ->select(['id', 'stock_quantite', 'cmup_ht', 'taux_tva'])
            ->where('suivi_stock', true)
            ->where('stock_quantite', '>', 0)
            ->orderBy('id')
            ->get());

        return $produits->sum(fn (Produit $produit): int => $this->calculateur->valeurAuCoutMoyen($produit->stock_quantite, $this->calculateur->enDixMilliemes($produit->cmup_ht), $this->calculateur->enCentiemes($produit->taux_tva))['montant_ht']);
    }

    /**
     * Le message d'avant la confirmation, un paragraphe par ligne.
     *
     * @param  int  $valeurHt  la valeur hors taxe du stock au coût moyen, en centimes
     * @param  string  $jour  AAAA-MM-JJ, le jour de la cession, qui choisit l'article cité
     * @return list<string>
     */
    public function avantLaCession(Societe $societe, int $valeurHt, string $jour): array
    {
        return [
            $this->phraseDeLaValeur($valeurHt),
            'Si le magasin est cédé avec son activité à un repreneur redevable de la TVA, la cession se fait sans TVA ('.self::referenceDeLaTransmission($jour).') : son montant hors taxe se déclare en « Autres opérations non imposables », sans facture de TVA.',
            $this->phraseDuStockVenduSeul($societe),
            'Faites vérifier ce choix par votre expert-comptable.',
        ];
    }

    /**
     * Le message de la notification que le propriétaire reçoit une fois le
     * magasin cédé : la valeur du stock sorti, à rapprocher du prix de
     * cession prévu à l'acte, et ce qui s'en déduit pour la TVA.
     *
     * @param  int  $valeurHt  la valeur hors taxe du stock sorti, en centimes
     * @param  string  $jour  AAAA-MM-JJ, le jour de la cession
     */
    public function apresLaCession(int $valeurHt, string $jour): string
    {
        return $this->phraseDeLaValeur($valeurHt).' À rapprocher du prix de cession prévu à l\'acte. Cédé avec son activité à un repreneur redevable de la TVA, le magasin l\'a été sans TVA ('.self::referenceDeLaTransmission($jour).') : son montant hors taxe se déclare en « Autres opérations non imposables ». Un stock vendu seul, ou à un acheteur qui n\'est pas redevable de la TVA, se facture avec TVA avant la cession : faites vérifier avec votre expert-comptable que ce n\'est pas le cas du stock sorti.';
    }

    /**
     * L'article qui dispense de TVA la transmission d'une activité à la
     * date de la cession : celui du CGI jusqu'au 31/12/2026, ceux du CIBS
     * ensuite.
     *
     * @param  string  $jour  AAAA-MM-JJ
     */
    public static function referenceDeLaTransmission(string $jour): string
    {
        return $jour < self::DEBUT_DU_CIBS
            ? 'transmission d\'universalité, art. 257 bis du CGI'
            : 'transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS';
    }

    private function phraseDeLaValeur(int $valeurHt): string
    {
        return 'Valeur du stock au coût moyen : '.$this->calculateur->enEuros($valeurHt).' HT (valeur comptable, pas un prix de vente).';
    }

    private function phraseDuStockVenduSeul(Societe $societe): string
    {
        $facture = $societe->estEnFranchiseDeTva()
            ? 'il se facture avant de céder le magasin, sans TVA puisque votre société vend en franchise en base (art. 293 B du CGI)'
            : 'il se facture avec TVA avant de céder le magasin';

        return 'Si le stock est vendu seul, ou à un acheteur qui n\'est pas redevable de la TVA, '.$facture.' : vous ne vendez jamais, un compte autorisé à vendre dans ce magasin établit cette facture avec « Facturer le stock », dans la liste des factures du magasin, même une fois le magasin fermé. Le stock que vous ne facturez pas sort à la cession, sans facture.';
    }
}
