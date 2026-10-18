<?php

namespace App\Integrations\Pdp\Contracts;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;

/**
 * Contrat d'intégration avec un PDP (plateforme agréée de facturation
 * électronique). Le domaine Facturation et le domaine Tenancy ne dépendent
 * QUE de cette interface, jamais d'un prestataire concret : aucun code
 * propre à un prestataire dans les actions métier. FakePdpGateway (liée par
 * défaut, voir PdpServiceProvider) est une passerelle factice ;
 * l'implémentation SUPER PDP (étape 2 de la feuille de route) prendra sa
 * place, avec l'envoi des ventes aux particuliers de chaque clôture
 * journalière ayant des opérations, depuis son aperçu (voir
 * EReportingDesParticuliers) : SUPER PDP tient le calendrier de
 * l'e-reporting.
 */
interface PdpGatewayInterface
{
    public function creerMandat(Magasin $magasin): ResultatMandatPdp;

    public function revoquerMandat(MandatPdp $mandat): void;

    public function soumettreFacture(Facture $facture): ResultatSoumissionPdp;
}
