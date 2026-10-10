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
 * - « TVA non applicable, art. 293 B du CGI » quand le document a été
 *   enregistré en franchise en base de TVA (B10) : lue sur le document,
 *   jamais sur le régime actuel du magasin, pour qu'un document réimprimé
 *   après la sortie de la franchise la porte toujours ; un devis la porte
 *   aussi, ses prix s'entendant sans TVA ;
 * - hors franchise, sur un ticket, une facture ou un avoir, l'option de
 *   son émetteur pour le paiement de la taxe d'après les débits (voir
 *   ParametresFiscaux) : sans TVA due, elle n'a pas d'objet. Un avoir
 *   rectifie une facture : il en reprend les mentions. L'émetteur d'un
 *   document émis est celui qu'il a signé (voir FigeSonEmetteur) :
 *   réimprimé après un changement de l'option, il garde la mention de
 *   son émission.
 *
 * S'y ajoute, sous les lignes d'un document à un professionnel,
 * l'explication de son calcul (voir calcul()), qui ne se signe pas.
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    private const CALCUL_VENTE_PROFESSIONNELLE = 'Client professionnel : HT de chaque ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA calculée sur le total HT de chaque taux.';

    private const CALCUL_RETOUR_PROFESSIONNEL = 'Client professionnel : chaque retour se calcule comme sa facture, pour tous les articles rendus jusqu\'ici (HT = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA sur ce HT, dans la limite de celle de la ligne), déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

    /**
     * @param  Emetteur  $emetteur  celui qu'imprime le document : signé une fois émis (voir FigeSonEmetteur::emetteur()), le magasin d'aujourd'hui pour un brouillon ou un devis, et pour le document qu'on émet (voir ContenuFiscal)
     * @return list<string>
     */
    public function pour(Facture|Avoir|Devis $document, Emetteur $emetteur): array
    {
        $mentions = [];

        if (! $document instanceof Devis) {
            $mentions[] = 'Catégorie de l\'opération : '.CategorieOperation::depuisNatures($document->lignes->pluck('nature'))->getLabel();
        }

        if ($document->franchise_en_base) {
            $mentions[] = RegimeTva::MENTION_FRANCHISE;
        } elseif (! $document instanceof Devis && $emetteur->optionDebitsTva) {
            $mentions[] = self::OPTION_DEBITS;
        }

        return $mentions;
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
     * dernier retour reprend ce qui reste de la ligne. Rien pour un
     * particulier, ni en franchise en base, où la vente se calcule article
     * par article (voir ContexteDeVente::documentCalculeDepuisLeHtDeLigne()).
     * Une explication, pas une mention : elle ne se signe pas avec le
     * document (voir ContenuFiscal).
     */
    public function calcul(Facture|Avoir|Devis $document): ?string
    {
        if (! ContexteDeVente::documentCalculeDepuisLeHtDeLigne($document)) {
            return null;
        }

        return $document instanceof Avoir ? self::CALCUL_RETOUR_PROFESSIONNEL : self::CALCUL_VENTE_PROFESSIONNELLE;
    }
}
