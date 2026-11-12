<?php

namespace App\Integrations\Pdp\Contracts;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use Throwable;

/**
 * Contrat d'intégration avec un PDP (plateforme agréée de facturation
 * électronique). Le domaine Facturation et le domaine Tenancy ne dépendent
 * QUE de cette interface, jamais d'un prestataire concret : aucun code
 * propre à un prestataire dans les actions métier. La passerelle active
 * suit son pilote (PDP_PILOTE, voir FournisseursExternesServiceProvider) :
 * aujourd'hui la seule passerelle factice (voir FakePdpGateway) ;
 * l'implémentation SUPER PDP (étape 2 de la feuille de route) prendra sa
 * place, avec l'envoi des ventes aux particuliers de chaque clôture
 * journalière ayant des opérations, depuis son aperçu (voir
 * EReportingDesParticuliers) : SUPER PDP tient le calendrier de
 * l'e-reporting.
 *
 * Une panne (réseau, plateforme injoignable, réponse illisible) est une
 * exception ; un rejet de la plateforme est une réponse, rendue dans le
 * résultat avec son motif (voir ResultatSoumissionPdp).
 */
interface PdpGatewayInterface
{
    public function creerMandat(Magasin $magasin): ResultatMandatPdp;

    public function revoquerMandat(MandatPdp $mandat): void;

    /**
     * Dépose la facture sous cette référence, que la plateforme garde avec
     * le dépôt : un second dépôt sous la même référence ne crée jamais une
     * seconde transmission chez elle.
     *
     * @throws Throwable sur une panne : le dépôt a pu être reçu ou non
     */
    public function soumettreFacture(Facture $facture, string $referenceDepot): ResultatSoumissionPdp;

    /**
     * Ce que la plateforme sait du dépôt fait sous la référence de cette
     * transmission, ou null si elle ne l'a jamais reçu : ce qu'on lui
     * demande avant de reprendre une transmission interrompue par une
     * panne, pour ne jamais déposer deux fois la même facture.
     *
     * @throws Throwable sur une panne
     */
    public function retrouverDepot(TransmissionPdp $transmission): ?ResultatSoumissionPdp;
}
