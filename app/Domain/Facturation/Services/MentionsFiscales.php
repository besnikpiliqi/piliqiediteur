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
 * - hors franchise, sur un ticket, une facture ou un avoir, l'option du
 *   magasin pour le paiement de la taxe d'après les débits (voir
 *   ParametresFiscaux) : sans TVA due, elle n'a pas d'objet. Un avoir
 *   rectifie une facture : il en reprend les mentions.
 *
 * S'y ajoute, sous les lignes d'un document à un professionnel,
 * l'explication de son calcul (voir calcul()), qui ne se signe pas.
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    private const CALCUL_VENTE_PROFESSIONNELLE = 'Client professionnel : HT de chaque ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA calculée sur le total HT de chaque taux.';

    private const CALCUL_RETOUR_PROFESSIONNEL = 'Client professionnel : chaque ligne se calcule comme sur sa facture, HT = quantité × PU TTC ÷ (1 + taux) arrondi une fois et TVA sur ce HT, déduction faite des avoirs déjà émis sur la même ligne.';

    /**
     * @return list<string>
     */
    public function pour(Facture|Avoir|Devis $document): array
    {
        $mentions = [];

        if (! $document instanceof Devis) {
            $mentions[] = 'Catégorie de l\'opération : '.CategorieOperation::depuisNatures($document->lignes->pluck('nature'))->getLabel();
        }

        if ($document->franchise_en_base) {
            $mentions[] = RegimeTva::MENTION_FRANCHISE;
        } elseif (! $document instanceof Devis && $document->magasin->option_debits_tva) {
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
     * émis sur la même ligne. Rien pour un particulier, ni en franchise en
     * base, où le HT est le TTC. Une explication, pas une mention : elle ne
     * se signe pas avec le document (voir ContenuFiscal).
     */
    public function calcul(Facture|Avoir|Devis $document): ?string
    {
        if (! $document->estPourUnProfessionnel() || $document->franchise_en_base) {
            return null;
        }

        return $document instanceof Avoir ? self::CALCUL_RETOUR_PROFESSIONNEL : self::CALCUL_VENTE_PROFESSIONNELLE;
    }
}
