<?php

namespace App\Integrations\Pdp;

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatMandatPdp;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use Illuminate\Support\Str;

/**
 * Sandbox locale : accepte tout instantanément. Permet de développer et
 * tester tout le pipeline (mandat, émission, transmission) sans attendre
 * le choix d'un vendeur PDP réel ni dépendre d'un réseau externe dans les
 * tests. À remplacer par une vraie implémentation sous
 * App\Integrations\Pdp\<Vendeur>\<Vendeur>PdpGateway une fois choisie,
 * en changeant uniquement le binding dans PdpServiceProvider.
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

    public function soumettreClotureCaisse(ClotureCaisse $cloture): ResultatSoumissionPdp
    {
        return new ResultatSoumissionPdp(
            statut: StatutTransmissionPdp::Acceptee,
            transmissionExterneId: 'FAKE-EREPORTING-'.Str::uuid(),
            format: 'ereporting',
        );
    }
}
