<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le type d'une preuve qui justifie l'exonération d'une vente hors de
 * France (I4, voir PreuveOperation et PreuvesRequises) :
 * - la déclaration d'export électronique (MRN) avec la certification de
 *   sortie de l'Union, ou la déclaration CN23 d'un colis postal, pour un
 *   export ou une livraison vers un DOM (art. 262 I et 294 du CGI) ;
 * - les documents de transport de l'art. 45 bis du règlement 282/2011 :
 *   catégorie a) (TransportA) et catégorie b) (TransportB), établis par
 *   des parties indépendantes du magasin et du client ;
 * - l'attestation de réception que l'acquéreur d'une livraison
 *   intracommunautaire remet quand il enlève lui-même les biens.
 */
enum TypePreuve: string implements HasLabel
{
    case Mrn = 'mrn';
    case Cn23 = 'cn23';
    case TransportA = 'transport_a';
    case TransportB = 'transport_b';
    case AttestationAcquereur = 'attestation_acquereur';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mrn => 'Déclaration d\'export (MRN) avec certification de sortie',
            self::Cn23 => 'Déclaration CN23 (colis postal)',
            self::TransportA => 'Document de transport : CMR signée, connaissement, LTA, facture du transporteur ou de fret',
            self::TransportB => 'Autre preuve d\'expédition : assurance du transport, paiement bancaire du transport, document d\'une autorité publique, récépissé d\'entrepositaire',
            self::AttestationAcquereur => 'Attestation de réception de l\'acquéreur',
        };
    }
}
