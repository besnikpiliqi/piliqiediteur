<?php

namespace App\Integrations\Pdp\Contracts;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;

/**
 * Contrat d'intégration avec un PDP (Plateforme de Dématérialisation
 * Partenaire). Le domaine Facturation et le domaine Tenancy ne dépendent
 * QUE de cette interface, jamais d'un vendeur concret (voir §18 du cahier
 * des charges : pas de code spécifique à un fournisseur dans les actions
 * métier). FakePdpGateway (liée par défaut, voir PdpServiceProvider) sert
 * de sandbox tant qu'aucun vendeur réel n'est choisi ; une implémentation
 * réelle prendra sa place sous App\Integrations\Pdp\<NomDuVendeur>\...
 */
interface PdpGatewayInterface
{
    public function creerMandat(Magasin $magasin): ResultatMandatPdp;

    public function revoquerMandat(MandatPdp $mandat): void;

    public function soumettreFacture(Facture $facture): ResultatSoumissionPdp;
}
