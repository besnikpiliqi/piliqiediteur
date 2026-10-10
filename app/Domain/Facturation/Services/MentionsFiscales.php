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
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

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
}
