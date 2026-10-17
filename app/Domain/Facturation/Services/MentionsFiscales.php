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
 * S'y ajoute, sous les lignes de tout document hors franchise en base,
 * l'explication de son calcul (voir calcul()), qui ne se signe pas, et,
 * sur l'avoir d'un client comptoir, la mention de son bon d'avoir (voir
 * bonDAvoir()).
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    private const CALCUL_VENTE = 'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence.';

    private const CALCUL_RETOUR = 'Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité (HT = TTC ÷ (1 + taux), arrondi une fois au centime ; TVA = la différence), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

    private const CALCUL_SANS_TVA = 'Vente sans TVA française : chaque produit, et chaque service à son prix de référence, se vend à son prix catalogue TTC sans la TVA française de son taux (HT de chaque taux = TTC du catalogue ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes) ; un autre service, à son prix sans TVA.';

    private const CALCUL_RETOUR_SANS_TVA = 'Vente sans TVA française : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité, sans dépasser le HT de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

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
     * L'explication du calcul d'un document, que les PDF impriment sous ses
     * lignes et que sa fiche affiche : toute vente soumise à la TVA
     * française se calcule taux par taux depuis ses prix TTC (voir
     * CalculateurLigne::chiffrerUneVente()), si bien que deux lignes
     * identiques peuvent avoir des HT qui diffèrent d'un centime ; un avoir
     * rend le calcul de la vente sur les articles rendus, dans la limite
     * de la ligne (voir CalculateurLigne::retourDeLigne()). Une vente sans
     * TVA française (K3, voir ContexteDeVente::documentRetireLaTvaDuPrix())
     * dit que ses produits, et ses services à leur prix de référence, se
     * comptent au prix de leur catalogue sans la TVA de leur taux, et ses
     * autres services à leur prix sans TVA. Rien en franchise en base, où
     * le HT est le prix TTC, sans TVA. Une explication, pas une mention :
     * elle ne se signe pas avec le document (voir ContenuFiscal).
     */
    public function calcul(Facture|Avoir|Devis $document): ?string
    {
        if ($document->franchise_en_base) {
            return null;
        }

        if (ContexteDeVente::documentRetireLaTvaDuPrix($document)) {
            return $document instanceof Avoir ? self::CALCUL_RETOUR_SANS_TVA : self::CALCUL_SANS_TVA;
        }

        return $document instanceof Avoir ? self::CALCUL_RETOUR : self::CALCUL_VENTE;
    }
}
