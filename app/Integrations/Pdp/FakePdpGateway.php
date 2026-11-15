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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * Passerelle factice (pilote « faux », voir
 * FournisseursExternesServiceProvider) : accepte tout instantanément, sans
 * aucune requête, et garde chaque dépôt sous sa référence, comme la
 * plateforme le ferait : un second dépôt sous la même référence rend le
 * premier. Elle permet de développer et de tester tout le circuit (mandat,
 * émission, transmission) ; l'implémentation SUPER PDP (étape 2 de la
 * feuille de route) la remplacera, par un nouveau pilote. Comme les autres
 * fournisseurs factices, elle se règle pour jouer les deux autres réponses
 * d'une vraie plateforme : rejeter le prochain dépôt (code et motif du
 * rejet), ou ne plus répondre, comme pendant une panne du réseau (voir
 * SubmitFactureToPdpJob, qui retente puis marque la transmission « non
 * transmise : incident technique »).
 */
class FakePdpGateway implements PdpGatewayInterface
{
    /**
     * Les dépôts reçus, par référence.
     *
     * @var array<string, ResultatSoumissionPdp>
     */
    private array $depots = [];

    /**
     * Le rejet que la plateforme opposera au prochain dépôt : son code et
     * son motif.
     *
     * @var array{0: string, 1: string}|null
     */
    private ?array $prochainRejet = null;

    private bool $injoignable = false;

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

    /**
     * @throws ConnectionException quand la plateforme est rendue injoignable
     */
    public function soumettreFacture(Facture $facture, string $referenceDepot): ResultatSoumissionPdp
    {
        $this->repondre();

        if (! isset($this->depots[$referenceDepot]) && $this->prochainRejet !== null) {
            [$code, $motif] = $this->prochainRejet;
            $this->prochainRejet = null;

            return $this->depots[$referenceDepot] = new ResultatSoumissionPdp(
                statut: StatutTransmissionPdp::Rejetee,
                transmissionExterneId: 'FAKE-TRANSMISSION-'.Str::uuid(),
                format: 'facturx',
                codeErreur: $code,
                messageErreur: $motif,
            );
        }

        return $this->depots[$referenceDepot] ??= new ResultatSoumissionPdp(
            statut: StatutTransmissionPdp::Acceptee,
            transmissionExterneId: 'FAKE-TRANSMISSION-'.Str::uuid(),
            format: 'facturx',
        );
    }

    /**
     * @throws ConnectionException quand la plateforme est rendue injoignable
     */
    public function retrouverDepot(TransmissionPdp $transmission): ?ResultatSoumissionPdp
    {
        $this->repondre();

        return $this->depots[$transmission->reference_depot] ?? null;
    }

    /**
     * La plateforme rejettera le prochain dépôt d'une facture qu'elle n'a
     * pas encore reçue, avec ce code et ce motif.
     */
    public function rejeterLeProchainDepot(string $code, string $motif): void
    {
        $this->prochainRejet = [$code, $motif];
    }

    /**
     * La plateforme ne répond plus (ou de nouveau), comme pendant une panne
     * du réseau.
     */
    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @throws ConnectionException
     */
    private function repondre(): void
    {
        if ($this->injoignable) {
            throw new ConnectionException('cURL error 7: Failed to connect to la plateforme agréée factice: Connection refused');
        }
    }
}
