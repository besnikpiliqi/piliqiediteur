<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Models\User;
use App\Providers\FournisseursExternesServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use LogicException;

/**
 * Le consentement se donne entièrement sur notre site (un clic "Accepter"
 * une fois le compte confirmé) -- pas de redirection vers une UI du PDP.
 * La preuve du consentement s'enregistre AVANT l'appel au PDP, dans sa
 * propre transaction, validée avant lui : c'est notre preuve légale,
 * indépendante de ce que répond le PDP, et elle survit à une panne de
 * l'appel (le mandat reste alors en attente, et l'exception remonte).
 */
class CreerMandatPdpAction
{
    public function __construct(private readonly PdpGatewayInterface $gateway) {}

    /**
     * @throws LogicException pour le magasin de démonstration, qui ne transmet jamais rien
     */
    public function handle(Magasin $magasin, User $accordePar): MandatPdp
    {
        if ($magasin->est_demonstration) {
            throw new LogicException('Le magasin de démonstration ne transmet rien à la plateforme.');
        }

        $mandat = DB::transaction(fn (): MandatPdp => MandatPdp::updateOrCreate(
            ['magasin_id' => $magasin->getKey()],
            [
                'prestataire_pdp' => FournisseursExternesServiceProvider::pdpActive(),
                'statut' => StatutMandatPdp::EnAttente,
                'perimetre' => 'both',
                'accorde_par' => $accordePar->getKey(),
                'consentement_ip' => Request::ip(),
                'consentement_user_agent' => Request::userAgent(),
                'consentement_a' => now(),
                'mandat_externe_id' => null,
                'revoque_a' => null,
            ],
        ));

        $resultat = $this->gateway->creerMandat($magasin);

        $mandat->forceFill([
            'mandat_externe_id' => $resultat->mandatExterneId,
            'statut' => $resultat->statut,
            'active_a' => $resultat->statut === StatutMandatPdp::Active ? now() : null,
            'reponse_brute' => $resultat->donneesBrutes,
        ])->save();

        return $mandat;
    }
}
