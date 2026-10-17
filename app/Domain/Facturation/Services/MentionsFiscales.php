<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\CategorieOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RegimeTva;

/**
 * Les mentions fiscales qu'imprime un document, dans l'ordre, que les PDF
 * reprennent telles quelles et que l'émission signe au registre fiscal
 * (voir ContenuFiscal) :
 *
 * - sur un ticket, une facture ou un avoir, la catégorie de l'opération
 *   (art. 242 nonies A de l'annexe II au CGI), tirée de la nature de ses
 *   lignes (voir NatureLigne) ;
 * - hors de la vente en France, le texte qui exonère la vente ou la place
 *   hors du champ de la TVA française (« Exonération de TVA, article 262
 *   I du CGI »..., voir NatureOperation::mention()), lu sur le document :
 *   un avoir reprend la nature des factures qu'il corrige, un devis celle
 *   qu'il propose ;
 * - « TVA non applicable, art. 293 B du CGI » quand le document a été
 *   enregistré en franchise en base de TVA (B10) : lue sur le document,
 *   jamais sur le régime actuel de la société, pour qu'un document réimprimé
 *   après la sortie de la franchise la porte toujours ; un devis la porte
 *   aussi, ses prix s'entendant sans TVA ;
 * - hors franchise, sur un ticket, une facture ou un avoir, l'option de
 *   son émetteur pour le paiement de la taxe d'après les débits (voir
 *   ParametresFiscauxSociete) : sans TVA due, elle n'a pas d'objet. Un avoir
 *   rectifie une facture : il en reprend les mentions. L'émetteur d'un
 *   document émis est celui qu'il a signé (voir FigeSonEmetteur) :
 *   réimprimé après un changement de l'option, il garde la mention de
 *   son émission.
 *
 * S'y ajoute, sous les lignes d'un document à un professionnel ou d'une
 * vente sans TVA française, l'explication de son calcul (voir calcul()),
 * qui ne se signe pas, et,
 * sur l'avoir d'un client comptoir, la mention de son bon d'avoir (voir
 * bonDAvoir()).
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    private const CALCUL_VENTE_PROFESSIONNELLE = 'Client professionnel : HT de chaque ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA calculée sur le total HT de chaque taux.';

    private const CALCUL_RETOUR_PROFESSIONNEL = 'Client professionnel : chaque retour se calcule comme sa facture, pour tous les articles rendus jusqu\'ici (HT = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA sur ce HT, dans la limite de celle de la ligne), déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

    private const CALCUL_SANS_TVA = 'Vente sans TVA française : PU HT de chaque produit = prix catalogue TTC ÷ (1 + TVA retirée), au centime, comme celui d\'un service à son prix de référence ; un autre service se facture à son prix sans TVA.';

    private const CALCUL_VENTE_PROFESSIONNELLE_SANS_TVA = 'Client professionnel, vente sans TVA française : HT de chaque ligne de produit = quantité × prix catalogue TTC ÷ (1 + TVA retirée), arrondi une fois, comme celui d\'un service à son prix de référence ; un autre service se facture à son prix sans TVA.';

    private const CALCUL_RETOUR_PROFESSIONNEL_SANS_TVA = 'Client professionnel, vente sans TVA française : chaque retour se calcule comme sa facture, pour tous les articles rendus jusqu\'ici (HT d\'un produit = quantité × prix catalogue TTC ÷ (1 + TVA retirée), arrondi une fois, comme celui d\'un service à son prix de référence ; d\'un autre service, quantité × son prix sans TVA), déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

    /**
     * @param  Emetteur  $emetteur  celui qu'imprime le document : signé une fois émis (voir FigeSonEmetteur::emetteur()), la société et le magasin d'aujourd'hui pour un brouillon ou un devis, et pour le document qu'on émet (voir ContenuFiscal)
     * @return list<string>
     */
    public function pour(Facture|Avoir|Devis $document, Emetteur $emetteur): array
    {
        $mentions = [];

        if (! $document instanceof Devis) {
            $mentions[] = 'Catégorie de l\'opération : '.CategorieOperation::depuisNatures($document->lignes->pluck('nature'))->getLabel();
        }

        if ($document->nature_operation->mention() !== null) {
            $mentions[] = $document->nature_operation->mention();
        }

        if ($document->franchise_en_base) {
            $mentions[] = RegimeTva::MENTION_FRANCHISE;
        } elseif (! $document instanceof Devis && $emetteur->optionDebitsTva) {
            $mentions[] = self::OPTION_DEBITS;
        }

        return $mentions;
    }

    /**
     * La mention que l'avoir d'un client comptoir imprime quand il lui
     * reste du crédit après son émission, une fois imputé sur les tickets
     * qu'il corrige : son numéro vaut bon d'avoir (voir
     * EnregistrerVenteCaisseAction). Calculée et signée à l'émission (voir
     * ContenuFiscal), elle se relit ensuite dans le contenu signé (voir
     * Avoir::mentionDuBonDAvoir()) : le montant du bon reste celui de son
     * émission, même une fois utilisé. Null pour tout autre avoir.
     */
    public function bonDAvoir(Avoir $avoir): ?string
    {
        $credit = $avoir->montantNonAlloue();

        if (! $avoir->client?->estComptoir() || $credit <= 0) {
            return null;
        }

        return 'Ce n° vaut bon d\'avoir de '.number_format($credit, 2, ',', ' ').' € à présenter lors d\'un prochain achat.';
    }

    /**
     * L'explication du calcul d'un document à un professionnel, que les
     * PDF impriment sous ses lignes et que sa fiche affiche : depuis le HT
     * de chaque ligne, PU HT x quantité peut différer du HT de la ligne
     * (1 000 x 0,83 € pour 833,33 €, voir CalculateurLigne). Un avoir
     * reprend le calcul de sa facture, par différence avec les avoirs déjà
     * émis sur la même ligne (K2, voir
     * CalculateurLigne::retourDeLigneProfessionnelle()) : sa TVA ne dépasse
     * jamais celle de la ligne facturée, sa part de la TVA de son taux,
     * qui peut différer d'un centime de la TVA de son propre HT, et le
     * dernier retour reprend ce qui reste de la ligne. Une vente sans TVA
     * française (K3, voir ContexteDeVente::documentRetireLaTvaDuPrix()) dit
     * que ses produits, et ses services à leur prix de référence, se
     * comptent au prix de leur catalogue sans la TVA de leur taux, deux
     * données que ses PDF et sa fiche impriment sous ces noms (« Prix
     * catalogue TTC », « TVA retirée ») à côté de leur taux de 0 %, et ses
     * autres services à leur prix sans TVA. Rien pour un particulier en
     * France, ni en franchise en base, où la vente se calcule article par
     * article à son prix TTC (voir
     * ContexteDeVente::documentCalculeDepuisLeHtDeLigne()). Une
     * explication, pas une mention : elle ne se signe pas avec le document
     * (voir ContenuFiscal).
     */
    public function calcul(Facture|Avoir|Devis $document): ?string
    {
        $sansTva = ContexteDeVente::documentRetireLaTvaDuPrix($document);

        return match (true) {
            ! ContexteDeVente::documentCalculeDepuisLeHtDeLigne($document) => $sansTva ? self::CALCUL_SANS_TVA : null,
            $document instanceof Avoir => $sansTva ? self::CALCUL_RETOUR_PROFESSIONNEL_SANS_TVA : self::CALCUL_RETOUR_PROFESSIONNEL,
            default => $sansTva ? self::CALCUL_VENTE_PROFESSIONNELLE_SANS_TVA : self::CALCUL_VENTE_PROFESSIONNELLE,
        };
    }
}
