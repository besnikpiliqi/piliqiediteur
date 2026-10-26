<?php

namespace App\Integrations\Sirene;

use App\Domain\Calendrier\Calendrier;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * L'API Sirene de l'INSEE (version 3.11, voir config/services.php) : GET
 * /siren/{siren} pour une unité légale, GET /siret/{siret} pour un
 * établissement, et la recherche multicritère GET /siret pour les
 * établissements actifs d'une entreprise, avec la clé d'intégration de
 * l'éditeur dans l'en-tête X-INSEE-Api-Key-Integration (INSEE_SIRENE_CLE).
 * L'API accepte 30 requêtes par minute : au-delà, le répertoire est tenu
 * pour indisponible, sans appel, et le contrôle se reprend plus tard.
 *
 * La recherche des établissements actifs demande ceux du SIREN dont l'état
 * administratif (variable historisée, d'où periode()) est « A » à la date
 * du jour (paramètre date), au plus ETABLISSEMENTS_PAR_RECHERCHE en une
 * fois ; chaque établissement rendu se relit ensuite comme une
 * consultation d'un SIRET (période en cours active, rattaché au SIREN),
 * et un établissement qui ne l'est pas n'est jamais proposé, quelle que
 * soit la lecture que l'API fait du critère.
 *
 * La réponse se lit avec tolérance : un champ absent ou d'un autre type ne
 * fait jamais croire à une entreprise ou à un établissement actif.
 * - 200 : la période en cours (la première de periodesUniteLegale ou de
 *   periodesEtablissement) dit l'état administratif, « A » pour actif ;
 * - 404 : le répertoire ne connaît pas ce numéro, ou aucun établissement
 *   ne répond à la recherche ;
 * - toute autre réponse, une réponse illisible, ou un service injoignable
 *   après une nouvelle tentative : SireneIndisponibleException.
 * Le journal de l'application note le statut HTTP d'une consultation
 * impossible, jamais la clé ni la réponse.
 */
class InseeSirene implements RepertoireSirene
{
    public const REQUETES_PAR_MINUTE = 30;

    /**
     * Le nombre d'établissements que la recherche demande en une fois, le
     * plus grand que l'API accepte.
     */
    public const ETABLISSEMENTS_PAR_RECHERCHE = 1000;

    public function unite(string $siren): ?UniteSirene
    {
        $corps = $this->consulter("siren/{$siren}");

        if ($corps === null) {
            return null;
        }

        $unite = is_array($corps['uniteLegale'] ?? null) ? $corps['uniteLegale'] : throw $this->illisible('siren');
        $periode = $this->periodeEnCours($unite['periodesUniteLegale'] ?? null) ?? throw $this->illisible('siren');
        $denomination = $this->texte($periode, 'denominationUniteLegale')
            ?? trim(implode(' ', array_filter([$this->texte($unite, 'prenomUsuelUniteLegale'), $this->texte($periode, 'nomUniteLegale')])));

        return new UniteSirene(
            siren: $siren,
            active: ($periode['etatAdministratifUniteLegale'] ?? null) === 'A',
            denomination: $denomination === '' ? null : $denomination,
            categorieJuridique: $this->texte($periode, 'categorieJuridiqueUniteLegale'),
            nicDuSiege: $this->texte($periode, 'nicSiegeUniteLegale'),
            diffusionPartielle: ($unite['statutDiffusionUniteLegale'] ?? null) === 'P',
        );
    }

    public function etablissement(string $siret): ?EtablissementSirene
    {
        $corps = $this->consulter("siret/{$siret}");

        if ($corps === null) {
            return null;
        }

        return $this->lireEtablissement(is_array($corps['etablissement'] ?? null) ? $corps['etablissement'] : throw $this->illisible('siret'), $siret);
    }

    public function etablissementsActifs(string $siren): EtablissementsActifs
    {
        $corps = $this->consulter('siret', [
            'q' => "siren:{$siren} AND periode(etatAdministratifEtablissement:A)",
            'date' => Calendrier::deParis()->aujourdhui(),
            'nombre' => self::ETABLISSEMENTS_PAR_RECHERCHE,
        ]);

        if ($corps === null) {
            return new EtablissementsActifs([], true);
        }

        $lignes = is_array($corps['etablissements'] ?? null) && array_is_list($corps['etablissements']) ? $corps['etablissements'] : throw $this->illisible('recherche');
        $total = is_array($corps['header'] ?? null) && is_int($corps['header']['total'] ?? null) ? $corps['header']['total'] : count($lignes);
        $actifs = [];

        foreach ($lignes as $ligne) {
            $siret = is_array($ligne) ? $this->texte($ligne, 'siret') : null;

            if ($siret === null || preg_match('/^\d{14}$/', $siret) !== 1) {
                throw $this->illisible('recherche');
            }

            $etablissement = $this->lireEtablissement($ligne, $siret);

            if ($etablissement->actif && $etablissement->estRattacheA($siren)) {
                $actifs[$siret] = $etablissement;
            }
        }

        usort($actifs, static fn (EtablissementSirene $un, EtablissementSirene $autre): int => [! $un->estSiege, $un->nic()] <=> [! $autre->estSiege, $autre->nic()]);

        return new EtablissementsActifs($actifs, $total <= count($lignes));
    }

    /**
     * Un établissement tel que le répertoire le décrit, dans une
     * consultation de son SIRET comme dans une recherche.
     *
     * @param  array<mixed>  $etablissement
     *
     * @throws SireneIndisponibleException
     */
    private function lireEtablissement(array $etablissement, string $siret): EtablissementSirene
    {
        $periode = $this->periodeEnCours($etablissement['periodesEtablissement'] ?? null) ?? throw $this->illisible('siret');
        $adresse = is_array($etablissement['adresseEtablissement'] ?? null) ? $etablissement['adresseEtablissement'] : [];
        $voie = trim(implode(' ', array_filter([
            $this->texte($adresse, 'numeroVoieEtablissement'),
            $this->texte($adresse, 'indiceRepetitionEtablissement'),
            $this->texte($adresse, 'typeVoieEtablissement'),
            $this->texte($adresse, 'libelleVoieEtablissement'),
        ])));

        return new EtablissementSirene(
            siret: $siret,
            siren: $this->texte($etablissement, 'siren') ?? substr($siret, 0, 9),
            actif: ($periode['etatAdministratifEtablissement'] ?? null) === 'A',
            adresse: $voie === '' ? null : $voie,
            complementAdresse: $this->texte($adresse, 'complementAdresseEtablissement'),
            codePostal: $this->texte($adresse, 'codePostalEtablissement'),
            codeCommune: $this->texte($adresse, 'codeCommuneEtablissement'),
            ville: $this->texte($adresse, 'libelleCommuneEtablissement'),
            diffusionPartielle: ($etablissement['statutDiffusionEtablissement'] ?? null) === 'P',
            enseigne: $this->texte($periode, 'enseigne1Etablissement') ?? $this->texte($periode, 'denominationUsuelleEtablissement'),
            estSiege: ($etablissement['etablissementSiege'] ?? null) === true,
        );
    }

    /**
     * Le corps de la réponse, null pour un numéro inconnu ou une recherche
     * sans résultat (404).
     *
     * @param  array<string, string|int>  $parametres
     * @return array<mixed>|null
     *
     * @throws SireneIndisponibleException
     */
    private function consulter(string $chemin, array $parametres = []): ?array
    {
        $cle = config('services.sirene.cle');

        if (! is_string($cle) || $cle === '') {
            Log::warning('Sirene : la clé d\'intégration de l\'INSEE (INSEE_SIRENE_CLE) est absente.');

            throw new SireneIndisponibleException;
        }

        if (! RateLimiter::attempt('insee-sirene', self::REQUETES_PAR_MINUTE, static fn (): bool => true, 60)) {
            throw new SireneIndisponibleException;
        }

        try {
            $reponse = Http::baseUrl(config('services.sirene.url'))
                ->withHeaders(['X-INSEE-Api-Key-Integration' => $cle])
                ->timeout(config('services.sirene.delai'))
                ->connectTimeout(5)
                ->acceptJson()
                ->retry(2, 500, static fn (Throwable $erreur): bool => $erreur instanceof ConnectionException, throw: false)
                ->get($chemin, $parametres);
        } catch (ConnectionException) {
            Log::warning('Sirene : le répertoire de l\'INSEE est injoignable.');

            throw new SireneIndisponibleException;
        }

        return $this->lire($reponse);
    }

    /**
     * @return array<mixed>|null
     *
     * @throws SireneIndisponibleException
     */
    private function lire(Response $reponse): ?array
    {
        if ($reponse->status() === 404) {
            return null;
        }

        if (! $reponse->successful() || ! is_array($reponse->json())) {
            Log::warning('Sirene : le répertoire de l\'INSEE n\'a pas répondu.', ['statut_http' => $reponse->status()]);

            throw new SireneIndisponibleException;
        }

        return $reponse->json();
    }

    /**
     * La période en cours : la première de la liste, la plus récente.
     *
     * @return array<mixed>|null
     */
    private function periodeEnCours(mixed $periodes): ?array
    {
        return is_array($periodes) && is_array($periodes[0] ?? null) ? $periodes[0] : null;
    }

    private function illisible(string $ressource): SireneIndisponibleException
    {
        Log::warning('Sirene : réponse illisible du répertoire de l\'INSEE.', ['ressource' => $ressource]);

        return new SireneIndisponibleException;
    }

    /**
     * @param  array<mixed>  $donnees
     */
    private function texte(array $donnees, string $cle): ?string
    {
        $valeur = is_string($donnees[$cle] ?? null) ? trim($donnees[$cle]) : '';

        return $valeur === '' || $valeur === '[ND]' ? null : $valeur;
    }
}
