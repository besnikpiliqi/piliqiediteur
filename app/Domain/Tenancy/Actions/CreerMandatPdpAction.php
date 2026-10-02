<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Le consentement se donne entièrement sur notre site (un clic "Accepter"
 * une fois le compte confirmé) -- pas de redirection vers une UI du PDP.
 * On enregistre la preuve de consentement AVANT même d'appeler le PDP :
 * c'est notre preuve légale, indépendante de ce que répond le PDP, et elle
 * doit survivre même si l'appel réseau échoue.
 */
class CreerMandatPdpAction
{
    public function __construct(private readonly PdpGatewayInterface $gateway) {}

    public function handle(Magasin $magasin, User $accordePar): MandatPdp
    {
        return DB::transaction(function () use ($magasin, $accordePar) {
            $mandat = MandatPdp::updateOrCreate(
                ['magasin_id' => $magasin->getKey()],
                [
                    'fournisseur_pdp' => config('services.pdp.nom', 'fake'),
                    'statut' => StatutMandatPdp::EnAttente,
                    'perimetre' => 'both',
                    'accorde_par' => $accordePar->getKey(),
                    'consentement_ip' => Request::ip(),
                    'consentement_user_agent' => Request::userAgent(),
                    'consentement_a' => now(),
                    'mandat_externe_id' => null,
                    'revoque_a' => null,
                ],
            );

            $resultat = $this->gateway->creerMandat($magasin);

            $mandat->forceFill([
                'mandat_externe_id' => $resultat->mandatExterneId,
                'statut' => $resultat->statut,
                'active_a' => $resultat->statut === StatutMandatPdp::Active ? now() : null,
                'reponse_brute' => $resultat->donneesBrutes,
            ])->save();

            return $mandat;
        });
    }
}
