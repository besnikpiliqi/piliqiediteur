<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Services\TerritoireFiscal;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Vies\ClientVies;
use App\Integrations\Vies\ViesIndisponibleException;
use InvalidArgumentException;
use LogicException;

/**
 * Vérifie sur VIES le n° de TVA d'un client professionnel de l'Union et
 * en conserve la preuve datée (I2, art. 262 ter I et 259-1° du CGI) : une
 * vérification, valide ou non, qui ne se modifie ni ne se supprime (voir
 * ConsultationVies). Sans n° valide, la vente reste à la TVA française.
 *
 * Le client se relit dans le magasin actif (MagasinScope) : c'est le n°
 * enregistré sur sa fiche qui se vérifie, jamais une copie en mémoire, et
 * jamais la fiche d'un autre magasin. Le magasin s'identifie auprès de
 * VIES par le n° de TVA de sa société, pour que la consultation reçoive un
 * n° de preuve.
 *
 * L'appel à VIES, qui peut durer plusieurs secondes, a lieu hors de toute
 * transaction : il ne retient aucun verrou, et la vérification
 * s'enregistre ensuite seule.
 */
class VerifierNumeroTvaAction
{
    public function __construct(private readonly ClientVies $vies) {}

    /**
     * @throws LogicException quand le client ne se vérifie pas sur VIES, ou quand VIES refuse le n° du magasin
     * @throws ViesIndisponibleException
     */
    public function handle(Client $client): ConsultationVies
    {
        $client = Client::query()->whereKey($client->getKey())->firstOrFail();

        if (! $client->estProfessionnel()) {
            throw new InvalidArgumentException('Seul le n° de TVA d\'un client professionnel se vérifie sur VIES.');
        }

        if (blank($client->numero_tva)) {
            throw new InvalidArgumentException('Ce client n\'a pas de n° de TVA à vérifier : renseignez-le d\'abord sur sa fiche.');
        }

        if (TerritoireFiscal::prefixeTvaUe($client->numero_tva) === null) {
            throw new InvalidArgumentException('VIES ne vérifie que les n° de TVA de l\'Union européenne (et XI pour l\'Irlande du Nord).');
        }

        $resultat = $this->vies->verifier($client->numero_tva, app(TenantContext::class)->magasin()->societe->numero_tva);

        return ConsultationVies::forceCreate([
            'client_id' => $client->getKey(),
            'numero_tva' => $client->numero_tva,
            'valide' => $resultat->valide,
            'nom' => $resultat->nom === null ? null : mb_substr($resultat->nom, 0, 255),
            'adresse' => $resultat->adresse,
            'identifiant_consultation' => $resultat->identifiantConsultation === null ? null : mb_substr($resultat->identifiantConsultation, 0, 64),
            'consultee_le' => now(),
            'consultee_par' => auth()->id(),
            'reponse' => $resultat->reponse,
        ]);
    }
}
