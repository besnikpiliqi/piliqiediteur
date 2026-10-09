<?php

namespace App\Integrations\Pdp;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatMandatPdp;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use Illuminate\Support\Str;

/**
 * Passerelle factice : accepte tout instantanément. Permet de développer et
 * tester tout le pipeline (mandat, émission, transmission) sans dépendre
 * d'un réseau externe. L'implémentation SUPER PDP (étape 11 de la feuille
 * de route) la remplacera, en changeant uniquement le binding dans
 * PdpServiceProvider.
 */
class FakePdpGateway implements PdpGatewayInterface
{
    public function creerMandat(Magasin $magasin): ResultatMandatPdp
    {
        return new ResultatMandatPdp(
            statut: StatutMandatPdp::Active,
            mandatExterneId: 'FAKE-MANDAT-'.Str::uuid(),
        );
    }

    public function revoquerMandat(MandatPdp $mandat): void
    {
        // Rien à faire côté sandbox : la révocation locale (statut,
        // revoque_a) est gérée par RevokerMandatPdpAction.
    }

    public function soumettreFacture(Facture $facture): ResultatSoumissionPdp
    {
        return new ResultatSoumissionPdp(
            statut: StatutTransmissionPdp::Acceptee,
            transmissionExterneId: 'FAKE-TRANSMISSION-'.Str::uuid(),
            format: 'facturx',
        );
    }

    public function soumettreEReporting(SoumissionEReporting $soumission): ResultatSoumissionPdp
    {
        return new ResultatSoumissionPdp(
            statut: StatutTransmissionPdp::Acceptee,
            transmissionExterneId: 'FAKE-EREPORTING-'.Str::uuid(),
            format: 'ereporting',
        );
    }
}
