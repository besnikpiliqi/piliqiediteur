<?php

namespace App\Integrations\Vies;

use App\Domain\Facturation\Services\NumeroTva;
use App\Domain\Facturation\Services\TerritoireFiscal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * VIES, le service de la Commission européenne qui dit si un n° de TVA de
 * l'Union est valide (art. 18 du règlement d'exécution 282/2011), par son
 * API REST (POST {url}/check-vat-number, voir config/services.php).
 *
 * Le demandeur s'identifie par le n° de TVA du magasin : VIES donne alors
 * à la consultation un n° (requestIdentifier), la preuve qu'elle a eu
 * lieu. Sans n° de TVA du magasin, la consultation n'en a pas.
 *
 * La réponse se lit avec tolérance : un champ absent ou d'un autre type ne
 * fait jamais croire à un n° valide.
 * - « valid » vrai ou faux : le n° est valide ou inconnu ;
 * - l'erreur INVALID_INPUT (n° mal formé pour son pays) : il est inconnu ;
 * - l'erreur INVALID_REQUESTER_INFO : VIES refuse le n° du magasin ;
 * - toute autre erreur (MS_UNAVAILABLE, TIMEOUT, SERVICE_UNAVAILABLE,
 *   MS_MAX_CONCURRENT_REQ, GLOBAL_MAX_CONCURRENT_REQ...), une réponse en
 *   erreur HTTP ou illisible, ou un service injoignable après une nouvelle
 *   tentative : ViesIndisponibleException, car rien ne prouve alors que le
 *   n° est valide.
 * Le journal de l'application note le pays, le statut HTTP et les codes
 * d'erreur d'une consultation impossible, jamais le n° ni la réponse.
 */
class ClientVies
{
    /**
     * Ce que VIES écrit à la place du nom ou de l'adresse que l'État membre
     * ne publie pas.
     */
    private const NON_PUBLIE = '---';

    /**
     * @param  string  $numeroTva  le n° vérifié, d'un État membre de l'Union ou d'Irlande du Nord (voir TerritoireFiscal::prefixeTvaUe())
     * @param  string|null  $numeroTvaDemandeur  le n° de TVA du magasin qui demande, s'il en a un
     *
     * @throws ViesIndisponibleException
     * @throws LogicException quand VIES refuse le n° du magasin
     */
    public function verifier(string $numeroTva, ?string $numeroTvaDemandeur): ResultatVies
    {
        $numeroTva = NumeroTva::normaliser($numeroTva);
        $prefixe = TerritoireFiscal::prefixeTvaUe($numeroTva)
            ?? throw new InvalidArgumentException('VIES ne vérifie que les n° de TVA de l\'Union européenne et d\'Irlande du Nord.');
        $demande = ['countryCode' => $prefixe, 'vatNumber' => substr($numeroTva, 2)];

        if (($prefixeDemandeur = TerritoireFiscal::prefixeTvaUe($numeroTvaDemandeur)) !== null) {
            $demande['requesterMemberStateCode'] = $prefixeDemandeur;
            $demande['requesterNumber'] = substr(NumeroTva::normaliser($numeroTvaDemandeur), 2);
        }

        try {
            $reponse = Http::baseUrl(config('services.vies.url'))
                ->timeout(config('services.vies.delai'))
                ->connectTimeout(5)
                ->acceptJson()
                ->retry(2, 500, static fn (Throwable $erreur): bool => $erreur instanceof ConnectionException, throw: false)
                ->post('check-vat-number', $demande);
        } catch (ConnectionException) {
            Log::warning('VIES est injoignable.', ['pays' => $prefixe]);

            throw new ViesIndisponibleException;
        }

        return $this->lire($reponse, $prefixe);
    }

    /**
     * @throws ViesIndisponibleException
     * @throws LogicException
     */
    private function lire(Response $reponse, string $prefixe): ResultatVies
    {
        $corps = is_array($reponse->json()) ? $reponse->json() : [];
        $erreurs = $this->erreurs($corps);

        if (in_array('INVALID_INPUT', $erreurs, true)) {
            return new ResultatVies(false, null, null, $this->texte($corps, 'requestIdentifier'), $reponse->body());
        }

        if (in_array('INVALID_REQUESTER_INFO', $erreurs, true)) {
            throw new LogicException('VIES refuse le n° de TVA du magasin, qui identifie chaque vérification : contrôlez-le dans Paramètres > Identité du magasin. Sans n° de TVA vérifié, la vente reste à la TVA française.');
        }

        if (! $reponse->successful() || $erreurs !== [] || ! is_bool($corps['valid'] ?? null)) {
            Log::warning('VIES n\'a pas pu vérifier un n° de TVA.', ['pays' => $prefixe, 'statut_http' => $reponse->status(), 'erreurs' => $erreurs]);

            throw new ViesIndisponibleException;
        }

        return new ResultatVies(
            valide: $corps['valid'],
            nom: $this->texte($corps, 'name'),
            adresse: $this->texte($corps, 'address'),
            identifiantConsultation: $this->texte($corps, 'requestIdentifier'),
            reponse: $reponse->body(),
        );
    }

    /**
     * Les codes d'erreur de la réponse : ceux de errorWrappers, et
     * userError quand il ne dit pas simplement « VALID » ou « INVALID ».
     *
     * @param  array<mixed>  $corps
     * @return list<string>
     */
    private function erreurs(array $corps): array
    {
        $codes = array_map(
            static fn (mixed $erreur): mixed => is_array($erreur) ? ($erreur['error'] ?? null) : null,
            is_array($corps['errorWrappers'] ?? null) ? $corps['errorWrappers'] : [],
        );
        $codes[] = $corps['userError'] ?? null;

        return array_values(array_filter($codes, static fn (mixed $code): bool => is_string($code) && ! in_array($code, ['VALID', 'INVALID'], true)));
    }

    /**
     * @param  array<mixed>  $corps
     */
    private function texte(array $corps, string $cle): ?string
    {
        $valeur = is_string($corps[$cle] ?? null) ? trim($corps[$cle]) : '';

        return $valeur === '' || $valeur === self::NON_PUBLIE ? null : $valeur;
    }
}
