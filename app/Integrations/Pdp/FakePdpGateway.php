<?php

namespace App\Integrations\Pdp;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\Contracts\ResultatMandatPdp;
use App\Integrations\Pdp\Contracts\ResultatSoumissionPdp;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use App\Integrations\Pdp\Models\TransmissionPdp;
use Illuminate\Support\Str;

/**
 * Passerelle factice (pilote « faux », voir
 * FournisseursExternesServiceProvider) : accepte tout instantanément, sans
 * aucune requête, et garde chaque dépôt sous sa référence, comme la
 * plateforme le ferait : un second dépôt sous la même référence rend le
 * premier. Elle permet de développer et de tester tout le circuit (mandat,
 * émission, transmission) ; l'implémentation SUPER PDP (étape 2 de la
 * feuille de route) la remplacera, par un nouveau pilote.
 */
class FakePdpGateway implements PdpGatewayInterface
{
    /**
     * Les dépôts reçus, par référence.
     *
     * @var array<string, ResultatSoumissionPdp>
     */
    private array $depots = [];

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

    public function soumettreFacture(Facture $facture, string $referenceDepot): ResultatSoumissionPdp
    {
        return $this->depots[$referenceDepot] ??= new ResultatSoumissionPdp(
            statut: StatutTransmissionPdp::Acceptee,
            transmissionExterneId: 'FAKE-TRANSMISSION-'.Str::uuid(),
            format: 'facturx',
        );
    }

    public function retrouverDepot(TransmissionPdp $transmission): ?ResultatSoumissionPdp
    {
        return $this->depots[$transmission->reference_depot] ?? null;
    }
}
